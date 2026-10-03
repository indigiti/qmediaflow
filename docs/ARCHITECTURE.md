# QMediaFlow Architecture — v0.3.0

## Core invariant

WordPress owns source attachments. QMediaFlow owns disposable generated derivatives and private runtime state.

The most important invariant is the warm path:

```text
GET immutable derivative
  -> web server / CDN serves existing file
  -> 0 WordPress bootstrap
  -> 0 QMediaFlow PHP execution
  -> 0 database queries
  -> 0 image encodes
```

Everything that can be moved away from that path is control-plane or background work.

## Processing planes

```text
CONTROL / PREPARATION                     DELIVERY
WordPress upload/admin                    CDN / web server
browser source optimization               static derivative hit
manifest + focal metadata                 standalone cold edge on miss
image intelligence                        no WordPress on cold edge
queues / warming                          immutable static result
telemetry / diagnostics
```

## Storage layout

Typical single-site layout:

```text
wp-content/
├── uploads/                         # WordPress-owned source attachments
├── image-cache/                     # public disposable QMediaFlow derivatives
│   ├── __qmediaflow-gateway-config.php
│   └── 12/34/123456/<namespace>/<revision>/...
└── .mediaflow-private/              # private runtime/control state
    ├── settings.json
    ├── secret.php
    ├── manifests/12/34/123456.json
    ├── focal/12/34/123456.txt
    ├── intelligence/12/34/123456.json
    ├── predictive/00/01/123.json
    ├── derivative-queue/
    ├── distribution-queue/
    ├── metrics.json
    └── gateway-metrics.json
```

The historical `.mediaflow-private/` identity is retained for upgrade compatibility. `QMEDIAFLOW_PRIVATE_DIR` can move private state outside document root.

## Warm request

A warm request never reaches the plugin:

```text
browser
  -> CDN/origin
  -> immutable file exists
  -> Cache-Control: public, max-age=31536000, immutable
  -> response
```

## Cold request

Routing schema 6 sends a missing signed derivative to the edge wrapper:

```text
missing derivative
   |
   v
qmediaflow-edge.php
   |  WordPress-free
   |  reads safe gateway runtime config
   |  registers optional distribution bridge
   v
qmediaflow-gateway.php
   |  validate HMAC before source work
   |  read filesystem manifest
   |  enforce source/output limits
   |  non-blocking global generator admission
   |  non-blocking immutable-variant lock
   |  encode
   |  atomic rename/publish
   `-> immutable response
```

When another process owns the required generation slot/variant lock, the request does not wait for an encoder and the cold path uses the existing no-store fallback policy.

The edge wrapper does not contain S3 credentials. Gateway runtime config exposes only whether object-store mirroring is enabled. After a successfully published cold derivative it creates a de-duplicated sharded distribution job and touches one `distribution-pending` marker. The next WordPress request schedules the normal background distribution worker.

## Identity and security

Derivative identity is revisioned and signed. Transform fields include attachment/source revision, namespace, width, height, crop, quality, format, and focal coordinates when relevant. Requests are constrained by source/output pixel budgets and strict transform parsing.

There is no arbitrary remote image proxy and no request-controlled arbitrary filesystem source path.

## Manifests and zero-DB delivery state

Filesystem manifests provide source path, URL, dimensions, MIME, revision, placeholder data and delivery policy inputs without attachment database resolution on the standalone path.

Custom focal coordinates are mirrored from attachment post meta to private filesystem markers. Delivery uses those markers via `Focal_Point::runtime()` and therefore does not query focal post meta. Existing focal metadata can be backfilled in bounded CLI batches.

Upload-time image intelligence is also private filesystem state. It is produced outside the warm path and can be read by encoding policy without a database lookup.

## Image intelligence

Analysis is bounded to a maximum 96×96 sample and computes deterministic visual statistics:

- luminance entropy;
- edge density;
- contrast;
- colorfulness;
- transparency ratio;
- complexity;
- a coarse saliency focal suggestion.

Those inputs classify an image as photo, graphic, text-heavy or transparent and produce advisory format/quality hints. This module is deliberately local/statistical; it is not face recognition or an external ML service.

## Responsive delivery and viewport activation

QMediaFlow bounds responsive candidate count instead of emitting every configured width.

For images WordPress already marks lazy, the real `src/srcset` can be held until one shared `IntersectionObserver` sees the image near the viewport. The preload distance is adaptive to `navigator.connection`/Save-Data where available, and fast scrolling can activate a small number of near-future images with bounded lookahead. Hero/eager/high-priority images are not deferred.

This JavaScript behavior changes only when a browser starts fetching the immutable derivative; it does not change derivative identity or the static warm path.

## Queue model

Derivative and distribution queues are filesystem-backed and sharded. Required properties are:

- immutable-identity de-duplication;
- bounded batch/time budgets;
- non-blocking locks;
- retry/backoff;
- priority for critical variants;
- Action Scheduler when available, WP-Cron fallback otherwise.

The Warmer selects only a bounded set of valuable variants.

## Predictive cache intelligence

Predictive warming stores a decaying post heat score, not user profiles. Sampling estimates traffic without writing on every request. No IP, cookie, user ID, user agent or referrer is stored.

Once heat crosses the configured threshold and cooldown, the module calls the existing Warmer. It never invents an unbounded variant set and therefore inherits queue de-duplication and concurrency controls.

## Telemetry

Static hits execute no telemetry code. Dynamic WordPress and standalone-gateway metrics are buffered in memory and merged into filesystem JSON at shutdown with non-blocking locks. Admin/CLI snapshots may block briefly because they are explicit control-plane operations.

Timing summaries expose average, maximum and histogram-derived p50/p95/p99 values.

## Production validation boundary

Repository CI validates syntax, source contracts, queue locking, browser behavior and tooling. Real production performance is environment-dependent and must be measured on the deployment stack.

`wp qmediaflow validate production --deep` checks configuration/storage/routing/encoders/workers/distribution. `tools/qmediaflow-load-test.py` measures real HTTP concurrency, success rate, RPS and p50/p95/p99 against supplied staging/production URLs.

See `docs/PRODUCTION-VALIDATION.md`.
