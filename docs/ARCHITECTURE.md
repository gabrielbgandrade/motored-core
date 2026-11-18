# Architecture

## Layers
1. **Bootstrap** (`motored-core.php`): constants, autoloader, HPOS/Blocks compatibility, activation hook,
   `plugins_loaded@20` -> `Plugin::instance()->boot()`. This (plus `Autoloader` and `Plugin`) is ALL
   that loads on a public request.
2. **Core** (`Plugin`, `Contracts\Module`): lifecycle and module registry.
3. **Support** (`Context`, `Options`, `Logger`, `Cache`, `Db`, `Migrations`): small, static-friendly
   helpers loaded on demand by the autoloader.
4. **Admin** (`SettingsTab`, `SectionRegistry`): only autoloaded inside WooCommerce settings screens.
5. **Integration** (`src/Integration/`): adapters for anything unverified (WC hooks, Mercado Pago,
   WAHA, Action Scheduler). Modules talk to adapters, never to raw third-party hooks.
6. **Modules** (`src/Modules/`): functional units. Empty in stage 1.

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
