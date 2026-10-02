# Scaling notes for large news sites

MediaFlow v0.2 is designed so total post count is not the primary delivery bottleneck. The important variables are attachment count, unique derivative miss rate, image-processing CPU/memory and storage inode behavior.

## Synthetic validation performed during v0.2 hardening

These are development-environment checks, **not production capacity guarantees**.

- 500,000 sequential attachment IDs: sharding distributed roots across >5,000 leaf directories; no leaf had more than 100 attachment roots.
- 100,000 real sharded manifest files: creation completed successfully; 10,000 random warm filesystem reads completed in about 88 ms in the test container.
- 40 concurrent misses for one variant with a simulated 300 ms encoder: one generator; 39 non-blocking busy responses; no duplicate generators.
- 20,000 static 100 KB image requests through local Nginx at concurrency 100: 0 failed requests. Throughput is host/cache dependent and should not be used as a production sizing figure.

## Recommended rollout for a 500k-post news site

1. Install on staging with **Safe** physical-size mode.
2. Verify theme, Gutenberg, galleries, SEO/social/email/RSS paths and page builders.
3. Move to **Adaptive** mode and test new uploads.
4. Warm only critical homepage/category/hero variants if required by the site's publishing workflow.
5. Load-test the real Nginx/LiteSpeed + PHP-FPM + storage stack.
6. Only after compatibility validation consider **Strict** mode.

Do not bulk-delete existing WordPress thumbnails until a dedicated migration tool has audited references.

## v0.2.6.1 LQIP rebuild budgets

Progressive LQIP generation is deliberately outside the visitor hot path. Each attachment source is decoded once per rebuild operation, then the same editor is reused for lower-quality and lower-resolution fallbacks. wp-admin rebuild requests process at most five candidates by default and stop starting new attachments after roughly eight seconds; WP-CLI defaults to 100 attachments per invocation.

Frontend inline LQIP markup is bounded by both a request-global count (8 by default) and a total request-global payload budget (24 KB by default). These counters are static to the PHP request rather than a site-scoped service instance, so `switch_to_blog()` context reconstruction cannot multiply the inline payload allowance.

## v0.2.7 Auto-LQIP scaling

Auto LQIP is demand-driven rather than library-wide. Existing attachments cost no image-processing CPU until they are actually rendered inside the Auto-LQIP request budget. A first dynamic render creates one small private queue marker; concurrent renders converge on that marker through exclusive file creation. Source decode/resize occurs later in a site-scoped background worker, one image per run with an eight-second worker budget.

New uploads still attempt the preview during normal attachment metadata processing, so current editorial content is usually ready without queueing. Administrators may optionally pre-warm older libraries in time-bounded wp-admin batches or with WP-CLI, but pre-warm is not required for correctness.

The default Auto-LQIP render ceiling is eight preview URLs per PHP request. Images beyond that ceiling receive only the deterministic gradient. Unlike Inline LQIP, Auto LQIP adds tiny cacheable preview requests instead of base64 HTML payload. Warm previews are static files. On Apache/LiteSpeed, cold preview URLs resolve to a transparent static no-store fallback and do not bootstrap WordPress.

A large burst can create many queue markers if it exposes many previously unseen attachments. Queue files are sharded by attachment ID, successful jobs remove their marker, failures back off, and generation locks use a fixed 64-slot pool. If a deployment routinely accumulates tens of thousands of simultaneous pending markers, use a real server cron and monitor queue/storage I/O; a dedicated queue backend can be considered in a later release.
