# MediaFlow 0.2.2 — Multisite support

Supersedes the single-site restriction in 0.2.1. Baseline is the delivered 0.2.1 ZIP; this release preserves its limits, locking, crop handling and public helper names.

## Update and enable

1. Back up the plugin and MediaFlow private directory. Upload this ZIP in Network Admin → Plugins → Add New → Upload Plugin and replace the installed version.
2. **Network Activate MediaFlow.** If it was activated on individual sites, network activation is still required. This ensures the plugin loads on the main site when WordPress routes a subfolder site's uncached static URL there.
3. Open **each site's Dashboard → Tools → MediaFlow**. Settings, signing storage and cache namespace apply to that site. Confirm Site scope shows the expected site ID.
4. Clear page caches so markup uses the new site-scoped URLs. Test one newly uploaded image on two different sites before rolling out broadly.

Single-site activation continues to work as before. No WordPress database schema migration or bulk attachment processing is required. Existing and newly added sites initialize their runtime on first use, avoiding an activation-time loop over a large network. On multisite, per-site-only activation displays a Network Activate instruction and leaves rewriting off.

## Isolation and routing

| Item | Multisite path / behavior |
|---|---|
| Generated images | `wp-content/image-cache/sites/<blog-id>/<shards>/<attachment-id>/...` |
| Settings, secrets, manifests | `<MEDIAFLOW_PRIVATE_DIR>/sites/<blog-id>/...` (default base `wp-content/.mediaflow-private`) |
| Processing slots | Shared `<MEDIAFLOW_PRIVATE_DIR>/locks/`, retaining the server-wide two-encoder default |
| Signatures | Separate site secrets plus an explicit blog ID in the signed payload |
| Cold request routing | Parse blog ID from cache URL, check site/network/status, switch to that site, then validate/generate |
| Site switching | Recreate context-bound services on switch and restore; hooks resolve the current services |
| Cleanup / repair / warm | Operate in the selected site's scope; use WP-CLI `--url=<site-url>` |

Single-site cache paths, private paths and mf2 signatures remain unchanged. Multisite starts isolated caches and does **not** import the old ambiguous global manifests/signing key. Normal per-site database settings are used if no scoped settings file exists. Existing global cache files are left in place for rollback and cached pages; purge old HTML and retire these legacy files separately after review.

Subdomain, subfolder and mapped-domain deployments need the normal public cache directory to resolve to the shared filesystem on every origin. Existing files must remain static; missing variants must reach WordPress. Custom CDN URLs must route misses to that origin. For customized content URL path rewriting, verify cold delivery explicitly. The dispatcher refuses nonexistent/deleted/archived/spam sites and other networks. Like ordinary WordPress media, existing static image files are public; this is not a private-media or membership access system.

## Nginx protection

The default private path is protected with:

```nginx
location ^~ /wp-content/.mediaflow-private/ {
    deny all;
    return 404;
}
```

Adjust for a custom content path. Nginx does not read .htaccess. Prefer private storage outside the document root; retain the existing base path when upgrading. Per-site private guard files and shared-root guard files are written for Apache/IIS.

## Verification

Source parsing and source contract checks pass. The prior 24-process filesystem admission test also passes. These do not execute PHP/WordPress.

A runnable WordPress test is included:

```sh
wp eval-file wp-content/plugins/mediaflow/tests/multisite-smoke.php
```

It needs two healthy sites and network activation. It checks same-ID path isolation, settings/key isolation, switching/restoration, own-site HMAC validation, rejection of cross-site HMAC replay (even with an equal in-memory key), and shared admission paths. It may initialize ordinary runtime files but does not change attachment data/options. **This WordPress test has not been executed here because PHP/WordPress are unavailable.**

Also verify:

- A cold image on a subfolder site resolves even when the request bootstraps the main site; the second hit is static.
- Same attachment IDs on two sites never produce the other site's image.
- A mapped-domain site generates URLs which reach the correct origin cache path.
- Settings/namespace changes, attachment deletion and purge-stale on A leave B intact.
- Existing single-site URLs and signatures continue to work.
- Newly created sites initialize automatically; Network Deactivate disables rewriting.
- Network-wide concurrency stays within the configured limit on a shared filesystem.

PHP lint, real GD/Imagick encoding, HTTP routing and production performance remain staging validation tasks. Retained Resolver/Settings objects must not be reused by external integrations after switch_to_blog; obtain the current instance/helper after switching. Built-in hooks do this automatically.

## Rollback

Restore the 0.2.1 plugin ZIP and prior settings snapshot. On multisite, 0.2.1 will again disable rewriting. Clear HTML caches. Leave per-site caches/private state untouched for a later re-upgrade; no automated destructive migration occurs.

## WordPress API references

Site-context refresh follows the [switch_blog hook](https://developer.wordpress.org/reference/hooks/switch_blog/), which also fires during restoration. Activation uses the [standard activation hook](https://developer.wordpress.org/reference/functions/register_activation_hook/).
