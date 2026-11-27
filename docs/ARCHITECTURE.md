# Architecture

## Layers
1. **Bootstrap** (`motored-core.php`): constants, autoloader, HPOS/Blocks compatibility, activation hook,
   `plugins_loaded@20` -> `Plugin::instance()->boot()`. This registers the core and the module entry points
   on public requests.
2. **Core** (`Plugin`, `Contracts\Module`): lifecycle and module registry.
3. **Support** (`Context`, `Options`, `Logger`, `Cache`, `Db`, `Migrations`): small, static-friendly
   helpers loaded on demand by the autoloader.
4. **Admin** (`SettingsTab`, `SectionRegistry`): only autoloaded inside WooCommerce settings screens.
5. **Integration** (`src/Integration/`): adapters for anything unverified (WC hooks, Mercado Pago,
   WAHA, Action Scheduler). Modules talk to adapters, never to raw third-party hooks.
6. **Modules** (`src/Modules/`): functional units. See `Plugin::MODULES` for the current registry.

### Engine and skin
Motored is one product in two packages: this plugin (engine) and the store theme (skin). The plugin renders
semantic markup with public BEM classes, behavior JS and **structural** CSS whose visual values come from
`--motored-*` input tokens; the theme sets those tokens on `:root` and owns the look and placement. The two
meet only at the surface in `docs/THEME-CONTRACT.md` (`Motored\Core\Api`, shortcodes, BEM classes,
`window.Motored`, `motored:*` events, `motored_garage_trigger`). `ThemeContractTest` guards the plugin side.

Public components own the spacing of their controls. Set the margins of buttons and fields through Motored component selectors, and use the wrapper's `gap` or padding for layout. This keeps spacing stable across themes while preserving native controls and WooCommerce button styles elsewhere. Avoid global resets for `button`, `input`, and `.button`.

## Lifecycle
- **Activation:** `Plugin::activate()` -> `Options::ensure('general')` (autoload=false) -> `Migrations::run()`.
- **Every request:** `plugins_loaded@20` -> `boot()`:
  1. hook `init` -> `load_textdomain` (no translation function runs earlier);
  2. if WooCommerce is absent: add an `admin_notices` hook (es_AR) and STOP;
  3. register in-memory defaults of the `general` options (no I/O);
  4. admin context only: hook `admin_init` -> migrations, and the `woocommerce_get_settings_pages` filter;
  5. instantiate configured modules, call `should_load( Context )`, `register()` only for those approved.
- **Uninstall:** `uninstall.php` deletes `motored_*` options and `motored_*` transients only if
  "Delete data on uninstall" is enabled.

## Contexts

Schema upgrades run during activation or on interactive admin screens for users with
`manage_woocommerce`. An insert-only lock in the options table serializes upgrades across
requests. If the PHP process was forcibly terminated and the lock remains, confirm no
upgrade is running before removing `motored_schema_lock` with WP-CLI or the site's database
administration tools. The next eligible admin request retries the pending migration.

InstallGallery queues its upgrade backfill in 50-product slices. The pending option lets an
interactive admin request restart an interrupted backfill. Action Scheduler jobs use a single
`motored-core` group for deactivation and uninstall.
`Context` exposes `is_admin`, `is_ajax` (admin-ajax and `wc-ajax`), `is_rest` (early URL detection,
because `REST_REQUEST` is defined late), `is_cron`, `is_cli`, `is_frontend`. Evaluation is lazy and memoized.
Modules use it inside `should_load()` so their hooks exist only where needed.

## How a module plugs in
1. Create `src/Modules/<Name>/<Name>Module.php` implementing `Module`.
2. Add the class to `Plugin::MODULES`.
3. `should_load()` is cheap (Context only). `register()` adds hooks; admin settings via
   `SectionRegistry::add()` (only when `is_admin()`).
4. Tables: add a migration to `Plugin::migrations()` (`'YYYYMMDDNN' => array( Class::class, 'up' )`).
   Migrations run only on activation / `admin_init`, never on the frontend.

## Data flow (target design for upcoming modules)
Customer request (checkout, cart events) -> record minimal data (DB / WC CRUD) -> enqueue an
Action Scheduler action -> worker (cron/async runner) calls external services (WAHA) -> result is
logged (masked) and stored as an event. No external HTTP ever happens inside a customer request.

## Cache strategy
- `Support\Cache` wraps `wp_cache_*` (group `motored`) with versioned keys
  `motored:{schema}:{namespace}:{nsversion}:{key}`; `bump( $namespace )` invalidates a namespace
  without `wp_cache_flush_group()`.
- Persistent object cache (Redis): used directly. Otherwise transients (DB) with TTL capped at
  30 days so they are never autoloaded. Use only off the public request path.
- Cache is an optimization only: every read path has a source-of-truth fallback.
- `add()` locks and `incr()` counters are atomic only with a persistent cache; never rely on them for correctness.

## Queues
Action Scheduler only (no custom queue table, no WP-Cron events of our own). Scheduling happens in
event handlers or admin, never on frontend page views. Availability is reported on the Status section.

## Cart capture flow (Recovery module, part A)
Checkout (classic) -> one deferred script posts e-mail/phone/name (debounced, only if changed) -> `wc-ajax=motored_capture`
(nonce, rate limit) -> validate -> read the cart ON THE SERVER -> `CaptureService` upsert in `motored_recoveries` + event in
`motored_recovery_events`. Logged-in cart changes capture at `shutdown` (5 min throttle). Order creation marks `converted`.
"Abandoned" is derived on read. A daily Action Scheduler job purges old rows (batches of 500). No messages yet.

