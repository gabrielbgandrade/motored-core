---
name: motored-module-installgallery
description: Rules for the InstallGallery module (second product gallery: owner-picked installation photos + approved review photos, native media picker, native dialog). Use when touching src/Modules/InstallGallery, assets/js/install-gallery.js, assets/css/install-gallery.css, or assets/admin/motored-install-gallery.js.
---

# Module InstallGallery
Read `src/Modules/InstallGallery/README.md` first (flows, meta keys, placement, hooks).

- Zero tables, zero jQuery, zero external lightbox libraries, zero theme selectors.
- Frontend assets: `assets/css/install-gallery.css` and `assets/js/install-gallery.js` (`defer`, < 3 KB), enqueued ONLY when a gallery with photos renders (`enabled` + non-empty resolved items).
- WooCommerce product hooks live ONLY in `Integration\WooCommerce\ProductPageHooks` (`register_before_tabs` @ 8, `register_after_summary` @ 11, `register_product_tab` @ 35); admin box hooks live in `AttributeHooks::register_product_box_hooks`.
- Admin script `motored-install-gallery-admin` MUST depend on `array( 'media-editor' )`, call `wp_enqueue_media( array( 'post' => $post_id ) )`, and NOT use `defer`. Keep `present` guard so Quick Edit never wipes `_motored_install_photos`.
- Never place `position: relative` on `<dialog>` itself (breaks top-layer viewport centering); style `.motored-ig-dialog__inner` instead and intercept thumbnails in the capture phase + `.no-lightbox`.
- Two product meta keys: `_motored_install_photos` (source of truth, max 12) and `_motored_review_gallery` (`{id, c, r}`, DERIVED via `GallerySync`, backfill `motored_ig_sync_reviews`, or migration `2026112401`, never rebuilt on page views).
- Product page order: check pre-primed meta before reading options (`0` queries/assets on photo-less products); prime attachments once via `_prime_post_caches`. Strip prints `thumbnail` images only; `<dialog>` loads `large`.
- Admin UI MUST follow `Admin\Ui` + shared classes in `assets/admin/motored-admin.css` (no colors, `background:`, or inline `style=`).
- Block themes: `ProductPageHooks::register_product_details_block` (Product Details block) mirrors the classic placements; keep the once-per-product guard shared. Derived-list changes go through `GalleryStore::rebuild()` (no-op when unchanged) which calls `GalleryStore::purge()` (page/object caches). `deleted_comment` must stay cheap (check the derived list, never query per deleted comment). Alt text: Media Library alt first.
- Strings: English source + complete `es_AR` voseo in `.pot/.po/.mo`. Tests: `tests/Unit/Modules/InstallGallery`.
- After changes: run `composer check` (`phpcs`, `phpstan`, `phpunit`) + `motored-review`; update `VERIFY.md`, `STAGING-CHECKLIST.md`, and `PROJECT.md`.
