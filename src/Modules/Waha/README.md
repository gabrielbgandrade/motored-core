# Module: Waha

## Purpose
WhatsApp **transport** through WAHA: HTTP client, phone normalization/confirmation, and a safe send queue
(Action Scheduler) other modules use. No business messages, inbound webhooks, media or tables.

## Public contracts
- `Sender::enqueue( string $kind, int $ref_id, ?string $step = null, int $delay = 0, string $priority = Sender::NORMAL ): bool` — schedules a job (IDs only + priority). `Sender::HIGH` = order notices, `NORMAL`, `LOW` = recovery / review requests.
- `Sender::register_handler( string $kind, callable $h )` — `$h( int $ref_id, ?string $step ): ?Message` runs AT SEND
  TIME; returns `new Message( $phone, $text )` or `null` to skip. Register in every job context (admin/cron/ajax/cli).
- Action `motored_waha_result( string $kind, int $ref_id, ?string $step, Result $result )` — job finished (sent, skipped
  or gave up); `Result`: `ok`, `http_status`, `code`, `message`, `retryable`. Codes: `ok`, `skipped`, `no_handler`,
  `handler_error`, `invalid_phone`, `no_whatsapp`, `auth`, `timeout`, `transport`, `server_error`, `invalid_response`...
- Filter `motored_waha_allow_insecure_http` (default false) — allow `http://` URLs in dev.
- Filter `motored_status_rows` (core) — used to add rows to the Status section.
- `Phone::normalize( $raw, $country_code ): ?string` (pure), `Phone::chat_id()`.

## Hooks and filters used
| Hook/filter | Context | Why | Status |
|---|---|---|---|
| `motored_waha_send` (our AS action) | cron/ajax/cli/admin | worker | unverified (VERIFY #21) |
| `admin_post_motored_waha_test_*` | admin | the two test buttons | unverified (#25) |
| `woocommerce_admin_field_motored_waha_tools` | admin | custom row with buttons | unverified (#24) |
| `motored_status_rows` | admin | Status rows | ours |
| `http_api_curl` (only during a request) | worker/admin | connect timeout 5 s | unverified (#23) |

## Data model
Option `motored_waha` (autoload=false). Cache bucket `waha`: `chat:{md5}` (chat id 30 d / negative 24 h),
`rate:m:*` / `rate:h:*` counters, `next_slot` (global spacing), `day:{Ymd}` (daily counter, site timezone), `session:health` (60 s), `breaker:until`, `breaker:fails`, `notice:{user}` (120 s). No tables.

## Configuration keys
`url`, `session`, `api_key` (secret), `country_code` (54), `rate_per_minute` (6), `rate_per_hour` (100),
`breaker_threshold` (5), `breaker_minutes` (15), `interval_min` (20 s), `interval_max` (60 s), `daily_cap` (150), `low_percent` (60), `typing` (yes). Constants `MOTORED_WAHA_URL`, `MOTORED_WAHA_API_KEY`,
`MOTORED_WAHA_SESSION` override the option; those fields become read-only. Edited in WooCommerce > Settings > Motored > WhatsApp.

## Main flows
1. `enqueue` -> AS single action (group `motored-core`, args `[kind, ref_id, step, attempt, priority]`) at now+delay+jitter(3-15 s).
2. Worker `Sender::run`: breaker open? rate limit hit? global spacing slot not reached? daily cap reached (low priority: only `low_percent` % of it)? -> reschedule (cap: start of the next day in the site timezone). Then handler -> `Message|null`.
3. Session health (`sessionStatus`, cached 60 s): not `WORKING` -> reschedule 60 s and count a breaker failure. Then `ChatResolver` (`Phone::normalize` candidate -> cache -> `check-exists` -> chat id), typing simulation (`startTyping`, wait 0.5-3 s, `stopTyping`; failures ignored; worker only) and `sendText`. After sending, the next slot is reserved (random `interval_min`-`interval_max`).
4. Retryable failure: retry with backoff 60 s / 300 s, max 3 attempts; consecutive service failures open the breaker.
5. Final outcome -> `motored_waha_result`. Never `sleep()`.

## Text variation
`OrderNotifications\Renderer` accepts `{option a|option b}` in templates (one random option per message; placeholders have no `|`, so they are untouched; values such as the customer note are never expanded). Default es_AR texts of Recovery and OrderNotifications use 1-2 variations.

## Performance and security notes
Loads only in admin, cron, ajax, cli (not public pages, not REST). Sync HTTP only on the two admin buttons
(nonce + `manage_woocommerce`, `admin-post.php`). TLS verified, no redirects, https required. Logs carry masked
phones, kinds and IDs; never text, API key or response bodies. The API key input is `type=password` but its value
is still in the page HTML for administrators; prefer the constant.

## How to test
`vendor/bin/phpunit tests/Unit/Modules/Waha tests/Unit/Integration/Waha`: client (success, 401, timeout, bad JSON,
http), phone (Argentine cases), sender (priority, daily cap, spacing, typing, session health, rate limit, backoff, breaker, null handler), module contexts.
Staging: see the WAHA section of `docs/STAGING-CHECKLIST.md`.

## Known limitations
Argentine normalization is heuristic (confirmed by check-exists). Rate/breaker/daily/spacing counters are best-effort when
Redis is down (the cap and the spacing then simply do not apply; jitter still does). Test buttons use saved settings. Endpoints unverified until checked against the instance Swagger.

## Changelog
- 0.2.0 — 2026-11-17 — initial transport implementation.
- 0.3.0 — 2026-11-18 — anti-block hardening: priority, global spacing, daily cap, typing simulation, session health, text variation.
