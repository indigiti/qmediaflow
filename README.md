> **0.2.8 Browser Upload Pipeline:** Selected images can now be normalized and compressed to WebP in the browser before upload. MediaFlow targets 480 KB, reduces dimensions when needed, hard-rejects above 500 KB, and leaves PHP to MIME/size validation only.

> **0.2.7 Adaptive Placeholder Engine:** Auto LQIP shows a deterministic gradient immediately, queues missing previews once, and upgrades later visits/cached HTML to a revisioned static LQIP. Apache/LiteSpeed missing-preview requests use a static no-store fallback rather than WordPress/PHP. See [release notes](docs/RELEASE-0.2.7.md).

> **v0.2.4:** Adds explicit Progressive JPEG / Interlaced PNG controls and a server encoder verification button. Preserves the v0.2.3 Apache/multisite hotfix. See [release notes](docs/RELEASE-0.2.4.md).

> **0.2.3 Apache + multisite routing hotfix:** MediaFlow now repairs the v0.2.2 `Options -Indexes` guard that could trigger Apache HTTP 500 and installs an explicit cold-cache route under `wp-content/image-cache/`. Network Activate on multisite; settings, signatures and generated caches remain per-site. See [release notes](docs/RELEASE-0.2.3.md).

# MediaFlow v0.2.8 — Browser Upload Pipeline + Adaptive Delivery

MediaFlow is an experimental WordPress adaptive image engine. It keeps source attachments under normal WordPress media management and generates responsive derivatives only when requested.

## Performance contract

1. Existing generated variants are real static files under `wp-content/image-cache/`.
2. A static cache hit does not bootstrap WordPress, execute MediaFlow PHP, query the MediaFlow settings DB option, or resize an image.
3. A cache miss validates a signed transform, reads a filesystem manifest and generates at most one derivative.
4. Concurrent misses do not wait for the generator lock: one worker generates while other workers immediately fall back to the original static source.
5. Runtime settings, the signing key and manifests are filesystem-backed; the DB is admin/recovery control-plane only.
6. Generated URLs are revisioned, namespaced and immutable.
7. Cache directories and manifests are sharded for large media libraries.
8. Logical cache purge is O(1) via namespace rotation; wp-admin never recursively enumerates millions of cache files.

## Browser upload pipeline (v0.2.8)

When enabled in Tools → MediaFlow, browser-selected images follow this source-upload path before WordPress stores the attachment:

```text
select image
  -> validate supported type + configured original byte limit
  -> decode in browser and normalize EXIF orientation
  -> constrain to configured max width/height (no upscaling)
  -> transfer the oriented bitmap to a Web Worker
  -> encode WebP and binary-search the highest quality <= 480 KB
  -> if even minimum quality is too large, reduce dimensions by 10% and retry
  -> final browser validation: WebP <= 500 KB
  -> upload to WordPress
  -> PHP validates final MIME + bytes only
  -> normal Media Library attachment handling continues
```

Defaults are 2560×2560 maximum dimensions and a 25 MB maximum original browser-processing size. JPEG, PNG, WebP, AVIF, HEIC and HEIF sources are accepted when the browser can decode them; animated GIF is intentionally not flattened into a still WebP. Non-image attachments are not changed.

On WordPress 7.1+, MediaFlow disables core client-side media processing while this optimizer is enabled so the same source is not processed twice.

## Storage layout

```text
wp-content/
├── uploads/
│   └── 2026/09/photo.jpg                  # source attachment
├── image-cache/                           # public generated files only
│   └── 12/34/123456/
│       └── v0200/
│           └── a82f91c042e1/
│               └── w768-h0-c0-q82-s<signature>.webp
└── .mediaflow-private/                    # deny web access
    ├── settings.json
    ├── secret.php
    ├── manifests/
    │   └── 12/34/123456.json
    └── tmp/
```

Define `MEDIAFLOW_PRIVATE_DIR` in `wp-config.php` to place the private runtime directory outside the document root when your hosting layout allows it.

## Cache miss flow

```text
request variant
    |
    +-- file exists --> web server serves static file
    |
    `-- file missing --> WordPress fallback reaches MediaFlow
            |
            +-- signed request + manifest
            |
            +-- non-blocking generation lock acquired
            |       |
            |       `-- WP_Image_Editor -> temp file -> atomic rename
            |
            `-- lock already held --> 302/no-store to original source
