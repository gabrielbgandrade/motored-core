# Mi Garage

Mi Garage owns saved motorcycles, the active motorcycle, the WooCommerce account
page, authenticated REST mutations and WordPress personal data export and erasure.
It uses Compatibility for published motorcycle profiles and CatalogFilters can
offer saving a motorcycle through the existing browser integration.

- Account endpoint: `mi-garage` (registered on `init@0`, `query_vars`, `woocommerce_get_query_vars`, and `woocommerce_endpoint_mi-garage_title` in all contexts so CLI/cron/REST/AJAX flushes never strip it; emits `noindex, follow` via `wp_robots`; `admin_init` flushes on version bump or when pretty `rewrite_rules` lack `mi-garage`, with a 5-minute lock transient; activation resets `motored_compat_rewrite_ver`). Shortcode `[motored_mi_garage]` lets guests also list, switch and remove `localStorage` bikes.
- REST: GET and POST `motored/v1/garage` (profiles batch-hydrated via `MatchEngine::bike_profile_many()`), DELETE `motored/v1/garage/{slug}`.
  Bikes whose version was deleted/unpublished are left out of the response (not persisted, they may come back) and their labels are returned in `unavailable`; the garage page shows "%s ya no está en nuestro catálogo…".
- Analytics: when `analytics_events` is on, `compat-garage.js` depends on the shared `motored-ui` helper so `motoredUi.track()` exists.
- Header integration: fire `motored_garage_trigger` when rendering a link marked `data-motored-garage-open`, with the public garage URL as its `href`. Mi Garage mounts one native dialog before footer assets print, except on checkout. The drawer and full page share cards, mutations and notices. The drawer loads the cascade on expansion and refreshes the account garage on open.
- Theme API (`docs/THEME-CONTRACT.md`): `Motored\Core\Api::garage_url()` for the link, `window.Motored.garage.active()` / `.bikes()` plus the `motored:garage` event for the header label. `window.motoredGarage*` and the storage keys are private.
- Data: `_motored_garage` user meta. The existing Compatibility settings remain
  the configuration source for enablement and capacity.
- Browser storage: guest selections and account session caches use distinct keys.
  The legacy shared key could contain account data and is never uploaded or
  restored into the new browser cache. Server-saved motorcycles are retained.
- Uninstall: follows the plugin's existing opt-in metadata deletion policy.

Verification: test guest-to-account sync, two tabs, two devices, failed writes,
logout on a shared device, and personal data erasure. Use Classic and Block themes
and plain and pretty permalinks.

If a PHP process was forcibly terminated during a save and the account remains
locked, confirm that its request has stopped before deleting the corresponding
`motored_garage_lock_{user_id}` option.
