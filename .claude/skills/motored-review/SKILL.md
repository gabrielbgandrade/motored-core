---
name: motored-review
description: Procedure for a DIFFERENT agent to validate and fix work in motored-core — runs composer check, audits performance budget, security, es_AR i18n, Redis/cache behavior, HPOS and lazy contexts, cross-checks docs/VERIFY.md, and logs findings in docs/REVIEW-LOG.md. Use after any change or at the end of a stage.
---

# Review motored-core

You are the reviewer, not the author. Do not trust the author's claims; verify.

## 1. Run the tools
```bash
composer install && composer check   # phpcs (WordPress-Extra/Docs) + phpstan level 6 + phpunit
```
If a tool cannot run, say exactly why. Never claim results you did not see. Fix real errors; do not
silence them with ignores unless the rule is wrong for that line (and then explain in a comment).

## 2. Performance budget (read the code paths, think "public request")
- Public pages: plugin adds 0 queries / 0 HTTP / 0 assets. Only `motored-core.php`, `Autoloader`, `Plugin`
  (+ `Context`) load. `Options::get`, `Cache::*` (transient fallback hits DB), `Migrations` must NOT run on public requests.
- Checkout: at most one vanilla `defer` script < 3 KB. No jQuery, no libraries.
- No sync HTTP to external services in customer requests; Action Scheduler only.
- Admin: no assets or work outside plugin screens and WC > Settings.
- No schema checks / scheduling / migrations outside activation and `admin_init`.

## 3. Lazy contexts
- `should_load()` is I/O-free and translation-free; hooks only for needed contexts.
- `SettingsTab` autoloaded only inside `woocommerce_get_settings_pages`.
- No translation function runs before `init` (grep for `__(` in constructors/`register()`/static props).

## 4. Security
- Capability `manage_woocommerce`; nonces on actions/forms; `wc-ajax`, never admin-ajax.
- `$wpdb->prepare` for all dynamic SQL; sanitize input (`sanitize_text_field`, `wp_unslash`), escape output.
- Secrets read from constants first; not logged; not exposed in the Status section.
- Logs: no message text, phones/e-mails masked (`Logger::mask_*`).

## 5. Cache / Redis
- Only `wp_cache_*`/transients via `Support\Cache`; keys prefixed and versioned.
- Every cached read has a source-of-truth fallback. Behavior identical with cache cleared, no persistent
  cache, or Redis down. `add()`/`incr()` never used for correctness.

## 6. Options, HPOS, WooCommerce
- Options autoload=false, one per module. No direct `wp_posts`/`postmeta` access to orders: use WC CRUD (HPOS).
- WooCommerce hooks/classes only via `src/Integration/` when not verified in stubs.

## 7. i18n es_AR
- Every visible string uses the `motored-core` text domain; `.pot` and es_AR `.po/.mo` contain it
  (compare `grep -rhoE "__\\('[^']+'" src` with the `.po`). Voseo, no peninsular forms.

## 8. Docs cross-check
- Every unverified assumption in code has a `docs/VERIFY.md` row (item, where used, status, how to verify).
- New/changed module has `README.md` (template) and `.claude/skills/motored-module-<id>/SKILL.md`.
- `docs/ROADMAP.md`, `DECISIONS.md` (divergences), `TESTING.md` accurate.

## 9. Record and fix
Append to `docs/REVIEW-LOG.md`: date, scope, findings (severity, file:line), fix applied or pending (and why).
Fix what you can with small commits; log the rest as pending items. Re-run `composer check` after fixes.
