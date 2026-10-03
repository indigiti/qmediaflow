# QMediaFlow Performance & Delivery Roadmap

This roadmap is now an implementation/status document for v0.3.0 rather than a list of speculative features.

## v0.3.0 invariant

**Warm derivative delivery remains a static-file request:** zero WordPress bootstrap, zero database queries, zero QMediaFlow PHP execution and zero encoding.

## Implemented in v0.3.0

| Capability | Status | Notes |
| --- | --- | --- |
| Direct cold-miss gateway | Implemented | `qmediaflow-edge.php` -> WordPress-free `qmediaflow-gateway.php`; routing schema 6 |
| Critical publish-time warming | Implemented | bounded variants only |
| Priority derivative queue | Implemented | sharded, de-duplicated, retry/backoff, Action Scheduler/WP-Cron |
| Immutable cache policy | Implemented | one-year immutable derivatives; no-store temporary fallbacks |
| CDN rewrite layer | Implemented | origin identity remains separate |
| S3-compatible object distribution | Implemented | SigV4; background distribution queue |
| Standalone gateway -> S3 bridge | Implemented | edge wrapper emits sharded distribution job/pending marker without credentials |
| Bounded AVIF/WebP `<picture>` | Implemented | candidate budget and format capability caching |
| Content-aware encoding | Implemented | optional deterministic quality policy |
| Browser upload concurrency | Implemented | bounded workers and adaptive dimension convergence |
| Telemetry | Implemented | buffered non-blocking filesystem metrics; p50/p95/p99 summaries |
| Health diagnostics | Implemented | routing/storage/encoders/workers/distribution checks |
| Adaptive generator concurrency | Implemented | memory/CPU-aware effective ceiling under administrator hard limit |
| Thumbnail migration | Implemented | audit, dry-run, migrate, rollback and purge tooling |
| WooCommerce adapter | Implemented | product/card/gallery policies and warming |
| Focal crop identity/runtime | Implemented | zero-DB delivery markers and existing-library CLI backfill |
| REST/headless integration | Implemented | stable image metadata/URLs with release-hardened visibility |
| Viewport progressive delivery | Implemented | lazy-only activation, shared observer, decode reveal, no-JS fallback |
| Adaptive viewport intelligence | Implemented | Network Information/Save-Data margins and bounded scroll-velocity lookahead |
| Image intelligence | Implemented | local bounded 96×96 visual statistics, saliency focal suggestion and quality hints |
| Predictive cache intelligence | Implemented | privacy-safe decaying post heat scores feeding existing bounded warmer |
| Production validation tooling | Implemented | release-gate WP-CLI + concurrent HTTP load tool |

## Phase status requested for the v0.3.0 release

### Phase 1 — Release hardening

Implemented in code:

- WordPress array crop definitions are recognized as hard crops.
- Existing focal post metadata has a bounded runtime-marker backfill command.
- Standalone cold generation now participates in object-store distribution.
- REST image visibility prevents anonymous access to private/non-public parent media and denies unattached media by default.
- Routing schema 6 forces managed web-server route migration to the edge wrapper.
- Release documentation and operational commands are updated.

A real staging compatibility pass is still a deployment activity, not something repository CI can truthfully simulate.

### Phase 2 — Production performance validation

Tooling is complete:

- `wp qmediaflow validate production [--deep]` provides a release gate.
- `tools/qmediaflow-load-test.py` measures real HTTP concurrency, RPS, success rate and p50/p95/p99.
- `docs/PRODUCTION-VALIDATION.md` defines the staging procedure and evidence to record.

**No repository-only test can substitute for running these against the real production-like host.** The actual hosting benchmark remains a deployment step.

### Phase 3 — Advanced viewport / perceived performance

Implemented:

- single shared IntersectionObserver;
- WordPress lazy/eager decisions remain authoritative;
- high-priority/LCP images are never deferred;
- network-aware preload distance;
- Save-Data conservative loading;
- bounded scroll-velocity lookahead;
- `<picture>` source activation;
- decode-aware blur-to-sharp reveal;
- dynamic image discovery;
- no-JavaScript and reduced-motion fallbacks.

### Phase 5 — Image intelligence

Implemented as deterministic local image analysis rather than an external ML dependency:

- bounded 96×96 sampling;
- photo/graphic/text-heavy/transparent classification;
- entropy/edge/contrast/color/transparency/complexity metrics;
- saliency-based focal suggestion;
- advisory preferred-format hint;
- content-aware quality delta;
- optional auto-focal only where manual focal data is absent;
- private filesystem runtime state.

Face/object recognition is not claimed by v0.3.0. It can be added later behind an explicit provider/filter without placing remote AI work on delivery paths.

### Phase 8 — Automated cache intelligence

Implemented:

- sampled public singular-page observation;
- exponential heat decay;
- threshold/cooldown policy;
- no IP/cookie/user-ID/user-agent/referrer storage;
- non-blocking filesystem updates;
- existing bounded Warmer and derivative queue reused for actual generation;
- CLI status and manual warm operation.

## Performance budgets enforced architecturally/in CI

- warm derivative PHP executions: **0** by routing design;
- warm derivative database queries: **0** by routing design;
- duplicate encoders for one immutable variant under contention: prevented by non-blocking locks;
- logical cache rotation: O(1);
- responsive candidates: bounded;
- upload workers: bounded;
- viewport observers: one shared implementation;
- image intelligence sample: max 96×96;
- generation/distribution publication: atomic/de-duplicated;
- cold path: no WordPress/theme/plugin bootstrap.

Absolute latency/RPS/LCP targets are intentionally deployment-specific. Use the production validation runbook rather than embedding misleading universal millisecond limits in repository CI.

## Next work after v0.3.0 staging sign-off

Future phases can focus on vendor-specific CDN controls, richer operations UI, cache lifecycle/LRU cleanup, WooCommerce predictive interaction prefetch, and optional external vision providers. Those should not block the current five-phase release-hardening program.
