# Modules

Empty in stage 1. A module is a class implementing `Motored\Core\Contracts\Module`
in `src/Modules/<Name>/<Name>Module.php`.

- `id()` returns a unique lowercase slug, also the key of its option `motored_{id}` (autoload=false).
- `should_load( Context $context )` is cheap (no I/O) and returns true only for the contexts where the module is needed.
- `register()` adds hooks; it runs only when `should_load()` returned true.
- Register the class in `Plugin::MODULES`; add migrations in `Plugin::migrations()`.
- Every module ships its own `README.md` (from `docs/templates/MODULE_README.md`) and a skill
  `.claude/skills/motored-module-<name>/SKILL.md`.

See `.claude/skills/motored-add-module/SKILL.md` for the full procedure.
