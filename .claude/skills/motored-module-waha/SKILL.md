---
name: motored-module-waha
description: Rules for working on the Waha module (WhatsApp transport via WAHA) of motored-core: client, phone normalization, Sender queue API, breaker/rate limit. Use when touching src/Modules/Waha or src/Integration/Waha.
---

# Module Waha
Read `src/Modules/Waha/README.md` first (contracts, flows, keys).

- Transport only: no business messages, webhooks, media or tables. Other modules call `Sender::enqueue()`
  and register a handler per kind that returns `Message|null` at send time.
- WAHA paths/payloads live ONLY in `src/Integration/Waha/Endpoints.php` (all unverified, VERIFY.md #21-#28, #59).
- Only the two admin test buttons do sync HTTP. Everything else goes through Action Scheduler, never `sleep()`.
- Job args = IDs only (kind, ref_id, step, attempt). Never put phone or text in AS args, logs or cache keys.
- Logs: `Logger::mask_phone()`; never message text, API key or response bodies. `Logger`/`Result` redact `X-Api-Key` and registered secrets centrally; only `Config` and `WahaClient` may read the key (a test enforces it).
- API key: encrypted option `motored_waha_secret` via `Support\Secrets`, constant wins, write-only field (never render it, plain or encrypted, never repopulate), `manage_options` only (URL too), `KeyGuard` deletes the key when the URL host/port changes and rejects `user:pass@`. Audit with `Logger::info` (user id, no value). Keep VERIFY #88-#89.
- Phone: `Phone::normalize()` is a pure candidate; always confirm with `ChatResolver` (check-exists + cache).
- Config: constants `MOTORED_WAHA_*` override options (read-only in the UI). Use `Config`, not `Options` directly.
- Anti-block (all in `Sender`, never `sleep()` except the <=3 s typing pause in the worker): enqueue with a priority (`HIGH` order notices, `LOW` recovery/reviews; default `NORMAL`), global random spacing (`next_slot` in Cache), daily cap (`day:{Ymd}`, `low_percent` share for LOW), session health cached 60 s. Waiting = reschedule, same attempt. Job args are `[kind, ref_id, step, attempt, priority]`.
- Spintax `{a|b}` lives in `OrderNotifications\Renderer::spin()`; typing endpoints are unverified (VERIFY #59).
- Retry max 3 (backoff 60/300 s); breaker counts only service failures (retryable or auth), not `no_whatsapp`.
- Loads in admin/cron/ajax/cli only (`WahaModule::should_load`). Keep it off public pages and REST.
- New strings: `.pot` + es_AR `.po` (voseo) + `.mo`. Tests in `tests/Unit/Modules/Waha`, `tests/Unit/Integration/Waha`.
- After changes: `composer check`, then `motored-review`; update VERIFY.md and PROJECT.md.

Launch safety: sending is OFF by default and test mode is ON by default (`Config::mode()`: off|test|live). Never bypass `Config::may_send_to()`/the
mode check in `Sender::handle`; discarded jobs end with `Sender::CODE_DISABLED` / `CODE_TEST_MODE` and modules must not retry or fall back (see `Sender::discard_note`).
