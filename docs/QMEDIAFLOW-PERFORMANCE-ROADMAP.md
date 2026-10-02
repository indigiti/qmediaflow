# QMediaFlow Performance & Delivery Roadmap

This roadmap starts from QMediaFlow's existing strengths: browser-first source optimization, static warm delivery, immutable revisioned URLs, sharded filesystem manifests, non-blocking anti-stampede generation, bounded responsive candidates, Auto LQIP, and O(1) logical cache rotation.

The main objective is to reduce **time to first optimized byte on cold variants** while preserving the existing rule that warm delivery remains a static-file request.

## P0 — Highest impact

### 1. Direct cold-miss gateway (bypass full WordPress bootstrap)

Current warm requests are already static, but a missing derivative can still fall through to WordPress. Add a tiny dedicated gateway that:

1. validates the signed transform,
2. resolves the filesystem manifest,
3. checks the generation lock,
4. generates/publishes the derivative or immediately falls back,
5. never loads themes, normal plugins, the REST stack, query parsing, or the full WordPress request lifecycle.

Target architecture:

```text
CDN / web server
    |
    +-- file exists ----------------------> static response
    |
    `-- missing
          |
          `-- qmediaflow-gateway.php
                 |
                 +-- validate signature
                 +-- read manifest
                 +-- acquire non-blocking slot
                 +-- encode + atomic publish
                 `-- fallback immediately when busy
```

Expected benefit: materially lower cold TTFB and less PHP-FPM memory/CPU per cache miss.

### 2. Publish-time critical-image warming

Add optional warming for images most likely to become traffic-critical immediately after publish/update:

- post featured image,
- homepage/card widths,
- first content image,
- configured hero width,
- WooCommerce product hero/gallery sizes when adapter is enabled.

Do **not** regenerate every possible size. Warm only a small, policy-driven candidate set.

Recommended default: maximum 2–4 variants per newly published attachment/post.

### 3. Real background queue with bounded concurrency

Auto LQIP already uses a bounded queue concept. Extend that pattern to derivative pre-generation using a queue abstraction with backends:

- filesystem queue (default, zero dependency),
- Action Scheduler adapter when available,
- WP-CLI/server-cron worker,
- optional Redis-backed queue later.

Required properties:

- de-duplication by immutable variant identity,
- bounded workers,
- retry/backoff,
- priority classes (hero > above-fold > normal > maintenance),
- queue age and failure telemetry.

### 4. Cache headers as a first-class contract

For revisioned immutable derivative URLs, emit a long-lived policy such as:

```http
Cache-Control: public, max-age=31536000, immutable
```

For temporary fallback responses, preserve `no-store` behavior.

Also document recommended CDN rules so customers do not accidentally bypass the static hot path.

## P1 — High impact

### 5. Dedicated CDN adapter

Add a CDN-origin abstraction without coupling generation to any vendor.

Capabilities:

- configurable public derivative base URL,
- origin URL retained separately,
- optional purge/prefetch hooks,
- signed URL compatibility,
- CDN diagnostics in admin/WP-CLI.

Adapters can later target Cloudflare, Bunny, Fastly, CloudFront or generic pull CDNs.

### 6. Object-storage adapter

Add an object-store interface for generated derivatives and optionally originals:

- S3-compatible storage,
- multipart-safe writes where relevant,
- atomic publish semantics implemented as temp-object + copy/promote,
- local filesystem metadata cache,
- origin/CDN URL separation.

Keep local filesystem as the default because it has the lowest operational complexity.

### 7. AVIF/WebP dual-output policy

Instead of forcing one site-wide derivative format, optionally generate a small explicit `<picture>` set:

```html
<picture>
  <source type="image/avif" srcset="...">
  <source type="image/webp" srcset="...">
  <img src="...">
