# Module: <Name>

> Mandatory template. Copy to `src/Modules/<Name>/README.md` and fill every section (write "none" if empty).

## Purpose
What the module does and why. One short paragraph.

## Public contracts
Classes/methods/filters other code may rely on. Everything else is private.

## Hooks and filters used
| Hook/filter | Context | Why | Verification status (`verified` / `partial` / `unverified` -> VERIFY.md row) |
|-------------|---------|-----|------|

## Data model
Options (`motored_<id>` keys), tables (with migration id), meta keys, transients/cache keys (namespace, TTL).

## Configuration keys
Key, type, default, where it is edited, secret? (prefer `wp-config.php` constants).

## Main flows
Step lists or short diagrams: triggers, Action Scheduler actions, external calls (always async), failure/retry handling.

## Performance and security notes
Contexts where it loads, query/HTTP/asset budget, capabilities and nonces, sanitization/escaping, PII handling in logs.

## How to test
Unit tests location and what they cover; manual steps on staging; reference to `docs/STAGING-CHECKLIST.md`.

## Known limitations

## Changelog
- x.y.z — YYYY-MM-DD — change
