# CatalogFilters Module (`Motored\Core\Modules\CatalogFilters`)

Theme-agnostic motorcycle tire search, faceted sidebar filters and smart suggestions for **Motored Neumáticos**, cleanly integrated with `Motored\Core\Modules\Compatibility`.

## Features
- **Home Search `[motored_buscador layout="tabs|split"]`** (default `tabs`):
  - **By motorcycle:** reuses Compatibility REST endpoints (`/compat/brands`, `/models`, `/years`, `/bike/{slug}`) and saves to `Mi Garage`.
  - **By tire size:** any-order `Width` / `Aspect ratio` / `Rim` cascade 100% client-side using a versioned JSON cached in `localStorage` (`GET /motored/v1/filters/sizes?v=...`); imperial widths (`4.10-18`) disable `Aspect ratio` (`— no profile`); rim-only search supported.
  - **Smart search:** 250 ms debounced autocomplete (`GET /motored/v1/filters/suggest?q=`, min 2 chars, max 8 items) over cached arrays (sizes via `SizeParser`, `"rodado 17"`/`"r17"`, motorcycles + aliases, brands); zero DB `LIKE` queries; pressing Enter submits native WooCommerce search (`/?s=...&post_type=product`).
- **Sidebar Filters `[motored_filtros]` & Classic Widget (`Motored: Catalog Filters`):**
  - 11 disjunctive facets: `Posición`, `Marca`, `Ancho`, `Perfil`, `Rodado`, `Índice de carga`, `Índice de velocidad` (ETRTO order), `Construcción`, `Uso con cámara`, `Tipo de uso`, `Oferta`.
  - Native WooCommerce URLs (`?filter_{slug}=a,b&query_type_{slug}=or`, `?product_brand=a,b`, `?oferta=1`) combined with `?moto=` and `&pos=`.
  - Active filter chips with `×`, result counter, collapsible accessible accordions (`aria-expanded`), real anchor links (works without JS), 700 ms click batching on desktop (`>= 768px`), `"Apply filters"` button on mobile (`< 768px`).
- **Upgraded Compatibility Product Tab & `[motored_compat_list]`:**
  - Server-side rendered HTML cached by tire specification (`MatchEngine::compatible_motos_for_product()`), grouped by brand in 2 columns, collapsed initially (6 brands), inline cap of 120 items with on-demand REST hydration, instant alias-aware search and client-side `Mi Garage` highlight.

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
