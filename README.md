# QMediaFlow v0.3.0

QMediaFlow is a WordPress image optimization and delivery engine built around a strict performance boundary:

- **upload/control plane** — WordPress, browser optimization, metadata, queues, analysis and administration may run here;
- **delivery/data plane** — a warm derivative is a static immutable file and does not bootstrap WordPress, query the database, or invoke an encoder.

v0.3.0 adds the standalone cold gateway, bounded warming/queues, CDN and S3-compatible distribution, focal crops, WooCommerce/headless integrations, adaptive viewport delivery, production diagnostics, upload-time image intelligence, and privacy-safe predictive cache warming.

## Performance contract

1. Warm derivatives are served directly from `wp-content/image-cache/` or a CDN/object-store front.
2. Warm delivery executes **0 QMediaFlow PHP, 0 WordPress bootstrap, 0 database queries, and 0 encodes**.
3. Cold signed derivatives route to `qmediaflow-edge.php`, which stays WordPress-free and delegates bounded generation to `qmediaflow-gateway.php`.
4. Generation uses non-blocking global admission slots and immutable-variant locks. Contended requests do not wait for an encoder.
5. Runtime settings, signatures, manifests, focal state, telemetry, image intelligence and predictive heat are filesystem-backed.
6. Generated files publish atomically and use `Cache-Control: public, max-age=31536000, immutable`.
7. Cache, manifest and work queues are sharded for large libraries.
8. Logical cache invalidation remains O(1) through namespace rotation.

## Upload optimization

Supported browser-selected images can be normalized and compressed before WordPress stores them:

```text
select image
  -> validate type + source size
  -> decode/orientation normalize
  -> constrain dimensions
  -> bounded Web Worker pool
  -> WebP encode
  -> binary-search highest quality <= 480 KB
  -> adaptive dimension convergence when required
  -> final validation <= 500 KB
  -> upload to WordPress
```

Defaults: 2560×2560 maximum working dimensions, 25 MB source-processing limit, 480 KB target, 500 KB hard final ceiling. Server-side QMediaFlow validates browser-produced uploads; it does not recompress them again.

## Progressive and viewport delivery

QMediaFlow supports Auto LQIP, generated gradient, inline LQIP, solid color, and disabled placeholder modes.

Below-the-fold images already classified by WordPress as `loading="lazy"` can keep the lightweight placeholder until they approach the viewport. Images with `fetchpriority="high"` or non-lazy/hero images stay immediate.

The v0.3.0 adaptive viewport loader uses one shared `IntersectionObserver` and:

- base preload margin: 250 px;
- fast-network margin: 400 px;
- slow-network margin: 100 px;
- Save-Data margin: 60 px;
- bounded fast-scroll lookahead: 900 px default;
- decode-aware 180 ms blur-to-sharp reveal;
- `prefers-reduced-motion` support;
- one shared MutationObserver for dynamically inserted images;
- a real `<noscript>` source fallback.

See `docs/VIEWPORT-DELIVERY.md`.

## Standalone cold path and distribution

```text
CDN / web server
      |
      +-- derivative exists ----------------> static immutable response
      |
      `-- missing
            |
            `-- qmediaflow-edge.php
                   |
                   +-- optional S3 queue bridge
                   `-- qmediaflow-gateway.php
                          +-- validate HMAC
                          +-- read filesystem manifest
                          +-- non-blocking generator admission
                          +-- encode
                          +-- atomic publish
                          `-- immediate fallback when busy
```

If S3-compatible object storage is configured, a derivative generated only by the standalone gateway creates a sharded distribution job and a tiny `distribution-pending` marker. The next WordPress request schedules the normal Action Scheduler/WP-Cron distribution worker; object-store credentials are never written into the public gateway config.

## Image intelligence

`QMEDIAFLOW_IMAGE_INTELLIGENCE` is enabled by default. Analysis runs at upload/control-plane time and downsamples to at most **96×96** before calculating deterministic visual statistics.

Stored private metadata includes:

- `photo`, `graphic`, `text-heavy`, or `transparent` classification;
- visual complexity;
- luminance entropy;
- edge density and contrast;
- colorfulness and transparency ratio;
- a saliency-based focal suggestion;
- preferred-format hint;
- content-aware quality delta.

This is a bounded visual/saliency analyzer, **not face recognition or a remote AI service**. No visitor information is involved. When `QMEDIAFLOW_AUTO_FOCAL` is enabled, the suggested focal point may be applied only when a manual focal point does not already exist; manual focal metadata remains authoritative.

## Predictive cache warming

`QMEDIAFLOW_PREDICTIVE_WARMING` is enabled by default. It samples public singular page traffic and stores only a decaying **post heat score** in private filesystem state.

