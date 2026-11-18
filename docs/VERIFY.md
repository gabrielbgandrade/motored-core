# VERIFY — unverified or partially verified assumptions

Status values: `verified` (confirmed in stubs/source in this repo's dev environment), `partial`, `unverified`.
Every `unverified`/`partial` row must be checked on a real WordPress (see `STAGING-CHECKLIST.md`).

| # | Item | Where used | Status | How to verify on real WordPress |
|---|------|-----------|--------|---------------------------------|
| 1 | Filter `woocommerce_get_settings_pages` (applied in `WC_Admin_Settings::get_settings_pages()`; read in WC trunk source, not in stub code) | `Plugin::register_settings_page` | partial | Open WC > Settings: tab "Motored" appears. With Query Monitor on a public page confirm `woocommerce_get_settings_pages` never fires and `Admin\SettingsTab` is not loaded (`get_included_files()`). |
| 2 | Filter runs only on WooCommerce settings screens (assumed; WC may call `get_settings_pages()` elsewhere, e.g. REST/Admin) | `Plugin::boot` (hook added only in admin context) | unverified | `error_log` inside the callback temporarily; browse wp-admin and REST; confirm it fires only for wc-settings. |
| 3 | `WC_Settings_Page` extension points: `$id`, `$label`, `get_own_sections()` (protected), `get_settings_for_section_core()` (protected), `output()`, `save()` | `Admin\SettingsTab` | verified (stubs + trunk source) | Sections General/Status render; saving General persists. |
| 4 | New React "Settings UI" does not hijack our classic-rendered tab (`get_settings_ui_request_context`) | `Admin\SettingsTab` | unverified | Open each section; confirm classic form/table renders and Save works. |
| 5 | Fields with `'autoload' => false` and ids `motored_general[key]` are saved into one array option with autoload `no` (save path read in `WC_Admin_Settings::save_fields`) | `SettingsTab::general_settings` | partial | Save settings, then `wp option get motored_general --format=json` and check `autoload` in `wp_options`. |
| 6 | Reading `id[key]` fields in the form (`WC_Admin_Settings::get_option` bracket handling) shows stored values and defaults | `SettingsTab::general_settings` | unverified | Change "Log level", save, reload: value persists; checkbox state persists. |
| 7 | `$GLOBALS['hide_save_button'] = true` hides the Save button for the Status section | `SettingsTab::output` | partial (WC sets it itself in `output()`) | Open Status: no Save button. |
| 8 | `FeaturesUtil::declare_compatibility()` signature | `motored-core.php` | verified (stubs) | — |
| 9 | Feature ids `custom_order_tables` and `cart_checkout_blocks` | `motored-core.php` | unverified | WC > Settings > Advanced > Features lists no incompatibility warning for Motored Core. |
| 10 | Action `before_woocommerce_init` (referenced in stub docblock) | `motored-core.php` | partial | HPOS compatibility check above. |
| 11 | Action Scheduler functions (`as_enqueue_async_action`) are NOT in the stubs | `SettingsTab::render_status` (via `function_exists`) | unverified | Status section shows "Action Scheduler available: Yes". Stage 2+ adapter goes in `src/Integration/`. |
| 12 | `wc-ajax` is signaled by `$_GET['wc-ajax']` before `init` | `Context::is_ajax` | partial (stub docblock mentions the arg) | Hit `/?wc-ajax=get_refreshed_fragments` with a debug log in a test module; `is_ajax()` is true. |
| 13 | Early REST detection by URL prefix (`rest_get_url_prefix()` is core) | `Context::is_rest` | verified (stubs) for the function; behavior with pretty-permalink-less sites unverified | Request `/wp-json/` and `/?rest_route=/`; `is_rest()` true in both. |
| 14 | `wc_get_logger()->log( $level, $msg, array( 'source' => ... ) )` | `Logger::log` | verified (stubs) | WC > Status > Logs shows source `motored-core`. |
| 15 | Interaction with WooCommerce's own `WC_LOG_THRESHOLD` | `Logger` | unverified | Set a stricter threshold constant; confirm entries are filtered by WC. |
| 16 | Redis drop-in atomicity of `wp_cache_add` / `wp_cache_incr` and by-ref `$found` support | `Support\Cache` | unverified | With the production drop-in run two parallel `wp eval` calls using `Cache::add` for the same key; one must return true. |
| 17 | `set_transient()` with expiry stores `autoload=no` rows | `Cache` fallback | unverified (WP core behavior from memory) | Disable object cache, trigger a `Cache::set`, inspect `wp_options` autoload column. |
| 18 | `Requires Plugins: woocommerce` header enforced by WP 6.5+ | `motored-core.php` | unverified | Try activating without WooCommerce: WP blocks it; our own notice covers deactivation later. |
| 19 | Minimum supported WooCommerce version | whole plugin | unverified | Decide from staging; add `WC requires at least` header. |
| 20 | `load_plugin_textdomain` finds `languages/motored-core-es_AR.mo` and `determine_locale()` is es_AR in admin | `Plugin::load_textdomain` | partial (signature in stubs) | Switch user/site locale to es_AR: all strings translated; check WC > Settings > Motored. |
