---
name: motored-review
description: Procedure for a DIFFERENT agent to validate and fix work in motored-core — runs composer check, audits performance budget, security, es_AR i18n, Redis/cache behavior, HPOS and lazy contexts, cross-checks docs/VERIFY.md, and logs findings in docs/PROJECT.md. Use after any change or at the end of a stage.
---

# Review motored-core

You are the reviewer, not the author. Verify; do not trust claims. Rules are in `AGENTS.md`.

1. **Tools:** `composer install && composer check`. If a tool cannot run, say why. Never claim unseen
   results; no ignores unless the rule is wrong for that line (explain in a comment).
2. **Performance:** on public requests only bootstrap, `Autoloader`, `Plugin`, `Context` load;
   no `Options`/`Cache`/`Migrations` work; no HTTP; no assets; checkout max one `defer` script < 3 KB.
3. **Lazy contexts:** `should_load()` free of I/O and translations; no `__(` before `init`
   (grep constructors, `register()`, static props); `SettingsTab` only loaded in `woocommerce_get_settings_pages`.
4. **Security:** capability, nonces, `wc-ajax`, `$wpdb->prepare`, sanitize/escape, secrets from constants
   and never logged, masked PII, no message text in logs.
5. **Cache/Redis:** only `Support\Cache`; versioned keys; every read has a source-of-truth fallback;
   `add()`/`incr()` never used for correctness.
6. **Options/HPOS:** autoload=false, one per module; orders via WC CRUD; unverified WC APIs only in `src/Integration/`.
7. **i18n:** every string in `motored-core` domain and present in `.pot` and es_AR `.po/.mo` (voseo).
8. **Docs:** each unverified assumption has a `docs/VERIFY.md` row; modules have README + skill;
   `docs/PROJECT.md` and `docs/TESTING.md` accurate.
9. **Record:** append to the Review log in `docs/PROJECT.md` (date, scope, findings with file:line,
   fixed/pending). Fix what you can, re-run `composer check`.
