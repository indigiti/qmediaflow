# QMediaFlow v0.3.0 production validation runbook

Repository CI proves source/syntax/contract behavior. It does **not** prove the capacity of a particular PHP-FPM pool, encoder build, CDN, S3 endpoint, theme, browser mix, or web server. Run this checklist against the actual staging stack before production release.

## 1. Upgrade and routing

After deploying v0.3.0, load WordPress once so routing schema 6 can rewrite the managed cache rules to `qmediaflow-edge.php`.

Apache/LiteSpeed checks:

- an existing derivative is served directly from `image-cache`;
- a missing signed derivative routes to `qmediaflow-edge.php`;
- the edge wrapper can delegate to `qmediaflow-gateway.php`;
- immutable files return `Cache-Control: public, max-age=31536000, immutable`;
- temporary cold fallback responses remain `no-store`.

For Nginx, mirror the same static-first rule and route only missing signed derivative patterns to the edge wrapper. Never route existing derivative files through WordPress/PHP.

## 2. WordPress release gate

Run:

```bash
wp qmediaflow validate production --deep
```

The command checks cache/private permissions, signing key, edge/gateway files, generated runtime config, cold routes, immutable/no-store rules, WebP support, queue failures/age, generation timing telemetry, and object-store health when configured.

A `production_ready: true` value means there are no hard configuration failures in those checks. Warnings still require review, and the command explicitly does not claim that HTTP load testing has run.

## 3. Existing focal metadata

Sites that already contain `_qmediaflow_focal_x` / `_qmediaflow_focal_y` attachment metadata should backfill zero-DB runtime markers in bounded batches:

```bash
wp qmediaflow focal sync --limit=250 --offset=0
wp qmediaflow focal sync --limit=250 --offset=250
```

Continue until the returned `processed`/`total` indicates completion. This is an administrative migration only; frontend delivery does not fall back to focal post-meta queries.

## 4. Encoder benchmark

Review the deep health output for WebP/AVIF support and encoder latency. WebP is the normal baseline. Enable or aggressively warm AVIF only when its actual server throughput is acceptable for the site's CPU/memory budget.

Also verify:

- PHP memory limit supports the configured source/output pixel ceilings;
- `QMEDIAFLOW_MAX_GENERATORS` does not consume the PHP-FPM worker pool under bursts;
- Imagick policy does not unexpectedly reject expected source dimensions/formats.

## 5. Concurrent HTTP validation

Prepare representative signed derivative URLs in a text file. For a warm-cache test, request already-generated URLs:

```bash
python3 tools/qmediaflow-load-test.py \
  --url-file warm-urls.txt \
  --requests 500 \
  --concurrency 50 \
  --min-success-rate 0.99
```

For cold generation, use unique uncached signed variants or purge only the relevant staging derivatives first:

```bash
python3 tools/qmediaflow-load-test.py \
  --url-file cold-urls.txt \
  --requests 100 \
  --concurrency 25 \
  --min-success-rate 0.99
```

The tool reports RPS, bytes, HTTP status counts and min/avg/p50/p95/p99/max latency. Optional `--max-p95-ms` can turn a deployment-specific latency target into a failing exit code.

Do not use one already-warm URL and call that a cold-generation benchmark.

## 6. Static hot-path proof

During the warm test, confirm at the server/APM layer that derivative requests do not appear as WordPress/PHP transactions. This is the strongest check of the central QMediaFlow invariant.

Expected warm derivative work:

- WordPress executions: **0**
- database queries: **0**
- encoder invocations: **0**

## 7. Viewport/perceived-performance validation

Test representative pages on desktop and mobile throttling:

- hero/LCP image is immediate and not viewport-deferred;
- below-fold image keeps LQIP/gradient until activation;
- fast networks preload farther ahead;
- Save-Data uses a conservative margin;
- fast scrolling does not show repeated blank images;
- reveal occurs after decode;
- `prefers-reduced-motion` removes transition;
- width/height remain stable and CLS is near zero.

Use browser performance tooling to record LCP, CLS and INP. QMediaFlow does not hard-code universal millisecond pass/fail thresholds because server, theme, geography and content vary.

## 8. WooCommerce/theme/editor compatibility

Exercise at least:

- featured images;
- Gutenberg image/gallery blocks;
- classic content images if used;
- theme card/grid images;
- WooCommerce catalog cards;
- product hero/gallery/variation images;
- cart/checkout thumbnails;
- any theme/plugin code that references physical thumbnail filenames directly.

Run thumbnail migration only after its dry-run report and rollback path have been reviewed.

## 9. CDN validation

When a CDN base URL is configured:

- verify the CDN receives immutable derivative URLs;
- check cache-hit ratio after warming;
- verify origin shielding/rate behavior during a cold burst;
- ensure query-string/cache-key rules do not collapse distinct signed paths;
- verify the CDN does not cache no-store fallback responses.

## 10. S3-compatible storage validation

With object storage enabled:

```bash
wp qmediaflow distribution health
wp qmediaflow validate production --deep
```

Then force at least one derivative to be generated only through the standalone cold edge and confirm:

1. a sharded distribution job appears;
2. `distribution-pending` causes background processing on the next WordPress request;
3. the object arrives at the configured key;
4. retries/backoff work for a deliberately unavailable test endpoint;
5. credentials never appear in `image-cache/__qmediaflow-gateway-config.php`.

## 11. Intelligence and predictive warming

Inspect a sample image:

```bash
wp qmediaflow intelligence analyze 123
wp qmediaflow intelligence show 123
```

Verify the classification/quality/focal suggestion is sensible for the site's image mix before enabling `QMEDIAFLOW_AUTO_FOCAL` globally.

Inspect predictive state:

```bash
wp qmediaflow predictive status
```

Predictive state should contain only post-level score/sample/timestamp values, not visitor identifiers.

## 12. Release record

Record these results with the deployment:

- WordPress/PHP versions;
- web server and CDN;
- Imagick/GD versions;
- CPU/memory/PHP-FPM worker configuration;
- warm/cold test request count and concurrency;
- success rate and p50/p95/p99;
- queue max age/failure count;
- CDN hit ratio after warm-up;
- S3 health result if enabled;
- representative LCP/CLS/INP;
- theme/WooCommerce/editor compatibility outcome.

Only those measured values should be used for production-capacity claims.
