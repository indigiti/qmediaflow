> Historical v0.2 architecture. See RELEASE-0.2.1.md for current limits, crop preservation, global admission controls and single-site restriction.

# MediaFlow Architecture — v0.2 scale hardening

## Core rule

WordPress owns the source attachment. MediaFlow owns disposable generated derivatives.

- Source: normal WordPress attachment file.
- Public derivative cache: `wp-content/image-cache/`.
- Private runtime/control files: `.mediaflow-private/` or `MEDIAFLOW_PRIVATE_DIR`.
- No variant lookup database table.
- No recursive cache enumeration in normal wp-admin requests.

## Control plane vs data plane

```text
CONTROL PLANE                         DATA PLANE
WordPress admin / upload              image delivery
DB allowed                            cache hit: static file only
settings / migration / recovery       cache miss: manifest + processor
```

## Hot path

```text
GET /wp-content/image-cache/12/34/123456/vXXXX/revision/variant.webp

file exists
  -> Nginx/Apache/LiteSpeed static response
  -> no WordPress bootstrap
  -> no MediaFlow PHP
  -> no MediaFlow DB lookup
  -> no resize
```

## Cache miss and stampede protection

The processor uses `LOCK_EX | LOCK_NB`.

```text
first miss  -> gets lock -> generate -> atomic publish
other miss  -> cannot get lock -> no waiting -> no-store redirect to original
```

This intentionally trades one temporary unoptimized source response for PHP worker availability during bursts.

## Sharding

```text
image-cache/12/34/123456/<namespace>/<revision>/...
private/manifests/12/34/123456.json
```

Two decimal levels avoid huge flat directories. The complete attachment ID remains in the path, so sharding never changes identity.

## Cache namespace

The active namespace is stored in the filesystem runtime config. Logical purge rotates it in O(1); new HTML receives new URLs. Old signed namespaces remain valid so previously cached HTML can still lazily generate a candidate that had not yet been requested. Physical stale namespace deletion is a separate maintenance operation.

## Source revision

Source revision is based on source identity, size, mtime, dimensions and small first/last byte samples. It deliberately does **not** include the MediaFlow plugin version, so routine plugin upgrades do not invalidate an entire archive.

## Runtime manifest

A v4 manifest contains source path/URL, MIME, dimensions, source revision, placeholder mode/signature and Auto-LQIP state. The absolute source path allows the normal cache-miss path to avoid attachment DB resolution. Relative source identity remains available as a recovery/migration fallback.

## Responsive candidates

MediaFlow uses a bounded candidate set around the rendered target width and approximately 2x DPR territory. It does not emit every configured width for every image. Width descriptors are derived from `output_dimensions()` after source/crop constraints.

## Progressive placeholder modes

- `auto` — default for new installs. Stable gradient immediately; revisioned static LQIP learns through the background queue.
- `gradient` — deterministic gradient only; no preview image.
- `lqip` — inline data URI with request-global count/byte budgets.
- `color` — solid color only.
- `none` — disabled.

Auto LQIP uses `<source-revision>/<lqip-settings-signature>.<ext>` beneath each attachment's `lqip/` directory. This identity allows long immutable caching once generated without serving a stale preview after source replacement or settings changes. On Apache/LiteSpeed, a missing Auto-LQIP is satisfied by the transparent static pending fallback; the normal signed-derivative cold route remains separate.

## Security boundaries

Transform URL fields are strict/numeric and covered by HMAC: attachment ID, source revision, cache namespace, width, height, crop, quality and format. The signature is 32 hexadecimal characters (128-bit truncated SHA-256 HMAC).

Default processing ceilings:

- source: 24,000,000 pixels
- output: 8,000,000 pixels
- individual dimensions: 8192 px

Remote source proxying is not implemented.

## WordPress 7.1

MediaFlow remains idempotent across `wp_generate_attachment_metadata` create/update passes. Registered physical sizes are controlled through `intermediate_image_sizes_advanced`; the plugin does not depend on server-side upload-time `WP_Image_Editor` hooks that the 7.1 client-side processing path can bypass.

## v0.2.8 browser source-upload boundary

MediaFlow now has two deliberately separate processing planes. The **upload plane** runs before WordPress stores a browser-selected image: browser decode/orientation, bounded resize, Web Worker WebP encode, 480 KB quality target, dimension fallback, 500 KB hard validation. The **delivery plane** remains the existing manifest/resolver/static derivative architecture.

The PHP upload guard does not resize or recompress MediaFlow browser uploads. It validates that the resulting source is WebP and no larger than 500,000 bytes, then allows normal WordPress attachment handling to continue. Non-image attachments are untouched. REST/sideload validation is scoped to requests marked by the MediaFlow browser transport so server-side imports are not unexpectedly converted or rejected.
