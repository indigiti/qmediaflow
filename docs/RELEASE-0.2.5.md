# MediaFlow 0.2.5 — Multisite recursion hotfix

The supplied PHP error is truncated, so it cannot establish every stack frame. Code inspection found a concrete recursion defect consistent with the repeated switch_to_blog frames:

switch_blog → Plugin::reload_context → Settings initialization → Paths::ensure → Apache rule construction → get_home_url(main site) → switch_to_blog → switch_blog → repeat.

WordPress get_home_url with a nonempty blog ID switches context even when targeting the current site: https://developer.wordpress.org/reference/functions/get_home_url/

## Fix

- Build the multisite front-controller path from main-site metadata (`get_site()->path`) instead of switching through home-URL/option helpers. Root and network subdirectory paths are preserved. Custom installations whose front-controller path differs from the main site's registered path can define MEDIAFLOW_FRONT_CONTROLLER_PATH (e.g. '/network/index.php') before plugin load.
- Guard nested context construction. Rebuild service objects locally and publish the complete graph together. Release the guard in finally, including storage/configuration errors. Refuse an unbalanced third-party site switch rather than publishing a graph under a wrong site.
- Preserve v0.2.4 encoder controls and v0.2.3 Apache repair rules, signing keys, image URLs, cache namespaces, source media and per-site settings.

## Recover and install

If Network Admin still loads, upload this ZIP and replace MediaFlow, retaining Network Activation.

If admin fails before the upload completes, use Cloudways SFTP:

1. Rename public_html/wp-content/plugins/mediaflow to mediaflow-disabled.
2. Upload the extracted mediaflow folder from this ZIP to public_html/wp-content/plugins/mediaflow.
3. Open Network Admin → Plugins and Network Activate MediaFlow if needed.
4. Once recovery is confirmed, remove the disabled backup folder after retaining a backup outside plugins.

Do not delete wp-content/image-cache or .mediaflow-private. Do not raise zend.max_allowed_stack_size to hide this recursion. If PHP continues executing old code after file replacement, reload PHP-FPM/clear OPcache through Cloudways's normal application/server controls.

Verify Network Admin upload/update, individual site Tools → MediaFlow, switching between two sites, a cold image URL and an existing static image. Then run the encoder compatibility button if using progressive/interlaced output.

The earlier “Primary script unknown” entries are a different FastCGI error. The provided lines omit the requested script, so their cause cannot be determined from this excerpt. They are not evidence of this recursion defect.

## Validation

Source syntax and existing source/OS-lock checks run locally. New standalone PHP regressions: tests/context-reentry.php simulates nested switching during construction and failure/retry; tests/routing-guard-smoke.php now throws if the routing code calls get_home_url. PHP runtime is unavailable here, so these executable PHP tests and live Cloudways validation are NOT RUN locally. TEST-RESULTS.txt records the exact scope.

No DB schema change, cache purge or binary replacement. Baseline: delivered MediaFlow 0.2.4. Rollback to that version would reintroduce the known recursion; if the hotfix has a problem, disable MediaFlow while collecting the full stack trace instead.
