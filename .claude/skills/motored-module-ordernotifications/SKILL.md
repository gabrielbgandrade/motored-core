---
name: motored-module-ordernotifications
description: Rules for the OrderNotifications module (WhatsApp order status and customer-note messages via Waha\Sender). Use when touching src/Modules/OrderNotifications or src/Integration/WooCommerce.
---

# Module OrderNotifications
Read `src/Modules/OrderNotifications/README.md` first (flows, option shape, MP mapping).

- Uses `Waha\Sender` (`kind = 'order_event'`, `step = event slug`); customer messages ONLY (the admin alert was removed; stored `admin_*` options are ignored); no tables, no media, no consent checkbox.
- All WooCommerce hooks and internals live ONLY in `src/Integration/WooCommerce/OrderHooks.php` (VERIFY #29-#34).
- Never load on public GET pages (`should_load` allows admin/ajax/rest/cron/cli and `wc-api`/POST only).
- Request listener: max 1 `Options::all` read + 1 `get_meta` check per active event + `Sender::enqueue`; exit early if disabled or `created_via = 'admin'` (unless `include_manual_orders`).
- Idempotency meta `_motored_wa_{event}` saved via WC CRUD (`save_meta_data()`, HPOS-safe) upon enqueue.
- At send time: reload `WC_Order`, skip if status changed (`EventMap::matches_status`), normalize billing phone with billing country calling code; invalid phone -> single private note (`_motored_wa_no_phone_noted`) and skip.
- `Renderer` is pure plain text (WhatsApp `*bold*` ok, never HTML); `{order_items}` <= 5 + `"y N más"`.
- `motored_waha_result` writes private order notes only, never logging/storing phone numbers or message text.
- Update `.pot`/es_AR `.po`/`.mo`, `tests/Unit/Modules/OrderNotifications/`, `VERIFY.md`, and `PROJECT.md`.
