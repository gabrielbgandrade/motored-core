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

## NOT covered (needs a real WordPress — see `STAGING-CHECKLIST.md`)
- `Admin\SettingsTab` rendering/saving inside WooCommerce (extends `WC_Settings_Page`).
- Query counts, HTTP requests and assets on public pages.
- Real Redis behavior (atomicity, eviction, outage), HPOS on/off, Flatsome classic checkout.
- `uninstall.php`, translations loading in the admin, `.mo` rendering.
- Anything in `docs/VERIFY.md` marked `unverified`/`partial`.
