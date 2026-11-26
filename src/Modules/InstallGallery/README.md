# Module: InstallGallery

## Purpose
A second gallery on the product page, separate from the native WooCommerce one: "Customer and installation photos". It joins (1) photos of tires fitted on motorcycles picked by the owner per product and (2) photos from approved product reviews (`ReviewPhotos`). The native review screen stays unchanged. No database tables, no lightbox library, and no jQuery.

## Public contracts
- Shortcode `[motored_install_gallery id="..."]`: renders once per product/request (defaults to current product resolved via `$GLOBALS['product']`, `get_the_ID()`, or queried product object), works in classic, block, and builder templates.
- `GalleryStore::rebuild( int $product_id )` and `GalleryStore::rebuild_all()`: rebuild derived `_motored_review_gallery` meta from approved reviews with photos.
- `GalleryItems`: pure normalization, deduplication, merge, rating preservation (`0..5`), and strip splitting rules.

## Hooks and filters used
| Hook/filter | Context | Why | Verification status -> VERIFY.md |
|---|---|---|---|
| `woocommerce_before_single_product_summary` (prio 30 via `ProductPageHooks`) | frontend | Prints gallery directly below the native product gallery (`below_gallery`, default) | unverified -> VERIFY #112 |
| `woocommerce_single_product_summary` (prio 35 via `ProductPageHooks`) | frontend | Prints gallery directly below the Add to Cart button (`after_add_to_cart`) | unverified -> VERIFY #112 |
| `woocommerce_after_single_product_summary` (prio 8 & 11 via `ProductPageHooks`) | frontend | Prints gallery above (`before_tabs`) or below (`after_tabs`) product tabs | unverified -> VERIFY #112 |
| `woocommerce_product_tabs` (prio 35 via `ProductPageHooks`) | frontend | Registers optional `motored_install_gallery` product tab (`placement = tab`) | unverified -> VERIFY #117 |
| `render_block_woocommerce/product-image-gallery` (via `ProductPageHooks`) | frontend | Block themes: appends the gallery below the Product Image Gallery block when placement is `below_gallery` | unverified -> VERIFY #122 |
| `render_block_woocommerce/add-to-cart-form` (via `ProductPageHooks`) | frontend | Block themes: appends the gallery below the Add to Cart block when placement is `after_add_to_cart` | unverified -> VERIFY #122 |
| `render_block_woocommerce/product-details` (via `ProductPageHooks`) | frontend | Block themes: prints the gallery before/after the Product Details block for `before_tabs` / `after_tabs` | unverified -> VERIFY #122 |
| `transition_comment_status` (prio 10, 3 args) | all except CLI | Rebuilds `_motored_review_gallery` on approve/unapprove/spam/trash | partial -> VERIFY #113 |
| `deleted_comment` (prio 10, 2 args) | all except CLI | Rebuilds `_motored_review_gallery` after review deletion | partial -> VERIFY #113 |
| `comment_post` (prio 20, 2 args) | frontend, ajax | Rebuilds `_motored_review_gallery` when a new review with photos arrives already approved (after `ReviewPhotos` at prio 10) | unverified -> VERIFY #62, #113 |
| `add_meta_boxes_product`, `woocommerce_admin_process_product_object` (via `AttributeHooks::register_product_box_hooks`) | admin | Renders and saves "Installation photos" meta box with quick-edit `present` guard | unverified -> VERIFY #114 |
| `admin_enqueue_scripts` | admin | Enqueues `wp_enqueue_media( array( 'post' => $post_id ) )` + `motored-install-gallery-admin` (depends on `media-editor`, non-deferred) on product edit screens | unverified -> VERIFY #114 |
| `admin_post_motored_ig_sync_reviews` | admin | One-click backfill rebuilding `_motored_review_gallery` across existing approved reviews | unverified -> VERIFY #114 |
| `motored_reviews_section_fields` (filter, prio 20) | admin | Appends Photo gallery settings + backfill tool under WooCommerce > Settings > Motored > Reviews | own |

## Data model
- Option `motored_install_gallery` (`autoload=false`).
- Product meta `_motored_install_photos`: source of truth, up to 12 owner-picked attachment IDs.
- Product meta `_motored_review_gallery`: derived list of `{id, c, r}` (attachment ID, comment ID, review rating `0..5`), max 24, rebuilt on moderation events, backfill, product save, or migration `2026112401` (never on storefront page views).
- Captions and alt texts live natively in the WordPress Media Library (put the motorcycle in the caption). Attachments are never deleted by this module.

