# MediaFlow server notes — v0.2.7

## Public cache

`wp-content/image-cache/` must be directly web-readable. Existing generated variants and Auto-LQIPs should bypass PHP. A missing normal signed derivative must fall through to WordPress so MediaFlow can generate that derivative. A missing **Auto-LQIP is different**: it should return the transparent MediaFlow pending fallback, while the CSS gradient underneath remains visible.

### Apache / LiteSpeed

MediaFlow writes one managed block to `wp-content/image-cache/.htaccess`. Routing schema 4 distinguishes the two cold paths:

1. Missing revisioned Auto-LQIP URL → internally serve `__mediaflow-lqip-pending.svg` with `Cache-Control: no-store, max-age=0`; **no WordPress/PHP bootstrap**.
2. Missing signed normal derivative → internally rewrite to the WordPress front controller for signature validation and bounded derivative generation.
3. Existing files → normal direct static delivery; generated raster images receive long immutable caching.

The older `Options -Indexes` directive remains removed because restricted `AllowOverride` configurations can turn it into HTTP 500. Directory listing protection is supplied by MediaFlow guard files. The managed rewrite block requires normal WordPress-style `mod_rewrite` / `AllowOverride FileInfo` support.

On upgrade MediaFlow removes/replaces only its known managed/legacy block and preserves unrelated administrator directives.

### Nginx

Nginx ignores `.htaccess`. Normal MediaFlow derivative misses still need to reach WordPress, but missing Auto-LQIPs should **not** be sent to PHP. Mirror the Apache pending-fallback behavior in the server configuration. A representative rule is:

```nginx
location ~ ^/wp-content/image-cache/(?:sites/[1-9][0-9]{0,9}/)?[0-9]{2}/[0-9]{2}/[0-9]+/lqip/[a-fA-F0-9]{12}/[a-fA-F0-9]{12}\.(?:webp|jpe?g)$ {
    try_files $uri /wp-content/image-cache/__mediaflow-lqip-pending.svg;
}

location = /wp-content/image-cache/__mediaflow-lqip-pending.svg {
    add_header Cache-Control "no-store, max-age=0" always;
    add_header X-Content-Type-Options "nosniff" always;
}

location / {
    try_files $uri $uri/ /index.php?$args;
}
```

Treat this as a template: managed hosting may already have broader `wp-content`, image-extension, CDN, or `try_files` locations whose precedence must be reconciled. Verify the effective Nginx configuration on staging. If the host sends the missing Auto-LQIP URL to WordPress, the page will still function, but the intended zero-PHP cold-preview performance contract is lost.

For existing generated raster images, retain direct static delivery and long immutable caching.

## Auto-LQIP background processing

A dynamic page render queues a missing preview by atomically creating a small private marker and schedules a site-scoped single WP-Cron event. The worker processes one image per run and reschedules while ready work remains. New uploads attempt preview generation during normal attachment processing.

If `DISABLE_WP_CRON` is true, configure a real server cron that runs WordPress scheduled events, or optionally pre-warm LQIPs. Without a functioning cron, the safe gradient still works, but queued previews will not automatically become ready.

## Private runtime directory

Default: `wp-content/.mediaflow-private/`.

MediaFlow writes Apache/IIS deny files, but Nginx does not read `.htaccess`. For Nginx, either place the directory outside the document root:

```php
define( 'MEDIAFLOW_PRIVATE_DIR', '/srv/private/example-mediaflow' );
```

or explicitly deny it:

```nginx
location ^~ /wp-content/.mediaflow-private/ {
    deny all;
    return 404;
}
```

## PHP-FPM sizing

A warm derivative or warm Auto-LQIP never reaches PHP. Missing Auto-LQIPs should also remain static at the web-server layer. For normal derivative misses, only one worker generates a given variant; concurrent misses fall back immediately to the source. CPU/memory sizing therefore depends mainly on unique derivative misses plus background LQIP generation, not total image traffic.

## CDN

A CDN may cache ready files under `image-cache/` as origin-static assets. Preserve the pending fallback's `no-store` behavior and do not negative-cache a missing Auto-LQIP URL for a long period; the same URL is expected to become a real static LQIP after the worker publishes it. Validate CDN behavior on staging.
