# Module: Seo

## Purpose
Lightweight WordPress-core SEO layer (`Modules\Seo`) providing `<title>`, meta descriptions, canonical URLs, `wp_robots`, Open Graph/Twitter tags, XML sitemap filtering, 301/302 redirects on 404s, and JSON-LD structured data (`BreadcrumbList`, `ItemList`, `FAQPage`, `Motorcycle`) without heavy third-party plugin overhead.

## Public contracts
- `Seo\Config`: typed accessors for option `motored_seo` (`autoload=false`).
- `Seo\SeoContext`: `set()`, `get()`, `reset()` + filter `motored_seo_context`.
- `Seo\Redirects`: `add( string $source_path, string $target_url, int $status_code = 301 )`, `find()`.
- `Seo\SitemapFilters::INDEXABLE_META` (`_motored_indexable`): `'1'` or `'0'` controls sitemap inclusion on `motored_bike` and `motored_tire_size`.
- `Seo\HeadRenderer`: meta keys `_motored_seo_title`, `_motored_seo_desc`, `_motored_seo_robots`.

## Hooks and filters used
| Hook/filter | Context | Why | Verification status |
|---|---|---|---|
| `pre_get_document_title` | Frontend | Applies `SeoContext` / per-post title overrides | `partial` (VERIFY #124) |
| `get_canonical_url` | Frontend | Emits clean base canonical URL | `partial` (VERIFY #124) |
| `wp_robots` | Frontend | Enforces `noindex, follow` on search, `?paged > 1`, and non-indexable landing pages | `partial` (VERIFY #124) |
| `wp_head` | Frontend | Outputs `<meta name="description">`, OG/Twitter tags, and `@graph` JSON-LD | `partial` (VERIFY #124) |
| `wp_sitemaps_posts_query_args` | Frontend | Excludes non-indexable `motored_bike` / `motored_tire_size` posts | `unverified` (VERIFY #125) |
| `wp_sitemaps_taxonomies_query_args` | Frontend | Excludes `motored_bike_brand` terms with zero indexable bikes | `unverified` (VERIFY #125) |
| `template_redirect` | Frontend | Checks `motored_seo_redirects` and `_motored_prev_slug` ONLY on `is_404()` | `unverified` (VERIFY #126) |
| (lazy) `HeadRenderer::external_seo_plugin()` | Frontend | With Yoast/Rank Math/AIOSEO/SEOPress/TSF active, title, description, canonical, OG and the `WebPage`/`BreadcrumbList` nodes are left to that plugin; we keep robots rules + `ItemList`/`FAQPage`. Filter `motored_seo_external_plugin` | `unverified` (VERIFY #131) |
| `add_meta_boxes`, `save_post` | Admin | Per-post SEO overrides on `motored_bike` and `motored_tire_size` | `unverified` |

## Data model
- Option: `motored_seo` (`autoload=false`).
- Table: `{prefix}motored_seo_redirects` (migration `2026112405`, dropped on uninstall).
- Post meta: `_motored_seo_title`, `_motored_seo_desc`, `_motored_seo_robots`, `_motored_indexable`.

## Configuration keys
Edited in WooCommerce > Settings > Motored > SEO:
- `enabled` (`yes`), `noindex_paged` (`no`; paged archives keep a self-referencing `/page/N/` canonical), `noindex_search` (`yes`), `og_enabled` (`yes`), `schema_enabled` (`yes`), `default_og_image` (`''`).

## Main flows
1. Renderers (e.g. `BikeRenderer`, `HubLinks`, `SizePages`) call `SeoContext::set()` at `wp`.
2. `HeadRenderer` merges `SeoContext` with post meta overrides on `is_singular( ['motored_bike', 'motored_tire_size'] )` and prints head tags/JSON-LD (stripping `Product` schema on landing pages and empty `FAQPage` nodes).
3. On 404 responses, `Redirects::maybe_redirect()` looks up `motored_seo_redirects` or `_motored_prev_slug`.

## Performance and security notes
- 0 DB queries on unrelated 200 requests; redirect lookup runs only when `is_404()`.
- All admin fields sanitized; JSON-LD encoded via `wp_json_encode()`.

## How to test
- Unit tests: `tests/Unit/Modules/Seo/` (`SeoModuleTest`, `SeoContextTest`, `HeadRendererTest`, `RedirectsTest`, `SitemapFiltersTest`).
- Staging: `docs/STAGING-CHECKLIST.md` + `docs/VERIFY.md` (#124–#126).

## Known limitations
- Redirects management UI is not built yet; redirects are populated automatically by `BikePageSync`.

## Changelog
- 1.0.0 — 2026-11-24 — Initial core SEO module (`SeoContext`, `HeadRenderer`, `SitemapFilters`, `Redirects`, `SeoAdmin`).
