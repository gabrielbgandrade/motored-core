---
name: motored-add-module
description: Step-by-step procedure to add a new functional module (e.g. Waha, Notifications, Recovery) to motored-core, including class, README, settings section, migrations, tests, docs and the module's own skill. Use whenever creating or substantially changing a module.
---

# Add a module to motored-core

Read `AGENTS.md` and `motored-core-overview` first. Replace `<Name>` (PascalCase) and `<id>` (lowercase slug).

## Mandatory rule
Every module ships (1) its own `README.md` and (2) its own skill
`.claude/skills/motored-module-<id>/SKILL.md`. A module missing either is NOT done.

## Steps
1. **Folder:** `src/Modules/<Name>/` (PSR-4: `Motored\Core\Modules\<Name>\...`, one class per file).
2. **Module class** `<Name>Module.php` implementing `Motored\Core\Contracts\Module`:
   - `id()` returns `<id>` (also the options key `motored_<id>`).
   - `should_load( Context $context )`: cheap, NO I/O, NO translations. Return true only for the
     contexts that need it (e.g. `$context->is_admin() || $context->is_cron()`). Frontend pages
     must stay at 0 queries/requests/assets unless the budget in AGENTS.md allows it.
   - `register()`: add hooks only. Defer heavy work to Action Scheduler.
3. **Register** the class in `Plugin::MODULES` (src/Plugin.php).
4. **README:** copy `docs/templates/MODULE_README.md` to `src/Modules/<Name>/README.md` and fill every
   section (hooks table with verification status, data model, config keys, flows, perf/security, tests, limitations, changelog).
5. **Options:** `Options::register_defaults( '<id>', array(...) )` in `register()`; reads via
   `Options::get`; writes via `Options::update` (autoload=false always). Secrets: prefer constants in `wp-config.php`.
6. **Settings section** (admin only): in `register()`, when `$context->is_admin()`, call
   `SectionRegistry::add( '<id>', label_callable, settings_callable )`. Labels are callables (lazy
   translation). Fields use ids `motored_<id>[key]` with `'autoload' => false`; capability `manage_woocommerce`.
7. **Migrations (if tables are needed):** add `'YYYYMMDDNN' => array( <Name>Migration::class, 'up' )`
   to `Plugin::migrations()`. `up()` is idempotent (`dbDelta`), uses `Db::table()` / `Db::charset_collate()`,
   returns false on failure. Never run schema work on frontend requests. Add table drops to `uninstall.php`.
8. **Third-party/unverified APIs:** wrap in `src/Integration/<Thing>.php`; add rows to `docs/VERIFY.md`
   (item, where used, status `unverified`, how to verify on real WordPress). Never invent signatures; check stubs.
9. **Async + external calls:** only via Action Scheduler; no HTTP in customer requests. Logs through
   `Logger` with `mask_phone()` / `mask_email()`; never log message text or full PII.
10. **i18n:** all strings via `__()`/`esc_html__()` with `motored-core`; update `.pot`, es_AR `.po`
    (voseo) and `.mo` (`bin/i18n.sh`). No translation calls before `init`.
11. **Tests:** `tests/Unit/Modules/<Name>/...` (Brain Monkey). Cover `should_load()` per context,
    registration, migrations, option defaults, masking. Fixtures in `tests/Unit/Fixtures/`.
12. **Docs:** update `docs/VERIFY.md`, `docs/ROADMAP.md` (status), `docs/ARCHITECTURE.md` if the flow
    changes, `docs/DECISIONS.md` for any divergence, `docs/STAGING-CHECKLIST.md` with module-specific checks.
13. **Module skill:** create `.claude/skills/motored-module-<id>/SKILL.md` (frontmatter `name`,
    `description`; body < 150 lines): purpose, public contracts, flows, pitfalls, how to test, where the README is.
14. **Verify:** `composer check` must pass; then run `motored-review`.

## Checklist before finishing
- [ ] should_load() has no I/O; hooks only in needed contexts
- [ ] options autoload=false; no new autoloaded data
- [ ] no admin-ajax / jQuery / external libs / sync HTTP
- [ ] README.md + skill created; VERIFY.md and ROADMAP.md updated
- [ ] es_AR complete; `composer check` green
