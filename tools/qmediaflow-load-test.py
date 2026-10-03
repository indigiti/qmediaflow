#!/usr/bin/env python3
"""Bounded HTTP load validator for QMediaFlow staging/production endpoints."""
from __future__ import annotations

import argparse
import concurrent.futures
import json
import math
import statistics
import time
import urllib.error
import urllib.request
from dataclasses import dataclass, asdict
from pathlib import Path


@dataclass
class Result:
    url: str
    status: int
    ms: float
    bytes: int
    error: str = ""


def percentile(values: list[float], q: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    index = max(0, min(len(ordered) - 1, math.ceil(len(ordered) * q) - 1))
    return ordered[index]


def request_once(url: str, timeout: float, headers: dict[str, str]) -> Result:
    started = time.perf_counter()
    request = urllib.request.Request(url, headers=headers, method="GET")
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = response.read()
            status = int(response.status)
            return Result(url, status, (time.perf_counter() - started) * 1000, len(body))
    except urllib.error.HTTPError as exc:
        body = exc.read() if exc.fp else b""
        return Result(url, int(exc.code), (time.perf_counter() - started) * 1000, len(body), str(exc))
    except Exception as exc:  # network failures are part of the measured result
        return Result(url, 0, (time.perf_counter() - started) * 1000, 0, str(exc))


def parse_headers(values: list[str]) -> dict[str, str]:
    headers = {"User-Agent": "QMediaFlow-Load-Validator/0.3.0", "Accept": "image/avif,image/webp,image/*,*/*;q=0.8"}
    for value in values:
        if ":" not in value:
            raise SystemExit(f"Invalid --header {value!r}; expected 'Name: value'.")
        name, header_value = value.split(":", 1)
        headers[name.strip()] = header_value.strip()
    return headers


def load_urls(args: argparse.Namespace) -> list[str]:
    urls = list(args.url or [])
    if args.url_file:
        for line in Path(args.url_file).read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                urls.append(line)
    if not urls:
        raise SystemExit("Provide at least one --url or --url-file.")
    for url in urls:
        if not (url.startswith("http://") or url.startswith("https://")):
            raise SystemExit(f"Unsupported URL: {url}")
    return urls


def main() -> int:
    parser = argparse.ArgumentParser(description="Measure QMediaFlow derivative/CDN HTTP latency and concurrency without third-party packages.")
    parser.add_argument("--url", action="append", help="Image URL to test; repeat for a representative URL set.")
    parser.add_argument("--url-file", help="Text file containing one image URL per line.")
    parser.add_argument("--requests", type=int, default=100, help="Measured requests (default: 100).")
    parser.add_argument("--concurrency", type=int, default=20, help="Concurrent workers (default: 20, max: 250).")
    parser.add_argument("--warmup", type=int, default=5, help="Unmeasured warmup requests (default: 5).")
    parser.add_argument("--timeout", type=float, default=15.0, help="Per-request timeout seconds (default: 15).")
    parser.add_argument("--expect-status", type=int, default=200, help="Expected HTTP status (default: 200).")
    parser.add_argument("--max-p95-ms", type=float, default=0.0, help="Fail if p95 exceeds this value; 0 disables the gate.")
    parser.add_argument("--min-success-rate", type=float, default=0.99, help="Fail below this 0..1 success fraction (default: 0.99).")
    parser.add_argument("--header", action="append", default=[], help="Extra request header, e.g. 'Cache-Control: no-cache'.")
    parser.add_argument("--json", action="store_true", help="Emit JSON only.")
    args = parser.parse_args()

    args.requests = max(1, min(100000, args.requests))
    args.concurrency = max(1, min(250, args.concurrency))
    args.warmup = max(0, min(1000, args.warmup))
    args.timeout = max(0.5, min(120.0, args.timeout))
    args.min_success_rate = max(0.0, min(1.0, args.min_success_rate))
    urls = load_urls(args)
    headers = parse_headers(args.header)

    for index in range(args.warmup):
        request_once(urls[index % len(urls)], args.timeout, headers)

    started = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        futures = [pool.submit(request_once, urls[index % len(urls)], args.timeout, headers) for index in range(args.requests)]
        results = [future.result() for future in concurrent.futures.as_completed(futures)]
    elapsed = max(0.000001, time.perf_counter() - started)

    success = [r for r in results if r.status == args.expect_status]
    latency = [r.ms for r in success]
    report = {
        "requests": len(results),
        "success": len(success),
        "failed": len(results) - len(success),
        "success_rate": round(len(success) / max(1, len(results)), 5),
        "concurrency": args.concurrency,
        "elapsed_seconds": round(elapsed, 4),
        "requests_per_second": round(len(results) / elapsed, 2),
        "latency_ms": {
            "min": round(min(latency), 2) if latency else 0.0,
            "avg": round(statistics.fmean(latency), 2) if latency else 0.0,
            "p50": round(percentile(latency, 0.50), 2),
            "p95": round(percentile(latency, 0.95), 2),
            "p99": round(percentile(latency, 0.99), 2),
            "max": round(max(latency), 2) if latency else 0.0,
        },
        "bytes": sum(r.bytes for r in success),
        "status_counts": {},
        "sample_errors": [asdict(r) for r in results if r.status != args.expect_status][:10],
        "note": "For a true cold-generation test, supply distinct uncached signed derivative URLs or purge the relevant staging cache first.",
    }
    for result in results:
        key = str(result.status)
        report["status_counts"][key] = report["status_counts"].get(key, 0) + 1

    if args.json:
        print(json.dumps(report, indent=2))
    else:
        print("QMediaFlow production load validation")
        print(f"requests={report['requests']} success={report['success']} failed={report['failed']} rate={report['success_rate']:.3f}")
        print(f"rps={report['requests_per_second']} p50={report['latency_ms']['p50']}ms p95={report['latency_ms']['p95']}ms p99={report['latency_ms']['p99']}ms")
        print(json.dumps(report, indent=2))

    failed_gate = report["success_rate"] < args.min_success_rate
    if args.max_p95_ms > 0 and report["latency_ms"]["p95"] > args.max_p95_ms:
        failed_gate = True
    return 2 if failed_gate else 0


if __name__ == "__main__":
    raise SystemExit(main())
