---
name: motored-module-recovery
description: Rules for Recovery module (cart capture, 3-step sequence, links, unpaid-order recovery, admin) of motored-core.
---

# Module Recovery
Read `src/Modules/Recovery/README.md`. Carts (parts A/B) and unpaid orders (part C, `type=order`) share tables, scanner, Sender, Renderer, limits.

- Capture: server-side cart; <= 3 queries. Frontend: one vanilla `defer` script (< 3 KB) on checkout only.
- Sequence: 3 steps (channel, delay min from last activity, text). Recompute next_action_gmt per step; expired after last.
- Scanner: AS recurring job (15 min, LIMIT 50); enqueues Sender::enqueue('recovery_step', id, step). No HTTP in scanner.
- Send: Window (09:00-21:00); skip if order exists after last activity; skip if out of stock; limit 1/24h, 3/week.
- Fallback: If no WhatsApp/fails permanently, fallback to AbandonedCartEmail (WC_Email loaded in callback).
- Tokens: HMAC(wp_salt) of id|expiry, only SHA-256 in DB, hash_equals, reused until expiry; NEVER store/cache raw tokens. template_redirect via early isset.
- Opt-out: stateless HMAC token; GET only confirms, POST suppresses (salted hash in motored_suppressions); checked before every send.
- Status: active|converted|expired|opted_out|recovered. "Abandoned" is derived. Events via History::log (no PII).
- Conversion: the cart row becomes the order row at creation; payment (paid status) resolves it: recovered + recovered_order_id + order meta _motored_recovered_cart_id.
- Admin: Section Settings > Motored > Recovery + screen Abandoned carts (manual actions with nonce + manage_woocommerce).
- Strings: .pot + es_AR .po (voseo) + .mo. Tests: unit tests for schedule, tokens, suppression, sequence.
- Orders: `OrderSync` (creation + status change, <= 2 queries, no HTTP) keeps ONE row per order; an active cart row of the session/e-mail is converted, never duplicated.
- Order cases (`OrderCase`): pending/failed = WA 30 min + e-mail 24 h; on-hold = WA 24 h. Config keys `order_{case}_step_{n}`; delays count from the status event.
- Send time (`OrderStepHandler`): ALWAYS reload the order (Mercado Pago confirms late); `OrderRecovery::decide` is the pure rule. on-hold never satisfies `needs_payment()`.
- Paid => recovered only if a message was delivered (step > 0 and sends > 0); cancelled by timeout => `order_cancelled_fallback` (restore|expire); trash/delete => expire.
- Every send writes a PRIVATE order note via `OrderNote` (no phone/text). Never read Mercado Pago plugin internals/meta; only WooCommerce order status and API.
