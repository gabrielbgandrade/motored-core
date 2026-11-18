# Staging checklist (manual, real WordPress)

Environment: staging clone with WooCommerce, Flatsome fork, Mercado Pago (Checkout Pro), Redis, WAHA.
Tick each item; record failures in `PROJECT.md` (Review log).

## Activation
- [ ] Activates without notices; `debug.log` clean (WP_DEBUG + WP_DEBUG_LOG on).
- [ ] Deactivate WooCommerce: admin notice appears in es_AR and nothing else initializes.
- [ ] `wp_options`: `motored_general` exists with autoload `no`.

## Performance (Query Monitor)
- [ ] Home, shop, category, product, cart: 0 queries, 0 HTTP calls, 0 scripts/styles from motored-core.
- [ ] Only `motored-core.php`, `Autoloader.php`, `Plugin.php` (+ `Support/Context.php`) are included on public pages.
- [ ] Checkout (classic, Flatsome): at most 1 deferred vanilla script <3 KB (none expected in stage 1).
- [ ] wp-admin outside WC settings: no motored assets/queries beyond `admin_init` migration check (no-op).

## Object cache
- [ ] Redis ON: Status shows "Persistent object cache: Yes"; settings save/load fine.
- [ ] Redis OFF / `wp redis flush` / service stopped: site and settings work identically.
- [ ] No object-cache drop-in: same behavior; Status shows "No".

## HPOS
- [ ] HPOS ON and OFF: no incompatibility warning for Motored Core in WC > Settings > Advanced > Features.
- [ ] Cart/Checkout Blocks compatibility declared (same screen).

## Settings
- [ ] WC > Settings > Motored: sections General and Status.
- [ ] General: toggle "Delete data on uninstall", change "Log level", save, reload: values persist; `autoload` = `no`.
- [ ] Status: values correct; no Save button.
- [ ] Non-admin (shop manager vs customer) access follows `manage_woocommerce`.

## Locale
- [ ] es_AR: every visible string is translated; voseo in the descriptions.
- [ ] No translation notices ("_load_textdomain_just_in_time") in `debug.log`.

## Uninstall
- [ ] With the option OFF: deleting the plugin leaves data. With ON: `motored_*` options/transients are gone.

## WAHA / WhatsApp (Motored > WhatsApp)
- [ ] Section renders; constants lock the matching fields; saving keeps values (`autoload` = `no`).
- [ ] "Test connection" shows the session status; wrong key shows "API key was rejected"; http URL is refused.
- [ ] "Send test message" delivers to a real number in 3 formats (011 15..., +54 9 11..., 0351 15...).
- [ ] Enqueue from `wp eval`: job in Scheduled Actions (group `motored-core`), jitter 3-15 s, `motored_waha_result` fires.
- [ ] Stop the WAHA session: retries (60 s/300 s), breaker pauses, Status shows "Paused ... until"; logs have no phone/text.
- [ ] Public pages / checkout: 0 extra queries, requests, assets with the module active.

## OrderNotifications (Motored > Messages)
- [ ] Section "Mensajes" renders all 6 events, manual-order toggle, admin phone, variable lists, and "Restaurar texto predeterminado" buttons; saving persists (`autoload` = `no`).
- [ ] Place checkout order -> `on-hold` / `processing` -> WhatsApp arrives with plain total, <=5 items (+ `y N más`), working guest `order_url`; private note `WhatsApp enviado: ...` appears.
- [ ] Order without valid phone -> single private note, no send; status changed before job runs -> skipped; manual admin order skipped unless enabled.
- [ ] Customer note added -> WhatsApp arrives with `{customer_note}`; private note does not trigger customer WhatsApp.
- [ ] Mercado Pago Checkout Pro (approved, offline ticket, abandoned `pending`) triggers expected events and logs `wc-api`/REST webhook behavior.

## Recovery (stage 4)
- [ ] Activation creates both tables with indexes; Scheduled Actions has `motored_recovery_cleanup` (daily, group `motored-core`).
- [ ] Checkout (classic): one deferred script < 3 KB, nothing else; typing e-mail/phone creates ONE row; cart content equals the server cart.
- [ ] Order-received and every other public page: no script, 0 extra queries/requests from Motored.
- [ ] Capture endpoint: <= 3 queries (Query Monitor); bad nonce 403; > 6 hits/min per session 429.
- [ ] Logged-in customer with phone: add/change/remove item writes once per 5 min; nothing on page views.
- [ ] Placing an order (classic and Blocks) marks the row `converted` and logs `order_created`.
- [ ] WooCommerce > Carritos abandonados: filters, search, sort, pagination, summary (cached 5 min), detail (stock/unavailable), note, delete single/bulk.
- [ ] Tools > Export/Erase personal data works by e-mail. Uninstall with delete option drops tables and the job.

