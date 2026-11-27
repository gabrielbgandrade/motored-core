---
name: motored-module-catalog-filters
description: Context and invariants for the CatalogFilters module (home search, faceted sidebar filters, smart suggestions and upgraded Compatibility product list).
---

# Motored Module: CatalogFilters

## Location & Option
- Namespace: `Motored\Core\Modules\CatalogFilters` (`src/Modules/CatalogFilters/`)
- Option: `motored_catalog_filters` (`autoload = false`, `enabled = 'no'` by default)
- Assets: `assets/css/catalog-filters.css`, `assets/js/catalog-search.js`, `assets/js/catalog-filters.js` (vanilla JS, `defer`, enqueued only when rendered).

## Key Classes
- `Config` — typed option accessors (`enabled`, `only_in_stock`, `noindex_filtered`, `accent_color`, `default_layout`, `shop_url`) and facet/attribute constants.
- `FilterState` — pure parser & native Woo URL builder (`?filter_*=a,b&query_type_*=or`, `?product_brand=a,b`, `?oferta=1`, `?moto=`, `&pos=`). Keeps every numeric slug strictly as `string` (`"4-10"`, `"120"`), strips `paged` and `/page/N/`, preserves unknown query params.
- `FacetEngine` — 1-SQL parent-product snapshot (`post_parent = 0`, excludes `exclude-from-catalog`), O(N) disjunctive counts across all 11 facets, `size_combinations()` for imperial (`p = null`) and metric unit tires. Kits (`Posición = Kit`) never enter `Ancho`/`Perfil`/`Rodado`/`Carga`/`Velocidad`. `on_sale` is NEVER stored in snapshot (`wc_get_product_ids_on_sale()` per request).
- `SnapshotInvalidator` — anti-storm dirty flag with generation tracking and a 60s rebuild cooldown (`REBUILD_COOLDOWN = 60`) and `Cache::add()` lock (`LOCK_TTL = 30`). Never invalidates on price or stock-quantity updates.
- `QueryFilter` — intersects `?oferta=1` with existing `post__in` (preserving `?moto=`; empty -> `[0]`) and sets `noindex, follow` via `wp_robots`.
- `RestController` — public `GET motored/v1/filters/sizes` and `GET motored/v1/filters/suggest?q=` (0 DB `LIKE` queries, min 2 chars, max 8 items).
- `SearchShortcode` (`[motored_buscador layout="tabs|split"]`), `FilterRenderer` (`[motored_filtros]`), `FilterWidget` (`Motored: Catalog Filters`), `StorefrontAssets`, `CatalogFiltersAdmin`.

## Non-Negotiable Invariants
1. **Theme-agnostic:** zero theme-specific code, hooks, selectors or detection (Flatsome, Storefront, block themes alike).
   After an AJAX catalog swap dispatch `motored:filters-updated` (`detail.url`, `detail.root`); the theme re-attaches
   its behaviors and closes its off-canvas panel (`docs/THEME-CONTRACT.md`).
2. **Numeric slugs stay strings:** never cast term slugs to `int` (WordPress treats ints as term IDs).
3. **ERP-safe:** `in_stock` in `motored_compat_product_index` and `SnapshotInvalidator` only react to `woocommerce_product_set_stock_status`, tracked taxonomy changes, or publish/trash transitions — never price or quantity edits.
4. **Cache safety:** `Support\Cache` only when `Cache::is_persistent()` is true; never write transients to `wp_options` on public requests.
5. **Counts follow the grid:** `FilterRenderer::archive_scope()` passes `cat_slugs` (category + descendants) or `universe_ids` (search / other product taxonomy archives) to `FacetEngine::compute_facets()`; out-of-stock exclusion in the sidebar follows `woocommerce_hide_out_of_stock_items` (`Config::grid_hides_out_of_stock()`), the module's `only_in_stock` only drives search sizes/suggestions.
6. **Shared front-end code:** cascades, text normalization and `dataLayer` events live in `assets/js/motored-ui.js` (`StorefrontAssets::enqueue_ui_js()`); search normalization in PHP is `Support\SearchText` (keep both rules identical). Styles: `compat.css` holds the tokens (`--motored-accent` reads `--motored-accent-color`), `catalog-filters.css` depends on it.
7. **No reload per click** in the sidebar: options are links (no-JS fallback); with JS they batch into "Apply filters (N)".
