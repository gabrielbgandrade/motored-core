---
name: motored-module-ordertracking
description: Rules for the OrderTracking module (tracking code per order, customer display, dedicated WhatsApp and e-mail, renderer placeholders). Use when touching src/Modules/OrderTracking or the tracking placeholders of OrderNotifications.
---

# Module OrderTracking
Read `src/Modules/OrderTracking/README.md` first (flows, keys, hooks, VERIFY rows).

- No tables, no carrier API. Data = order meta `_motored_tracking` via WC CRUD (HPOS). Code and URL are sanitized in `TrackingLink` / `Tracking::from_array` only.
- Final link = manual https URL > carrier template with `{code}` > empty (`TrackingLink::build`, pure). Carrier list ships empty: never invent URLs.
- Idempotency is per channel with a hash of the code: `_sent_wa` / `_sent_email`; `_carried_*` means a completed/processing message or e-mail already carried it.
- Save path: admin meta box only (nonce + `edit_shop_orders`), then `TrackingNotifier::on_saved`. Never from frontend requests.
- WhatsApp goes through `Sender::enqueue( 'order_tracking', ..., Sender::HIGH )`; text is rendered at send time. Notes never hold phone or text.
- Customer display callbacks read order meta before the option (0 extra queries); never theme classes or hooks.
- New placeholders live in `Renderer::PLACEHOLDERS`; keep `FlowsTest` (placeholder/kind consistency) green.
- After a change: `composer check`, `motored-review`, update `docs/VERIFY.md` (#90-#92) and `docs/PROJECT.md`.
