---
name: motored-module-reviewrequests
description: Rules for the ReviewRequests module (one WhatsApp review request days after an order, via Waha Sender, priority low). Use when touching src/Modules/ReviewRequests.
---

# Module ReviewRequests
Read `src/Modules/ReviewRequests/README.md` first (flows, keys, hooks).

- No tables. One request per order: order meta `_motored_review_req` is the idempotency guard (set only after enqueue succeeded).
- Scheduling = `Sender::enqueue( 'review_request', $order_id, null, $delay, Sender::LOW )` on the existing status listener
  (`OrderHooks::register_status_changed`). Cost on the request: 1 meta read, 1 AS insert, 1 meta write. Args = ids only.
- Send time is the source of truth: `RequestHandler` reloads the order and re-checks status, refund, phone, suppression
  (raw + normalized), window, per-number limit, reviewed products. Skips return null; window/limit re-enqueue.
- Reuse Recovery's `Suppression`, `MessageLimiter`, `Schedule` and window settings; do not copy them. Opt-out is our own
  stateless HMAC link per order (`ReviewOptout`), GET never changes state.
- Message: plain text, `{a|b}` variations, drop the `{store_review_url}` line when the URL is empty (`ReviewRules::drop_lines`).
- Result: private order note without phone or text (`RequestResult`); skipped jobs stay silent.
- Settings live in the shared "Reseñas" section through `motored_reviews_section_fields` (ReviewPhotos uses it too).
- Theme-dependent bits (`#reviews` anchor) and WooCommerce hooks are unverified: VERIFY.md #66-#70.
- Strings: English source + es_AR voseo in `.pot/.po/.mo`. Tests: `tests/Unit/Modules/ReviewRequests`.
- After changes: `composer check`, then `motored-review`; update VERIFY.md and PROJECT.md.
