---
name: motored-module-waha
description: Rules for working on the Waha module (WhatsApp transport via WAHA) of motored-core: client, phone normalization, Sender queue API, breaker/rate limit. Use when touching src/Modules/Waha or src/Integration/Waha.
---

# Module Waha
Read `src/Modules/Waha/README.md` first (contracts, flows, keys).

- Transport only: no business messages, webhooks, media or tables. Other modules call `Sender::enqueue()`
  and register a handler per kind that returns `Message|null` at send time.
- WAHA paths/payloads live ONLY in `src/Integration/Waha/Endpoints.php` (all unverified, VERIFY.md #21-#28).
- Only the two admin test buttons do sync HTTP. Everything else goes through Action Scheduler, never `sleep()`.
- Job args = IDs only (kind, ref_id, step, attempt). Never put phone or text in AS args, logs or cache keys.
- Logs: `Logger::mask_phone()`; never message text, API key or response bodies.
- Phone: `Phone::normalize()` is a pure candidate; always confirm with `ChatResolver` (check-exists + cache).
- Config: constants `MOTORED_WAHA_*` override options (read-only in the UI). Use `Config`, not `Options` directly.
- Retry max 3 (backoff 60/300 s); breaker counts only service failures (retryable or auth), not `no_whatsapp`.
- Loads in admin/cron/ajax/cli only (`WahaModule::should_load`). Keep it off public pages and REST.
- New strings: `.pot` + es_AR `.po` (voseo) + `.mo`. Tests in `tests/Unit/Modules/Waha`, `tests/Unit/Integration/Waha`.
- After changes: `composer check`, then `motored-review`; update VERIFY.md and PROJECT.md.
