# Module: Compatibility

## Purpose
Motorcycle-to-tire compatibility engine and Mi Garage experience without linking motorcycles inside products. Motorcycles map to canonical tire sizes (`motored_compat_*`), products expose native WooCommerce tire attributes synced into a lightweight index (`motored_compat_product_index`), and page-cache-safe client-side hydration evaluates compatibility from `<span hidden data-motored-fit>` markers.

## Public contracts
- `SizeParser::parse( string $raw )`, `SizeParser::format_full_size()` (pure canonicalizer preserving imperial trailing zeroes as strings).
- `TireSpecs::speed_rank()`, `TireSpecs::attribute_order_map()`, `TireSpecs::normalize_position()`, `TireSpecs::normalize_construction()`.
- `MatchEngine::evaluate_product()`, `MatchEngine::bike_profile()`, `MatchEngine::matching_product_ids()`, `MatchEngine::compatible_motos_for_product()`.
- Shortcode `[motored_compat_selector mode="shop|garage"]`.
- REST namespace `motored/v1`: public `/compat/brands`, `/compat/models`, `/compat/years`, `/compat/bike/{slug}`, `/compat/product/{id}/motos`; authenticated `/garage`, `/garage/{slug}`.
- My Account endpoint `mi-garage`.

## Hooks and filters used
| Hook/filter | Context | Why | Verification status |
|-------------|---------|-----|------|
| `wc_create_attribute` / `wp_insert_term` (`AttributeHooks`) | admin / cli | Two-step global attribute & fixed-term installation | partial (#93) |
| `taxonomy_exists( 'product_brand' )` | admin | Native WooCommerce brand detection | partial (#94) |
| `woocommerce_after_product_object_save`, `set_object_terms`, `transition_post_status`, `wp_trash_post`, `before_delete_post` | admin / rest / ajax / cron / cli | ERP-safe hash-guarded product index sync | unverified (#95) |
| `woocommerce_product_query`, `query_loop_block_query_vars`, `woocommerce_layered_nav_link` | frontend / ajax | `?moto=` + `&pos=` filtering & attribute link preservation | unverified (#96) |
| `the_posts`, `woocommerce_before_shop_loop`, `woocommerce_after_shop_loop_item_title`, `woocommerce_single_product_summary` | frontend / ajax | Single-query batch priming, active bike bar, cache-safe markers | unverified (#97) |
| `woocommerce_product_tabs`, `woocommerce_product_additional_information_heading`, `woocommerce_display_product_attributes` | frontend | Technical information tab rename, Full size row, lazy Compatibility tab | unverified (#98) |
| `woocommerce_product_options_general_product_data`, `woocommerce_admin_process_product_object` | admin | Optional `_motored_full_size` simple product meta field | unverified (#99) |
| `add_rewrite_endpoint( 'mi-garage' )`, `woocommerce_account_menu_items`, `woocommerce_account_mi-garage_endpoint`, `wp_logout` | frontend / admin | Mi Garage account tab and logout localStorage cleanup | unverified (#100) |
| `wp_robots` | frontend | Adds `noindex, follow` on `?moto=` filtered URLs | partial (#101) |
| `wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers` | admin / ajax | Export/erase `_motored_garage` user meta | unverified (#102) |

## Data model
- Option `motored_compatibility` (`autoload = false`): `enabled`, `max_garage_bikes`, `strict_specs`, `show_incompatible_badge`, `rename_tech_tab`, `show_compat_tab`, `auto_fill_dimensions`, `disclaimer`, `shop_url`.
- Option `motored_compat_rewrite_ver` (`autoload = false`), `motored_compat_last_import`, `motored_compat_import_job_<id>` (temporary batch staging).
- Tables (`Migration 2026112001`): `motored_compat_brands`, `motored_compat_models`, `motored_compat_versions` (`year_to = 0` sentinel for present, immutable `slug`), `motored_compat_sizes`, `motored_compat_fitments`, `motored_compat_equivalences`, `motored_compat_product_index` (`product_id` PK, no `variation_id`).
- Meta keys: `_motored_garage` (`user_meta`), `_motored_full_size` (`product` post meta).
- Cache buckets (`CompatCache`, persistent object cache only): `compat_catalog` (TTL 6h), `compat_matches` (TTL 1h). Never writes transients to `wp_options` when Redis is inactive.

## Configuration keys
Edited in **WooCommerce > Ajustes > Motored > Compatibilidad** (`enabled` default `no`; `max_garage_bikes` `5`; `strict_specs` `no`; `show_incompatible_badge` `no`; `rename_tech_tab` `yes`; `show_compat_tab` `yes`; `auto_fill_dimensions` `no`; `disclaimer` empty -> translated default; `shop_url` empty -> WC shop URL). No secrets.

## Main flows
1. **Product sync (ERP-safe):** Product save or `set_object_terms` on our 6 indexed taxonomies -> `ProductIndexSync::mark_dirty()` -> `shutdown` (<= 20) or AS `motored_compat_reindex` (> 20) computes MD5 `terms_hash` -> exits early if unchanged (ERP price/stock saves write 0 rows); otherwise updates `motored_compat_product_index` and optionally fills empty `ancho`/`perfil`/`rodado` on single tires (`never` on kits).
2. **Matching (`MatchEngine`):** `bike_profile( slug )` loads direct `oem`/`alt` fitments + 1-hop directed equivalences originating ONLY from `oem` fitments (non-transitive). Kits match iff BOTH front and rear axles pass.
3. **Storefront (cache-safe):** Public HTML is identical for all visitors (`<span hidden data-motored-fit>`), `the_posts` primes loop products in 1 SQL query, `compat-garage.js` evaluates badges client-side from `localStorage` (0 REST requests when browsing, 12h TTL revalidation), and `compat-tab.js` lazy-loads compatible bikes via `IntersectionObserver` only when the Compatibility tab opens.

## Performance and security notes
- `register()` executes 0 DB queries and enqueues 0 scripts. `compat-garage.js` (3,795 B) only enqueues on WC shop/archive/product/account pages when enabled; `compat-selector.js` (2,695 B) only on shortcode render; `compat-tab.js` (2,454 B) only inside the Compatibility tab; `compat-garage-page.js` (1,409 B) only on `mi-garage`.
- Admin screens check `manage_woocommerce` + nonces and use `Admin\Ui` / `motored-admin.css` exclusively. All SQL uses `$wpdb->prepare()` with `Db::table()`. Authenticated garage REST routes verify `is_user_logged_in()` + `wp_rest` nonce.

## How to test
`tests/Unit/Modules/Compatibility/` (`SizeParserTest`, `TireSpecsTest`, `CompatibilityMigrationTest`, `CompatibilityModuleTest`, `MatchEngineTest`, `JsonImporterTest`, `GarageRepositoryTest`, `ProductIndexSyncTest`, `StorefrontTabsTest`, `PrivacyTest`). Staging: `docs/STAGING-CHECKLIST.md` (Compatibility + Mi Garage).

## Known limitations
- Supports simple products only (including simple-product kits/combos; variable products are out of scope per store catalog decision).
- JSON import only (no CSV).
- `pa_*` global attributes and terms are preserved on uninstall so WooCommerce product catalogs remain intact.

## Changelog
- 0.1.0 — 2026-11-19 — Phases 1–7 initial implementation.
