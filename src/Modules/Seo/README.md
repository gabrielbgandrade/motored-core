# Module: Seo

## Purpose
Full-site, lightweight, Redis-compatible SEO engine (`Modules\Seo`) built directly on WordPress core and WooCommerce hooks — providing `<title>`, meta descriptions, clean canonicals, `wp_robots` (with `max-image-preview:large`), rich Open Graph / Product / Twitter tags, XML sitemap filtering + `<lastmod>`, virtual `robots.txt` crawl-budget protection, 301/302 Redirects Manager + auto-redirects on slug changes, and JSON-LD structured data (`AutoPartsStore`, `WebSite`/`SearchAction`, `BreadcrumbList`, `CollectionPage`, `Article`, `ItemList`, `FAQPage`, plus enriched WooCommerce `Product` schema) with zero frontend JS/CSS overhead.

## Public contracts
- `Seo\Config`: typed accessors for option `motored_seo` (`autoload=false`).
- `Seo\SeoContext`: `set()`, `get()`, `reset()` + filter `motored_seo_context`.
- `Seo\SiteContextBuilder`: automatic full-site SEO payload for Home, Products, Shop/Categories/Brands, Pages, Blog and Transactional views (never overwrites `BikePages` / `SizePages` / `HubLinks` contexts).
- `Seo\ProductSchemaEnhancer`: enriches WooCommerce's native `Product` JSON-LD (`woocommerce_structured_data_product`) with `Brand`, `mpn`, `itemCondition`, `seller`, gallery images and tire technical `PropertyValue`s (no duplicate `Product` node).
- `Seo\Redirects`: `add()`, `delete()`, `list_rules()`, `find()` (Redis-cached via `Support\Cache` bucket `seo_redirects`), `on_post_updated()` (auto-301 on slug changes).
- `Seo\SitemapFilters::INDEXABLE_META` (`_motored_indexable`): `'1'` or `'0'` controls sitemap inclusion; brand hub query cached in Redis (`seo_sitemap` bucket).
- `Seo\HeadRenderer`: per-post meta keys `_motored_seo_title`, `_motored_seo_desc`, `_motored_seo_canonical`, `_motored_seo_robots` on `product`, `page`, `post`, `motored_bike`, `motored_tire_size`.

## Hooks and filters used
| Hook/filter | Context | Why | Verification status |
|---|---|---|---|
| `wp` (prio 20) | Frontend | `SiteContextBuilder::populate()` builds site-wide context when no specialized module set one | `partial` (VERIFY #124) |
| `pre_get_document_title` | Frontend | Applies `SeoContext` / per-post title overrides | `partial` (VERIFY #124) |
| `get_canonical_url` | Frontend | Emits clean base canonical URL (or per-post override) | `partial` (VERIFY #124) |
| `wp_robots` | Frontend | Enforces `noindex` on search, transactional pages, filtered archives, non-indexable landings; adds `max-image-preview:large` on indexable pages | `partial` (VERIFY #124) |
| `wp_head` | Frontend | Outputs `<meta name="description">`, OG/Product/Article/Twitter tags, and `@graph` JSON-LD | `partial` (VERIFY #124) |
| `woocommerce_structured_data_product` | Frontend | Enriches WooCommerce's native `Product` JSON-LD with brand, SKU/MPN, condition, seller and tire specs | `unverified` |
| `wp_sitemaps_add_provider`, `wp_sitemaps_posts_query_args`, `wp_sitemaps_posts_entry`, `wp_sitemaps_taxonomies_query_args` | Frontend | Removes `users` sitemap, excludes non-indexable/transactional/hidden items, adds `<lastmod>`, caches brand hub IDs in Redis | `unverified` (VERIFY #125) |
| `robots_txt` | Frontend | Disallows cart/checkout/account/filter/sort query strings and declares `/wp-sitemap.xml` | `unverified` |
| `template_redirect` (prio 1) | Frontend | Resolves Redis-cached 301/302 redirects ONLY on `is_404()` | `unverified` (VERIFY #126) |
| `post_updated`, `transition_post_status` | Admin / Cron | Auto-creates 301 redirect on slug change; invalidates `seo_sitemap` Redis bucket | `unverified` |
| `add_meta_boxes`, `save_post`, `admin_enqueue_scripts` | Admin | "Motored SEO" meta box with live Google SERP preview on `product`, `page`, `post`, `motored_bike`, `motored_tire_size` | `unverified` |
| `admin_post_motored_seo_{add,del}_redirect` | Admin | 301/302 Redirects Manager inside WooCommerce > Settings > Motored > SEO | own |

## Data model
- Option: `motored_seo` (`autoload=false`).
- Table: `{prefix}motored_seo_redirects` (migration `2026112405`, dropped on uninstall).
- Post meta: `_motored_seo_title`, `_motored_seo_desc`, `_motored_seo_canonical`, `_motored_seo_robots`, `_motored_indexable`.
- Redis cache buckets (`Support\Cache`): `seo_redirects` (1800 s TTL, bumped on add/delete) and `seo_sitemap` (3600 s TTL, bumped on post status/meta writes). Cache is never the source of truth; works cleanly with Redis down or flushed.

## Performance and security notes
- 0 frontend JS/CSS; 0 extra SQL queries on normal 200 page views; redirect DB lookup runs only on 404 cache misses.
- External SEO plugin auto-detection (`HeadRenderer::external_seo_plugin()`) steps back cleanly if Yoast, Rank Math, AIOSEO, SEOPress or TSF is activated so tags never duplicate.
- Compatible with Flatsome (`header.php` emits no duplicate meta/canonical/OG tags) and WooCommerce native `Product` structured data.
- All admin actions enforce `manage_woocommerce` / `edit_post` + nonces; shared `Admin\Ui` + `motored-admin.css` classes only.

## How to test
- Unit tests: `tests/Unit/Modules/Seo/SeoModuleTest.php`
- Staging: `docs/STAGING-CHECKLIST.md` + `docs/VERIFY.md` (#124–#126).

## Changelog
- 2.1.0 — 2026-11-26 — Phase 2 SEO: native Redis-cached Google Merchant Center XML feed (`/feed/motored-merchant/` via `MerchantFeed`), `hasMerchantReturnPolicy` (10 days AR default) + optional `shippingDetails` in `ProductSchemaEnhancer`, and per-term SEO overrides (`_motored_seo_title`, `_motored_seo_desc`, `_motored_seo_robots`) for `product_cat`, `product_brand`, and `motored_bike_brand`.
- 2.0.0 — 2026-11-26 — Full-site SEO upgrade: `SiteContextBuilder` (Home `AutoPartsStore`+`WebSite`/`SearchAction`, Products, Shop/Categories/Brands, Pages, Blog, Transactional `noindex,nofollow`), `ProductSchemaEnhancer` (Brand/MPN/NewCondition/specs), XML sitemap `<lastmod>` + `users`/hidden/transactional exclusions + Redis caching, `robots.txt` crawl-budget protection, Redis-cached 404 redirects + auto-301 on slug changes + admin Redirects Manager, and live Google SERP preview meta box across all public post types.
- 1.0.0 — 2026-11-24 — Initial core SEO module (`SeoContext`, `HeadRenderer`, `SitemapFilters`, `Redirects`, `SeoAdmin`).
