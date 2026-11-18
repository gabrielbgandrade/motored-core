# Review log

Record each review: date, reviewer (agent/human), scope, findings, fixes, pending items.

## 2026-11-17 — Stage 1 authoring (self-review during build; author = same agent, needs independent review)
Tools were run with PHP 8.5.9 (Ubuntu packages extracted locally; the project targets 8.1–8.3, so
8.1/8.3 runs are still pending) and Composer-installed dev dependencies.

| Finding | Severity | Resolution |
|---------|----------|------------|
| PHPCS: `$namespace` / `$default` are reserved-keyword parameter names (`Cache`, `Options`) | low | Renamed to `$bucket` / `$fallback`. |
| PHPCS: missing `@var`, doc type hints in `Plugin`; Context param spacing | low | Fixed (phpcbf + manual). |
| PHPCS: WooCommerce's `hide_save_button` global flagged by PrefixAllGlobals | low | Scoped `phpcs:ignore` with explanation in `SettingsTab::output`. |
| PHPStan: `SettingsTab::output()` return type, `MOTORED_CORE_BASENAME` unknown, redundant `is_array`, by-ref `$found` type | low | Return type added; constants declared in `tests/phpstan-bootstrap.php`; docblock `mixed`; `@param-out`. |
| Brain Monkey cannot propagate by-ref `$found` for `wp_cache_get()` | test infra | In-memory fakes in `tests/stubs/object-cache.php`. |

Results: `composer check` -> phpcs clean, phpstan level 6 "No errors", phpunit 52 tests / 125 assertions OK.
Smoke check: `SettingsTab` loads and constructs against the real `WC_Settings_Page` class from the stubs (signatures compatible).

Pending (cannot be done without a real WordPress): everything in `docs/VERIFY.md` marked `unverified`/`partial`;
the staging checklist; PHP 8.1 and 8.3 runs; independent review by another agent (`motored-review`).
