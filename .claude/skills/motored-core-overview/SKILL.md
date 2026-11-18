---
name: motored-core-overview
description: Explains what the motored-core WooCommerce plugin is, its architecture, non-negotiable principles and where to read first. Use at the start of any task in this repository, before designing or changing code.
---

# motored-core overview

## What it is
Base plugin for **Motored Neumáticos** (Argentina; WordPress + WooCommerce, Flatsome fork theme with
classic shortcode cart/checkout, own VPS, Redis object cache, WAHA WhatsApp API, Mercado Pago
Checkout Pro). It provides module infrastructure; features arrive as modules (Waha, Notifications,
Recovery). Stage 1 has NO functional modules, tables or external calls.

## Read first (in order)
1. `AGENTS.md` — principles, conventions, commands.
2. `docs/ARCHITECTURE.md` — layers, lifecycle, contexts, cache, queues.
3. `docs/VERIFY.md` — what is NOT verified yet (never assume it works).
4. `docs/DECISIONS.md` and `docs/ROADMAP.md` — what was decided / what is next.
5. The module's own `README.md` and skill when touching a module.

## Architecture in one screen
- `motored-core.php` -> autoloader -> `Plugin::boot()` on `plugins_loaded@20`.
- `Plugin` instantiates classes in `Plugin::MODULES`; only those whose `should_load( Context )`
  returns true get `register()` called.
- `Support/`: `Context`, `Options`, `Logger`, `Cache`, `Db`, `Migrations`.
- `Admin/`: `SettingsTab` (WC > Settings > Motored) + `SectionRegistry` for module sections.
- `Integration/`: all adapters for unverified WooCommerce/third-party behavior.

## Principles (violating any is a bug)
1. Public pages: 0 queries, 0 HTTP, 0 assets. Checkout: max 1 deferred vanilla script < 3 KB.
   No sync external HTTP in customer requests; async via Action Scheduler.
2. Lazy by context; nothing registers hooks outside the contexts it needs.
3. Cache (`Support\Cache`) is never the source of truth; works with Redis off/cleared.
4. Options: autoload=false, one per module (`motored_{module}`).
5. Native first: WC Settings API, Action Scheduler, WC_Logger, order notes, WC CRUD (HPOS), `wc-ajax`.
   No jQuery, no libraries, no `vendor/` at runtime, no admin-ajax.
6. Security: `manage_woocommerce`, nonces, `$wpdb->prepare`, sanitize in / escape out, secrets in
   constants, logs without PII (mask phone/e-mail, never log message text).
7. No schema/scheduling/migration work on frontend requests (activation + `admin_init` only).

## Product decisions
No consent checkbox (this phase); opt-out link in commercial messages; WhatsApp text only;
backend + frontend always `es_AR` (voseo); cart AND pending/failed-order recovery are ONE module "Recovery".

## Language
Code, comments, agent docs: English. UI strings: English source + es_AR `.po/.mo`.

## Never invent APIs
Verify in `vendor/php-stubs/*`; otherwise isolate in `src/Integration/` and add a row to `docs/VERIFY.md`.

## Commands
`composer check` (phpcs + phpstan lvl 6 + phpunit), `bin/build-zip.sh`, `bin/i18n.sh`.

## Related skills
`motored-add-module` (create a module), `motored-review` (validate/fix work).
