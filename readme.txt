=== QMediaFlow ===
Contributors: indigiti
Tags: images, performance, responsive-images, webp, avif, lqip
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later

Browser-first WordPress image optimization with static warm delivery, standalone cold generation, adaptive viewport loading and bounded cache intelligence.

== Description ==

QMediaFlow keeps source attachments under normal WordPress Media Library management while generating disposable, revisioned responsive derivatives outside the uploads tree.

The warm path is intentionally static: an existing generated derivative is served by the web server/CDN without bootstrapping WordPress, querying the database, or invoking an encoder. Missing signed derivatives use the WordPress-free `qmediaflow-edge.php`/`qmediaflow-gateway.php` cold path with non-blocking generation admission and atomic publication.

v0.3.0 includes browser-side upload optimization, responsive WebP/AVIF delivery, Auto LQIP, adaptive viewport activation, critical and predictive warming, a de-duplicated derivative queue, CDN/S3-compatible distribution, telemetry, health diagnostics, focal crops, WooCommerce support, headless REST integration, reversible thumbnail migration and bounded upload-time image intelligence.

== Progressive viewport delivery ==

Below-the-fold images that WordPress already marks `loading="lazy"` retain the QMediaFlow placeholder until they approach the viewport. High-priority/hero images are not deferred. The shared observer adapts its preload margin to browser network hints and Save-Data, and adds bounded lookahead during fast scrolling.

== Image intelligence ==

Upload-time analysis downsamples an image to at most 96×96 and derives deterministic visual statistics used for classification, saliency focal suggestions and content-aware quality hints. It is not face recognition and does not use a remote AI service.

== Predictive warming ==

QMediaFlow can sample public page traffic and maintain only a decaying post heat score. It does not store IP addresses, cookies, user IDs, user agents or referrers. Hot content reuses the existing bounded warmer and de-duplicated derivative queue.

== Production validation ==

Use:

`wp qmediaflow validate production --deep`

and the repository's `tools/qmediaflow-load-test.py` against the actual staging/production web server. Automated repository tests cannot establish real hosting capacity, CDN hit ratio or S3 latency.

== Compatibility ==

QMediaFlow is the canonical product identity. Existing MediaFlow-era helpers, `MEDIAFLOW_*` constants, namespace symbols, hooks, runtime paths and CLI aliases remain supported where renaming would create migration/cache risk.

== Important ==

Validate theme/plugin/editor behavior and real web-server/CDN/object-store routing on staging before production rollout. If the default private directory is web-accessible under Nginx, deny access to `/.mediaflow-private/` or define `QMEDIAFLOW_PRIVATE_DIR` outside the document root.

== Changelog ==

= 0.3.0 =
* Add WordPress-free cold derivative routing through `qmediaflow-edge.php` and `qmediaflow-gateway.php`, with bounded non-blocking generation and immutable static publication.
* Add critical warming, de-duplicated priority derivative queue, telemetry, health diagnostics and adaptive generator concurrency.
* Add CDN rewriting, S3-compatible distribution, standalone-gateway distribution bridging and bounded AVIF/WebP picture delivery.
* Add content-aware encoding, WooCommerce integration, focal crops, headless REST API and reversible thumbnail migration tooling.
* Add viewport-aware progressive delivery with network/Save-Data adaptive margins, scroll-velocity lookahead, decode-aware reveal and no-JavaScript fallback.
* Add release hardening for WordPress array crop definitions, focal runtime backfill and REST visibility.
* Add bounded 96×96 upload-time image intelligence for visual classification, saliency focal suggestions and quality hints.
* Add privacy-safe predictive cache warming using decaying post heat scores without visitor identifiers.
* Add `wp qmediaflow validate production` and a dependency-free concurrent HTTP load validator for real staging/production testing.

= 0.2.9 =
* Rebrand the public product and plugin metadata to QMediaFlow.
* Add canonical `QMEDIAFLOW_*` configuration constants while retaining `MEDIAFLOW_*` aliases.
* Add canonical `qmediaflow_url()` and `qmediaflow_image()` helpers while retaining legacy API aliases.
* Document the compatibility boundary so existing sites can upgrade without cache, hook or integration breakage.

= 0.2.8 =
* Add browser-first image upload optimization with orientation normalization, dimension limits and Web Worker WebP encoding.
* Binary-search the highest quality under a 480 KB target and enforce a 500 KB final ceiling.

= 0.2.7 =
* Add Auto LQIP with a deterministic gradient, bounded background generation and immutable revisioned previews.

= 0.2.6.1 =
* Harden LQIP performance, filesystem setup and bounded admin/CLI rebuilds.

= 0.2.0 =
* Scale-hardening release with sharding, anti-stampede generation, private runtime storage, O(1) namespace rotation and bounded responsive candidates.

= 0.1.0 =
* Initial prototype.
