# MediaFlow 0.2.7 — Adaptive Placeholder Engine

## Goal

Remove the operational requirement to rebuild the entire existing Media Library before progressive previews become useful.

Auto LQIP now follows this lifecycle:

```text
first rendered visit
    -> deterministic gradient appears immediately
    -> MediaFlow creates one private queue marker
    -> a bounded site-scoped WP-Cron worker generates one LQIP
    -> the LQIP is published as a static public file
later visits / cached HTML
    -> same revisioned LQIP URL resolves to the static low-resolution preview
    -> normal src/srcset image still loads independently and paints over it
```

New uploads still attempt to generate the LQIP during normal upload metadata processing so they can be ready immediately.

## Pre-build audit — 0.2.6.1 baseline

The 0.2.6.1 baseline passed PHP lint and its existing source, multisite re-entry, routing, encoder fixture, lock contention and LQIP performance regressions.

The audit identified these functional gaps rather than baseline regressions:

1. Existing attachments needed explicit admin/WP-CLI rebuilds before an inline LQIP existed.
2. A missing inline LQIP could only fall back to a solid color.
3. Full-page cached HTML could not automatically improve from the fallback to an inline LQIP after background generation because the placeholder bytes were embedded in that HTML snapshot.
4. There was no bounded first-use background queue.
5. A naive external Auto-LQIP design would have sent every missing preview through WordPress/PHP and could create a first-visit PHP stampede.
6. An external immutable preview URL also needed the source revision in its identity; attachment ID + LQIP settings alone would allow a replaced source image to reuse a stale LQIP.

## Implementation

### Auto LQIP mode

`placeholder_mode=auto` is the default for new installs. Existing installs retain their stored mode until changed.

For each selected image MediaFlow emits two background layers:

1. a revisioned static LQIP URL;
2. a deterministic generated gradient beneath it.

If the LQIP file is not ready, the gradient remains visible. When the LQIP becomes available, the same cached HTML can resolve the unchanged URL to the static preview.

### Deterministic gradient

The fallback uses a curated palette and angle derived from attachment ID + source revision. It looks varied across images but remains stable across requests and cache namespace rotation.

### Revisioned static LQIP identity

Auto-LQIP path shape:

```text
wp-content/image-cache/
  12/34/123456/
    lqip/
      <source-revision>/
        <lqip-settings-signature>.webp
```

Multisite keeps the existing site scope before the attachment shards.

Including both source revision and LQIP settings signature prevents immutable-cache collisions after source replacement or LQIP size/quality changes.

### Background queue and anti-stampede

Missing Auto-LQIPs create private queue markers under `.mediaflow-private/.../lqip-queue/` using exclusive file creation. Concurrent visitors therefore converge on one marker rather than creating duplicate jobs.

A single site-scoped WP-Cron worker processes one image per run with an eight-second worker budget and reschedules itself while ready jobs remain. Failed jobs use delayed retry markers.

Generation uses a bounded 64-slot hashed lock pool rather than one permanent lock inode per attachment.

### Static pending fallback — no cold-LQIP PHP stampede

Apache/LiteSpeed routing does **not** send a missing Auto-LQIP URL to WordPress. Instead the managed cache `.htaccess` internally serves a transparent 1×1 SVG with `Cache-Control: no-store` while the gradient layer remains visible.

Once the real LQIP exists, the web server serves it directly with the normal long immutable image-cache policy.

This preserves page-cache compatibility without turning a burst of missing LQIPs into a burst of PHP 404 requests.

Nginx hosts should mirror this behavior in server configuration; see `docs/SERVER-NOTES.md`.

### Optional pre-warm

The old rebuild UI remains as **LQIP pre-warm (optional)**. It is useful when administrators want existing images ready before visitors see them, but Auto LQIP no longer depends on it.

WP-CLI `lqip_rebuild` remains available and works for Auto or Inline LQIP modes.

## Post-build code audit

### Code/safety

- PHP 8.4 lint: PASS.
- Existing transform HMAC validation remains before manifest recovery: PASS.
- Auto-LQIP URLs are not arbitrary transforms and are constrained to current attachment revision/settings paths.
- Queue markers live in the private denied runtime tree and use mode 0600.
- Generated LQIPs use atomic temp-file publication and mode 0644.
- Source decode remains behind source-pixel/memory admission and global encoder-slot limits.
- Existing multisite context re-entry guard is unchanged and still passes its regression.
- Normal real image `src`, `srcset`, `sizes`, WordPress lazy loading and fetch-priority decisions remain intact.

### Performance

- Cached responsive variants: unchanged static hot path.
- Cached Auto-LQIP: static file; no WordPress bootstrap.
- Missing Auto-LQIP on Apache/LiteSpeed: transparent static no-store fallback; no WordPress bootstrap.
- Visitor rendering: queue creation/scheduling only; no source decode for an existing image missing its LQIP.
- Background generation: one source decode, adaptive quality/size fallback, one image per worker run.
- Queue stampede: deduplicated by exclusive marker creation.
- Lock inode growth: bounded generation lock pool.
- Inline LQIP mode retains its request-global count and byte ceilings.
- Auto LQIP uses the count ceiling; images beyond the ceiling use gradient only for that rendered request.

### Regression tests

The local standalone test suite covers:

- PHP syntax/source contracts.
- v0.2.6.1 single-decode LQIP fallback.
- request-global inline count/byte budget.
- revisioned Auto-LQIP URL.
- filesystem queue deduplication.
- atomic static Auto-LQIP publication with a fake editor.
- bounded hash lock-pool pathing.
- deterministic gradient output.
- nested multisite context switch/restore.
- Apache routing migration including the transparent pending fallback.
- 24-process encoder-slot contention.
- progressive JPEG, baseline JPEG, Adam7 PNG, plain PNG and invalid-JPEG fixtures.

## Late post-audit corrections

The final hardening pass found and corrected two additional edge cases before packaging:

1. **Encoder extension consistency:** Auto-LQIP rendering/readiness now follows the current LQIP encoder extension rather than stale manifest metadata. This prevents page-cache HTML from pointing to a `.webp` preview while a changed server capability would publish only `.jpg`, or the reverse.
2. **Cheap existing-manifest migration:** switching an already-runtime-ready attachment into Auto LQIP updates its manifest fields directly. The visitor request does not repeat attachment DB lookups, source sampling, or image decode merely to change placeholder mode.

The trade-off remains explicit: Auto LQIP may add up to the configured preview-count ceiling (8 by default) as tiny static HTTP requests. Inline LQIP avoids those requests but adds base64 bytes to HTML. Auto mode is preferred for page-cache compatibility and demand-driven adoption of large existing libraries.

## Recommended settings

```text
Placeholder mode:          Auto LQIP
LQIP width:                64px
LQIP quality:              24
Max LQIP bytes:            3072
Max LQIP previews/page:    8
Inline-only total budget:  24576 bytes
Background jobs/run:       1 (fixed in 0.2.7)
```

## Production validation still required

This package cannot certify the target host without staging tests. Validate:

- real GD/Imagick WebP/JPEG behavior;
- WordPress WP-Cron or server-cron execution;
- Cloudways/Nginx/Apache cache routing;
- CDN treatment of the no-store pending fallback;
- full-page cache behavior;
- WooCommerce galleries/variation swaps/lightboxes;
- page builders and custom themes;
- browser behavior on slow connections;
- a representative bulk pre-warm and sustained traffic test.

If `DISABLE_WP_CRON` is true, configure a real server cron for WordPress or use pre-warm; the admin status page reports this condition.
