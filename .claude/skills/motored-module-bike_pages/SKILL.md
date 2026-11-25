---
name: motored-module-bike_pages
description: Rules for Modules\BikePages (motored_bike CPT, tire-size signatures, BikePageSync, BikeRenderer, HubLinks, Avisame demand capture, SizePages). Use when editing src/Modules/BikePages.
---

# Module BikePages
Read `src/Modules/BikePages/README.md` and `docs/STUDY-COMPAT-GARAGE-FILTERS.md` first.

- **Signature unit:** one `motored_bike` CPT post per `(model_id, OEM signature)`, keyed by `_motored_sig`. Never copy product lists into post content; render live from `MatchEngine` via `[motored_moto]`.
- **Preserve owner edits:** only update `post_title` while equal to `_motored_synced_title` and `post_content` while equal to `_motored_synced_content`. Register 301 via `Seo\Redirects::add()` on slug changes or trashed orphan signatures.
- **Theme-agnostic & SEO safe:** never output `<h1>` inside `[motored_moto]` (theme owns H1); use `wc_get_template_part( 'content', 'product' )` for cards; `index,follow` ONLY when `_motored_indexable = '1'`.
- **Contexts:** the `motored_compat_bike_page_url` filter must exist on frontend AND REST (banner, garage and tab rows are REST payloads). Generated titles are built in the site locale; bump `BikePageSync::SYNC_VERSION` whenever generated title/slug/content format changes so existing pages are rebuilt once.
- **URLs & index:** clean `{brand}-{model}` slug per model (sticky), year suffix only for extra signatures (`Signature::assign_post_slugs()`); every rename must leave a 301. Indexability and the hero count both use `Availability` — never a second definition of "in stock". Removed pages answer 410 (`GoneHandler`).
- **Listings:** `[motored_moto]` renders the full page ONLY on `is_singular( 'motored_bike' )`; anywhere else it is `listing_card_html()` (0 queries). Hub bodies come from `[motored_bike_hub]` (block templates) or the archive description (classic).
- **Counts:** front/rear counts never include kits (same buckets as `?pos=`); use `MatchEngine::matching_ids_by_position()` / `matching_product_counts()`, never a local tally or a second candidate query.
- **Decoupled demand capture:** `DemandStore` never calls `wp_mail()` or `Waha\Sender` directly — always emit `Integration\BikeDemandHooks::created()`. Mask PII in logs and enforce 180-day purge + WP Privacy exporter/eraser.
- **Admin UI:** `BikePagesAdmin` uses `Admin\Ui` + `motored-admin.css` only (`SharedAdminUiTest`).
- After changes: `composer check`, `motored-review`, update `docs/VERIFY.md` and `docs/PROJECT.md`.
