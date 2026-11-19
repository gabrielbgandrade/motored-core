---
name: motored-module-compatibility
description: Rules for the Compatibility + Mi Garage module (tire size parser, global attributes, ERP-safe product index, directed equivalences, JSON import/export, ?moto= filter, 3 product tabs, Mi Garage). Use when editing src/Modules/Compatibility or compat JS assets.
---

# Module Compatibility
Read `src/Modules/Compatibility/README.md` and `docs/COMPATIBILITY-PHASES.md` first.

- **Simple products only (units + kits):** `motored_compat_product_index` uses `product_id` PK (never `variation_id`). Kits (`Posición = Kit`) NEVER copy `ancho`/`perfil`/`rodado` or infer `min(front, rear)` load/speed.
- **Sizes & ETRTO order:** Always treat imperial widths (`4.10-18`, `3.00-18`) as strings (`SizeParser`). `pa_indice-velocidad` uses `menu_order` (non-alphabetical ETRTO ranks); `pa_medida-delantera`/`pa_medida-trasera` use `name` (never `name_num`).
- **Equivalences:** Directed (`source -> target`), 1-hop ONLY from `oem` fitments, never transitive.
- **Performance & Redis:** `register()` reads nothing; `CompatCache` uses `Support\Cache` ONLY when `Cache::is_persistent()` is true. Product sync hashes our 6 taxonomies so ERP price/stock updates write 0 rows.
- **Page-cache safety & JS:** `<span hidden data-motored-fit>` keeps HTML identical; keep `assets/js/compat-*.js` strictly vanilla `defer` within byte limits.
- **Admin UI & Uninstall:** `CompatibilityAdmin` must use `Admin\Ui` + `motored-admin.css` only (`SharedAdminUiTest`); `uninstall.php` drops `motored_compat_*` tables + `_motored_garage` user meta, NEVER WooCommerce `pa_*` attributes/terms.
- After changes: `composer check`, `motored-review`, update `docs/VERIFY.md` (#93–#103) and `docs/PROJECT.md`.
