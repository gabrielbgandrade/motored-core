# Module: Recovery (parts A, B & C)

## Purpose
Captures carts (visitors and logged-in customers), manages a 3-step automated recovery sequence (WhatsApp + Email with fallback),
handles recovery and opt-out links, tracks conversions and suppression, and provides an admin screen with manual actions.
Part C adds `type = order` rows: orders left pending, failed or on hold follow their own 2-step sequence, sharing the tables,
scanner, `Waha\Sender`, `Renderer`, sending window, suppression, per-number limits, history and admin screens.

## Public contracts
- `Repository`: Explicit column queries, prepared SQL, pagination, summary metrics (`recovered_link` vs `recovered_message`).
- `History::log( $id, $event, $channel, $source, $user_id, $result, $detail )`: Insert-only event timeline.
- `RecoveryToken`: recovery token = HMAC(`wp_salt('motored_recovery')`, id|expiry); only its SHA-256 is stored, verified with `hash_equals` + expiry, reused until it expires (never cached, so a cache wipe does not break sent links). Opt-out token = stateless HMAC of the id (never expires).
- `Suppression`: Salted SHA-256 suppression list (`motored_suppressions`) for WhatsApp and email opt-outs.
- `Schedule`: Pure window logic (default 09:00–21:00 in site timezone).
- `MessageLimiter`: Soft message rate limiting per phone number in Cache (1/24h, 3/week).
- `AbandonedCartEmail`: Subclass of `WC_Email` (`motored_abandoned_cart`) loaded lazily on `woocommerce_email_classes`.

## Unpaid orders (part C)
- Registration (`OrderSync`, no HTTP, <= 2 queries): on order creation and on `woocommerce_order_status_changed` (via `OrderHooks`) a pending/failed/on-hold
  order creates or updates ONE `type=order` row (`order_id`, billing e-mail/phone/name, total, currency, items as IDs). An active cart row of the same
  session or a recent same-e-mail cart is converted into it (same row, progress kept, no second sequence; cart capture is skipped while it is active).
- Paid status (`wc_get_is_paid_statuses()`): `recovered` when a message was delivered (step > 0 and sends > 0, `recovered_order_id` + order meta
  `_motored_recovered_cart_id` + event), otherwise `converted`; `next_action_gmt` cleared so queued jobs find the row inactive and skip.
- Sequences (Settings > Motored > Recovery, 2 steps per case, channel + delay in minutes from the status event + text): pending/failed = WhatsApp 30 min, e-mail 24 h
  (before the WooCommerce unpaid-order cancellation); on-hold = WhatsApp 24 h, step 2 off. Distinct es_AR texts per case. Placeholders: `{first_name} {order_number}
  {order_total} {order_items} {pay_url} {optout_url} {store_name}` (`{recovery_url}` for cancelled). The on-hold text differs from the OrderNotifications "en espera" notice.
- Send time (`OrderStepHandler`, kind `recovery_step`, branch by row type): reloads the order; skips/stops when paid, trashed/deleted, status changed, or (pending/failed)
  `needs_payment()` is false. `{pay_url}` = `get_checkout_payment_url()`; for on-hold orders (never "payable" in WooCommerce) the order-received page with the payment instructions.
  Cancelled by timeout: setting "restore" (new cart through the existing recovery link, own text; e-mail uses the abandoned-cart e-mail) or "expire". Cancelled by a person stops the sequence.
- E-mail: `Emails\PendingPaymentEmail` (`motored_pending_payment`, own subject/heading in WooCommerce > Emails, HTML + plain templates with `{pay_url}`).
- Every automatic or manual send writes a PRIVATE order note (channel, source, result; no phone, no text). The detail screen of an order row shows the order link, current status,
  payment method, fresh total, payment link and the latest native order notes. Manual actions (send WhatsApp/e-mail now, open WhatsApp Web, copy link, note) work on both types.
- Mercado Pago: only standard order statuses/APIs are used (no plugin internals). The handler ALWAYS re-checks the order before sending because the IPN/webhook can be late. See VERIFY #55.