## Configuration keys
Edited under WooCommerce > Settings > Motored > Reviews (`autoload=false`):
- `enabled` (checkbox, default `yes`): show gallery on products that have photos.
- `include_reviews` (checkbox, default `yes`): include approved `ReviewPhotos` photos.
- `placement` (select, default `before_tabs`): `before_tabs`, `after_tabs`, `tab`, or `shortcode`.
- `visible` (number 4–12, default `8`): thumbnails rendered in the strip before `+N`.

## Main flows
1. **Admin product box:** Owner opens product edit screen -> clicks Add photos (`wp.media`, `multiple: true`), reorders (`←`/`→`), edits caption, or removes (`×`) -> Update saves `_motored_install_photos` (only if `present` marker is posted) and refreshes `_motored_review_gallery`.
2. **Review moderation & backfill:** `GallerySync` listens to `transition_comment_status`, `deleted_comment`, and `comment_post`; existing stores run migration `2026112401` or the "Sync review photos now" admin button.
3. **Storefront rendering:** Reads pre-primed product meta first (**0 DB queries**, **0 option reads**, **0 assets** when a product has no photos). When photos exist: reads `motored_install_gallery`, primes attachments with `_prime_post_caches`, renders source filter pills (`All`, `Workshop installations`, `Customer reviews`), `thumbnail` images (lazy, async), `+N` chip, `.no-lightbox` anchors, and enqueues `assets/css/install-gallery.css` + `assets/js/install-gallery.js` (~3 KB, `defer`).
4. **Lightbox dialog:** Capture-phase click handler blocks theme lightboxes (e.g., Magnific Popup) and opens a native `<dialog>` showing the `large` sub-size (never the original), star ratings for reviews, prev/next arrows, Esc, touch swipe, and a "View review" link that activates/scrolls to the reviews tab. Review uploads handled by `ReviewPhotos` use `Support\ImageProcessor::process_upload()` (1600 px, q82, WebP when supported, EXIF stripped, 3 sub-sizes).

## Performance and security notes
- Products without photos add **0 queries, 0 options, and 0 assets** (even with `placement = tab` when empty). Only shop managers (`manage_woocommerce`) can run settings/backfill; product saves require nonce + `edit_post` + attachment edit capability. All output escaped; zero reviewer PII rendered.

## How to test
- `php vendor/phpunit/phpunit/phpunit tests/Unit/Modules/InstallGallery`
- Staging checks: `docs/STAGING-CHECKLIST.md` (Photo gallery section).

## Known limitations
- Owner-picked installation photos use the sub-sizes generated at upload unless `ImageOptimizer` is enabled (Phase 2).
- Manual block-theme placement outside WooCommerce's classic hooks requires inserting `[motored_install_gallery]`.

## Changelog
- 0.2.0 — 2026-11-20 — Placement selector (`before_tabs` default, `after_tabs`, `tab`, `shortcode`), dedicated theme-agnostic `install-gallery.css`, capture-phase dialog isolation (`.no-lightbox`, un-relative `<dialog>`), review ratings + source filter pills, admin `media-editor` dependency + caption preview + reorder controls + quick-edit `present` guard, review backfill tool + migration `2026112401`, shared `Support\ImageProcessor` for `ReviewPhotos`.
- 0.1.0 — 2026-11-20 — Initial implementation.
- 0.3.0 — 2026-11-20 — Review fixes: block themes get the before/after-tabs placement through `render_block_woocommerce/product-details` (once-per-product guard shared with the classic hook; VERIFY #122); `deleted_comment` only rebuilds when the deleted comment is in the derived list (a spam/trash purge no longer runs one rebuild query per comment); `rebuild()` writes nothing when the list is unchanged and, when it changes, calls `clean_post_cache()` + `wc_delete_product_transients()` so object/page caches drop the stale gallery (VERIFY #121); `rebuild_all()` uses one indexed SQL (no 5,000-review cap) and also clears products whose list became stale. SEO/a11y: the Media Library alt text is used (it was overwritten before), then the install caption, then "<product> — customer photo"; `sizes` lets retina screens pick the 300 px square size; filter pills expose `aria-pressed`; the section has an accessible name. JS rewritten readable: left-click only, focus returns to the thumbnail, next photo preloaded, live counter, passive touch listener, image released on close. CSS: accent from `--motored-accent-color`, visible `:focus-visible`, `prefers-reduced-motion`.
