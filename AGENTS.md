# AGENTS.md — Motored Core

Code, comments and agent docs in ENGLISH. User-facing strings: English source + complete `es_AR`
translation (voseo). Details live in `docs/`; this file is the short version.

## Purpose
Base WordPress/WooCommerce plugin for **Motored Neumáticos** (Argentina). Future modules (WAHA/WhatsApp,
Notifications, Recovery) plug into it. The core registers the feature modules listed in `src/Plugin.php`. Keep external
calls out of customer requests. Must NOT depend on the theme (any classic or block theme; classic and Blocks cart/checkout).
Motored is ONE product in two packages: this plugin is the **engine**, `themes/flatsome` ("Motored") is the **skin**;
they meet only at `docs/THEME-CONTRACT.md`. A change to that surface updates both packages and the doc together.
Infra: own VPS, Redis object cache, WAHA, Mercado Pago Checkout Pro. `Requires PHP 8.1`; stay 8.3-compatible.

## Paired package: the store theme (always check it)
The skin lives in `../../themes/flatsome` (Flatsome build "Motored"; its rules: `../../themes/flatsome/AGENTS.md`,
its side of the contract: `../../themes/flatsome/docs/MOTORED-CONTRACT.md`). Before finishing ANY task here, ask:
does it change markup, a BEM class, a storefront style, a token, a shortcode, `Api`, `window.Motored` or a
`motored:*` event? If yes, update the theme in the same task (its tokens/CSS/JS/templates and its contract file),
bump `Api::CONTRACT` when the surface changes, and verify the storefront with both active. A visual request
("make X look like Y") is usually a theme change, not a plugin one.

## Decisions (see docs/PROJECT.md)
No consent checkbox this phase (opt-out link lives in modules); WhatsApp text only; backend and
frontend always `es_AR`; abandoned-cart AND pending/failed-order recovery are ONE module "Recovery".

## Non-negotiable rules
1. **Perf budget:** public pages without Motored features add 0 queries / 0 HTTP / 0 assets. Checkout: max ONE vanilla `defer`
   script < 3 KB. No sync external HTTP in customer requests (Action Scheduler). Admin: nothing outside
   plugin screens and WooCommerce > Settings.
2. **Lazy by context:** own PSR-4 autoloader; modules register hooks only in contexts they use.
3. **Cache is never the source of truth** (`Support\Cache` only; works with Redis down/cleared).
4. **Options:** `autoload=false`, one per module (`motored_{module}`). Only exception: modules in
   `Support\Options::AUTOLOAD_MODULES` (today `bike_pages`, needed by `register_post_type()` on every request).
5. **Native first:** Woo Settings API, Action Scheduler, `WC_Logger`, WC CRUD (HPOS), `wc-ajax`. No
   jQuery, libraries, admin-ajax or runtime `vendor/`.
6. **Security:** `manage_woocommerce`, nonces, `$wpdb->prepare`, sanitize in / escape out, secrets as
   constants, logs WITHOUT PII (`Logger::mask_phone()` / `mask_email()`; never log message text).
7. **No schema checks, scheduling or migrations on frontend requests** (activation + `admin_init` only).
8. **Never invent APIs:** verify in `vendor/php-stubs/*`; otherwise isolate in `src/Integration/` and
   add a row to `docs/VERIFY.md`.
9. **Engine, not skin (`docs/THEME-CONTRACT.md`):** the plugin owns data, rules, markup and behavior; the theme
   owns the look. Storefront CSS is structural and reads visual values only through `--motored-*` tokens (literals
   only as `var()` fallbacks, no `!important`); themes talk to us only through the contract (`Motored\Core\Api`,
   shortcodes, BEM classes, `window.Motored`, `motored:*` events). `ThemeContractTest` fails otherwise.
   **Theme-agnostic:** no theme-specific code, selector, CSS class or hook (Flatsome, Storefront, block themes
   alike); only WordPress and WooCommerce core. No `get_header`/`get_footer`; own pages are standalone.
10. **Admin UI:** every new admin table, box or field uses the classes of `assets/admin/motored-admin.css` through
   `Admin\Ui` (`.motored-table`, `.motored-box`, `.motored-field`). Ad hoc per-module CSS, inline `style=` and the
   WC field `css` option are forbidden (`SharedAdminUiTest` fails). Missing class? Add it to the shared file.

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
- `docs/THEME-CONTRACT.md` the only plugin ↔ theme surface (tokens, markup, `Api`, JS, events)
- `docs/VERIFY.md` unverified assumptions · `docs/TESTING.md` · `docs/STAGING-CHECKLIST.md`
- `.claude/skills/`: `motored-core-overview`, `motored-add-module`, `motored-review`

## Modules
New/changed module: follow `motored-add-module`. Every module MUST have its own `README.md` and
`.claude/skills/motored-module-<name>/SKILL.md`, or it is not done. After any change run
`composer check`, then `motored-review` and log findings in `docs/PROJECT.md`.
