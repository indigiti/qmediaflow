# MediaFlow 0.2.8 — Browser Upload Pipeline

## Goal

Move source-image optimization ahead of WordPress storage so the server receives a final WebP rather than a large JPEG/PNG/AVIF that must be resized and recompressed in PHP.

## Pipeline

```text
SELECT IMAGE
      ↓
Validate supported type + configured original size
      ↓
Read image in browser / normalize orientation
      ↓
Constrain to configured max dimensions (no upscale)
      ↓
Transfer ImageBitmap to Web Worker
      ↓
Encode WebP
      ↓
Binary-search highest quality <= 480 KB
      ↓
Still > 480 KB? — YES → reduce dimensions by 10% → encode/search again
      ↓
Final browser validation: WebP <= 500 KB
      ↓
Upload to WordPress
      ↓
Server validates MIME + size only
      ↓
Normal Media Library handling
```

## Defaults

- Browser upload optimizer: enabled
- Maximum dimensions: 2560 × 2560 px
- Maximum original browser-processing size: 25 MB
- Working target: 480,000 bytes
- Hard final ceiling: 500,000 bytes
- Highest WebP quality considered: 0.95
- Lowest quality before a dimension reduction: 0.25
- Dimension reduction step: 10%

## Source formats

The browser path accepts JPEG, PNG, WebP, AVIF, HEIC and HEIF when the current browser can decode the source. Animated GIF is not accepted by the optimizer because converting it through a canvas would flatten animation. Non-image attachments remain outside the optimizer.

## WordPress 7.1 compatibility

WordPress 7.1 introduced its own client-side media processing path. MediaFlow disables that core path while MediaFlow upload optimization is enabled; otherwise the same source could be decoded/encoded twice before storage. The existing MediaFlow delivery/cache pipeline is unchanged.

## Server contract

For classic browser image uploads, the pre-upload guard requires a real WebP MIME and a final size <= 500,000 bytes. For REST media uploads that use WordPress's sideload internals, the same validation is applied only when the request carries MediaFlow's browser-upload marker. This keeps remote/server-side sideload imports compatible.

The server validator intentionally performs no resize or recompression. WordPress core still performs its normal security/file-type checks after the MediaFlow prefilter.

## Tests performed in this package

- PHP lint across the plugin tree.
- JavaScript syntax checks for the browser transport and worker.
- Mocked browser transport test verifies fetch interception, WebP file replacement, MediaFlow marker header and preservation of non-file multipart fields.
- Standalone upload-contract test: 500,000-byte WebP accepted; 500,001-byte WebP rejected; JPEG final rejected; non-image attachment preserved; unmarked sideload preserved; marked REST JPEG rejected.
- Existing MediaFlow standalone regression suite for routing, multisite context handling, LQIP generation, encoding fixtures, lock contention and static source contracts.

## Staging validation still required

Browser behavior must still be validated in a real WordPress staging site, especially Media Library grid/list upload, media modal upload, Block Editor upload, multi-file selection, browser cancellation/retry, HEIC/HEIF decode availability and host/CDN request limits.
