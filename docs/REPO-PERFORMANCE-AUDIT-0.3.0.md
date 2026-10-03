# QMediaFlow v0.3.0 — Repository Performance Audit

This audit targets the v0.3.0 integration branch and its runtime architecture, with one non-negotiable invariant: **warm derivative delivery must remain a web-server static-file path with zero WordPress bootstrap, zero PHP application work, and zero database queries.**

## Audit result

The core architecture is sound for large libraries: immutable signed derivative URLs, sharded cache/manifests, non-blocking generation locks, atomic publication, bounded responsive candidates, bounded workers, O(1) namespace rotation, and a standalone cold-miss gateway. The audit found several implementation details that would have created avoidable filesystem/CPU/database pressure at scale. They are fixed on the performance-audit branch.

## High-impact fixes

### 1. Telemetry no longer serializes image delivery

Before the audit, each telemetry counter/timing update could take a blocking filesystem lock, read JSON, and rewrite the telemetry file. One image generation emits several events, so telemetry could become its own contention point.

Now WordPress-side telemetry is aggregated in request memory and flushed at most once at shutdown. Normal shutdown uses a non-blocking exclusive lock; if metrics storage is busy, observability yields to delivery rather than delaying the response. Admin/CLI snapshots can explicitly perform a blocking flush because they are not visitor hot paths.

The standalone gateway uses the same principle: counters/timings are buffered and merged once with a non-blocking shutdown flush.

### 2. Gateway configuration is change-driven

The feature graph reloads on ordinary WordPress requests and on multisite context switches. The previous gateway config included a changing timestamp in the runtime state and therefore rewrote the config and inspected routing on every request.

Gateway state now has a semantic hash stored in the private runtime. Ordinary requests perform a tiny state check and do **zero writes** when configuration has not changed. Gateway config and focal routing are regenerated only after a real runtime/routing change or missing file.

### 3. Distribution queue is sharded

The object-storage distribution queue previously stored all jobs in one directory. That conflicts with QMediaFlow's large-library design. Distribution jobs are now sharded by the first four hex characters of the immutable job identity, with bounded generator-style scans and empty-shard cleanup.

The CDN/public base URL is also resolved once per site-scoped `Distribution` instance instead of re-running constant/filter resolution for every responsive candidate.

### 4. Optional smart delivery has true fast exits

The smart-delivery decorator previously reopened manifests and evaluated focal metadata for images even when CDN rewriting and smart quality were disabled.

Now non-cropped images with optional smart features disabled return immediately. The second `WP_HTML_Tag_Processor` pass is skipped when no CDN, smart-quality or cropped-QMediaFlow token is present. Registered-size crop metadata is request-cached.

### 5. Focal delivery is zero-DB

Post meta remains authoritative in the Media editor, but delivery no longer queries attachment meta to discover focal coordinates. Only non-center focal points receive tiny sharded private runtime markers. Absence of a marker means the default center point. Markers are atomically updated from admin edits and removed with the attachment.

This is especially important for WooCommerce/archive grids containing many cropped images.

### 6. Content-aware encoding avoids repeated source stats

Source byte size is cached by source revision/path per request. Responsive width/format loops therefore do not repeatedly `stat()` the same original image.

### 7. Browser upload convergence is faster

The Web Worker retains the quality binary search and 500 KB hard contract, but difficult sources no longer shrink by only 10% for every failed dimension pass. The worker estimates the next linear size from the encoded-byte ratio (`sqrt(target/current)`) with conservative 0.65–0.90 bounds, reducing repeated WebP encodes on high-resolution or high-entropy inputs. One `OffscreenCanvas` is reused across dimension passes.

### 8. AVIF/WebP capability checks are cached

Dual-format `<picture>` generation caches per-request encoder capability and resolves a focal point once per image instead of repeatedly inside format × width loops.

## Performance invariants enforced by CI

`tests/check_source.py` now explicitly checks that:

- the standalone gateway does not load WordPress;
- cold-generation locks remain non-blocking;
- gateway and WordPress telemetry use buffered/non-blocking delivery-path behavior;
- gateway runtime sync is semantic-change-driven;
- derivative and distribution queues are sharded/bounded;
- browser upload uses bounded workers and byte-ratio dimension convergence;
- default smart-delivery HTML processing has a fast exit;
- focal runtime lookup contains no post-meta query;
- generated files are atomically published;
- cache success responses use immutable one-year headers and fallbacks use `no-store`.

## Current performance model

### Warm image request

Web server/CDN → immutable file.

Target application work: **0 PHP, 0 WordPress, 0 DB, 0 encoder work.**

### Cold image request

Web server → standalone QMediaFlow gateway → HMAC validation → filesystem manifest → non-blocking per-variant lock → bounded global generator slot → Imagick/GD encode → atomic publish → immutable response.

Full WordPress bootstrap is not required for the normal standalone gateway path.

### WordPress HTML generation

Manifest data is filesystem-backed and request-cached. Responsive candidate count remains bounded. Optional CDN/smart/focal decorators now short-circuit when not needed. Custom focal delivery uses private filesystem state instead of attachment-meta DB reads.

### Background work

Critical warming, derivative jobs, LQIP jobs and object-store mirroring are bounded/deduplicated and separated from the warm delivery path. Queue storage is sharded for large installations.

## Production validation still required

Source/contract CI cannot prove real production throughput. Before calling any installation fully tuned, validate on its actual stack:

1. Apache/LiteSpeed or Nginx static-hit and cold-gateway routing.
2. Real PHP-FPM worker count, memory limits and ImageMagick resource policy.
3. WebP/AVIF encoder throughput and output quality on representative media.
4. CDN cache headers, origin shield behavior and cache-hit ratio.
5. S3-compatible latency/error behavior if object mirroring is enabled.
6. WooCommerce/theme/plugin compatibility for registered crop sizes and markup filters.
7. Multisite routing under real host/path topology.
8. Sustained cold-miss concurrency and publish-time warming under production CPU/memory limits.

Useful operational commands:

```bash
wp qmediaflow status
wp qmediaflow health --deep
wp qmediaflow metrics
wp qmediaflow queue status
wp qmediaflow distribution health
```

For Nginx, mirror the documented `/image-cache` static-first routing and deny HTTP access to `__qmediaflow-gateway-config.php`.

## Remaining deliberate trade-offs

- S3 mirroring is asynchronous and may buffer an object in the background PHP worker; original mirroring is optional and should remain disabled for unusually large unbounded imports unless the deployment is sized for it.
- Gateway telemetry is best-effort by design. Losing a metric under lock contention is preferable to slowing an image response.
- Runtime routing self-repair is no longer a per-request `.htaccess` content scan. Health diagnostics should be used after manual server-rule edits.
- AVIF is opt-in because encoder throughput varies materially by server build. WebP remains the safer general-purpose default until real encoder benchmarks justify AVIF generation.

## Release recommendation

Merge these audit fixes into the v0.3.0 integration branch only after CI is green. Then perform a staging smoke test with the deployment's actual web server, WordPress/theme/WooCommerce stack, and encoder libraries before merging v0.3.0 to `main`.
