---
name: motored-module-reviewphotos
description: Rules for the ReviewPhotos module (photos on native WooCommerce product reviews, Media Library + comment meta). Use when touching src/Modules/ReviewPhotos or src/Integration/WooCommerce/ReviewHooks.php.
---

# Module ReviewPhotos
Read `src/Modules/ReviewPhotos/README.md` first (flows, keys, hooks).

- No tables, no jQuery, no lightbox, no theme dependency. Only asset: `assets/js/review-photos.js` (< 2 KB, defer),
  only on a product page with open comments and the module enabled.
- WooCommerce hook names live ONLY in `src/Integration/WooCommerce/ReviewHooks.php` (unverified, VERIFY.md #60-#64).
- The server is the validation authority: `comment_post` only; never lose the comment; skip bad photos; contain exceptions.
- Accept JPEG/PNG/WebP only (never SVG/GIF). Type = `wp_check_filetype_and_ext` AND `getimagesize` agree; re-encode with the
  GD editor (1600 px, q82, drops EXIF/GPS); random file names; reject the photo if re-encoding fails.
- Never store photos for spam. Per-IP limit via `UploadLimiter` (Cache only, HMAC of the IP in the key).
- Only delete attachments flagged `_motored_review_photo`; deleting a comment or running the eraser deletes its photos.
- Display: read comment meta first (already primed), config second; no queries outside product pages.
- Keep `FileRules`/`PhotoMeta`/`UploadLimiter` pure and tested; `PhotoProcessor` is the only WordPress image code.
- Strings: English source + es_AR voseo in `.pot/.po/.mo`. Tests: `tests/Unit/Modules/ReviewPhotos`.
- After changes: `composer check`, then `motored-review`; update VERIFY.md and PROJECT.md.

Theme independence: keep the single-field/single-thumbnail guarantee (`ReviewForm::$rendered`, `ReviewDisplay::$done`) when touching hooks; no theme selectors or classes.
