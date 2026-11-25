---
name: motored-module-compatibility
description: Rules for the Compatibility + Mi Garage module (tire size parser, global attributes, ERP-safe product index, directed equivalences, JSON import/export, ?moto= filter, 3 product tabs, Mi Garage). Use when editing src/Modules/Compatibility or compat JS assets.
---

# Module Compatibility
Read `src/Modules/Compatibility/README.md` and `docs/COMPATIBILITY-PHASES.md` first.
Mi Garage owns account state and privacy in `src/Modules/MiGarage/`.

- **Simple products only (units + kits):** `motored_compat_product_index` uses `product_id` PK (never `variation_id`). Kits (`Posición = Kit`) NEVER copy `ancho`/`perfil`/`rodado` or infer `min(front, rear)` load/speed.
- **Kit sheet:** per-axle load/speed/construction/tube live ONLY in `_motored_tire_spec` (`KitSpec`), never in `indice-carga`/`indice-velocidad` terms (CatalogFilters facets exclude kits; a term would desync the native filter from the counts). Construction/tube terms only when both axles agree; model may have two terms. The index trusts the sheet only while `KitSpec::matches_sizes()` holds; the sheet is part of `terms_hash`. Marker keys `fc`/`rc` are optional and `ev()` falls back to `c`.
- **Sizes & ETRTO order:** Always treat imperial widths (`4.10-18`, `3.00-18`) as strings (`SizeParser`). `pa_indice-velocidad` uses `menu_order` (non-alphabetical ETRTO ranks); `pa_medida-delantera`/`pa_medida-trasera` use `name` (never `name_num`).
- **Equivalences:** Directed (`source -> target`), 1-hop ONLY from `oem` fitments, never transitive.
  When two OEM routes have the same target, prefer the exact route, then the canonical OEM size, in both lookup directions before testing requirements.
- **Performance & Redis:** `register()` reads nothing; `CompatCache` uses `Support\Cache` ONLY when `Cache::is_persistent()` is true. Product sync hashes our 6 taxonomies so ERP price/stock updates write 0 rows.
- **Page-cache safety & JS:** `<span hidden data-motored-fit>` keeps HTML identical; keep `assets/js/compat-*.js` strictly vanilla `defer` within byte limits.
- **Admin UI & Uninstall:** `CompatibilityAdmin` must use `Admin\Ui` + `motored-admin.css` only (`SharedAdminUiTest`); `uninstall.php` drops `motored_compat_*` tables + `_motored_garage` user meta, NEVER WooCommerce `pa_*` attributes/terms.
- **PHP/JS parity:** `ev()` in `compat-garage.js` mirrors `MatchEngine::evaluate_product()`; any rule change updates both plus `tests/fixtures/compat-cases.json` (`FrontendFixesTest`, `node tests/js/compat-parity.test.js`).
- **Storefront rules (2026-11-20 review):** bar also on `woocommerce_no_products_found`, printed once (`Storefront::$bar_rendered`); block themes via `render_block_woocommerce/product-collection|product-price` (VERIFY #115) — no theme hooks/selectors; "Complete kit" only when the PRODUCT position is kit (`CompatListRenderer::position_label()`); product page always honest about the active bike (`show_incompatible_badge` = catalog cards only); tab REST returns server-built `rows` (never re-format in JS); `?moto=` IDs cached best fit first (`mids2_`) + `orderby=post__in` only without a shopper sorting.
- After changes: `composer check`, `motored-review`, update `docs/VERIFY.md` (#93–#103) and `docs/PROJECT.md`.
