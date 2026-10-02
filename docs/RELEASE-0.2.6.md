# MediaFlow 0.2.6 — Progressive LQIP Upgrade

## Scope

This release upgrades the existing inline LQIP implementation without introducing a second lazy-loader or moving real image URLs into JavaScript-only `data-src` attributes.

### Progressive image pipeline

- New uploads generate LQIP during attachment metadata capture when LQIP mode is selected.
- Supported preview widths: 16, 32, 64, 128 and 256px; default 64px.
- WebP is preferred for LQIP when the WordPress image editor supports it; JPEG is the fallback.
- The inline data URI has a configurable total byte budget. If the target exceeds the budget, MediaFlow retries at lower quality/resolution.
- A configurable per-request cap defaults to eight inline LQIPs; later images receive the configured color placeholder, limiting HTML growth on image-heavy pages.
- Real `src`, `srcset`, `sizes`, WordPress lazy-loading and fetch-priority decisions remain intact.

### Existing libraries

Frontend requests do not decode source images merely to create missing LQIPs. Old or stale manifests return the color fallback until an explicit rebuild is run.

Use Tools → MediaFlow → Progressive LQIP rebuild, or WP-CLI:

    wp mediaflow lqip-rebuild --limit=500
    wp mediaflow lqip-rebuild --limit=500 --after=12345

### Multisite safety

The 0.2.5 context re-entry guard and routing behavior were left unchanged. Rebuilds operate in the current site's Media Library scope.