## Recovery part C (unpaid orders)
- [ ] Card/Mercado Pago pending, rejected and abandoned payments, transfer and cash (on-hold): one `type=order` row per order (filter Type = Order); cart row of the same session converted, not duplicated.
- [ ] Pending/failed: WhatsApp ~30 min, e-mail ~24 h; on-hold: WhatsApp ~24 h, no second step. Text differs per case and from the immediate "en espera" notice.
- [ ] Pay before the first step (also with a late IPN): nothing is sent; row `converted`. Pay after a message: row `recovered`, order meta set, event logged.
- [ ] `{pay_url}`: pending/failed open "Pay for order"; on-hold shows payment instructions; open it with a Mercado Pago order.
- [ ] Let an order time out (cancelled): step 2 follows the fallback (restore link / expire); cancel manually: sequence stops; trash: nothing is sent.
- [ ] Each send adds a private order note without phone/text; detail screen shows order link, status, payment method, total and latest notes; manual actions work.
- [ ] WooCommerce > Settings > Emails lists "Pending payment reminder (Motored)"; HTML and plain render with the pay button.
- [ ] Query Monitor: status events and checkout add <= 2 queries from Motored, no HTTP.

## Review fixes (2026-11-18)
- [ ] Opt-out link: GET shows "¿Querés dejar de recibir…" with a button; only the POST suppresses. A link preview/scanner opening it changes nothing.
- [ ] Recovery link from step 1 still works after step 2 is sent and after flushing the object cache.
- [ ] Deactivate the plugin: no pending `motored_*` actions in Scheduled Actions; reactivate: scan/cleanup are back.

## Review photos
- [ ] WooCommerce > Settings > Motored > Reseñas: enable, limits saved (`motored_review_photos`, autoload no).
- [ ] Product page: one deferred script (< 2 KB) and the field "Agregar fotos (opcional)"; other pages: none (Network + Query Monitor).
- [ ] Review with 3 photos as guest and logged in; 4th photo / over 5 MB / SVG / GIF are blocked in the browser and ignored by the server.
- [ ] Stored photos: <= 1600 px, no EXIF/GPS, random names, attached to the product; spam-marked review gets none.
- [ ] Approved review shows thumbnails (new tab); pending review does not; layout OK in every theme of the matrix.
- [ ] Delete the review: photos removed from the Media Library; privacy eraser removes them by e-mail.

## Review requests
- [ ] Settings > Motored > Reseñas: enable, status, days, store URL, max products saved (`motored_review_requests`, autoload no); ReviewPhotos fields still there.
- [ ] Complete an order: one Scheduled Action (`motored_waha_send`, kind `review_request`, low); order meta `_motored_review_req` set; complete again: no second action.
- [ ] Delay 1 day on staging: message arrives inside the window, lists only unreviewed products, links open the reviews section, opt-out works.
- [ ] Refunded/cancelled/trashed before the send, suppressed number, all products reviewed: skipped, no message.
- [ ] Order > "Review request" box: "Send now" queues a message; private notes carry no phone or text.

## VERIFY.md
- [ ] Work through every `unverified`/`partial` row; update statuses.

## Theme matrix (the plugin must not depend on the theme)
Repeat the rows below on each theme; the result must be identical. Storefront = classic (shortcode checkout, PHP
templates); Twenty Twenty-Five = block theme (Blocks checkout, block comments); Flatsome = third-party theme with
its own templates.

| Check | Storefront (classic) | Twenty Twenty-Five (blocks) | Flatsome |
|---|---|---|---|
| Checkout capture: e-mail/phone/name typed, autofilled and prefilled (logged in) produce one capture POST ~1.5 s later; row appears in Abandoned carts; no console errors | [ ] | [ ] (Blocks checkout) | [ ] |
| Review photos: exactly one field in the review form; 3 photos upload; thumbnails once per approved review; nothing on other pages | [ ] | [ ] | [ ] |
| Opt-out and recovery pages: neutral standalone page, POST confirms, invalid link 400; recovery link redirects to checkout with the cart restored | [ ] | [ ] | [ ] |
| E-mails: abandoned-cart and pending-payment HTML + plain render with WooCommerce header/footer; override in `theme/woocommerce/emails/motored-*.php` is picked up | [ ] | [ ] | [ ] |
| Network: public pages load no Motored asset except the checkout script and the product-page review script | [ ] | [ ] | [ ] |

## Launch safety (WhatsApp switch)
- [ ] Fresh install: Status shows "Off"; a status change sends nothing and leaves the note "envío desactivado".
- [ ] Switch on: Status "Test mode (N)"; only a listed number receives messages; others get the note "modo de prueba".
- [ ] Test mode off: real customers receive; Status shows "Real". Recovery is off until enabled.