</picture>
```

Avoid unbounded format × width explosion. Apply a per-image total candidate budget across formats.

Recommended policy:

- WebP remains fast/default,
- AVIF enabled only when server encoder throughput is acceptable,
- critical variants may be pre-warmed,
- fallback format retained for compatibility.

### 8. Content-aware encoding policy

Add an optional policy layer that chooses quality/format from measurable image characteristics instead of one fixed delivery quality.

Possible inputs:

- dimensions,
- entropy/edge density,
- alpha channel,
- photographic vs graphic classification,
- output width,
- target byte density.

Keep deterministic output for identical source revision + transform policy.

### 9. Browser upload parallelism controls

For multi-file uploads:

- cap decode/encode workers by device capability,
- avoid decoding many large files simultaneously,
- apply memory backpressure,
- allow upload of completed files while later images are still encoding,
- prioritize editor-visible/featured assets.

This reduces editor wait time and avoids browser memory spikes.

## P2 — Operational speed and reliability

### 10. Production telemetry / observability

Add low-overhead counters without putting a database lookup on the hot static path.

Useful metrics:

- warm-hit ratio,
- cold-miss count,
- generation duration p50/p95/p99,
- lock-contention/fallback count,
- encoder failures,
- queue depth/age,
- generated bytes vs source bytes,
- LQIP ready/pending/failure counts,
- stale-cache bytes removed,
- CDN/object-store health.

Expose summaries through:

```bash
wp qmediaflow status
wp qmediaflow metrics
wp qmediaflow queue status
```

### 11. Health checks and self-diagnostics

Add a one-command/admin diagnostic that validates:

- cache and private-runtime permissions,
- static hot-path routing,
- cold-miss routing,
- signing key availability,
- WebP/AVIF encoder support and speed,
- cron/worker health,
- CDN reachability,
- object-storage write/read/delete cycle,
- effective cache headers.

### 12. Adaptive generator concurrency

The current bounded generator pool protects PHP workers. Add optional adaptive limits based on:

- PHP memory limit,
- configured worker count,
- CPU count where reliably detectable,
- recent encode duration,
- queue pressure.

Always retain a hard administrator-defined ceiling.

### 13. Safer thumbnail migration/removal tool

Before deleting legacy WordPress sub-sizes:

- audit metadata and filesystem references,
- identify theme/plugin hard-coded paths,
- dry-run savings report,
- migrate in resumable batches,
- preserve rollback metadata,
- never recursively process the entire library in one request.

## P3 — Product adapters

### 14. WooCommerce adapter

Provide explicit policies for:

- catalog cards,
- product hero,
- gallery zoom,
- cart/checkout thumbnails,
- structured-data/social images.

Warm the most valuable variants when product media changes.

### 15. Focal-point and smart-crop UI

Store focal metadata with the attachment and include it in the transform identity. This prevents cache collisions when crop intent changes.

Optional later enhancement: automated saliency/face-aware crop suggestions, with manual focal point remaining authoritative.

### 16. REST/API integration layer

Expose stable APIs for headless WordPress and external frontends:

- derivative URL generation,
- bounded responsive candidate sets,
- LQIP metadata,
- source revision,
- width/height/aspect ratio.

Do not expose arbitrary source URLs or filesystem paths.

## Recommended implementation order

### Phase A — Delivery latency

1. direct cold-miss gateway,
2. critical publish-time warming,
3. derivative background queue,
4. immutable cache-header/CDN contract.

### Phase B — Distribution

5. generic CDN adapter,
6. object-storage abstraction,
7. dual-format bounded `<picture>` output.

### Phase C — Intelligence and operations

8. content-aware encoding policy,
9. production telemetry,
10. diagnostics + adaptive concurrency.

### Phase D — ecosystem

11. WooCommerce adapter,
12. thumbnail migration tool,
13. focal-point UI,
14. headless REST integration.

## Performance budgets to enforce in CI

Add regression gates instead of relying only on feature tests.

Suggested budgets for a reference environment:

- warm derivative PHP executions: **0**,
- warm derivative DB queries: **0**,
- duplicate encoders for one immutable variant under contention: **0**,
- admin cache purge: **O(1) logical rotation**, no full recursive traversal,
- responsive candidates: bounded by configured maximum,
- upload browser worker count: bounded,
- generated file publication: atomic,
- cold path: no theme/plugin bootstrap once the direct gateway ships.

Absolute millisecond thresholds should be measured per supported CI/reference environment rather than hard-coded globally.

## Naming compatibility policy

From v0.2.9 onward the product name is **QMediaFlow**.

Canonical new integration names:

- `qmediaflow_url()`
- `qmediaflow_image()`
- `QMEDIAFLOW_*` configuration constants
- `wp qmediaflow ...` once the CLI alias is enabled

Legacy `mediaflow_*`, `MEDIAFLOW_*`, filesystem paths, hooks/actions and stored settings should remain available through a deprecation window. Avoid renaming immutable cache/storage identities merely for branding; migrations that add latency or invalidate a large derivative estate provide no user benefit.
