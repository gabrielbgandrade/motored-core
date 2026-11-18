# Module: ReviewRequests

## Purpose
Days after an order reaches a trigger status (default `completed`), sends ONE WhatsApp text asking for a review of the
products (links to the product reviews anchor) and, optionally, of the store. Customers are never filtered by satisfaction.
Uses `Waha\Sender` with priority LOW. No tables.

## Public contracts
None. Sender kind `review_request` (ref id = order id, step `null` automatic or `manual`).

## Hooks and filters used
| Hook/filter | Context | Why | Status |
|---|---|---|---|
| `woocommerce_order_status_changed` (via `OrderHooks`) | all but public GET (+ wc-api/POST) | schedules the request | unverified (VERIFY #29) |
| `motored_waha_result` | admin/cron/ajax/cli | private order note + meta | ours |
| `template_redirect` | frontend | `?motored_review_optout` (exits at `isset`, 0 queries) | unverified (#68) |
| `add_meta_boxes` (screen = `wc_get_page_screen_id('shop-order')`) | admin | "Send now" box | unverified (#69) |
| `admin_post_motored_review_request_send` | admin | manual send (nonce + `manage_woocommerce`) | unverified (#69) |
| `motored_reviews_section_fields` (ours) | admin | fields in the shared "Reseñas" section | ours |

## Data model
Option `motored_review_requests` (autoload=false). Order meta `_motored_review_req` = scheduled timestamp, then `sent`
(idempotency: one request per order, ever). Cache: Recovery's per-number counters. Suppression: Recovery's `motored_suppressions`.

## Configuration keys
`enabled` (no), `trigger_status` (completed), `delay_days` (7), `store_review_url` (''), `max_products` (3), `text` (es_AR, voseo,
`{a|b}` variations). Placeholders: `{first_name} {order_number} {review_links} {store_review_url} {optout_url} {store_name}`.
Edited in WooCommerce > Settings > Motored > Reseñas. The sending window and per-number limits are Recovery's settings.

## Main flows
1. Status change to the trigger status: 1 meta check; `Sender::enqueue( delay = days, moved to the window start, LOW )`; 1 meta write.
2. At send time `RequestHandler` reloads the order and skips when: module off, status differs from the trigger, fully refunded,
   no valid phone, number suppressed (raw or normalized), or every product was already reviewed (ONE comments query by user
   or e-mail). Outside the window or over the per-number limit it re-enqueues itself. Reviewed products are left out of the list.
3. Result: private order note (no phone, no text) and meta `sent`. Opt-out link: GET shows a form, POST suppresses the phone.
4. "Send now" (order box): manual step; ignores trigger status, delay, window and the limit; never refunds/cancelled/suppressed.

## Performance and security notes
Registers hooks only. Public GET: only the `template_redirect` check. Status change: 1 meta read + 1 AS insert + 1 meta write.
HMAC opt-out token per order; nonce + capability on the manual action; no PII in logs, notes or AS args.

## How to test
`vendor/bin/phpunit tests/Unit/Modules/ReviewRequests`. Staging: `docs/STAGING-CHECKLIST.md` (Review requests).

## Known limitations
Opt-out suppresses the billing phone in the shared list (also stops Recovery WhatsApp). A review left under another e-mail is
not detected. The `#reviews` anchor depends on the theme.

## Changelog
- 0.1.0 — 2026-11-18 — initial implementation.
