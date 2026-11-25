---
name: motored-core-overview
description: Explains what the motored-core WooCommerce plugin is, its architecture, non-negotiable principles and where to read first. Use at the start of any task in this repository, before designing or changing code.
---

# motored-core overview

Base plugin for Motored Neumáticos (WordPress + WooCommerce). Features are organized into modules. See the current registry in
src/Plugin.php. Rules, conventions and commands are in `AGENTS.md` — do not restate them here.

## Read first (in order)
1. `AGENTS.md` — purpose, decisions, non-negotiable rules, commands.
2. `docs/ARCHITECTURE.md` — layers, lifecycle, contexts, cache, queues.
3. `docs/VERIFY.md` — what is NOT verified yet (never assume it works).
4. `docs/PROJECT.md` — decisions, roadmap, review log.
5. The module's own `README.md` and skill when touching a module.

## Map
- `motored-core.php` -> autoloader -> `Plugin::boot()` on `plugins_loaded@20`.
- `Plugin::MODULES` entries get `register()` only if `should_load( Context )` is true.
- `Support/` helpers, `Admin/` settings tab + `SectionRegistry`, `Integration/` adapters for unverified APIs.

## Related skills
`motored-add-module` (create a module), `motored-review` (validate/fix work).
