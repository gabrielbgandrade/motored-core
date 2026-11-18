# Module: OrderNotifications

## Purpose
Sends plain-text WhatsApp order notifications equivalent to WooCommerce's standard customer emails (`on-hold`, `processing`, `completed`, `refunded`, `customer_note`) plus an optional internal new-order alert to the store admin. Enqueues jobs via `Waha\Sender` (no tables, no consent checkbox, no media).

## Public contracts
- Option key: `motored_order_notifications` (`autoload=false`), `{enabled, text}` per event plus `include_manual_orders` and `admin_phone`.
- `Waha\Sender` job kind: `order_event`, step = event slug (`on_hold`, `processing`, `completed`, `refunded`, `customer_note`, `admin_new_order`).
- `Renderer::render( string $template, array $vars ): string`, `Renderer::format_items()`, `Renderer::format_price()`.
- `EventMap`: pure mapping between WooCommerce statuses and event slugs.

## Hooks and filters used
| Hook/filter | Context | Why | Verification status |
|---|---|---|---|
| `woocommerce_order_status_changed` (4 args) | admin/ajax/rest/cron/cli/wc-api/POST | enqueue status events & admin alert | unverified (VERIFY #29) |
| `woocommerce_new_customer_note` (1 arg array) | admin/ajax/rest/cron/cli/wc-api/POST | enqueue customer note notification | unverified (VERIFY #30) |
| `motored_waha_result` | admin/cron/ajax/cli | write private order note on outcome | ours (`Waha\Sender`) |
| `woocommerce_admin_field_motored_order_event` | admin | render per-event row in Messages | unverified (VERIFY #33) |
| `woocommerce_update_options_motored_messages` | admin | save per-event `{enabled, text}` | unverified (VERIFY #33) |

## Data model
- Option `motored_order_notifications` (`autoload=false`): `include_manual_orders` (`'no'`), `admin_phone` (`''`), and per event (`on_hold`, `processing`, `completed`, `refunded`, `customer_note`, `admin_new_order`) `{enabled: 'yes'|'no', text: string}`.
- Order meta (via WC CRUD, HPOS-compatible): `_motored_wa_{event}` (`'1'` for status/admin events, `md5($note)` for `customer_note`), `_motored_wa_no_phone_noted` (`'1'`).
- No custom tables or transients.

## Configuration keys
Edited in WooCommerce > Settings > Motored > Messages:
- `include_manual_orders` (`'no'`): also notify orders with `created_via = 'admin'`.
- `admin_phone` (`''`): recipient for `admin_new_order`.
- Per-event `{enabled, text}`: customer events default `'yes'`, `admin_new_order` defaults `'no'`; default texts are short `es_AR` voseo with WhatsApp `*bold*`.

## Main flows
1. **Trigger (lightweight):** Hook reads `motored_order_notifications` once; exits early if event disabled or `created_via === 'admin'` (unless enabled). Checks `_motored_wa_{event}` meta; if absent, enqueues `Sender::enqueue('order_event', $order_id, $event)` and saves meta. Zero HTTP, zero template rendering.
2. **Send time (`Notifier::build_message`):** Reloads fresh `WC_Order`; skips if status no longer matches event (`EventMap::matches_status`). Resolves billing phone + billing country calling code (`WC()->countries`); if invalid/empty, writes one private note (`_motored_wa_no_phone_noted`) and returns `null`. Otherwise renders plain-text message (`{order_items}` max 5 + `"y N más"`, `{order_total}` plain text from `wc_price`, `{order_url}` with guest order key).
3. **Result (`motored_waha_result`):** Writes a private note (`"WhatsApp enviado: {event}"` or `"WhatsApp falló: {short reason}"`) without phone or text. Ignored when `code === 'skipped'`.

### Mercado Pago Checkout Pro mapping (unverified — VERIFY #34)
- Offline ticket / awaiting confirmation -> `on-hold` -> covered by `on_hold` (and `admin_new_order` if enabled).
- Approved payment (immediate return or IPN/webhook) -> `processing` (or `completed` for virtual orders) -> covered by `processing` / `completed`.
- Unpaid / abandoned checkout in `pending` -> intentionally NOT sent here (handled by future `Recovery` module).
- Refunded -> `refunded` -> covered by `refunded`.

## Performance and security notes
Never loads on public GET pages (`should_load` returns false). Checkout/webhook listeners perform at most 1 option read + 1 meta check per active event + 1 AS insert. All order reads/meta/notes use WC CRUD (HPOS-ready). Admin save checks `manage_woocommerce` + nonce + `sanitize_textarea_field`. Logs and order notes never include phone numbers or message text. No theme dependency.

## How to test
- Unit: `vendor/bin/phpunit tests/Unit/Modules/OrderNotifications` (Renderer, EventMap, Notifier idempotency/skip rules/private notes, Module contexts).
- Staging: see the OrderNotifications section of `docs/STAGING-CHECKLIST.md`.

## Known limitations
Per-note idempotency uses `md5($note)`, so re-adding the exact same customer note text is suppressed. Sending depends on the `Waha` module being configured and healthy.

## Changelog
- 0.3.0 — 2026-11-17 — initial OrderNotifications module implementation.