```

The fallback behavior protects PHP-FPM workers during a breaking-news stampede. The redirect is not cached, so later requests use the optimized derivative after generation completes.

## Large-library layout

Attachment IDs are split into two decimal shard levels. For example attachment `123456` is stored below `12/34/123456`. The first 500,000 sequential IDs place no more than 100 attachment roots in any leaf shard.

## WordPress size compatibility modes

- **Safe** — keep WordPress/theme physical sub-sizes. Best first step on established sites.
- **Adaptive** — keep `thumbnail` and Site Icon derivatives; virtualize other normal sizes. Retained only when explicitly selected.
- **Strict** — keep Site Icon derivatives only. Use after compatibility testing.

Integrations can preserve extra physical sizes with `mediaflow_preserved_physical_sizes`.

## Progressive delivery

Default for new installs: **Auto LQIP**. Existing attachments do not need a full rebuild. On first dynamic render MediaFlow emits a stable gradient plus a revisioned static LQIP URL and creates a deduplicated private queue marker. The real `src/srcset` image remains browser-visible and loads normally. A bounded background worker generates the preview later.

On Apache/LiteSpeed, a missing Auto-LQIP URL resolves to a tiny transparent static `no-store` fallback instead of WordPress/PHP, so the gradient remains visible without creating a cold-preview PHP stampede. Nginx requires an equivalent server rule; see `docs/SERVER-NOTES.md`.

Other modes remain available: **Generated Gradient**, **Inline LQIP**, **Solid Color**, and **Disabled**. Inline LQIP avoids an extra preview request but embeds bytes in HTML and is therefore bounded by both count and total-byte budgets.

## Responsive output

MediaFlow emits a bounded candidate set instead of every configured width. The default maximum is four candidates per rendered image. Crop descriptors use the actual constrained output width, not the requested width.

## Security / resilience

- signed transforms (128-bit truncated HMAC)
- namespace included in signature
- hard width/height limits
- default 8 MP output ceiling
- default 24 MP source processing ceiling
- no remote URL proxy
- no arbitrary filesystem path from URL
- non-blocking per-variant locks
- atomic file publication
- private signing key/manifests
- generated files are disposable

The pixel limits can be overridden before the plugin loads with `MEDIAFLOW_MAX_OUTPUT_PIXELS` and `MEDIAFLOW_MAX_SOURCE_PIXELS`.

## Public API

```php
$url = mediaflow_url( 123, [
    'width'   => 768,
    'format'  => 'webp',
    'quality' => 82,
] );

echo mediaflow_image( 123, 'large', [
    'class' => 'hero-image',
] );
```

Normal `wp_get_attachment_image()` / `image_downsize()` flows are also integrated.

## WP-CLI

```bash
wp mediaflow status
wp mediaflow inspect 123
wp mediaflow rotate
wp mediaflow purge-stale --dry-run --limit=1000
wp mediaflow purge-stale --limit=1000 --yes
```

Stale purge is streaming/bounded rather than a recursive whole-cache `glob()`.

## Current development status

v0.2.8 is a browser-upload/adaptive-placeholder staging build, not yet a claim of production certification for a 500k-post site. The architecture has synthetic tests for sharding, zero MediaFlow option queries on filesystem-ready runtime paths, cache-miss contention and static delivery. A real 500k-post deployment still needs full WordPress/theme/plugin integration testing on the target hosting stack.

Not yet included: direct cache-miss gateway without WordPress bootstrap, existing-thumbnail migration/removal, focal-point UI, WooCommerce-specific adapter, object storage/CDN adapter and production telemetry.

## Adaptive Placeholder Engine (v0.2.7)

Select **Auto LQIP** in Tools → MediaFlow. Preview width can be 16/32/64/128/256px (64px default), with configurable quality and per-image byte budget. The first dynamic render of an old image can show a deterministic gradient immediately and queue one background preview job; source decoding never happens in that visitor request. New uploads still attempt LQIP creation during normal attachment processing.

Auto-LQIP URLs include source revision plus the LQIP settings signature. They therefore remain safe for immutable caching even when an attachment is replaced or preview settings change. A site-scoped WP-Cron worker processes one image per bounded run with filesystem queue deduplication and delayed retries. The old rebuild tool remains available as optional **pre-warm**, not a prerequisite.

Inline LQIP remains available and retains the v0.2.6.1 request-global count (8 default) and total payload (24 KB default) ceilings. Auto mode uses the count ceiling and falls back to the deterministic gradient beyond it.
