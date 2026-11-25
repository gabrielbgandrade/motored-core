# Module: OrderNotifications

## Purpose
Sends plain-text WhatsApp order notifications equivalent to WooCommerce's standard customer emails (`on-hold`, `processing`, `completed`, `refunded`, `customer_note`) (customer messages only; there is no admin alert). Enqueues jobs via `Waha\Sender` (no tables, no consent checkbox, no media).

## Public contracts
- Option key: `motored_order_notifications` (`autoload=false`), `{enabled, text}` per event plus `include_manual_orders`. Stored `admin_phone` / `admin_new_order` values from older versions are ignored (no migration).
- `Waha\Sender` job kind: `order_event`, step = event slug (`on_hold`, `processing`, `completed`, `refunded`, `customer_note`).
- `Renderer::render( string $template, array $vars ): string`, `Renderer::format_items()`, `Renderer::format_price()`.
- `EventMap`: pure mapping between WooCommerce statuses and event slugs.

## Hooks and filters used
| Hook/filter | Context | Why | Verification status |
|---|---|---|---|
| `woocommerce_order_status_changed` (4 args) | admin/ajax/rest/cron/cli/wc-api/POST | enqueue status events | unverified (VERIFY #29) |
| `woocommerce_new_customer_note` (1 arg array) | admin/ajax/rest/cron/cli/wc-api/POST | enqueue customer note notification | unverified (VERIFY #30) |
| `motored_waha_result` | admin/cron/ajax/cli | write private order note on outcome | ours (`Waha\Sender`) |
| `woocommerce_admin_field_motored_order_event` | admin | render per-event row in Messages | unverified (VERIFY #33) |
| `woocommerce_update_options_motored_messages` | admin | save per-event `{enabled, text}` | unverified (VERIFY #33) |

## Data model
- Option `motored_order_notifications` (`autoload=false`): `include_manual_orders` (`'no'`) and per event (`on_hold`, `processing`, `completed`, `refunded`, `customer_note`) `{enabled: 'yes'|'no', text: string}`.
- Order meta (via WC CRUD, HPOS-compatible): `_motored_wa_{event}` (`'1'` for status/admin events, `md5($note)` for `customer_note`), `_motored_wa_no_phone_noted` (`'1'`).
- No custom tables or transients.

## Configuration keys
Edited in WooCommerce > Settings > Motored > Messages:
- `include_manual_orders` (`'no'`): also notify orders with `created_via = 'admin'`.
- Per-event `{enabled, text}`: all events default `'yes'`; default texts are short `es_AR` voseo with WhatsApp `*bold*`.

## Main flows
1. **Trigger (lightweight):** Hook reads `motored_order_notifications` once; exits early if event disabled or `created_via === 'admin'` (unless enabled). Checks `_motored_wa_{event}` meta; if absent, enqueues `Sender::enqueue('order_event', $order_id, $event)` and saves meta. Zero HTTP, zero template rendering.
2. **Send time (`Notifier::build_message`):** Reloads fresh `WC_Order`; skips if status no longer matches event (`EventMap::matches_status`). Resolves billing phone + billing country calling code (`WC()->countries`); if invalid/empty, writes one private note (`_motored_wa_no_phone_noted`) and returns `null`. Otherwise renders plain-text message (`{order_items}` max 5 + `"y N más"`, `{order_total}` plain text from `wc_price`, `{order_url}` with guest order key).
3. **Result (`motored_waha_result`):** Writes a private note (`"WhatsApp enviado: {event}"` or `"WhatsApp falló: {short reason}"`) without phone or text. Ignored when `code === 'skipped'`.

### Mercado Pago Checkout Pro mapping (unverified — VERIFY #34)
- Offline ticket / awaiting confirmation -> `on-hold` -> covered by `on_hold`.
- Approved payment (immediate return or IPN/webhook) -> `processing` (or `completed` for virtual orders) -> covered by `processing` / `completed`.
- Unpaid / abandoned checkout in `pending` -> intentionally NOT sent here (handled by future `Recovery` module).
- Refunded -> `refunded` -> covered by `refunded`.

## Performance and security notes
Never loads on public GET pages (`should_load` returns false). Checkout/webhook listeners perform at most 1 option read + 1 meta check per active event + 1 AS insert. All order reads/meta/notes use WC CRUD (HPOS-ready). Admin save checks `manage_woocommerce` + nonce + `sanitize_textarea_field`. Logs and order notes never include phone numbers or message text. No theme dependency.

## Motorcycle stock subscriptions
`BikeDemandNotifier` consumes the BikePages demand contract without coupling business messages into the WAHA transport. Kind `bike_stock_alert`; handlers are registered in worker contexts even when individual order messages are disabled. It uses BikePages `notify_enabled` plus WAHA OFF/test/live safety switches, not the order-event toggles.

- `motored_bike_demand_created`, `motored_compat_product_index_changed`, `motored_compat_catalog_changed` enqueue a unique cursor scan; no HTTP is performed in request listeners.
- AS hooks: `motored_bike_demand_scan` (50 IDs per page), `motored_bike_demand_delivery_daily`, `motored_bike_demand_mail` (ID + bounded attempt). The daily action is installed on admin requests, not frontend GET.
- Business state/contacts live in `BikePages\\DemandStore`, not order meta or scheduler payloads. Stock/visibility/purchasability are rechecked at delivery. Compare-and-swap claims prevent duplicate active workers; completed results do not trigger a second delivery.
- WAHA retries first; terminal reachability failures can fall back to the account's saved email. No phone means native WooCommerce email directly, provided live delivery is enabled. Disabled/test transport, missing configuration and authentication errors pause instead of bypassing the safety switch with real email.
- E-mail is a native `WC_Email` (`Emails\StockAlertEmail`, key `WC_Email_Motored_Stock_Alert`, templates `templates/emails/motored-stock-alert.php` + `plain/`, overridable from the theme like any WooCommerce e-mail). The owner enables/disables it and edits subject (`{site_title}`, `{bike}`), heading and additional text in WooCommerce > Settings > Emails. Disabled = alerts with only e-mail stay `paused` (`email_disabled`) and a WhatsApp failure does not fall back to e-mail (row `failed`). The `motored_bike_demand_message` filter applies to the WhatsApp text only. E-mail acceptance is not proof of inbox delivery. Signed cancellation links contain no contact data. Explicit renewed consent can reactivate a completed/failed one-shot subscription.
- See `BikePages/README.md` for migration `2026112407`, lifecycle states, legacy rows and process-crash limitations. Local delivery verification blocked HTTP and intercepted mail; real WAHA delivery remains a staging/configuration check.

## How to test
- Unit: `vendor/bin/phpunit tests/Unit/Modules/OrderNotifications` (Renderer, EventMap, Notifier idempotency/skip rules/private notes, Module contexts).
- Staging: see the OrderNotifications section of `docs/STAGING-CHECKLIST.md`.

## Known limitations
Per-note idempotency uses `md5($note)`, so re-adding the exact same customer note text is suppressed. Sending depends on the `Waha` module being configured and healthy.

## Changelog
- 0.4.0 — 2026-11-24 — Added BikePages stock-alert consumer, WAHA-first delivery, terminal email fallback, safety switches, bounded scans/retries and delivery tests.
- 0.3.0 — 2026-11-17 — initial OrderNotifications module implementation.
