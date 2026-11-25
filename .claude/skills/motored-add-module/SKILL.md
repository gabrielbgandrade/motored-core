---
name: motored-add-module
description: Step-by-step procedure to add a new functional module (e.g. Waha, Notifications, Recovery) to motored-core, including class, README, settings section, migrations, tests, docs and the module's own skill. Use whenever creating or substantially changing a module.
---

# Add a module to motored-core

Read `AGENTS.md` and `docs/ARCHITECTURE.md` first. `<Name>` = PascalCase, `<id>` = lowercase slug.
Rule: a module ships its own `README.md` AND `.claude/skills/motored-module-<id>/SKILL.md`, or it is not done.

## Steps
1. Folder `src/Modules/<Name>/`; class `<Name>Module` implements `Contracts\Module`.
2. `id()` returns `<id>` (options key `motored_<id>`). `should_load( Context )`: no I/O, no translations,
   only the contexts needed. `register()`: hooks only; heavy work via Action Scheduler.
3. Register the class in `Plugin::MODULES`.
4. README: copy `docs/templates/MODULE_README.md` to `src/Modules/<Name>/README.md`, fill every section.
5. Options: `Options::register_defaults( '<id>', ... )`; `Options::get` / `Options::update` (autoload=false).
6. Settings (admin only): `SectionRegistry::add( '<id>', label_callable, settings_callable )`; field ids
   `motored_<id>[key]` with `'autoload' => false`. Every new admin UI (table, box, field, meta box) uses the
   classes of `assets/admin/motored-admin.css` via `Admin\Ui`; ad hoc per-module CSS and inline styles are forbidden.
7. Tables: add `'YYYYMMDDNN' => \\Closure::fromCallable( array( <Name>Migration::class, 'up' ) )` to `Plugin::migrations()`
   (idempotent `dbDelta`, `Db::table()`); add drops to `uninstall.php`.
8. Unverified/third-party APIs: wrap in `src/Integration/`, add rows to `docs/VERIFY.md`.
9. i18n: `motored-core` domain; update `.pot`, es_AR `.po` (voseo), `.mo` via `bin/i18n.sh`.
10. Tests in `tests/Unit/Modules/<Name>/` (`should_load()` per context, registration, migrations, defaults, masking).
11. Docs: `docs/VERIFY.md`, `docs/PROJECT.md` (roadmap/decisions), `docs/ARCHITECTURE.md` if flow changes,
    `docs/STAGING-CHECKLIST.md`.
12. Module skill: frontmatter `name`, `description`; body under 60 lines pointing to the README.
13. `composer check`, then `motored-review`.
