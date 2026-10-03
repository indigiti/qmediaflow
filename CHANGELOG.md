## 0.3.0 — Standalone delivery, intelligence and production hardening

- Added `qmediaflow-edge.php` as the WordPress-free cold-miss front controller and retained `qmediaflow-gateway.php` as the bounded standalone encoder/generator.
- Advanced the routing schema to **6** so existing installations rewrite managed cold-miss rules to the new edge wrapper.
- Added an object-store bridge for derivatives generated exclusively by the standalone gateway; jobs are sharded, atomically de-duplicated and resumed through the normal background distribution worker.
- Added critical publish-time warming, a priority derivative queue, Action Scheduler/WP-Cron integration, retry/backoff and adaptive generator concurrency.
- Added CDN URL rewriting, S3-compatible SigV4 distribution, bounded AVIF/WebP `<picture>` support and distribution health checks.
- Added buffered non-blocking filesystem telemetry with p50/p95/p99 timing summaries and deep encoder/route/storage health diagnostics.
- Added content-aware encoding, cached source-size inputs, smart delivery fast exits and bounded responsive candidate generation.
- Added WooCommerce image policies, focal-point crop identity/runtime markers, headless REST metadata and reversible thumbnail migration tooling.
- Fixed hard-crop detection for WordPress image sizes whose `crop` setting is an array such as `[ 'left', 'top' ]`.
- Added bounded focal runtime backfill through `wp qmediaflow focal sync` so existing focal post metadata can remain zero-DB on the delivery path.
- Hardened the public REST image route: anonymous requests are limited to media attached to publicly viewable content; unattached public exposure is opt-in.
- Added viewport-aware progressive delivery: only WordPress-lazy images are deferred, hero/high-priority images remain immediate, `<picture>` candidates activate together, and decode-aware blur-to-sharp reveal has no-JavaScript/reduced-motion fallbacks.
- Added adaptive viewport margins using browser network hints and Save-Data plus bounded scroll-velocity lookahead, while keeping one shared `IntersectionObserver`.
- Added bounded upload-time image intelligence using at most a 96×96 sample. It stores deterministic classification, complexity, entropy, edge/contrast/color/transparency statistics, saliency focal suggestion, format hint and content-aware quality delta in private runtime state.
- Added optional automatic focal application for images without manual focal metadata. Manual focal points remain authoritative.
- Added privacy-safe predictive cache warming using sampled, exponentially decaying post heat scores. No IP address, cookie, user ID, user agent or referrer is stored.
- Added `wp qmediaflow validate production`, intelligence/predictive operations commands and `tools/qmediaflow-load-test.py` for deployment-specific concurrent HTTP validation.
- Expanded CI with release-intelligence contracts, adaptive viewport browser contracts and a load-tool smoke check.

## 0.2.9 — QMediaFlow identity + compatibility layer

- Renamed the public product/plugin identity from MediaFlow to **QMediaFlow**.
- Added canonical `QMEDIAFLOW_*` configuration constants while preserving `MEDIAFLOW_*` aliases for existing installations.
- Added canonical `qmediaflow_url()` and `qmediaflow_image()` helpers while preserving legacy helper aliases.
- Added the canonical `wp qmediaflow ...` WP-CLI command while retaining `wp mediaflow ...` compatibility.
- Kept historical namespaces, hook/action names, runtime paths and cache identities where renaming would create unnecessary upgrade/cache migration risk.
- Reworked README/readme metadata around the QMediaFlow identity and documented the compatibility boundary.
- Added `docs/QMEDIAFLOW-PERFORMANCE-ROADMAP.md`.

## 0.2.8 — Browser Upload Pipeline

- Added browser-first source optimization for Media Library/editor uploads: type/source-size validation, orientation normalization, dimension constraints, Web Worker WebP encoding and final WordPress upload.
- Added highest-quality binary search against a 480 KB working target, with adaptive dimension step-down when required.
- Added a hard 500 KB final WebP ceiling and bounded browser worker concurrency.
- Disabled WordPress client-side media processing while QMediaFlow owns the upload transform to avoid double processing.

## 0.2.7 — Adaptive Placeholder Engine

- Added Auto LQIP: deterministic gradient immediately, revisioned static LQIP on later visits and a bounded background queue.
- Added queue de-duplication, retry state and a bounded generation lock pool.
- Added a transparent no-store static pending fallback for Apache/LiteSpeed.

## 0.2.6.1 — Performance Hardening

- Reused one decoded source during adaptive LQIP generation.
- Added request-global LQIP count and byte budgets.
- Memoized path/guard setup and separated routing schema migration from plugin versioning.
- Bounded admin/CLI rebuild work.

## 0.2.6 — Progressive LQIP Upgrade

- Added configurable 16/32/64/128/256px LQIP output, quality and inline budgets.
- Added existing-library rebuild tooling and placeholder setting signatures.

## 0.2.5 — Multisite recursion hotfix

- Removed site-switching helpers from Apache rule construction and guarded nested context reload.

## 0.2.4 — Progressive JPEG and Interlaced PNG

- Added scoped native encoder policies and output-header verification.

## 0.2.3 — Apache + Multisite Routing Hotfix

- Fixed Apache guard compatibility and installed explicit cache-root cold-miss routing for single-site and multisite.

## 0.2.2 — Multisite

- Added site-scoped caches, settings, keys, manifests, signatures and cold-miss routing. Network activation is required on multisite.

## 0.2.1 — Audit hardening

- See `docs/RELEASE-0.2.1.md`.

## 0.2.0 — Scale hardening

- Sharded public cache and private manifest paths.
- Moved runtime settings/signing/manifests out of the public cache tree.
- Added non-blocking anti-stampede generation, O(1) namespace rotation, bounded responsive candidates and processing ceilings.

## 0.1.0

Initial adaptive image-engine foundation.
