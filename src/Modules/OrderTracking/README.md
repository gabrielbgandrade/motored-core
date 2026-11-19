# Module: OrderTracking
## Purpose
One tracking entry per order (carrier, code, optional https link). Shown to the customer and sent once per code by WhatsApp and e-mail. No tables, no carrier API.
## Public contracts
`TrackingLink::build( $override, $template, $code )` (pure), `Tracking::from_order()`, `Presenter::vars()` (renderer placeholders `{tracking_carrier} {tracking_code} {tracking_url} {tracking_block}`).
## Hooks and filters used
| Hook/filter | Context | Why | Verification status |
|-------------|---------|-----|------|
| `add_meta_boxes` (screen id) + `woocommerce_process_shop_order_meta` | admin | meta box + save/events | unverified (#90) |
| `woocommerce_order_details_after_order_table` | frontend | order page block | unverified (#91) |
| `woocommerce_email_order_meta` | any | block in processing/completed customer e-mails | unverified (#92) |
| `woocommerce_email_classes` | any | lazy `TrackingEmail` (`motored_tracking`) | unverified (#46) |
## Data model
Option `motored_order_tracking` (autoload=false). Order meta `_motored_tracking` = {carrier_id, code, url_override, updated_gmt}; flags `_motored_tracking_sent_wa|_sent_email` (hash at send) and `_carried_wa|_carried_email` (a completed/processing message or e-mail already carried the code).
## Configuration keys
`carriers` (list: id, name, URL template with `{code}`, active; ships EMPTY), `send_whatsapp`, `send_email`, `show_customer` (all `yes`), `send_delay` (minutes, default 3), `text_added`, `text_updated` (es_AR, voseo, `{a|b}`).
## Main flows
Admin saves code -> if new/changed: per channel, skip when the hash flag or the carried flag equals the code hash -> `Sender::enqueue( 'order_tracking', id, added|updated, delay, HIGH )` and an Action Scheduler job `motored_tracking_email` (same delay; skipped at send time if superseded or carried) running `TrackingEmail::trigger_order()` -> private note (no phone, no text). Skipped with a note: manual orders (unless OrderNotifications includes them), no valid phone/e-mail. WhatsApp text is rendered at send time.
## Performance and security notes
No query on public pages: callbacks read the loaded order meta first, the option only when tracking exists. Save needs nonce + `edit_shop_orders`; code `[A-Za-z0-9_.-]{1,40}`, manual URL https `esc_url_raw` max 300; output escaped; no PII in logs.
## How to test
`tests/Unit/Modules/OrderTracking/`, `OrderNotifications/NotifierTest`. Staging: `docs/STAGING-CHECKLIST.md` (OrderTracking).
## Known limitations
No carrier lookups or status polling; one entry per order; a changed code is sent again.
## Changelog
- 0.1.0 — 2026-11-19 — first version
