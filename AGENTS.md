# AGENTS.md — Motored Core

Code, comments and agent docs in ENGLISH. User-facing strings: English source + complete `es_AR`
translation (voseo). Details live in `docs/`; this file is the short version.

## Purpose
Base WordPress/WooCommerce plugin for **Motored Neumáticos** (Argentina). Future modules (WAHA/WhatsApp,
Notifications, Recovery) plug into it. Stage 1 (current): infrastructure only — no modules, tables or
external calls. Must NOT depend on the theme (Flatsome fork, classic shortcode cart/checkout).
Infra: own VPS, Redis object cache, WAHA, Mercado Pago Checkout Pro. `Requires PHP 8.1`; stay 8.3-compatible.

## Decisions (see docs/PROJECT.md)
No consent checkbox this phase (opt-out link lives in modules); WhatsApp text only; backend and
frontend always `es_AR`; abandoned-cart AND pending/failed-order recovery are ONE module "Recovery".

## Non-negotiable rules
1. **Perf budget:** public pages add 0 queries / 0 HTTP / 0 assets. Checkout: max ONE vanilla `defer`
   script < 3 KB. No sync external HTTP in customer requests (Action Scheduler). Admin: nothing outside
   plugin screens and WooCommerce > Settings.
2. **Lazy by context:** own PSR-4 autoloader; modules register hooks only in contexts they use.
3. **Cache is never the source of truth** (`Support\Cache` only; works with Redis down/cleared).
4. **Options:** `autoload=false`, one per module (`motored_{module}`).
5. **Native first:** Woo Settings API, Action Scheduler, `WC_Logger`, WC CRUD (HPOS), `wc-ajax`. No
   jQuery, libraries, admin-ajax or runtime `vendor/`.
6. **Security:** `manage_woocommerce`, nonces, `$wpdb->prepare`, sanitize in / escape out, secrets as
   constants, logs WITHOUT PII (`Logger::mask_phone()` / `mask_email()`; never log message text).
7. **No schema checks, scheduling or migrations on frontend requests** (activation + `admin_init` only).
8. **Never invent APIs:** verify in `vendor/php-stubs/*`; otherwise isolate in `src/Integration/` and
   add a row to `docs/VERIFY.md`.

## Conventions
Namespace `Motored\Core\`, PSR-4, one class per file; `motored`/`MOTORED` prefix for globals;
`defined( 'ABSPATH' ) || exit;` in every PHP file; WordPress-Extra + Docs style. i18n text domain
`motored-core`; no translation calls before `init`; update `.pot`, es_AR `.po` and `.mo` together.

## Commands
- `composer install`, then `composer check` (phpcs + phpstan level 6 + phpunit)
- `bin/build-zip.sh` — runtime-only zip; `bin/i18n.sh` — regenerate `.pot`/`.po`/`.mo`

## Where to read
- `docs/ARCHITECTURE.md` layers, lifecycle, contexts, cache, queues
- `docs/PROJECT.md` decisions, roadmap, review log
- `docs/VERIFY.md` unverified assumptions · `docs/TESTING.md` · `docs/STAGING-CHECKLIST.md`
- `.claude/skills/`: `motored-core-overview`, `motored-add-module`, `motored-review`

## Modules
New/changed module: follow `motored-add-module`. Every module MUST have its own `README.md` and
`.claude/skills/motored-module-<name>/SKILL.md`, or it is not done. After any change run
`composer check`, then `motored-review` and log findings in `docs/PROJECT.md`.
