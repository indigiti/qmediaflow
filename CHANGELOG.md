## 0.2.9 — QMediaFlow identity + compatibility layer

- Renamed the public product/plugin identity from MediaFlow to **QMediaFlow**.
- Added canonical `QMEDIAFLOW_*` configuration constants while preserving `MEDIAFLOW_*` aliases for existing installations.
- Added canonical `qmediaflow_url()` and `qmediaflow_image()` helpers while preserving legacy helper aliases.
- Added the canonical `wp qmediaflow ...` WP-CLI command while retaining `wp mediaflow ...` compatibility.
- Kept historical namespaces, hook/action names, runtime paths and cache identities where renaming would create unnecessary upgrade/cache migration risk.
- Reworked README/readme metadata around the QMediaFlow identity and documented the compatibility boundary.
- Added `docs/QMEDIAFLOW-PERFORMANCE-ROADMAP.md`, prioritizing a direct cold-miss gateway, critical publish-time warming, a de-duplicated derivative queue, immutable cache/CDN policy, object storage, bounded dual-format delivery and production observability.

## 0.2.8 — Browser Upload Pipeline

- Added browser-first source optimization for Media Library/editor uploads: type/source-size validation, orientation normalization, dimension constraints, Web Worker WebP encoding and final WordPress upload.
- Added highest-quality binary search against a 480 KB working target, with 10% dimension step-down when the minimum quality still misses the target.
- Added a hard 500 KB final WebP ceiling. MediaFlow's PHP upload path validates MIME + bytes only and does not recompress the browser-produced source.
- Added configurable maximum upload dimensions (2560×2560 default) and original browser-processing size (25 MB default).
- Disabled WordPress 7.1+ core client-side media processing while MediaFlow upload optimization is enabled to avoid double processing.
- Preserved non-image attachments and unmarked server-side sideload/import flows.
- Added standalone upload-contract tests and JS/PHP source assertions.

## 0.2.7 — Adaptive Placeholder Engine

- Added Auto LQIP: existing images show a deterministic gradient immediately, queue one background job, and use a static LQIP on later visits.
- Added revisioned external LQIP URLs so replaced source images and changed LQIP settings cannot reuse stale immutable previews.
- Added filesystem anti-stampede queue markers, delayed failure retries, and a site-scoped WP-Cron worker that processes one image per bounded run.
- Added a bounded 64-slot generation lock pool instead of one persistent lock inode per image.
- Added a transparent no-store static pending SVG for Apache/LiteSpeed so missing Auto-LQIPs do not bootstrap WordPress/PHP.
- Added Generated Gradient mode and kept Inline LQIP, Color and Disabled modes.
- Reframed the admin rebuild as optional LQIP pre-warming; Auto LQIP no longer requires a full Media Library rebuild.
- Preserved v0.2.6.1 single-decode generation, request budgets, multisite re-entry protection, real src/srcset discovery and static variant delivery.

## 0.2.6.1 — Performance Hardening

- Reworked LQIP fallback generation to decode each source image once and reuse the same editor for quality/resolution retries.
- Memoized successful `Paths::ensure()` and routing-guard checks per site context/request to reduce repeated filesystem work during uploads and rebuilds.
- Added an independent request-global inline LQIP byte ceiling (24 KB default) in addition to the existing image-count cap; multisite context reloads cannot reset either budget.
- Reduced wp-admin LQIP rebuild batches to five by default and added an 8-second request time budget so long rebuilds yield between AJAX requests.
- Reduced the WP-CLI LQIP rebuild default from 500 to 100 attachments while retaining an explicit `--limit` override.
- Decoupled Apache routing migration from plugin releases using `MEDIAFLOW_ROUTING_SCHEMA_VERSION`; feature-only updates no longer trigger network-wide routing scans.
- Added standalone performance-hardening regressions for source decode reuse and request-global LQIP budgets.

## 0.2.6 — Progressive LQIP Upgrade

- Upgraded inline LQIP from a fixed 16px preview to configurable 16/32/64/128/256px output; 64px is the default.
- Added configurable LQIP quality, inline byte budget, adaptive step-down encoding, and a per-request inline LQIP cap with color fallback.
- Fixed existing-library behavior: frontend rendering never performs surprise LQIP generation; missing previews fall back to color until rebuilt.
- Added bounded wp-admin AJAX rebuild for existing JPEG/PNG/WebP/AVIF attachments.
- Added `wp mediaflow lqip-rebuild --limit=<n> --after=<id>` for large libraries.
- Added placeholder signatures so changing LQIP generation settings invalidates stale previews safely.
- Preserved the v0.2.5 multisite recursion/re-entry hotfix and existing static cache routing architecture.

# 0.2.5 — Multisite recursion hotfix

Remove site-switching helpers from Apache rule construction; guard nested context reload and atomically publish complete services. See docs/RELEASE-0.2.5.md.

# 0.2.4 — Progressive JPEG and Interlaced PNG

Per-site controls (default off), scoped native encoder policy, output-header verification, protected admin compatibility check, CLI encoding-check, namespace rotation on encoding changes, WebP LQIP guidance. Based on 0.2.3; Apache/multisite fixes preserved.

# 0.2.3 — Apache + Multisite Routing Hotfix

Fixes Apache HTTP 500 responses caused by MediaFlow v0.2.2 public cache guard files on hosts that do not permit the `Options` directive in `.htaccess`. Adds an explicit cache-root cold-miss rewrite for single-site and multisite, preserves direct static delivery for warm hits, and automatically migrates known v0.2.2 guard files without overwriting unrelated administrator rules. Also removes the legacy Options directive from private MediaFlow guards while retaining deny rules. See `docs/RELEASE-0.2.3.md`.

# 0.2.2 — Multisite

Per-site caches/settings/keys/manifests; explicit site-bound signatures; cold-miss site routing; switch/restore context refresh; shared server processing slots. Network activation required on multisite. See docs/RELEASE-0.2.2.md.

# 0.2.1 — Audit hardening

See docs/RELEASE-0.2.1.md for changes, installation, test scope, limitations and rollback.

# Changelog

## 0.2.0

Scale-hardening release.

- Sharded public cache and private manifest paths.
- Moved settings/signing/manifests out of the public cache tree.
- Added private directory deny files and `MEDIAFLOW_PRIVATE_DIR` override.
- Replaced blocking generation locks with non-blocking anti-stampede behavior.
- Added static-original fallback for concurrent/failed generation.
- Added O(1) cache namespace rotation.
- Preserved signed old namespaces for already-cached HTML.
- Added streaming WP-CLI stale namespace purge.
- Bounded responsive candidates and fixed crop `srcset` width descriptors.
- Changed default progressive placeholder from inline LQIP to lightweight color.
- Added Safe / Adaptive / Strict physical sub-size modes.
- Removed plugin version from source revision identity.
- Added source byte sampling to revision fingerprints.
- Increased transform signature to 128-bit truncated HMAC.
- Added source/output pixel processing ceilings.
- Removed expensive whole-cache `glob()` calls from wp-admin.
- Added v0.1 filesystem settings/key/manifest migration paths.

## 0.1.0

Initial adaptive image-engine foundation.
