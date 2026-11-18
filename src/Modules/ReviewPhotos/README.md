# Module: ReviewPhotos

## Purpose
Lets customers attach photos to the NATIVE WooCommerce product review form and shows them under approved reviews.
No tables: photos are Media Library attachments (`post_parent` = product) and their ids live in comment meta.

## Public contracts
None for other modules. Internals: `FileRules` (pure rules), `PhotoMeta` (meta/attachment cleanup), `UploadLimiter`.

## Hooks and filters used
| Hook/filter | Context | Why | Status |
|---|---|---|---|
| `woocommerce_product_review_comment_form_args` (filter) | frontend | appends the file input (via `Integration\WooCommerce\ReviewHooks`) | unverified (VERIFY #60) |
| `woocommerce_review_after_comment_text` (action) | frontend | prints thumbnails | unverified (#61) |
| `comment_post` ( $id, $approved ) | frontend | validates and stores the photos | unverified (#62) |
| `wp_enqueue_scripts` | frontend | the one `defer` script on product pages | unverified (#62) |
| `delete_comment` | all but CLI | deletes our attachments with the comment | partial (#64) |
| `add_meta_boxes_comment` | admin | thumbnails on the comment edit screen | unverified (#64) |
| `wp_privacy_personal_data_erasers` | admin/ajax | deletes photos by e-mail | unverified (#64) |

## Data model
Option `motored_review_photos` (autoload=false). Comment meta `_motored_review_photos` = list of attachment ids.
Attachment post meta `_motored_review_photo` = `1` (only flagged attachments are ever deleted).
Cache bucket `review_photos`: `up:{hmac(ip)}:{hour}` counters (2 h).

## Configuration keys
`enabled` (no), `max_photos` (3, 1-5), `max_mb` (5, 1-10), `show_photos` (yes). WooCommerce > Settings > Motored > Reviews.

## Main flows
1. Product page with open comments and module on: field "Agregar fotos (opcional)" + `assets/js/review-photos.js` (<1 KB, defer):
   sets `enctype=multipart/form-data`, checks count/size/type, shows local thumbnails. Without JS: normal review, no photos.
2. `comment_post`: skip spam/non-review/no files/disabled; per-IP limit 5 photos/hour; count and size limits; `is_uploaded_file`;
   `wp_check_filetype_and_ext` + `getimagesize` must agree on JPEG/PNG/WebP (no SVG/GIF, max 40 MP); GD editor (`maybe_exif_rotate`,
   max 1600 px, quality 82; re-encoding drops EXIF/GPS); random file name; attachment + comment meta. Invalid photos are
   skipped and any error is contained: the comment is never lost.
3. Approved reviews: thumbnails linking to the full image (`target=_blank rel=noopener`), no lightbox.
4. Deleting a comment (admin, cron trash purge, REST) deletes its photos; the privacy eraser deletes them by e-mail.

## Performance and security notes
Registers hooks only (no option read). Non-product pages: 0 queries, 0 assets. Product page: 1 option read, 1 script;
display reads cached comment meta, then primes the attachment posts once per review. Photos are public files with random names.
Logs: comment id and counts only. Photos stay in the library on uninstall.

## How to test
`vendor/bin/phpunit tests/Unit/Modules/ReviewPhotos`: file rules, limits, rate limit, meta, upload flow, module contexts.
Staging: `docs/STAGING-CHECKLIST.md` (Review photos).

## Known limitations
Image re-encoding, the WooCommerce hooks and the theme output cannot be run in unit tests. Photos of a review
later marked as spam stay until the comment is deleted. No lightbox, no exporter.

## Changelog
- 0.1.0 — 2026-11-18 — initial implementation.

## Theme independence
Primary hooks are the WooCommerce review form/list hooks. If a theme replaces the review template, fallbacks take over
without duplicating anything: core `comment_form_field_comment` (field), core `comment_text` (thumbnails) and, last
resort, JS injection into `#reviews #commentform` (the script does nothing when that form does not exist).
