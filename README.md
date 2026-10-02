# QMediaFlow v0.2.9

QMediaFlow is a WordPress image optimization and adaptive delivery engine designed around two independent planes:

1. **Upload plane** — optimize supported browser-selected images before they reach PHP.
2. **Delivery plane** — generate responsive variants only when requested and serve warm variants as static files.

The v0.2.9 release establishes **QMediaFlow** as the canonical product identity while keeping MediaFlow-era integration identifiers available for backward compatibility.

## Core performance contract

1. Existing generated variants are real static files under `wp-content/image-cache/`.
2. A warm derivative request does not bootstrap WordPress, query QMediaFlow settings, or resize the image.
3. A cold derivative request validates a signed transform and uses a filesystem manifest.
4. Only one generator works on a missing immutable variant; concurrent requests do not wait and can fall back immediately.
5. Runtime settings, signing material and manifests are filesystem-backed; the database remains an administrative/control-plane source.
6. Generated URLs are revisioned, namespaced and suitable for immutable caching.
7. Cache and manifest directories are sharded for very large Media Libraries.
8. Logical cache invalidation is O(1) through namespace rotation.

## Browser upload optimization

When enabled, supported images follow this path before WordPress stores the source attachment:

```text
select image
  -> validate type + source byte limit
  -> decode in browser
  -> normalize EXIF orientation
  -> constrain dimensions without upscaling
  -> move encoding work to a Web Worker
  -> encode WebP
  -> binary-search highest quality <= 480 KB
  -> reduce dimensions and retry when required
  -> validate WebP <= 500 KB
  -> upload to WordPress
  -> PHP validates MIME + bytes
  -> normal Media Library handling continues
```

Defaults:

- upload dimensions: `2560 × 2560`
- maximum original browser-processing size: `25 MB`
- optimization target: `<= 480 KB`
- final hard ceiling: `<= 500 KB`

Supported source formats depend on browser decode capability and include JPEG, PNG, WebP, AVIF, HEIC and HEIF. Non-image attachments are not processed.

## Adaptive delivery

QMediaFlow keeps original attachments under normal WordPress media management and stores generated derivatives separately:

```text
wp-content/
├── uploads/
│   └── 2026/10/photo.webp
├── image-cache/
│   └── 12/34/123456/
│       └── v0200/
│           └── <source-revision>/
│               └── w768-h0-c0-q82-s<signature>.webp
└── .mediaflow-private/
    ├── settings.json
    ├── secret.php
    ├── manifests/
    └── tmp/
```

The historical `.mediaflow-private/` path is intentionally retained because changing runtime storage identity purely for branding would create unnecessary migration risk.

Define `QMEDIAFLOW_PRIVATE_DIR` in `wp-config.php` to place the private runtime outside webroot. The legacy `MEDIAFLOW_PRIVATE_DIR` name is also supported.

## Static hot path

Warm requests are served directly by Apache, LiteSpeed, Nginx or another fronting web server.

```text
request derivative
    |
    +-- file exists --> static response
    |
    `-- file missing --> signed cold path
                            |
                            +-- lock acquired --> generate --> atomic publish
                            |
                            `-- lock busy --> immediate original fallback
```

This protects PHP-FPM capacity during traffic spikes.

## Responsive images

Default allowed widths:

```text
320 480 640 768 1024 1200 1600 1920
```

Each rendered image receives a bounded subset rather than every configured width. The default candidate ceiling is four.

## Output formats

Available delivery policies:

- Auto
- WebP
- AVIF
- Original format

Generated derivative quality is configurable. Progressive JPEG and PNG Adam7 interlacing are available when those formats are used.

## Progressive placeholders

Modes:

- **Auto LQIP** — deterministic gradient immediately, background generation of a revisioned tiny preview.
- **Generated Gradient** — no preview file generation.
- **Inline LQIP** — tiny data URI with strict per-page count and byte budgets.
- **Solid Color** — lowest overhead.
- **Disabled**.

Auto LQIP uses filesystem queue de-duplication and bounded background work. Existing libraries do not require a full rebuild.

## Physical WordPress thumbnail modes

- **Safe** — retain WordPress/theme physical sizes.
- **Adaptive** — retain mainly thumbnail + Site Icon.
- **Strict** — retain only the physical sizes QMediaFlow considers essential.

Third-party integrations can preserve additional sizes through the existing `mediaflow_preserved_physical_sizes` compatibility filter.

## Security and resilience

QMediaFlow includes:

- 128-bit truncated SHA-256 HMAC transform signatures
- signed attachment/revision/namespace/size/crop/quality/format identity
- maximum dimension limits
- source and output pixel ceilings
- no arbitrary remote image proxy
- no arbitrary filesystem source path from request URLs
- non-blocking generation locks
- atomic cache publication
- site-scoped multisite signing/runtime state

## Public API

Canonical v0.2.9 API:

```php
$url = qmediaflow_url( 123, [
    'width'   => 768,
    'format'  => 'webp',
    'quality' => 82,
] );

echo qmediaflow_image( 123, 'large', [
    'class' => 'hero-image',
] );
```

Backward-compatible aliases remain available:

```php
mediaflow_url( ... );
mediaflow_image( ... );
```

Normal WordPress `wp_get_attachment_image()` / `image_downsize()` flows remain integrated.

## Configuration constants

Canonical names:

```php
QMEDIAFLOW_PRIVATE_DIR
QMEDIAFLOW_MAX_SOURCE_PIXELS
QMEDIAFLOW_MAX_OUTPUT_PIXELS
QMEDIAFLOW_MAX_GENERATORS
```

Legacy `MEDIAFLOW_*` equivalents continue to work.

## WP-CLI

Canonical command:

```bash
wp qmediaflow status
wp qmediaflow inspect 123
wp qmediaflow rotate
wp qmediaflow purge-stale --dry-run --limit=1000
wp qmediaflow purge-stale --limit=1000 --yes
```

The legacy `wp mediaflow ...` command remains supported.

## Naming compatibility policy

QMediaFlow is the public product name from v0.2.9 onward.

For upgrade safety, these historical identifiers are intentionally retained unless there is a strong technical reason to migrate them:

- `MediaFlow\...` PHP namespace
- `mediaflow_*` hooks/actions/filters
- stored settings keys
- `.mediaflow-private/`
- existing cache URL/storage identities
- `wp mediaflow ...`

New code should use the QMediaFlow public helpers and `QMEDIAFLOW_*` configuration constants.

## Performance roadmap

See [`docs/QMEDIAFLOW-PERFORMANCE-ROADMAP.md`](docs/QMEDIAFLOW-PERFORMANCE-ROADMAP.md).

The highest-impact next work is:

1. a direct cold-miss gateway that avoids full WordPress bootstrap,
2. publish-time warming of only critical variants,
3. a de-duplicated background derivative queue,
4. first-class immutable cache/CDN policy,
5. CDN and object-storage adapters,
6. bounded AVIF/WebP dual-format output,
7. production telemetry and diagnostics,
8. WooCommerce, focal-point and headless adapters.

## Current development status

QMediaFlow remains a staging/development build rather than a blanket production-capacity claim. Its architecture already includes regression and synthetic scalability checks, but production rollout should still be validated against the actual theme, plugin set, browser mix, WordPress configuration, encoder stack, web server and storage platform.