## Unpaid-order recovery flow (Recovery module, part C)
Order created / status changed (pending, failed, on-hold) -> `OrderSync` (lookup + write, no HTTP) -> one `type=order` row (an active cart row of the same
session/e-mail is converted) with `next_action_gmt` from the case sequence -> scanner enqueues `recovery_step` -> `OrderStepHandler` reloads the order and decides
(`OrderRecovery::decide`: paid / expire / restore / send) -> WhatsApp via `Sender` or `PendingPaymentEmail`; each send adds a private order note.
Paid status -> `recovered` (message delivered first) or `converted`.

## WhatsApp send flow (Waha module)
Module -> `Sender::enqueue( kind, id, step, delay )` -> Action Scheduler (`motored_waha_send`, group `motored-core`)
-> worker: breaker, rate limit, handler(kind) builds `Message` -> `ChatResolver` (check-exists, cached chat id) ->
`WahaClient::sendText` -> retry/backoff or `motored_waha_result`. Only the two admin test buttons make sync HTTP.
The core filter `motored_status_rows` lets modules add rows to the Status section.

## Compatibility + Mi Garage flow (Compatibility module)
Product save / `set_object_terms` on tire taxonomies -> `ProductIndexSync` (`shutdown` <= 20, AS `motored_compat_reindex` > 20; MD5 `terms_hash` skips ERP price/stock updates) -> `motored_compat_product_index` (`product_id` PK; kits read per-axle ratings from the `_motored_tire_spec` meta, which is part of the hash). Storefront remains 100% page-cache safe: `the_posts` batch-primes markers, `<span hidden data-motored-fit>` prints uniform HTML, `compat-garage.js` matches client-side from `localStorage` (0 REST calls during browsing, 12 h TTL revalidation), `?moto=` filters via `post__in` + `noindex`, and the `Compatibility` product tab lazy-loads via REST on open (`CompatCache` uses `Support\Cache` only when persistent).

A storefront header can opt into the Mi Garage drawer with `motored_garage_trigger`. The plugin renders one dialog with the shared garage UI for links marked `data-motored-garage-open`; those links retain a direct page URL for checkout and when the dialog cannot open. Motorcycle options load when the drawer selector expands.

With a motorcycle selected, the compatibility bar and catalog sidebar use `pos` for axle or kit selection. The sidebar counts compatible products per position after applying the remaining catalog filters. The compatibility bar displays totals for the motorcycle across positions, while the sidebar header displays the current result total.

## Image pipeline & galleries (ImageProcessor, InstallGallery, ImageOptimizer)
- **Shared image pipeline (`Support\ImageProcessor`):** centralizes GD/Imagick editor selection (preferring GD for predictable memory/WebP behavior), `supports_webp()`, max-side containment, EXIF/IPTC stripping, and atomic same-directory `optimize_in_place()`. Used by `ReviewPhotos` on upload, `ImageOptimizer` on bulk runs, and status diagnostics.
- **Storefront gallery (`InstallGallery`):** reads pre-primed `_motored_install_photos` and derived `_motored_review_gallery` (`GallerySync` / migration `2026112401` / admin backfill) BEFORE reading module options, ensuring 0 queries and 0 assets on products without photos; when present, enqueues `assets/css/install-gallery.css` and `assets/js/install-gallery.js` (`defer`, capture-phase dialog isolation).
- **Store optimization (`ImageOptimizer`):** `should_load()` returns `! $context->is_frontend()` (0 public overhead); registers native upload filters cleanly and executes bulk in-place optimization (preserving original attachment URLs/names by default for ERP compatibility) in cursor-paginated Action Scheduler slices (`motored_image_opt_batch`) guarded by a `Cache::add` lock.

## Motorcycle landing pages & core SEO (`BikePages` + `Seo`)
- **BikePages (`src/Modules/BikePages/`):** `CatalogRepository` emits `motored_compat_catalog_changed` (including pre-delete/pre-update context) and `ProductIndexRepository` emits `motored_compat_product_index_changed`. `BikePageSync` schedules batched Action Scheduler jobs to group each model's versions by `Signature` (OEM tire-size SHA-1) into `motored_bike` CPT posts (`_motored_sig`), preserving manual titles/content and registering 301 redirects on slug changes or trashed orphans. `HubLinks` builds a cached `slug => permalink` map so product Compatibility tabs (`CompatListRenderer`) and banners link directly to bike pages without N+1 queries. `BikeRenderer` (`[motored_moto]`) renders live WooCommerce product sections, "Complete the set", and data-driven FAQs. `DemandStore` + `POST /motored/v1/compat/notify` record out-of-stock requests in `motored_bike_demand` and emit `Integration\BikeDemandHooks` without calling mail/WAHA directly.
- **Seo (`src/Modules/Seo/`):** `SeoContext` collects title, meta description, canonical, robots and JSON-LD (`BreadcrumbList`, `ItemList`, `FAQPage`, `Motorcycle`) populated by landing renderers; `HeadRenderer` outputs head metadata via native WP hooks, `SitemapFilters` excludes non-indexable (`_motored_indexable = '0'`) posts and empty brand hubs from `wp_sitemaps`, and `Redirects` resolves 301s only on 404 responses.
