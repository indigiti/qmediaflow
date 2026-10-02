# MediaFlow 0.2.3 — Apache + Multisite Routing Hotfix

## Why this release exists

MediaFlow 0.2.2 created `.htaccess` files below the public image cache containing `Options -Indexes`. On Apache hosts where the applicable `AllowOverride` policy does not permit the `Options` override class, Apache rejects the request before WordPress/PHP runs and returns a generic HTTP 500 page.

Multisite also needs an explicit cold-cache path because standard WordPress Apache rules can short-circuit requests beneath `wp-content` before the normal front-controller fallback.

## Changes

- Removed `Options -Indexes` from newly generated public and private MediaFlow guard files.
- Added a single managed `wp-content/image-cache/.htaccess` block.
- Existing generated files remain ordinary static files and bypass PHP.
- Missing MediaFlow-shaped GET/HEAD URLs are rewritten to the WordPress front controller.
- The rewrite accepts both single-site and `/sites/<blog-id>/` multisite cache paths.
- Added automatic filesystem-versioned migration on plugin boot.
- Migration scans existing numeric multisite cache scopes so a broken child `.htaccess` cannot survive merely because that subsite has not been visited yet.
- Known v0.2.2 legacy blocks are removed while unrelated custom `.htaccess` content is retained.
- Known v0.2.2 private guards are migrated to deny-only rules without the `Options` directive.
- Added `tests/routing-guard-smoke.php` to verify migration, custom-rule preservation and idempotence without WordPress.

## Upgrade behavior

No database migration is required. On the first normal WordPress request after the plugin files are updated, MediaFlow checks a filesystem marker under `.mediaflow-private`, repairs legacy guard files when needed, writes the shared public routing block, and records version `0.2.3`.

The migration does not purge generated images, rotate signatures, change attachment manifests or alter MediaFlow settings.

## Expected Apache result

For a warm derivative:

```text
GET /wp-content/image-cache/.../variant.webp
→ Apache/LiteSpeed serves the existing file directly
→ WordPress/PHP is not bootstrapped
```

For a cold derivative:

```text
GET /wp-content/image-cache/sites/1/.../variant.webp
→ cache-root .htaccess confirms file is missing
→ internal rewrite to WordPress index.php
→ MediaFlow validates site/signature/limits
→ generates atomically or fails open to source
```

## Deployment check

After updating, load one normal WordPress/admin page once, then retest the previously failing MediaFlow URL. If Apache still returns a generic 500 before WordPress, inspect the Apache error log for another disallowed directive or inherited `.htaccess` outside MediaFlow.

For Nginx, `.htaccess` is ignored; keep the MediaFlow-specific `try_files`/fallback guidance in `SERVER-NOTES.md`.
