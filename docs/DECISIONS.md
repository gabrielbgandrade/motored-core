# Decisions

Format: date — decision — reason. Divergences from the original brief are marked **DIVERGENCE**.

- 2026-11-17 — No consent checkbox in this phase; opt-out link in commercial messages (module-level). — Product decision.
- 2026-11-17 — WhatsApp messages are text only. — Product decision.
- 2026-11-17 — Backend and frontend always `es_AR`; English source strings + es_AR `.po/.mo`. — Store locale.
- 2026-11-17 — Cart recovery and pending/failed-order recovery are one module ("Recovery"), with per-item event history and its own admin screen. — Shared pipeline.
- 2026-11-17 — PSR-4 autoloader of our own, no Composer in runtime. — Zero runtime dependencies, lazy loading.
- 2026-11-17 — One option per module (`motored_{module}`), autoload=false; WooCommerce fields use `motored_{module}[key]` ids with `'autoload' => false` (verified in `WC_Admin_Settings::save_fields` source). — Avoid autoload bloat.
- 2026-11-17 — Migrations registry is `Plugin::migrations()` (id => callable), not a collection filled by modules. — Modules may not be loaded in the admin/activation context where migrations must run.
- 2026-11-17 — Migration ids are `YYYYMMDDNN`, a single high-water-mark version option. — Simple ordering; ids must increase over time.
- 2026-11-17 — **DIVERGENCE:** `Support/Cache` has a `counter()` reader and `bump()` in addition to the requested methods. — Counters are stored unwrapped so `wp_cache_incr` works; invalidation needs `bump`.
- 2026-11-17 — **DIVERGENCE:** the prompt's file tree lists no `Contracts/Migration.php`; migrations are plain callables instead of a class contract. — Keeps the requested structure.
- 2026-11-17 — **DIVERGENCE:** `.mo` was compiled with a small Python writer because `msgfmt` is not installed in the dev environment (verified by round-trip with Python `gettext`). `.pot` is hand-maintained for the same reason (no `xgettext`). Re-run `bin/i18n.sh` where gettext exists. — Tool availability.
- 2026-11-17 — Uninstall removes options and transients only; table drops and Action Scheduler cleanup are added by the first module that creates them. — No tables exist yet.
- 2026-11-17 — No `WC requires at least` header; minimum WooCommerce version unverified. — See `VERIFY.md`.
- 2026-11-17 — Multisite is out of scope (single-site store). — Infra.
