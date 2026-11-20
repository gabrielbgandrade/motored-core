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
- [ ] WC > Settings > Motored: sections in order (… Estado, Avanzado last); the default section is the first one.
- [ ] Avanzado as shop manager: hidden in the menu, direct URL shows the error notice, no save; as administrator it works (VERIFY #87).
- [ ] Avanzado: toggle "Delete data on uninstall", change "Log level", save, reload: values persist; `autoload` = `no`.
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
- [ ] API key (VERIFY #88-#89): as administrator save a key (field empty afterwards, "Clave guardada"), replace it, save with the field empty (kept), delete with/without the checkbox; view-source never shows the key; change the URL host -> key deleted + warning; `user:pass@` URL rejected; constant defined -> read-only "definida en wp-config.php"; changed salt -> "Ilegible" + "Volvé a ingresar la clave"; shop manager sees no URL/key rows; WooCommerce > Status > Logs has the audit lines (user id) and never the key.
- [ ] Public pages / checkout: 0 extra queries, requests, assets with the module active.

## OrderNotifications (Motored > Messages)
- [ ] Section "Mensajes" renders the 5 customer events, manual-order toggle, variable lists, and "Restaurar texto predeterminado" buttons; saving persists (`autoload` = `no`).
- [ ] Place checkout order -> `on-hold` / `processing` -> WhatsApp arrives with plain total, <=5 items (+ `y N más`), working guest `order_url`; private note `WhatsApp enviado: ...` appears.
- [ ] Order without valid phone -> single private note, no send; status changed before job runs -> skipped; manual admin order skipped unless enabled.
- [ ] Customer note added -> WhatsApp arrives with `{customer_note}`; private note does not trigger customer WhatsApp.
- [ ] Mercado Pago Checkout Pro (approved, offline ticket, abandoned `pending`) triggers expected events and logs `wc-api`/REST webhook behavior.

## OrderTracking (Motored > Seguimiento)
- [ ] Section lists no carriers by default; add one with `{code}` URL: saved (autoload `no`); a template without `{code}` is dropped.
- [ ] Order edit (HPOS on and off): Tracking box in the side column; save code: reload keeps it; link preview opens; http manual link is rejected.
- [ ] New code: ONE WhatsApp (test mode) and ONE e-mail, private notes without phone/text; save again unchanged: nothing; change the code: both again.
- [ ] Completed order whose message carried the code: no dedicated WhatsApp/e-mail; manual order and order without phone/e-mail: skipped with a note.
- [ ] Customer order page and processing/completed e-mails show carrier, code and button (classic and block theme); switch off "Show to the customer": hidden. VERIFY #90-#92.

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

## Photo gallery (InstallGallery)
- [ ] Product edit screen: box "Fotos de colocación" in the side column (`media-views` loaded cleanly, no JS console errors on add/upload); add 3 photos, reorder with `←`/`→`, edit captions (motorcycle), remove one, Update: 2 stay in the saved order; Quick Edit price afterwards keeps `_motored_install_photos` intact.
- [ ] Settings > Motored > Reseñas > Galería de fotos: test placements (`before_tabs` default, `after_tabs`, `tab`, `shortcode`), toggle show/include reviews, confirm `motored_install_gallery` `autoload=no`, and click "Sincronizar ahora" to backfill existing approved review photos into `_motored_review_gallery`.
- [ ] Product page with photos: ONE gallery in the configured placement with `install-gallery.css` and `install-gallery.js` (`defer`, ~3 KB); filter pills ("Todas", "Colocaciones", "Reseñas") reveal hidden thumbnails lazily; a product without photos renders nothing with 0 extra queries / 0 assets (Query Monitor + Network).
- [ ] Dialog lightbox & theme isolation: clicking a strip thumbnail or `+N` opens the native `<dialog>` centered over backdrop (never opening Flatsome Magnific Popup or theme lightbox); image loads `large` (never full original), star rating shows for reviews, arrows / Esc / swipe navigate, "Ver reseña" opens/scrolls to `#reviews` tab, and JS-disabled opens the photo directly.
- [ ] Review with photos: approve it, its photos join the gallery (with rating) without page-view rebuilds; unapprove/spam/trash/delete: they leave; pending review: hidden.
- [ ] New review photos: `ImageProcessor::process_upload()` stores stripped WebP (when supported) or JPEG/PNG with only `thumbnail`, `medium`, `large` sub-sizes in uploads.
- [ ] Shortcode `[motored_install_gallery]` inside a block-theme or page-builder product template prints the gallery once.

## Image optimizer (ImageOptimizer)
- [ ] Motored > Imágenes & Motored > Estado show the active server editor (`GD` or `Imagick`), WebP support status, Media Library pending/optimized counts, and cumulative MB saved; `motored_image_optimizer` has `autoload=no`.
- [ ] Public storefront pages add **0** hooks, queries, and assets from `ImageOptimizerModule`.
- [ ] Enable upload optimization and upload a large photo with EXIF metadata: longest side scales to `max_side`, quality drops to configured `quality`, EXIF/GPS strips when enabled, and WebP sub-sizes generate for JPEG (PNG only if `convert_png_webp` enabled).
- [ ] Run **Escanear sin modificar (prueba)** (dry-run scan): finishes through Action Scheduler `motored_image_opt_batch` (or sync resume) with byte stats and 0 modified files.
- [ ] Run **Optimizar imágenes pendientes** with `bulk_webp_subsizes` OFF (ERP-safe default): file sizes decrease on disk while attachment URLs/filenames remain identical; resume and cancel buttons behave cleanly, and re-running skips `_motored_img_opt` items.

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

## Payment plans (Motored > Planes de pago)
- [ ] Default OFF; enable: products show "6 cuotas sin interés de $X" + "Ver todos los medios de pago" (single, shop, category; shortcode on a block theme); out-of-stock / no price: nothing.
- [ ] Dialog: discounts show "$total (X% OFF)", surcharges only installment + total; variable product updates on variation change and resets.
- [ ] Query Monitor: pages without a link load no script/dialog; the one script is < 3 KB `defer`; no HTTP.
- [ ] Classic checkout with Mercado Pago Checkout Pro: selector visible only for that gateway, fee row (negative/positive, untaxed), totals after coupon and tax; place the order: `_motored_plan_*` meta on the order (HPOS on/off).
- [ ] Pay an unpaid order from its link: same plan/fee. Blocks checkout: no selector, no fee (documented).
- [ ] MP settings with discount/commission/low max installments: warning in the section.

## Payment plans x Mercado Pago sandbox (part B)
Prerequisites: MP plugin 8.10.2, sandbox credentials, Checkout Pro enabled, plugin discount/commission = 0, installments >= 12; in the MP account confirm "cuotas sin interés" up to 12 is active; "Cuotas sin Tarjeta" (`consumer_credits`) is off in the account/plugin.
- [ ] Motored > Status: "Integración con Mercado Pago: OK (plugin 8.10.2)".
- [ ] Cash plan: Checkout Pro offers no credit/debit card (account money, transfer, cash only); the order total in MP = order total.
- [ ] "Cuotas sin Tarjeta" is not offered in any plan (VERIFY #86: real id, MP accepts the exclusion?).
- [ ] Plan 3: account money and 1, 2 or 3 installments; NEVER 6 or more.
- [ ] Plan 9: account money and installments up to 9 (1, 2, 3, 6, 9 as MP offers).
- [ ] Preference value: sum of items = order total (coupon + tax + shipping + plan fee); the preference shows no extra discount/commission.
- [ ] Pending order paid later from its link: same plan limit, same total.
- [ ] Fail closed: break the plan meta of a pending order, press pay: error message in es_AR, NO preference in MP, private note, log without personal data.
- [ ] Approved payment: order meta `Mercado Pago - <id> - installments` <= plan max; reconcile leaves state `ok`. Forced mismatch: on-hold, note, admin e-mail, nothing cancelled or refunded.
- [ ] Update the MP plugin: Status shows "Revisar integración"; repeat the cash/3/9 checks.

## Admin spacing (Motored tab)
- [ ] Planes de pago: columns aligned, "Efectivo / transferencia" not cut, checkboxes centered; at ~700 px the table scrolls sideways.
- [ ] Mensajes: a box per event with spacing, full-width textarea, variables list separated from it.
- [ ] Recuperación (steps), WhatsApp (tests), Estado: spacing and alignment consistent; the CSS file is requested only on the Motored tab.

## Compatibility + Mi Garage (Motored > Compatibilidad & Productos > Compatibilidad)
- [ ] Settings > Compatibilidad: enable off by default; buttons 1 (Install global attributes) and 2 (Seed fixed terms) register all 12 attributes (`pa_posicion` has 4 terms including `Delantero / Trasero`, `pa_indice-velocidad` in ETRTO order, `pa_medida-*` sorted by `name`); `product_brand` detected.
- [ ] Productos > Compatibilidad: CRUD Brands, Models (with aliases), Versions (year range + `0` present, overlap blocked, immutable slug), Fitments, Sizes (`4.10-18` keeps zero), Directed equivalences (with "Create inverse too"), Invalid sizes report.
- [ ] JSON Import/Export: Export downloads `schema_version: 1` JSON; Dry-Run reports new/updated/unchanged/rejected/suspicious/overlaps; batches > 50 queue via `motored_compat_import`; imported versions default to `draft` and ignore AI constraints unless checked.
- [ ] ERP-safe sync: saving a tire updates `motored_compat_product_index`; updating price/stock alone writes 0 rows (Query Monitor). Kit products keep `Ancho/Perfil/Rodado` and per-axle load/speed empty; single tires only auto-fill empty dimensions when enabled.
- [ ] Storefront & Tabs (Storefront, Block theme, Flatsome): `[motored_compat_selector]` cascades Brand -> Model -> Year (+ Version only if multiple); `?moto=` + `&pos=` filter shop/archive & Product Collection, set `noindex`, keep clean product URLs; cards & product page render badges with 0 REST calls when browsing; single product shows `Descripción` -> `Información técnica` (with `Medida completa`) -> `Compatibilidad` (lazy-loaded on open, hidden on non-tires); My Account > `Mi Garage` saves/merges/switches/removes bikes and clears `localStorage` on logout. VERIFY #93–#103.

## Catalog Filters & Search (Motored > Filtros)
- [ ] Settings > Filtros: disabled by default at launch; enabling activates `[motored_buscador]`, `[motored_filtros]`, classic widget `Motored: Filtros de catálogo`, `?oferta=1` and `/filters/*` REST endpoints; optional `accent_color` applies `--motored-accent`.
- [ ] Zero overhead on non-filter pages: blog/checkout/institutional pages load 0 queries, 0 `catalog-filters.css`, 0 `catalog-search.js`, 0 `catalog-filters.js` (Query Monitor).
- [ ] `[motored_buscador layout="tabs"]` & `layout="split"`: Moto tab reuses Compatibility endpoints and saves to Mi Garage; Medida tab cascades Ancho/Perfil/Rodado in any order from versioned `localStorage` JSON (0 REST per click), disables Perfil (`— sin perfil`) for imperial widths (`4.10-18`), allows rim-only search (`Rodado 17`).
- [ ] Smart search: typing >= 2 chars suggests parsed sizes (`120/70ZR17`), rims (`r17`/`rodado 17`), bikes + aliases (`tornado`), and brands (`pirelli`) with 250 ms debounce; pressing Enter submits native Woo search (`/?s=...&post_type=product`).
- [ ] Sidebar `[motored_filtros]` / `Motored: Filtros de catálogo` widget (desktop + mobile off-canvas drawer): active chips with `×` (including `?moto=`), 11 collapsible sections with exact disjunctive counts (kits excluded from Ancho/Perfil/Rodado/Carga/Velocidad; speed sorted in ETRTO order), real `<a>` links work with JS disabled, desktop batches clicks (~700 ms), mobile (`< 768px`) shows `"Aplicar filtros"`.
- [ ] Upgraded Compatibility product tab & `[motored_compat_list]`: SSR HTML grouped by brand in 2 columns (collapsed to 6 brands), heading `"Motos compatibles con {medida}"`, instant alias search, Mi Garage highlight client-side, cap at 120 items with on-demand REST hydration. VERIFY #104–#109.

## Storefront review fixes (2026-11-20) — Compatibility + Mi Garage + Filtros
Run on a classic theme (Flatsome or a fork) AND a block theme; see `docs/REVIEW-FRONTEND-COMPAT.md`.
- [ ] `?moto=` of a bike without tires: bar with factory sizes, empty message and "Quitar filtro" (VERIFY #116). Position buttons without tires are hidden.
- [ ] Tire that fits front AND rear of a bike (e.g. 2.75-18): Compatibility tab says "Delantero / Trasero", never "Kit completo"; a kit product says "Kit completo".
- [ ] Active bike that does NOT fit: product banner says "No coincide con tu …" with factory sizes (never "Elegí tu moto"); the tab line agrees. Turning `show_incompatible_badge` off hides only the red badges on shop cards.
- [ ] Compatibility tab with > 120 bikes: "Ver todas" keeps the same wording/order, toggles "Ver menos"; with the network offline shows the error + "Reintentar"; searching "cb 500" finds "CB500F".
- [ ] Mi Garage: add 6 bikes with max 5 → "Mi Garage estaba lleno, así que se quitó …"; remove → "… eliminada. Deshacer" restores it; two open tabs stay in sync; logout clears local data.
- [ ] Shop without `?moto=` and an active bike: "Tu moto: … Ver solo las cubiertas que le quedan" (off with `garage_cta = no`); with `?moto=` the bar lists the other saved bikes.
- [ ] `?moto=` results: OEM sizes first unless the shopper picks a sorting (`oem_first_sort`).
- [ ] Block theme: badges under prices, bar above the Product Collection, banner once on the block single product (VERIFY #115).
- [ ] Home search: no REST request until a panel is visible (Network tab); size selects show counts; tabs work with arrow keys; smart search works with ↑/↓/Enter/Esc and a screen reader announces the count; typing "120/70-17" + Enter goes straight to the size filter.
- [ ] Sidebar filters: ticking options does not reload; "Aplicar filtros (N)" applies all; counts match the grid on a parent category, a brand archive and a search.
- [ ] Accent color setting changes buttons/borders (was ignored before); keyboard focus is visible on tabs, filters, chips and selector.
- [ ] With `analytics_events = yes` and GTM: `motored_moto_select`, `motored_filter_apply`, `motored_suggest_click`, `motored_size_search`, `motored_garage_cta` reach `dataLayer` without personal data.

## InstallGallery + ImageOptimizer review (2026-11-20)
- [ ] Product with photos on a block theme: placement "above tabs" and "below tabs" print the gallery once (VERIFY #122); Flatsome (or the fork) and Storefront unchanged.
- [ ] Approve a review with a photo while the page cache and Redis are on: the product page shows the photo without a manual purge (VERIFY #121).
- [ ] Empty the spam with hundreds of comments: no slowdown (Query Monitor on the cron/admin request: no `get_comments` per deleted comment).
- [ ] View source of a product with gallery photos: `alt` uses the Media Library alt text, else the caption, else "<producto> — foto de cliente"; thumbnails have `srcset` + `sizes`.
- [ ] Keyboard: Tab to a thumbnail, Enter opens the viewer, arrows navigate, Esc closes and focus returns to the thumbnail; Ctrl/middle click opens the image in a new tab.
- [ ] ImageOptimizer with "Convert new JPEG sub-sizes to WebP" ON: run "Optimize pending images" on JPEGs → every file keeps its `.jpg` URL AND is still a real JPEG (`file` command / response `Content-Type`).
- [ ] Bulk run with `bulk_resize` OFF (default): a 2400 px banner keeps 2400 px; ON: it becomes `max_side`.
- [ ] Cancel a running bulk job: it stays "cancelada" (no new Action Scheduler action appears afterwards).
- [ ] Large originals: each `motored_image_opt_batch` action finishes in < 30 s (Action Scheduler log); the job still completes.
