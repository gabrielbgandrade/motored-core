# Testing

Run `composer test` (PHPUnit 10/11 + Brain Monkey + Mockery). No WordPress is loaded; WordPress
functions are stubbed per test. `tests/stubs/object-cache.php` provides in-memory `wp_cache_*` with
the real by-reference `$found` signature (Brain Monkey cannot propagate by-ref args).

## Covered
- `Autoloader`: class -> file mapping, foreign namespaces ignored.
- `Options`: option naming, defaults overlay, memoization, `autoload=false` on update/ensure.
- `Cache`: versioned keys, `false` values, `bump()`, wipe/outage behavior, `remember`, `add` lock,
  `incr`/`counter`, transient fallback (TTL never 0).
- `Logger`: phone/e-mail masks, threshold logic, `source` passed to `WC_Logger`.
- `Context`: each signal, early REST/`wc-ajax` detection, memoization.
- `Migrations`: ordering, idempotence, skip applied, stop on failure/exception, lock release, no I/O when empty.
- `Db`: table naming/validation. `SectionRegistry`: ordering and validation.
- `Plugin`: nothing registered without WooCommerce; only approved modules registered; admin-only hooks;
  idempotent boot; text domain path; activation creates option without autoload.

- `Waha` module: `WahaClient` (success, 401, timeout, invalid JSON, https rule), `Phone` (Argentine formats),
  `Sender` (rate limit, backoff, breaker, null handler, IDs-only args), `WahaModule` contexts.
- `OrderNotifications` module: `Renderer` (placeholders, <=5 items + "y N más", plain `wc_price`, empty text),
  `EventMap` (status -> event, send-time match, admin new order transitions), `Notifier` (idempotency, early exit when
  disabled, skip manual orders/invalid phone/status change, billing country code, private result notes),
  `OrderNotificationsModule` contexts.

- `Recovery`: payload/phone validation, status derivation, snapshot from a cart array, retention window, history cleaning/formatting,
  capture rate limit, `Repository::where()` (filters, LIKE escaping), schema statements, per-context hook registration.

- Recovery part C: status -> case mapping, per-case delays/texts/default sequences, cart -> order conversion without a second row/sequence, paid attribution,
  skip when paid / `needs_payment()` false, cancelled/trashed fallback decisions, e-mail template choice, scanner step numbers for orders, private order note content, `find_for_order` query.

## NOT covered (needs a real WordPress — see `STAGING-CHECKLIST.md`)
- `Admin\SettingsTab` rendering/saving inside WooCommerce (extends `WC_Settings_Page`).
- Query counts, HTTP requests and assets on public pages.
- Real Redis behavior (atomicity, eviction, outage), HPOS on/off, Flatsome classic checkout.
- `uninstall.php`, translations loading in the admin, `.mo` rendering.
- Anything in `docs/VERIFY.md` marked `unverified`/`partial`.
