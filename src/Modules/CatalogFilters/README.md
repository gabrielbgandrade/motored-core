# CatalogFilters Module (`Motored\Core\Modules\CatalogFilters`)

Theme-agnostic motorcycle tire search, faceted sidebar filters and smart suggestions for **Motored Neumáticos**, cleanly integrated with `Motored\Core\Modules\Compatibility`.

## Features
- **Home Search `[motored_buscador layout="tabs|split"]`** (default `tabs`):
  - **By motorcycle:** reuses Compatibility REST endpoints (`/compat/brands`, `/compat/brands/{id}/models`, `/compat/models/{id}/years`, `/compat/bike/{slug}`), shows loading/empty/error states inline, and saves to `Mi Garage`.
  - **By tire size:** any-order `Width` / `Aspect ratio` / `Rim` cascade 100% client-side using `GET /motored/v1/filters/sizes` with HTTP caching and one load per page (the versioned route remains available); imperial widths (`4.10-18`) disable `Aspect ratio` (`— no profile`); rim-only search supported.
  - **Smart search:** 250 ms debounced autocomplete (`GET /motored/v1/filters/suggest?q=`, min 2 chars, max 8 items) over cached arrays (sizes via `SizeParser`, `"rodado 17"`/`"r17"`, motorcycles + aliases, brands); zero DB `LIKE` queries; pressing Enter opens a unique exact match or submits native WooCommerce search (`/?s=...&post_type=product`).
  - **Responsive layout:** container-query-driven `split` mode stacks cleanly under `780px`, renders 3-column field rows (`2x2` when a variant selector opens) without clipping or orphan inputs, and aligns styled action buttons.
- **Sidebar Filters `[motored_filtros]` & Classic Widget (`Motored: Catalog Filters`):**
  - 11 disjunctive facets: `Posición`, `Marca`, `Ancho`, `Perfil`, `Rodado`, `Índice de carga`, `Índice de velocidad` (ETRTO order), `Construcción`, `Uso con cámara`, `Tipo de uso`, `Oferta`.
  - Native WooCommerce URLs (`?filter_{slug}=a,b&query_type_{slug}=or`, `?product_brand=a,b`, `?oferta=1`) combined with `?moto=` and `&pos=`.
  - With a motorcycle selected, the compatibility bar and sidebar share one `pos=front|rear|kit` selection. Sidebar position counts use compatible product IDs and respect the other catalog filters. Legacy single-axis links normalize to `pos`; legacy multi-position or combined-axle links keep their taxonomy results until a position is selected in the compatibility bar.
  - Kits by size: when the filters name exactly one size (one width, one rim, at most one profile) and front + rear kits include it, the panel links to `?kit_medida=130/70-17` ("There are also N front + rear kits with 130/70-17"). That view drops the unit-only facets (width, profile, rim, load, speed), keeps brand/sale/motorcycle, intersects `post__in` with `ProductIndexRepository::kit_ids_with_size()` and restricts facet counts the same way (`kit_ids` context). Kits never enter the dimension facets themselves.
  - Active filter chips with `×`, result counter, native collapsible accordions, real anchor links (works without JS); with JS, ticks are batched and applied with a sticky `"Apply filters (N)"` button on every width (no reload per click).
- **Upgraded Compatibility Product Tab & `[motored_compat_list]`:**
  - Server-side rendered HTML cached by tire specification (`MatchEngine::compatible_motos_for_product()`), grouped by brand in 2 columns, collapsed initially (6 brands), inline cap of 120 items with on-demand REST hydration, instant alias-aware search and client-side `Mi Garage` highlight.

## Theme contract
- After an AJAX catalog swap the module dispatches `motored:filters-updated` (`detail.url`, `detail.root`). The theme re-attaches its own behaviors and closes its off-canvas filter panel; the module never calls theme code.
- `catalog-filters.css` owns the structure of the search, the filters and the smart-search panels (`.motored-suggest__*`, `.motored-trending__*`); colors, radii and shadows come from the `--motored-*` tokens set by the theme.

## Option & Defaults
- Option: `motored_catalog_filters` (`autoload=false`), disabled at launch (`enabled = 'no'`).
- `only_in_stock = 'yes'` (always respects WooCommerce's `woocommerce_hide_out_of_stock_items` and catalog visibility).
- `noindex_filtered = 'yes'` (`noindex, follow` via `wp_robots` on filtered URLs).
- `accent_color = ''` (optional hex color feeding `--motored-accent`).

## Performance & ERP Safety
- `CatalogFiltersModule::register()` performs 0 DB queries, 0 option reads and 0 asset enqueues.
- Pages without `[motored_buscador]`, `[motored_filtros]`, `FilterWidget` or `[motored_compat_list]` load 0 CSS and 0 JS.
- `FacetEngine` uses `Support\Cache` ONLY when `Cache::is_persistent()` is true; on miss builds in 1 SQL query over parent products. `on_sale` is resolved live via `wc_get_product_ids_on_sale()`.
- `SnapshotInvalidator` never invalidates on ERP price or stock-quantity updates; uses a dirty flag + 60-second cooldown + concurrency lock.

The sidebar and search set control margins on their own classes. Keep spacing in the accordion padding and the footer, tab, or field gaps so generic theme form rules cannot add another margin.

## Changelog
- 2026-11-24 — Added cached/memoized search snapshot (`FacetEngine::SNAPSHOT_SEARCH_KEY`, `SnapshotInvalidator` search variant tracking), deterministic `size_combinations()` ordering (B7), and `replace_native_layered_nav` setting via `LayeredNavHooks`.
- 2026-11-20 — Storefront review fixes: lazy REST loading of the search panels (`IntersectionObserver` via `motored-ui.js`), stock counts in the size selects ("120 (14)"), keyboard tabs (arrows/Home/End, roving tabindex), WAI-ARIA combobox for smart search (arrow keys, `aria-activedescendant`, live count), smart Enter (exact size/rim/single motorcycle match goes straight to the filtered shop; otherwise native search), accent/spacing-insensitive suggestions (`Support\SearchText`, cache key `suggest_bikes_v2`), old `motored_sizes_*` localStorage keys cleaned. Sidebar: no reload per click — "Apply filters (N)" sticky button on every width, accordion expansion initialized from active filters; facet counts follow the grid (category archives include child categories, search / brand / tag / attribute archives use the main query base set, out-of-stock follows `woocommerce_hide_out_of_stock_items`); filter links keep the current product taxonomy archive. Styles: `catalog-filters.css` depends on the shared `compat.css` tokens.
