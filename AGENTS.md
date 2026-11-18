# AGENTS.md — Motored Core

Guide for any coding agent working in this repository. Code, comments and agent docs are in
ENGLISH. User-facing strings: English source + complete `es_AR` translation (voseo).

## Purpose
`motored-core` is the base WordPress/WooCommerce plugin for **Motored Neumáticos** (Argentina).
Future modules (WAHA/WhatsApp, notifications, Recovery) plug into it. Stage 1 (current) ships
infrastructure only: no functional modules, no tables, no external calls.

## Project context (persist faithfully)
- Store: current WordPress + WooCommerce. Theme: a Flatsome fork (classic shortcode cart and
  checkout). The plugin MUST NOT depend on the theme.
- Infra: own VPS, Redis persistent object cache, WAHA (unofficial WhatsApp HTTP API) in production.
  Payments: official Mercado Pago plugin, Checkout Pro (redirect).
- PHP: `Requires PHP 8.1` (WooCommerce 11.6 will require 8.1); code must stay compatible with 8.3.
- Decisions already made: NO consent checkbox in this phase; keep an opt-out link in commercial
  messages (implemented inside modules); WhatsApp messages are text only; backend and frontend are
  ALWAYS `es_AR`; abandoned-cart recovery AND pending/failed-order recovery live in ONE module,
  "Recovery", with a per-item event history and its own admin screen.

## Non-negotiable principles
1. **Performance budget.** Public pages (home, shop, category, product, cart): the plugin adds 0
   queries, 0 HTTP requests, 0 assets. Checkout: at most ONE vanilla script (<3 KB) with `defer`.
   No synchronous HTTP to external services in customer requests; everything async via Action
   Scheduler. Admin: nothing loads outside the plugin's screens and WooCommerce > Settings.
2. **Lazy by context.** Own PSR-4 autoloader; modules register hooks only in the contexts they use.
3. **Cache is never the source of truth.** Only `wp_cache_*` and transients (via `Support\Cache`).
   Everything must work with a cleared cache, no persistent cache, or Redis down. Keys are
   prefixed and versioned.
4. **Options:** always `autoload=false`, one option per module (`motored_{module}`).
5. **Native first:** Woo Settings API (`WC_Settings_Page`), Action Scheduler, `WC_Logger`, order
   notes, WC CRUD (HPOS-compatible), `wc-ajax` (never admin-ajax), native coupons, WP privacy
   exporter/eraser. No own jQuery, no external libraries, no `vendor/` at runtime.
6. **Security:** capability `manage_woocommerce`, nonces, `$wpdb->prepare`, sanitize on input,
   escape on output, secrets preferably as `wp-config.php` constants, logs WITHOUT PII
   (mask phone/e-mail with `Logger::mask_phone()` / `mask_email()`; never log message text).
7. **No schema checks, scheduling or migrations on frontend requests.** Migrations run only on
   activation and `admin_init`.

## Never invent APIs
Verify every WordPress/WooCommerce function, class, hook and signature in
`php-stubs/wordpress-stubs` and `php-stubs/woocommerce-stubs` (under `vendor/` after
`composer install`). Anything NOT verifiable (WooCommerce hook names, third-party plugin
behavior, external endpoints, Action Scheduler functions) must be (a) isolated in
`src/Integration/` and (b) recorded in `docs/VERIFY.md` with status `unverified`, where it is
used, and how to verify it on a real WordPress.

## Repository map
```
motored-core.php        bootstrap, constants, HPOS/Blocks compatibility
uninstall.php           removes data only if "delete data on uninstall" is on
src/Plugin.php          lifecycle, module registry (Plugin::MODULES), migrations registry
src/Autoloader.php      PSR-4 Motored\Core\ -> src/
src/Contracts/Module.php  id(), should_load(Context), register()
src/Support/            Context, Options, Logger, Cache, Db, Migrations
src/Admin/              SettingsTab (WC > Settings > Motored), SectionRegistry
src/Integration/        adapters for WooCommerce/third parties (unverified assumptions live here)
src/Modules/            functional modules (empty in stage 1)
languages/              .pot, es_AR .po/.mo
docs/                   ARCHITECTURE, DECISIONS, VERIFY, TESTING, STAGING-CHECKLIST, ROADMAP, REVIEW-LOG
.claude/skills/         motored-core-overview, motored-add-module, motored-review (+ one per module)
tests/                  PHPUnit + Brain Monkey (no real WordPress)
```

## Commands
- `composer install` then `composer check` (= `lint` PHPCS, `stan` PHPStan level 6, `test` PHPUnit).
- `bin/build-zip.sh` — runtime-only `build/motored-core.zip`.
- `bin/i18n.sh` — regenerate `.pot`, merge `es_AR`, compile `.mo` (see script header for fallbacks).

## Conventions
- Namespace `Motored\Core\...`; classes in `src/` follow PSR-4 (one class per file, file = class name).
- Global names (constants, hooks, options, transients, handles) use the `motored` / `MOTORED` prefix.
- Every PHP file starts with `defined( 'ABSPATH' ) || exit;` (after namespace/use).
- WordPress-Extra + WordPress-Docs code style (tabs, long `array()` syntax, Yoda conditions, docblocks).
- i18n: text domain `motored-core`; every visible string through `__()`/`esc_html__()`; never call
  translation functions before `init` (resolve lazily, e.g. label callables); update `.pot`, es_AR
  `.po` and `.mo` in the same change. Voseo rioplatense (“Activá”, “Elegí”).
- Escape on output (`esc_html`, `esc_attr`, `esc_url`), sanitize on input, nonces on every form/action.

## Workflow: create or change a module
Follow `.claude/skills/motored-add-module/SKILL.md`. Summary: folder in `src/Modules/<Name>/`,
class implementing `Contracts\Module`, register in `Plugin::MODULES`, README from
`docs/templates/MODULE_README.md`, settings section via `SectionRegistry`, migrations in
`Plugin::migrations()`, unit tests, update `docs/VERIFY.md` + `docs/ROADMAP.md`, write the module skill.

**Rule for every future module:** it MUST have its own `README.md` (inside its folder) AND its own
skill at `.claude/skills/motored-module-<name>/SKILL.md`. A module without both is not done.

After any change: `composer check`, then review with `.claude/skills/motored-review/SKILL.md`
and log findings in `docs/REVIEW-LOG.md`.
