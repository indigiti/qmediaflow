# MediaFlow 0.2.4 — Explicit image encoding settings

Built from MediaFlow_v0.2.3_Apache_Multisite_Hotfix.zip. Apache guard migration, cold-miss rewrite rules, multisite isolation and shared processing limits are carried forward unchanged.

## Installation and settings

1. Back up the plugin directory and MediaFlow private configuration. Upload this ZIP and replace the installed plugin. Retain Network Activation for multisite.
2. In each site's **Tools → MediaFlow**, find **Image encoding**:
   - **Progressive JPEG**: requests progressive scan output for JPEG derivatives.
   - **Interlaced PNG (Adam7)**: requests interlaced PNG derivatives.
3. Run **Check JPEG / PNG encoders**. The result must say VERIFIED for the relevant ON/OFF modes before relying on them. This button uses tiny real encodes through the WordPress-selected editor and reads the output headers.
4. Check the desired boxes and save. Both default OFF on upgrade to avoid a surprise cache rebuild. Changing either option rotates only this site's cache namespace. Existing files remain intact; clear page caches to refresh cached HTML.
5. These controls affect JPEG/PNG **outputs**. With Auto/WebP selected, most derivatives remain WebP; choose **Original format** if you want JPEG sources to produce progressive JPEG and PNG sources to produce interlaced PNG. A custom mediaflow_url request can also select jpeg or png.

For WebP preview while loading, keep Auto or WebP and select **Progressive placeholder → Inline LQIP**. New uploads create their preview during metadata capture. Existing attachments need `wp mediaflow repair <attachment-id>` to generate an LQIP; page rendering does not decode large originals merely to generate previews. Color placeholders remain the default. LQIP adds inline HTML bytes and is optional.

## Encoder behavior

Uses WordPress 6.5+'s native [image_save_progressive filter](https://developer.wordpress.org/reference/hooks/image_save_progressive/) only around a MediaFlow derivative save. The GD and Imagick APIs are checked before applying the policy. The temporary filter is removed in a finally block on successful saves, errors and exceptions. No editor subclassing or second encode pass is required. WebP/AVIF bypass this policy, and original uploads/WordPress physical thumbnails are not changed.

JPEG scan markers and the PNG IHDR interlace byte are inspected after save with bounded header reads, without decoding the output. If the editor is unsupported or the output is unverified, MediaFlow preserves the image editor's normal output and emits `mediaflow_encoding_unavailable` for integrations. It does not sacrifice image availability or repeatedly re-encode to force the setting. The admin compatibility check distinguishes VERIFIED, NOT VERIFIED, unsupported and save-failed results; it is not a blanket guarantee for every custom editor/source combination. Re-run after server/library updates.

The checker consumes a shared processing slot and removes its temporary files. Its button requires manage_options and a WordPress nonce. It writes no image-library content or settings. CLI equivalent:

```sh
wp mediaflow encoding-check
# On multisite, select the site:
wp --url=https://your-site.example mediaflow encoding-check
```

## Cache and performance

Settings and namespace stay per-site. Toggling these encoding options creates new URLs without deleting old derivatives or changing source files. Cached old URLs remain valid; a not-yet-generated old URL uses the current encoder preference when requested. Output dimensions and format stay as signed. Pure progressive/interlaced encoding does not change the visual crop or dimensions.

Static hits still bypass WordPress. The cold-save path adds a short header inspection, not a second image decode/encode. Enabling encoding can change byte size and encoding CPU cost, especially for small PNGs. Do not assume it always improves transfer size or LCP. Browsers may render progressive passes differently depending on transfer buffering; LQIP is a separate visual placeholder mechanism.

## Validation and limits

See TEST-RESULTS.txt for checks run in this environment. The ZIP includes independently encoded progressive/baseline JPEG and Adam7/plain PNG fixtures. Run `php tests/encoding-headers.php` to test the PHP header inspector, and run the admin/CLI checker on staging for actual WordPress encoder verification. PHP/WordPress runtime was unavailable in the build environment, so these PHP checks and live browser tests were not executed here.

Staging checks: JPEG ON/OFF, PNG ON/OFF, unsupported encoder, normal WebP delivery with LQIP, a settings toggle followed by a fresh URL, an unchanged settings save retaining the namespace, two-site isolation, existing Apache cache-hit/cold-miss routes, transparent PNG and preservation of physical WordPress thumbnails. Compare generated file size and cold encoding time on representative images.

## Rollback

Restore the backed-up v0.2.3 plugin and settings file, and clear page caches. Retain sources, keys, manifests and old cache files. This release has no database schema migration or automatic cache deletion.