It does **not** store IP addresses, cookies, user IDs, user agents, or referrers. When estimated heat reaches the configured threshold and cooldown has elapsed, QMediaFlow reuses the existing bounded `Warmer` and de-duplicated derivative queue.

Defaults:

```php
QMEDIAFLOW_PREDICTIVE_SAMPLE_RATE = 8;
QMEDIAFLOW_PREDICTIVE_THRESHOLD  = 24;
QMEDIAFLOW_PREDICTIVE_COOLDOWN   = 3600;
QMEDIAFLOW_PREDICTIVE_HALF_LIFE  = 21600;
```

## Focal crops and upgrade sync

Delivery reads focal state from private filesystem markers, keeping crop delivery zero-DB. Attachment post meta remains the administrative source of truth.

For an existing library with QMediaFlow focal metadata, backfill runtime markers in bounded batches:

```bash
wp qmediaflow focal sync --limit=250 --offset=0
```

Repeat with increasing offsets until `processed` reaches `total`.

WordPress image sizes using array crop anchors such as `[ 'left', 'top' ]` are treated as real hard crops and no longer bypass focal handling.

## REST visibility

The headless route remains `/qmediaflow/v1/image/<attachment-id>`, but anonymous access is release-hardened:

- attachments belonging to publicly viewable content can be read;
- authorized logged-in users can read media they are permitted to access;
- private/non-public parent media is rejected;
- unattached media is anonymous-deny by default.

Headless installations that intentionally expose unattached images can opt in with the `qmediaflow_rest_public_unattached` filter.

## Production validation

Code-level and synthetic contracts are part of CI, but real hosting throughput must be measured on the deployment environment.

Run the WordPress release gate:

```bash
wp qmediaflow validate production
wp qmediaflow validate production --deep
```

Run concurrent HTTP validation against representative warm or cold signed derivative URLs:

```bash
python3 tools/qmediaflow-load-test.py \
  --url-file qmediaflow-urls.txt \
  --requests 250 \
  --concurrency 50 \
  --min-success-rate 0.99
```

For a true cold-generation test, use distinct uncached signed derivative URLs or purge the relevant staging cache first. See `docs/PRODUCTION-VALIDATION.md`.

## Core operations

```bash
wp qmediaflow status
wp qmediaflow health --deep
wp qmediaflow metrics
wp qmediaflow queue status
wp qmediaflow distribution health
wp qmediaflow validate production --deep
wp qmediaflow focal sync --limit=250 --offset=0
wp qmediaflow intelligence analyze 123
wp qmediaflow intelligence show 123
wp qmediaflow predictive status
wp qmediaflow predictive warm 456
```

## Important runtime constants

```php
QMEDIAFLOW_PRIVATE_DIR
QMEDIAFLOW_MAX_SOURCE_PIXELS
QMEDIAFLOW_MAX_OUTPUT_PIXELS
QMEDIAFLOW_MAX_GENERATORS
QMEDIAFLOW_CDN_BASE_URL
QMEDIAFLOW_CONTENT_AWARE_ENCODING
QMEDIAFLOW_DUAL_FORMAT
QMEDIAFLOW_IMAGE_INTELLIGENCE
QMEDIAFLOW_AUTO_FOCAL
QMEDIAFLOW_PREDICTIVE_WARMING
QMEDIAFLOW_VIEWPORT_LOADING
QMEDIAFLOW_VIEWPORT_ADAPTIVE
QMEDIAFLOW_VIEWPORT_MARGIN
QMEDIAFLOW_VIEWPORT_FAST_MARGIN
QMEDIAFLOW_VIEWPORT_SLOW_MARGIN
QMEDIAFLOW_VIEWPORT_SAVE_DATA_MARGIN
QMEDIAFLOW_VIEWPORT_VELOCITY_LOOKAHEAD
```

S3-compatible storage uses `QMEDIAFLOW_S3_*` constants. Keep credentials in server configuration/`wp-config.php`; the standalone gateway receives only a boolean indicating whether distribution is enabled.

## Compatibility policy

QMediaFlow is the canonical product identity. Existing `MediaFlow\...` namespace symbols, `mediaflow_*` hooks/helpers, `MEDIAFLOW_*` constants, `.mediaflow-private/` state paths and legacy CLI aliases are retained where renaming them would create migration or cache invalidation risk.

## Release status

The **implementation and automated validation tooling for v0.3.0 are present**, but this repository does not claim that an arbitrary production host has already passed real load/CDN/S3/theme tests. Before merging/releasing to a production site, execute the production validation runbook against the actual staging stack and record the results.