## Hooks and filters used
| Hook/filter | Context | Why | Status |
|---|---|---|---|
| `template_redirect` | storefront | handles recovery and opt-out links via early `isset` | unverified (VERIFY #45) |
| `woocommerce_email_classes` | background/admin | registers `AbandonedCartEmail` (lazy file load) | unverified (#46) |
| `motored_recovery_scan` | AS worker | recurring scanner (every 15 min, LIMIT 50) | verified (#21) |
| `motored_waha_result` | background | advances steps, triggers email fallback on failure | verified (#21) |
| `wc_ajax_motored_capture` | ajax | checkout capture endpoint | unverified (#35) |
| `woocommerce_add_to_cart`, `_removed`, `_quantity_update` | storefront/ajax/REST | logged-in capture | unverified (#36) |
| `woocommerce_checkout_order_processed`, `_store_api_` | storefront/ajax/REST | cart -> order row on creation | unverified (#37, #49) |
| `woocommerce_order_status_changed` (via `OrderHooks`) | any | order row sync, paid/cancel handling | unverified (#49, #51) |
| `wp_enqueue_scripts` | storefront | checkout script (checkout only) | unverified (#39) |
| `motored_recovery_cleanup` | AS worker | retention cleanup | partial (#41) |
| `admin_menu`, `load-{page}`, `woocommerce_update_options_motored_recovery` | admin | screen, actions, settings | unverified (#43) |

## Data model
- Tables (migrations `2026111801`, `2026111901`): `{prefix}motored_recoveries`, `{prefix}motored_recovery_events`, `{prefix}motored_suppressions`.
- Option `motored_recovery` (`autoload=false`): Enabled, abandon_minutes, retention_days, window_start, window_end, coupon_code, token_validity_days, fallback_email, limit_24h, limit_week, step_1..3 config.
- Order meta: `_motored_recovered_cart_id` when a recovery is attributed. Option keys added: `order_enabled`, `order_{pending|failed|on_hold}_step_{1|2}`, `order_cancelled_fallback`, `order_cancelled_text`.

## Main flows
1. Capture & Rescheduling: Captures cart at checkout / cart changes. Sets `next_action_gmt = now + step_1_delay`. New activity postpones `next_action_gmt`.
2. Scanner: Action Scheduler job `motored_recovery_scan` every 15 min queries `status = 'active' AND next_action_gmt <= now LIMIT 50` and enqueues `Sender::enqueue('recovery_step', id, step)`.
3. Send Handler: Verifies sending window, recent orders, stock, opt-out suppression, and per-number limits. Sends WhatsApp or triggers `AbandonedCartEmail`.
4. Result & Fallback: On success, advances step and updates `next_action_gmt`. On failure, triggers email fallback if configured; if sequence finished, status is `expired`.
5. Recovery Link: `?motored_recover={id}.{token}` restores cart items with `add_to_cart()`, applies coupon, sets nocache headers, redirects to checkout.
6. Opt-out Link: `?motored_optout={id}.{token}` shows a confirmation form on GET (mail scanners/previews prefetch links); the POST adds the contact hash to `motored_suppressions` and marks the row `opted_out`.
7. Admin: Settings section in WooCommerce > Settings > Motored > Recovery; Abandoned carts list and detail with "Enviar WhatsApp ahora", "Enviar email ahora", "Abrir en WhatsApp Web", and "Copiar link".

## How to test
`./vendor/bin/phpunit tests/Unit/Modules/Recovery/`. Staging: `docs/STAGING-CHECKLIST.md` and VERIFY rows 35–57.

## Changelog
- 0.5.0 — 2026-11-18 — part C: unpaid-order recovery (`type=order`), per-case sequences, pending-payment e-mail, private order notes, cancelled-order fallback.
- 0.4.0 — 2026-11-18 — part B: 3-step sequence, scanner, email fallback, recovery tokens, suppression table, links handler, and admin manual actions.
- 0.3.0 — 2026-11-17 — part A: tables, capture, conversion, cleanup, admin screen, history, privacy.
