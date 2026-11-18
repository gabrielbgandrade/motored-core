# Module: Waha

## Purpose
WhatsApp **transport** through WAHA: HTTP client, phone normalization/confirmation, and a safe send queue
(Action Scheduler) other modules use. No business messages, inbound webhooks, media or tables.

## Public contracts
- `Sender::enqueue( string $kind, int $ref_id, ?string $step = null, int $delay = 0 ): bool` — schedules a job (IDs only).
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
`rate:m:*` / `rate:h:*` counters, `breaker:until`, `breaker:fails`, `notice:{user}` (120 s). No tables.

## Configuration keys
`url`, `session`, `api_key` (secret), `country_code` (54), `rate_per_minute` (6), `rate_per_hour` (100),
`breaker_threshold` (5), `breaker_minutes` (15). Constants `MOTORED_WAHA_URL`, `MOTORED_WAHA_API_KEY`,
`MOTORED_WAHA_SESSION` override the option; those fields become read-only. Edited in WooCommerce > Settings > Motored > WhatsApp.

## Main flows
1. `enqueue` -> AS single action (group `motored-core`, args `[kind, ref_id, step, attempt]`) at now+delay+jitter(3-15 s).
2. Worker `Sender::run`: breaker open? -> reschedule. Rate limit hit? -> reschedule to next window. Handler -> `Message|null`.
3. `ChatResolver`: `Phone::normalize` candidate -> cache -> `check-exists` -> chat id; then `sendText`.
4. Retryable failure: retry with backoff 60 s / 300 s, max 3 attempts; consecutive service failures open the breaker.
5. Final outcome -> `motored_waha_result`. Never `sleep()`.

## Performance and security notes
Loads only in admin, cron, ajax, cli (not public pages, not REST). Sync HTTP only on the two admin buttons
(nonce + `manage_woocommerce`, `admin-post.php`). TLS verified, no redirects, https required. Logs carry masked
phones, kinds and IDs; never text, API key or response bodies. The API key input is `type=password` but its value
is still in the page HTML for administrators; prefer the constant.

## How to test
`vendor/bin/phpunit tests/Unit/Modules/Waha tests/Unit/Integration/Waha`: client (success, 401, timeout, bad JSON,
http), phone (Argentine cases), sender (rate limit, backoff, breaker, null handler), module contexts.
Staging: see the WAHA section of `docs/STAGING-CHECKLIST.md`.

## Known limitations
Argentine normalization is heuristic (confirmed by check-exists). Rate/breaker counters are best-effort when
Redis is down. Test buttons use saved settings. Endpoints unverified until checked against the instance Swagger.

## Changelog
- 0.2.0 — 2026-11-17 — initial transport implementation.
