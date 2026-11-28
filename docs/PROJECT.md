# Project

## Decisions
Format: date — decision — reason. Divergences from the original brief are marked **DIVERGENCE**.

- 2026-11-17 — No consent checkbox in this phase; opt-out link in commercial messages (module-level). — Product decision.
- 2026-11-17 — WhatsApp messages are text only. — Product decision.
- 2026-11-17 — Backend and frontend always `es_AR`; English source strings + es_AR `.po/.mo`. — Store locale.
- 2026-11-17 — Cart recovery and pending/failed-order recovery are one module ("Recovery"), with per-item event history and its own admin screen. — Shared pipeline.
- 2026-11-17 — PSR-4 autoloader of our own, no Composer in runtime. — Zero runtime dependencies, lazy loading.
- 2026-11-17 — One option per module (`motored_{module}`), autoload=false; WooCommerce fields use `motored_{module}[key]` ids with `'autoload' => false` (verified in `WC_Admin_Settings::save_fields` source). — Avoid autoload bloat.
- 2026-11-17 — Migrations registry is `Plugin::migrations()` (id => callable), not a collection filled by modules. — Modules may not be loaded in the admin/activation context where migrations must run.
- 2026-11-17 — Migration ids are `YYYYMMDDNN`, a single high-water-mark version option. — Simple ordering; ids must increase over time.
- 2026-11-17 — **DIVERGENCE:** `Support/Cache` has a `counter()` reader and `bump()` in addition to the requested methods. — Counters are stored unwrapped so `wp_cache_incr` works; invalidation needs `bump`.
- 2026-11-17 — **DIVERGENCE:** no `Contracts/Migration.php`; migrations are plain callables. — Keeps the requested structure.
- 2026-11-17 — **DIVERGENCE:** `.mo` compiled with a small Python writer (no `msgfmt` in dev env; round-trip verified with Python `gettext`); `.pot` hand-maintained (no `xgettext`). Re-run `bin/i18n.sh` where gettext exists. — Tool availability.
- 2026-11-17 — Uninstall removes options and transients only; table drops and Action Scheduler cleanup are added by the first module that creates them. — No tables exist yet.
- 2026-11-17 — No `WC requires at least` header; minimum WooCommerce version unverified (see `VERIFY.md`). — Unverified.
- 2026-11-17 — Multisite is out of scope (single-site store). — Infra.

- 2026-11-17 — Waha module is transport only; other modules enqueue by kind and register a handler that builds phone + text at send time (nothing personal in Action Scheduler args). — Privacy and fresh data.
- 2026-11-17 — Jitter (3-15 s) is applied when scheduling, never with `sleep()`. Rate limit / breaker hold = reschedule. — No blocked workers.
- 2026-11-17 — **DIVERGENCE:** `Result` has an extra `data` array (whitelisted parsed fields); core filter `motored_status_rows` added so modules extend the Status section; `Plugin::MODULES` now lists Waha. — Needed by the spec.
- 2026-11-17 — Default limits: 6/min, 100/h, breaker after 5 consecutive service failures for 15 min (all configurable). — Conservative WhatsApp anti-ban defaults, to tune on staging.
- 2026-11-17 — `OrderNotifications` uses `Waha\Sender` (`kind = 'order_event'`, `step = event slug`); per-event `{enabled, text}` is rendered via custom WC field type + `woocommerce_update_options_motored_messages` because `WC_Admin_Settings` bracket parsing only handles one level (`option[key]`). — Preserves requested option structure cleanly.
- 2026-11-17 — `customer_note` idempotency stores `md5($note)` in `_motored_wa_customer_note` (instead of a static `'1'`) so duplicate triggers are suppressed while subsequent distinct notes still send. — Practical order note flow.
- 2026-11-17 — `Plugin::MODULES` now also registers `OrderNotificationsModule`; loads in admin/ajax/rest/cron/cli and `wc-api`/POST requests (never public GET pages). — Supports Store API and Mercado Pago IPN without touching public GET pages.

- 2026-11-17 — **DIVERGENCE:** Recovery roadmap re-split as requested: stage 4 = part A (data, capture, admin, history, privacy), stage 5 = sending flows for carts, stage 6 = pending/failed orders. The privacy exporter/eraser and admin screen moved into stage 4. — Product request.
- 2026-11-17 — Recovery stores IDs/qty/attributes only (no names or prices); admin reads product data and totals fresh; the cart content never comes from the client. — Data minimization and integrity.
- 2026-11-17 — "Abandoned" is derived on read (active + inactive longer than the setting); nothing writes it. Capture rate limit: 6 hits/min per session, 30/min per IP (Cache, hashed keys). Logged-in capture: 5 min throttle per user, written at `shutdown`. — Keeps the 3-query budget and avoids jobs.
- 2026-11-17 — Retention: converted/recovered rows 7 days, the rest 30 days (setting); daily Action Scheduler job in batches of 500, scheduled on activation and repaired from `admin_init` (1 h Cache flag). Tables and the job are removed on uninstall (delete-data option). — Minimal data kept.
- 2026-11-17 — **DIVERGENCE:** phpstan runs with `treatPhpDocTypesAsCertain: false` and the WooCommerce *packages* stubs (Action Scheduler functions); `phpcs.xml.dist` declares `manage_woocommerce` as a known capability; `Plugin::migrations()` entries may be closures (first-class callable). — WooCommerce stubs type runtime-nullable properties via PHPDoc.
- 2026-11-18 — Recovery part B: 3-step sequence (channel, delay min, text), Action Scheduler scanner (15 min, LIMIT 50), send handler enforcing window (09:00-21:00) and frequency limits (1/24h, 3/week), email fallback via `AbandonedCartEmail` (`WC_Email`), recovery tokens/links (`?motored_recover`, SHA-256, 7-day validity, coupon, nocache), opt-out suppression table `motored_suppressions` (`2026111901`), and admin manual send/link actions. — Automated abandoned cart messaging with privacy compliance.

- 2026-11-18 — Unpaid-order recovery reuses the cart tables/scanner/Sender/Renderer/window/suppression/limits/history/screens; the cart row of the same session (or recent same e-mail) is CONVERTED into the order row (one sequence, progress kept). Registration costs <= 2 queries (lookup + write), no HTTP; an extra history insert only when a recovery is attributed. — Part C requirements.
- 2026-11-18 — Order cases and defaults: pending/failed = WhatsApp 30 min (before WooCommerce's unpaid-order cancellation, 60 min by default) + e-mail 24 h; on-hold = WhatsApp 24 h, step 2 off; delays count from the status event; separate es_AR texts per case (on-hold text differs from the OrderNotifications notice). Configurable in Settings > Motored > Recovery. — Part C requirements.
- 2026-11-18 — The order is ALWAYS reloaded at send time (Mercado Pago/IPN can confirm late). `needs_payment()` gates pending/failed only, because WooCommerce never treats on-hold orders as payable; on-hold `{pay_url}` is the order-received page with the payment instructions. Mercado Pago internals are never read (VERIFY #55). — Part C requirements.
- 2026-11-18 — **DIVERGENCE:** "recovered" requires a delivered message (step > 0 AND sends > 0) rather than step > 0 alone, and is decided when the order is PAID (not on creation, which also changes the cart behavior of part B). A cancellation by a logged-in person stops the sequence; a timeout cancellation uses the configurable fallback (restore through the existing recovery link, or expire); trashed/deleted orders expire. — Avoids false attribution and messaging customers whose order was cancelled on purpose.
- 2026-11-18 — Part C needs no migration: `type = order` and `order_id` already exist; `session_key` stays on the converted row so cart capture is skipped while the order row is active, and finished order rows release it. — Reuse of tables.

- 2026-11-18 — Recovery/opt-out tokens are HMACs keyed with `wp_salt('motored_recovery')` (no raw token stored or cached; recovery token reused until expiry; opt-out token stateless). Opt-out requires a POST confirmation. — Review: cache-loss rotation broke sent links; raw tokens sat in cache/transients; link prefetchers could opt people out.
- 2026-11-18 — Action Scheduler jobs are removed on deactivation and on every uninstall (data only when "delete data" is on). — Review: recurring jobs kept firing with no listener.

- 2026-11-18 — Waha anti-block hardening (low expected volume, so soft guarantees only): job priority (`high` OrderNotifications, `normal`, `low` Recovery; 5th AS arg, old 4-arg jobs run as normal); global random spacing 20-60 s via a Cache slot (empty cache = scheduling jitter only); daily cap 150 per site-timezone day in Cache, `low` limited to 60% of it, over-cap = reschedule to local midnight; typing simulation (startTyping, 0.5-3 s pause in the worker, stopTyping; on by default, failures ignored); session health cached 60 s (down = reschedule + breaker failure); `{a|b}` text variation in `Renderer` with 1-2 variations in the es_AR default texts (stored custom texts unchanged). New settings in WhatsApp section, "sent today" in Status. Endpoints unverified (VERIFY #59). — Reduce WhatsApp ban risk.

- 2026-11-18 — ReviewPhotos module: photos on the native WooCommerce review form; Media Library attachments + comment meta (`_motored_review_photos`), no tables; disabled by default. Upload only on `comment_post` (never loses the comment); JPEG/PNG/WebP validated by `wp_check_filetype_and_ext` + `getimagesize`; re-encoded with the GD editor only (1600 px, q82, drops EXIF/GPS; rejects the photo on failure); per-IP limit 5 photos/hour in Cache; photos deleted with the comment and by the privacy eraser. A top-level `comment` on a product is also treated as a review (WooCommerce versions differ, VERIFY #62). Uninstall keeps the Media Library files. — Customer photos on reviews with minimal surface.

- 2026-11-18 — ReviewRequests module: ONE WhatsApp review request per order, scheduled with `Sender::enqueue` (priority LOW, delay = days moved to the Recovery window start) from the existing status listener; no own AS hook and no tables; idempotency by order meta `_motored_review_req`; everything is re-checked at send time (status, full refund, phone, suppression, window, per-number limit, already reviewed). Reuses Recovery's suppression, per-number limiter and window settings; own stateless per-order opt-out link (Recovery's link needs a recovery row). Manual "Send now" uses priority NORMAL and skips trigger/window/limit only. Settings share the "Reseñas" section with ReviewPhotos through the `motored_reviews_section_fields` filter (`Admin\ReviewsSection`). `Renderer` gained `{review_links}` and `{store_review_url}`. Disabled by default. — Reviews without new infrastructure.

- 2026-11-18 — PaymentPlans module, part A: the ERP price is the 6-installment list price; plans (cash -10%, 3 -5%, 6 0%, 9 +10%, 12 +20%) are rows in option `motored_payment_plans` (OFF by default) edited in Motored > Planes de pago. Money is integer cents (`PlanCalculator`, half up, leftover cents on the last installment); texts are formatted on the server only (discounts show "% OFF", surcharges never show the percentage). Storefront uses core hooks (single/loop priority 11) + shortcode, ONE shared `<dialog>` and a < 3 KB defer script only on pages with a link. Classic checkout only: plan in the WC session while `woo-mercado-pago-basic` is chosen, untaxed native fee on base = product lines after coupons + taxes (no shipping), order meta `_motored_plan_*`. Blocks and the Mercado Pago installments/preference integration (part B) are deferred. — Product request.
- 2026-11-18 — **DIVERGENCE:** the storefront needs the plans, so public product/shop pages read option `motored_payment_plans` once (0 queries with Redis, 1 without) instead of 0 queries; `register()` itself reads nothing and nothing loads when the module is off beyond that read. The variation script listens to WooCommerce's jQuery events only when `window.jQuery` exists (the script itself uses no jQuery). — The spec requires the price to come from the server and `found_variation` updates.
- 2026-11-18 — The MP gateway option keys (`gateway_discount`, `commission`, effective only with `<key>_checkbox` = yes; `installments`, 0 = 12) were read in the MP plugin 8.10.2 source copy under `reference/`; read-only admin warnings. — Verification of an unverified API (VERIFY #77).

- 2026-11-18 — PaymentPlans part B: the MP plugin (8.10.2, read in `reference/`) has no preference filter, so `PreferenceFilter` rewrites the outgoing POST to `/v1/asgard/preferences` (`http_request_args` 9999) and blocks through `pre_http_request` (priority 1) when the plan limit cannot be applied (**fail closed**: unreadable body, bad plan meta, total mismatch, unidentifiable order while plans are enabled). Installments = min(plugin, plan max); cash plans merge `credit_card`+`debit_card` exclusions (never `account_money`). New order meta `_motored_plan_type`. Version guard `VALIDATED_VERSION` = 8.10.2 (warning only). `Reconciler` (status change + 120 s Action Scheduler job `motored_plans_reconcile`) compares the plugin's order meta (`Mercado Pago - <id> - installments`; `mp_installments` is NOT written by Checkout Pro) with the plan: divergence = note + on-hold + admin e-mail, never cancel/refund. The module now also loads in cron. — Product request.

- 2026-11-18 — OrderNotifications: the internal admin new-order alert (field "Número de WhatsApp del admin", event `admin_new_order`, listener branch, defaults, texts) was REMOVED; the module sends customer messages only. Old stored `admin_phone` / `admin_new_order` values stay in the option and are ignored (no migration). The PaymentPlans divergence e-mail to the administrator is another flow and stays. — Product request.

- 2026-11-19 — Admin CSS root cause (inconsistent th/td padding, per-table rules, inline styles): ONE shared stylesheet `assets/admin/motored-admin.css` (moved from `assets/css/`) with reusable `.motored-table` (same th/td padding, column width classes, 100% inputs, horizontal scroll), `.motored-box` and `.motored-field`, emitted through `Admin\Ui`; loaded by `AdminAssets` only on the Motored tab, the order edit screen (legacy and HPOS edit/new) and the Recovery screen (also a plugin screen with tables; it had inline styles). All tables/meta boxes/Mensajes boxes/WhatsApp tests were converted; inline styles and the WC field `css` option were removed. Rule in AGENTS.md (#10), `motored-add-module`, `motored-review` and `SharedAdminUiTest` (fails on `<table` outside `Ui`, inline `style=`/`css`, unknown `motored-` classes, meta boxes without `.motored-box`). Not covered: the ReviewPhotos comment meta box has the class but the CSS is not loaded on the comment screen (kept to the requested screens); WC e-mail settings keep the WC `css` option (WooCommerce screen, not ours). OrderTracking carriers: no editable id (slug of the name, read-only after save), template must be https + `{code}` (row rejected with an es_AR error and example), static preview. — Product request.
- 2026-11-18 — Admin spacing (superseded by the entry above; file now `assets/admin/motored-admin.css`, rules start with `.motored-`): one stylesheet `assets/css/motored-admin.css` (CSS only, no JS/dependencies), enqueued by `Admin\AdminAssets` only on WooCommerce > Settings > Motored; every rule is scoped with `.motored-admin` (wrapper printed by `SettingsTab::output`), no own colors or fonts (borders derive from `currentColor`). Wide tables (plans, recovery steps) scroll horizontally inside `.motored-table-wrap`. Inline styles and the hard-coded notice colors of the WhatsApp tests were replaced by classes / WP `notice-*`. — Product request.

- 2026-11-18 — Settings menu: the "General" section was renamed "Avanzado" (id `advanced`, option `motored_general` unchanged) and is always the LAST section (priority 2000, after "Estado"). The tab's default section ('' in the URL) is now the first registered section instead of General. — Product request.

- 2026-11-18 — "Avanzado" needs `manage_options`: `SectionRegistry::add(..., $capability)` + `allowed()/visible()`; shop managers (`manage_woocommerce`) do not see it in the menu, get an error notice on the direct URL (nothing is read or rendered), and `SettingsTab::save()` (nonce `woocommerce-settings` + capability) ignores the POST with a notice; `advanced_settings()` returns no fields without the capability; `uninstall.php` also requires `delete_plugins` when a user is logged in. Reading the option `motored_general` by code (log level) is unchanged. — Product request.

- 2026-11-19 — Compatibility + Mi Garage module (Phases 1–7): motorcycles map to canonical tire sizes (`motored_compat_*`, `year_to = 0` sentinel for open-ended "present", immutable version slug), products expose native global attributes (`pa_posicion` with 4 terms including `delantero-trasero`, ETRTO `menu_order` on `pa_indice-velocidad`, `name` on size attributes, native `product_brand`) synced via `terms_hash` into `motored_compat_product_index` (`product_id` PK only, no `variation_id` since store uses simple products exclusively, including simple-product kits/combos). Kits omit `Ancho`/`Alto`/`Rodado` and never infer shared `min(front, rear)` load/speed. Directed 1-hop equivalences originate ONLY from OEM fitments (non-transitive). Public HTML stays page-cache safe (`<span hidden data-motored-fit>` primed in 1 `the_posts` query; client-side evaluation from `localStorage` with 12 h TTL revalidation and 0 REST calls during normal browsing); `?moto=` + `&pos=` add `noindex, follow` via `wp_robots`. Product tabs: `Additional information` -> `Technical information` (+ `_motored_full_size` / generated Full size) and lazy-loaded `Compatibility` tab (`IntersectionObserver`, shared Redis spec cache). JSON v1 import/export only (no CSV) with dry-run, AS 50-version batching, draft default, and AI constraint import disabled by default. — Product request.
- 2026-11-19 — **DIVERGENCE:** Compatibility storefront hooks read `motored_compatibility` once on WooCommerce catalog/product/account requests (0 queries with Redis, 1 non-autoloaded read without) and `CompatCache` uses `Support\Cache` ONLY when `Cache::is_persistent()` is true (never falling back to `wp_options` transients on public paths). Conditionally loads up to 4 small vanilla `defer` scripts (`compat-selector.js` 2.7 KB, `compat-tab.js` 2.5 KB, `compat-garage-page.js` 1.4 KB on `mi-garage`, `compat-garage.js` 3.8 KB on WC shop/product/account pages — never on checkout). — Performance & page-cache safety.

- 2026-11-20 — InstallGallery overhaul (Phase 1 fixes): `before_tabs` default placement (`after_tabs`, `tab`, `shortcode` via `ProductPageHooks`), dedicated theme-agnostic `install-gallery.css`, native `<dialog>` centered via inner container (no `position: relative` on `<dialog>`) with capture-phase `.no-lightbox` theme isolation, source filters + review star ratings, admin `media-views` dep + `post` context in `wp_enqueue_media()`, caption preview & reorder arrows, quick-edit `present` guard, one-click review backfill (`motored_ig_sync_reviews`) + migration `2026112401` (`GalleryStore::rebuild_all()`). — Product & storefront reliability.

- 2026-11-20 — Shared `Support\ImageProcessor` + `ImageOptimizer` module (Phase 2): extracted upload/in-place processing out of `ReviewPhotos` into `Support\ImageProcessor`; added `ImageOptimizerModule` (`should_load` = `! $context->is_frontend()`, 0 public overhead), native WP upload filters (`big_image_size_threshold`, `wp_editor_set_quality`, `image_editor_output_format`, `intermediate_image_sizes_advanced`, `image_strip_meta`, `wp_generate_attachment_metadata`), and Action Scheduler cursor-batched bulk compression (`motored_image_opt_batch`) with dry-run scan and ERP-safe in-place defaults (`bulk_webp_subsizes` OFF by default so attachment filenames/URLs remain unchanged). — Media weight & ERP safety.

- 2026-11-27 — Motored is ONE product in two packages: motored-core = engine (data, rules, markup, behavior, structural CSS), the store theme = skin (look and placement). They meet only at `docs/THEME-CONTRACT.md` (contract 1.0: `--motored-*` input tokens, shortcodes, BEM classes, `Motored\Core\Api`, `window.Motored.garage`, `motored:garage` / `motored:filters-updated`, `motored_garage_trigger`). Plugin storefront CSS has no `!important` and color literals only as token fallbacks (`ThemeContractTest`). — Plugin CSS shipped the brand look and the theme overrode it with 153 `!important`; the theme read plugin localStorage keys and options; the plugin called `Flatsome.attach`. Each side kept overwriting the other.

## Roadmap
| Stage | Scope | Status |
|-------|-------|--------|
| 1 | Base plugin: skeleton, quality tools, module infrastructure, settings tab, i18n, docs, skills | **done** (staging validation and independent review pending) |
| 2 | WAHA module: client, session health, send text via Action Scheduler, masked logging | **implemented** (transport only; tests/phpcs/phpstan not yet run, see review log) |
| 3 | Notifications module (`OrderNotifications`): order status + customer note messages, admin alert, templates (text only) | **implemented** (tests/phpcs/phpstan not yet run, see review log) |
| 4 | Recovery part A: tables, cart capture (checkout script + logged-in), conversion, cleanup job, admin screen, history, privacy exporter/eraser | **implemented** (see review log) |
| 5 | Recovery part B: sending flows for abandoned carts (WhatsApp via `Sender`), recovery link/token, coupons, Mercado Pago return handling | **implemented** (see review log) |
| 6 | Recovery part C: pending/failed/on-hold orders (`type = order`) | **implemented** (see review log; reporting deferred) |
| 7 | Hardening: load/perf validation on staging, docs/skills final pass, release | planned |
| 8 | ReviewPhotos: photos on native product reviews | **implemented** (see decisions; VERIFY #60-#65 pending staging) |
| 9 | ReviewRequests: one review request per order after N days | **implemented** (VERIFY #66-#70 pending staging) |
| 10 | PaymentPlans part A: plans, calculator, storefront line + dialog, classic checkout selector and fee, admin warnings | **implemented** (VERIFY #73-#79 pending staging); part B (Mercado Pago limit, fail closed, reconciliation) **implemented** (VERIFY #80-#85 pending sandbox); Blocks planned |
| 11 | Compatibility + Mi Garage: size parser, 12 global attributes, 7 tables + ERP-safe index sync, admin CRUD + JSON v1 import/export, `?moto=` + selector, 3 product tabs, Mi Garage (`mi-garage`) & client-side badges | **implemented** (Phases 1–7; VERIFY #93–#103 pending staging; needs independent `motored-review`) |
| 12 | InstallGallery: second product gallery (installation photos + approved review photos), placement modes, filter pills, native media picker & dialog | **implemented** (fixed & upgraded; VERIFY #112–#114, #117 pending staging) |
| 13 | ImageOptimizer + shared `Support\ImageProcessor`: native WP upload compression/WebP/EXIF strip + AS ERP-safe bulk optimizer | **implemented** (VERIFY #118–#120 pending staging) |

Each module stage delivers: module README, module skill (`motored-module-<name>`), VERIFY.md rows, tests, es_AR strings.

## Review log
Record each review: date, reviewer, scope, findings, fixes, pending items.

### 2026-11-17 — Stage 1 authoring (self-review; needs independent review)
Run with PHP 8.5.9 (project targets 8.1–8.3; 8.1/8.3 runs pending).
- PHPCS: reserved-keyword param names (`$namespace`/`$default`) renamed; missing docblocks fixed; `hide_save_button` global covered by scoped `phpcs:ignore` in `SettingsTab::output`.
- PHPStan: `SettingsTab::output()` return type, constants in `tests/phpstan-bootstrap.php`, `@param-out` for `$found`.
- Brain Monkey cannot propagate by-ref `$found`: in-memory fakes in `tests/stubs/object-cache.php`.
- Result: phpcs clean, phpstan level 6 no errors, phpunit 52 tests / 125 assertions OK.
- Pending: every `VERIFY.md` row `unverified`/`partial`; staging checklist; PHP 8.1/8.3 runs; independent `motored-review`.

### 2026-11-17 — Stage 2 Waha authoring (self-review; needs independent review)
Environment had NO `php`/`git`: `composer check`, phpunit, phpcs and phpstan were NOT run; code and tests were written
and reasoned by hand (phone cases were cross-checked with a Python port). `.pot`/`.po`/`.mo` updated (74 entries,
`.mo` read back with Python gettext).
- Known risks to check first: phpcs (camelCase method names carry scoped `phpcs:ignore`), phpstan level 6 on
  `Sender`/`WahaAdmin` (`never` return type), PHPUnit/Brain Monkey expectations in `SenderTest`.
- Pending: VERIFY.md rows 21-28 on a real WordPress + WAHA; independent `motored-review`.

### 2026-11-17 — Stage 3 OrderNotifications authoring (self-review; needs independent review)
Environment had NO `php`/`git`: `composer check`, phpunit, phpcs and phpstan were NOT run; code and tests were written
and verified against `vendor/php-stubs/woocommerce-stubs/` and WPCS sniffs by inspection. `.pot`/`.po`/`.mo` updated
(110 entries, `.mo` compiled and read back with Python `gettext`).
- Known risks to check first: `NotifierTest` Mockery mocks for `WC_Order`/`WC_Order_Item`, PHPStan level 6 on
  `OrderHooks::country_calling_code` / `OrderNotificationsAdmin::save_events` (`wp_unslash`), PHPCS on inline script.
- Pending: VERIFY.md rows 29-34 on a real WordPress + WooCommerce + Mercado Pago Checkout Pro; independent `motored-review`.

### 2026-11-17 — Stage 4 Recovery part A authoring (self-review; needs independent review)
Environment: a PHP 8.5.9 binary was borrowed from another session's scratchpad (project targets 8.1-8.3). Results: phpunit 174 tests OK,
phpstan level 6 0 errors, phpcs clean. Also fixed in passing: stage 2 `SenderTest` (`expectDone` needs `zeroOrMoreTimes()`), stage 2/3
phpstan/phpcs findings (Sender backoff index, `OrderNotifications\Config`, hook-name warning). `.pot`/`.po`/`.mo` updated (187 entries,
`.mo` read back with Python gettext). Reviewer: look first at `CaptureService` (query budget), `RecoveryAdmin::process_request` (nonces), `Repository`.
- Not executable here: browser behavior of `checkout-capture.js`, real DB (`dbDelta`, prepared SQL), WooCommerce hooks, AS scheduling, list table.
- Pending: VERIFY.md rows 35-44 on a real WordPress + WooCommerce; independent `motored-review`.

### 2026-11-18 — Stage 5 Recovery part B authoring (self-review; needs independent review)
Results: phpunit 196 tests passing (100%), PHPStan level 6 clean, PHPCS clean.
Delivered:
- 3-step sequence: Step 1 (WhatsApp, 60m), Step 2 (email, 1440m), Step 3 (off, 4320m); dynamic next_action_gmt and expired status.
- Action Scheduler scanner: `motored_recovery_scan` (every 15 min, LIMIT 50), non-blocking, enqueues `Sender::enqueue('recovery_step', id, step)`.
- Send-time checks: sending window (09:00-21:00 in site timezone), recent order detection (marks converted), stock resolution, suppression, soft limits (1/24h, 3/week).
- Email integration: `AbandonedCartEmail extends WC_Email` registered via `woocommerce_email_classes` (file loaded only inside callback), HTML/plain templates.
- Recovery & Opt-out Links: `?motored_recover` and `?motored_optout` handled in `template_redirect` via strict `isset` check; tokens hashed with SHA-256 (7-day validity); salted hash table `motored_suppressions` (migration `2026111901`).
- Admin: Settings section with steps table, window, coupon, tokens, fallback, limits; list table & detail with manual actions ("Enviar WhatsApp ahora", "Enviar email ahora", "Abrir en WhatsApp Web", "Copiar link") protected by nonces and `manage_woocommerce`.
- i18n & Docs: Full es_AR (voseo) translations compiled, README updated, skill updated (<= 30 lines), VERIFY.md updated (rows 45-48).

### 2026-11-18 — Stage 6 Recovery part C authoring (self-review; needs independent review)
Environment: `php` 8.5.9 available (project targets 8.1-8.3). Results: phpunit 243 tests OK, phpstan level 6 0 errors, phpcs clean (see the final run below). `.pot`/`.po`/`.mo` updated (278 entries, `.mo` read back with Python gettext).
- Delivered: `OrderCase`, `OrderRecovery` (pure rules), `OrderSync`, `OrderStepHandler`, `OrderNote`, `Emails\PendingPaymentEmail` + templates, `Config` order steps, admin (settings, detail, order-aware manual actions), `Repository::find_for_order`, adapter methods in `OrderHooks`.
- Changed behavior of part B: cart rows convert into order rows at creation and are resolved on payment; `Status::derive_row` never marks orders as abandoned; "abandoned" counts and filters are carts only.
- Reviewer: check first `OrderSync::apply` (lookup/convert/paid), `OrderStepHandler::handle` (re-check before send, fallback email), `RecoveryResultHandler::advance_step` (order timing) and the `CaptureService` session guard.
- Not executable here: WooCommerce/Mercado Pago behavior, real DB, AS runs, e-mail rendering, admin screens. Pending: VERIFY rows 49-57 on staging with MP sandbox; independent `motored-review`.

### 2026-11-18 — Stage 7 review (motored-review; reviewer = different agent session; no staging findings provided)
Tools: `composer check` (PHP 8.5.9) green before and after: phpcs clean, phpstan level 6 0 errors, phpunit 247 tests OK. Branch `review/stage-7`,
first commit is a snapshot of the uncommitted stages 2-6, then one commit per fix.

**Performance proof (public GET page view, read from code):** `motored-core.php` only defines constants, registers the autoloader and 4 hooks.
`Plugin::boot()` (plugins_loaded@20): `init` -> `load_textdomain` (reads the .mo file, no query); `Options::register_defaults('general')` is memory only;
modules: `WahaModule::should_load` false (admin/cron/ajax/cli only); `OrderNotificationsModule::should_load` false on GET (true on POST / `wc-api`);
`RecoveryModule::register()` only adds hooks (`woocommerce_email_classes`, `wp_enqueue_scripts`, `template_redirect`, 3 cart-change hooks,
2 order-created hooks, `woocommerce_order_status_changed`) plus `Options::register_defaults` (memory). On a page view: `CheckoutScript::enqueue` returns at
`is_checkout()` (no query) and `RecoveryLinkHandler::handle` returns at `isset()`; the other hooks fire only on cart/order events.
**Option reads during bootstrap: none.** Option reads happen lazily: checkout page `motored_recovery` (1 query without a persistent cache) + the only asset
(`checkout-capture.js`, 1.5 KB, defer); `Logger` reads `motored_general` only when logging; admin `admin_init` reads `motored_schema_version` (migrations) and
two hourly Cache flags. `woocommerce_email_classes` instantiates our two `WC_Email` (their settings options) only when WooCommerce builds its mailer, not on page views.
No HTTP outside Action Scheduler workers and the 2 WAHA admin test buttons / manual send actions.

Findings and fixes (commit):
- FIXED (9f306ba) `RecoveryToken`: raw tokens were stored in Cache (=`wp_options` transients without Redis) and a cache miss rotated the token, breaking links already sent; opt-out on plain GET (mail scanners/WhatsApp previews prefetch) — HMAC tokens, hash-only storage, reuse until expiry, stateless opt-out, POST confirmation.
- FIXED (c170856) `Scanner::run` read-then-clear could enqueue the same row twice on overlapping runs -> atomic `Repository::claim_due()`; `OrderSync::resolve_paid` could log "recovered" twice (IPN + return) -> `Repository::update_if_status()` compare-and-set.
- FIXED (5dd1ed2) jobs left running after deactivation / uninstall without "delete data" -> `Plugin::deactivate()` + uninstall always unschedules; uninstall deletes our WC e-mail settings.
- FIXED (60dcc89) i18n: e-mail classes/templates used Spanish msgids missing from .pot/.po (and "\n" inside msgids) -> English source + es_AR; catalog regenerated (295 entries, `msgfmt --check`); `bin/i18n.sh` now scans `templates/`.
- FIXED (e189116) cart handler marked a cart `recovered` when ANY order (even pending/on-hold) existed in another session -> closes it as `converted`; removed dead code (`CaptureService::convert`, `Repository::mark_converted*`, `*::unschedule`, `Schedule::adjust_to_window`, `EventMap::expected_status`).
- FIXED (this commit) `MessageLimiter` cache keys were md5(phone) (reversible) -> keyed HMAC (`Suppression::hash`).
- OK: every admin action checks `manage_woocommerce` + nonce (`RecoveryAdmin::process_request`, `handle_note`, `save_steps`; `WahaAdmin::guard`; `OrderNotificationsAdmin::save_events`); wc-ajax capture has nonce + session/IP rate limit and reads the cart server-side; every `$wpdb` query is prepared (only fixed table names/whitelisted ORDER BY interpolated); logs mask phones and never carry text/body/API key; Cache is never the source of truth (limits, throttles, breaker, summary, notices are best-effort; migrations idempotent); window uses `wp_timezone()` and GMT storage; `bin/build-zip.sh` ships only motored-core.php, uninstall.php, readme.txt, LICENSE, src/, templates/, assets/, languages/ (README.md stripped).
- PENDING (needs staging/decision): recovery link GET still restores the cart and marks `clicked` when a scanner prefetches it (VERIFY #45); WAHA API key is printed in the password input HTML (prefer the constant); the checkout page reads `motored_recovery` (1 query without Redis) to decide whether to enqueue the script; order meta `_motored_*` is kept on uninstall on purpose; all VERIFY rows remain unconfirmed (risk order at the top of VERIFY.md); PHP 8.1/8.3 runs.

### 2026-11-18 — Theme-agnostic audit (all modules)
New rule 9 in AGENTS.md and a checklist item in `motored-review`. Audit by grep (theme names, `get_header`/`get_footer`, layout ids): no PHP
referenced a theme; findings and fixes:
- FIXED `assets/js/checkout-capture.js` used the layout ids `billing_email/billing_phone/billing_first_name` and `form.checkout` only (broke on Blocks) -> detects by `type` and `autocomplete`, resolves the classic/Blocks root lazily, listens on `document` (no MutationObserver, no jQuery); 2.0 KB.
- FIXED ReviewPhotos depended on the WC review template hooks -> fallbacks: core `comment_form_field_comment` (field), core `comment_text` (thumbnails, only if the template does not fire `woocommerce_review_before_comment_text`), and a JS injection into `#reviews #commentform` as last resort. Never duplicated (per-request flag / per-review map; wrapper class renamed `motored-rp-field`). The JS acts only if the form exists.
- OK: opt-out/recovery pages use `wp_die` (standalone core page, no theme) and redirects; e-mails use `wc_get_template_html` with templates under `templates/emails/` (theme-overridable) and inline CSS only; own assets are the 2 scripts, no stylesheet; no jQuery.
- Unit tests added for ReviewForm/ReviewDisplay fallbacks. New VERIFY rows 68-70; theme matrix in STAGING-CHECKLIST. PENDING: staging on the three themes. No new strings (.pot/.po/.mo unchanged).

### 2026-11-18 — End-to-end flow review (theory, by code reading + scenario tests)
Flows traced: order status -> OrderNotifications -> Sender -> handler -> order note; cart capture -> scanner -> step 1 WhatsApp -> step 2 e-mail -> expiry
and the paid path; pending/failed/on-hold sequence; recovery/opt-out links; ReviewRequests scheduling/skip rules; ReviewPhotos rules; activation/deactivation/uninstall.
Scenario tests: `tests/Unit/Scenarios/FlowsTest.php` (chain + consistency: hooks vs uninstall, kinds vs handlers, default placeholders vs Renderer).
Bugs fixed (one commit each):
- FIXED `WahaModule` registered the `motored_waha_send` callback with 4 accepted args but every job carries 5 (`priority`) -> priority was always lost (`normal`), so the low-priority share of the daily cap never applied to recovery/review messages and retries lost their priority. Constant `Sender::HOOK_ARGS`; regression tests.
- FIXED `RecoveryResultHandler`: the scanner clears `next_action_gmt` when it claims a row; a skipped WhatsApp step (no stock, suppressed/invalid phone without e-mail fallback, limiter, empty text) left the row `active` with no next action forever -> now moved past the step (`release_skipped`); sending-window reschedules and closed rows are untouched.
- FIXED `Renderer::format_items` appended a hard-coded Spanish "y N más" (outside i18n) -> translated `and %d more` (es_AR `y %d más`).
- FIXED `Notifier::short_reason` showed "unexpected error" for `session_down`/`http_error` order notes -> own messages (es_AR added).
- OK (no change): hook/kind/option names agree across modules (3 jobs, 3 kinds, each with one handler and result listener); all default-text placeholders are resolved by the Renderer; migrations 2026111801/2026111901 cover the 3 tables dropped by uninstall; modules that handle jobs register handlers in admin/ajax/cron/cli.
- PENDING / known: a claimed row stays without next action if `Sender::enqueue` fails right after the claim (Action Scheduler missing) — needs staging; Recovery is `enabled=yes` by default (acts as soon as Waha is configured); VERIFY #71 added. `docs/FEATURES.md` (pt-BR) describes the owner-facing behaviour.

### 2026-11-19 — WAHA API key from the panel (secure)
Decision: the API key can be set 100% from WooCommerce > Settings > Motored > WhatsApp. Priority constant `MOTORED_WAHA_API_KEY` > panel. Stored in its own option
`motored_waha_secret` (autoload=false, no `register_setting`, not in REST), encrypted by `Support\Secrets` (libsodium secretbox, random nonce, key = generichash(`wp_salt(auth)` + label), `v1:` + base64);
unreadable = not configured + es_AR warning, never an exception. Write-only field (never rendered, not even encrypted; empty keeps; errors never repopulate), Replace / Delete with own nonce and confirmation.
`manage_options` only for URL and key (render + save + `KeyGuard`). `KeyGuard` (`pre_update_option_motored_waha`): host/port change deletes the key with a warning; `user:pass@` URLs rejected;
https still mandatory. Central redaction in `Logger` (X-Api-Key/api_key/Authorization keys and registered secret values) and `Result`; test proves only `Config` and `WahaClient` touch the key.
Audit (info, user id, no value): saved/replaced/deleted/host change. Status shows only the state. `uninstall.php` deletes `motored_waha_secret` explicitly. A plain legacy key in the settings array is migrated when an admin opens the section.
Audit of Logger, order notes (Notifier/Recovery notes use `Result::code` only), WahaClient, Result, error messages, test buttons, Status, privacy exporters (none read settings) and uninstall: no path printed or logged the key.
Tests: `SecretsTest`, `ApiKeyTest`, `WahaAdminSecretTest`, Logger/Result redaction. Results (PHP 8.3.36 with ext-sodium): phpcs and phpstan clean; phpunit green except 2 pre-existing `Recovery\ScheduleTest` failures (timezone data, unrelated, fail on a clean checkout).
PENDING: VERIFY #88 (libsodium on the VPS, stable salt) and #89 (real save actions) on staging; independent `motored-review`.

### 2026-11-18 — Launch safety: master switch and test mode
Decision: WhatsApp sending is OFF by default (`motored_waha[enabled]=no`); with it on, test mode (default yes) only sends to `test_numbers`
(normalized, one per line); the admin must turn test mode off to reach real customers. Enforced once, in `Sender::handle` (switch before the circuit breaker,
allowed list after the handler built the message, before any WAHA call); discarded jobs finish with `sending_disabled` / `test_mode` (no retry, no e-mail fallback),
logged without PII and noted by OrderNotifications, ReviewRequests and Recovery (order notes / history). Recovery now defaults to disabled; OrderNotifications,
ReviewPhotos and ReviewRequests keep their defaults. Status row + warning notice show the state. Tests: `DeliveryModeTest`; VERIFY #72. PENDING: staging check of the settings fields.


### 2026-11-18 — PaymentPlans part A authoring (self-review; needs independent `motored-review`)
Results (PHP 8.5.9): phpunit 393 tests OK (43 new), phpstan level 6 clean, phpcs clean on the plugin (run with `--ignore='reference/*,vendor/*,build/*'`: the untracked `reference/` copy of the MP plugin is picked up by the default `composer check` and fails phpcs; it is not part of this change). `.pot/.po/.mo` updated (393 entries, `msgfmt --check`).
- Delivered: `PlanCalculator`/`Plan`/`Plans`/`Quote`, `Presenter`, `Storefront` + `PlanDialog` + `assets/js/payment-plans.js` (2.0 KB), `CheckoutPlans`, `PaymentPlansAdmin`, `GatewayCheck`, adapters `Integration/WooCommerce/{PlanHooks,CheckoutState}` and `Integration/MercadoPago/CheckoutPro`.
- Perf check by reading: `register()` reads no option; the cart-fee path tests the session before `Config`; script/dialog only when a link renders; no HTTP; no migrations/tables.
- Reviewer: look first at `CheckoutState::cart_base_cents` (cart totals at the fee hook, VERIFY #76), `CheckoutPlans::on_posted_data` (order of `woocommerce_checkout_posted_data` vs `update_session`) and the `.motored-plan-selector` fragment + `update_totals_on_change` refresh (VERIFY #75).
- Pending: VERIFY #73-#79 on staging (themes matrix, variable products, tax/coupon/shipping totals, HPOS); Blocks checkout and part B (Mercado Pago installments from `_motored_plan_max_installments`).

### 2026-11-18 — PaymentPlans part B authoring (self-review; needs independent `motored-review`)
Results (PHP 8.5.9): phpunit 429 tests OK, phpstan level 6 clean, phpcs clean on the plugin (default `composer check` still scans the untracked `reference/`). `.pot/.po/.mo` updated (`msgfmt --check`). Payment type ids checked against MP docs only (VERIFY #82).
- Delivered: `PreferenceFilter`, `PreferenceRewriter`, `Reconciler`, `CheckoutPro` (version, order id from reference, payment meta), Status row, version notice; `motored_plans_reconcile` added to `Plugin::ACTION_HOOKS` and `uninstall.php`.
- Reviewer: look first at `PreferenceFilter::guard` (every path that returns the original args must be provably "no plan"), the `order not found` policy (blocks only while the module is enabled), and `Reconciler` on-hold side effects (VERIFY #84).
- Pending: sandbox run of the staging checklist (cash/3/9, fail closed, reconciliation), real `/v1/payment_methods` list for AR.


### 2026-11-19 — OrderTracking authoring (self-review; needs independent `motored-review`)
Decision: tracking is its own module (no tables, no carrier API). One entry per order in meta `_motored_tracking`; carriers editable in Motored > Seguimiento and shipped EMPTY (no invented URLs). Idempotency per channel by code hash (`_sent_*`), plus `_carried_*` when a processing/completed WhatsApp or e-mail already carried the code. Manual orders follow the OrderNotifications setting.
- Delivered: `TrackingLink` (pure), `Tracking`, `Config`, `Presenter`, `TrackingNotifier` (kind `order_tracking`, priority high), `TrackingEmail` + templates, `CustomerDisplay`, `OrderTrackingAdmin`; Renderer placeholders `{tracking_*}`; default texts of processing/completed carry `{tracking_block}`.
- Perf: no query on public pages (order meta first, option only with tracking); no HTTP; no assets; no JS.
- Reviewer: look first at the save hook for HPOS/legacy (VERIFY #90), the carried flags written while an e-mail renders (#92) and the delayed dedicated send (default 3 min, AS hook `motored_tracking_email`, WhatsApp via Sender delay) that fixes the one-click duplicate with the completed message; regression tests cover before/same click/after/changed code.
- Pending: VERIFY #90-#92 on staging; independent review.

### 2026-11-19 — Compatibility + Mi Garage authoring (self-review; needs independent `motored-review`)
Results (PHP 8.3.36): `phpcs` clean (152/152 files), `phpstan` level 6 clean (146/146 files), `phpunit` 559 tests / 2137 assertions OK (32 pre-existing skips untouched; 28 new unit tests across 10 suites under `tests/Unit/Modules/Compatibility/`). `.pot`/`.po`/`.mo` updated (687 compiled MO entries, 0 missing Compatibility strings, binary round-trip verified).
- Delivered: `docs/COMPATIBILITY-PHASES.md`, `Config`, `SizeParser`, `TireSpecs`, `AttributeInstaller`, `CompatibilityMigration` (7 tables, `2026112001`), `CompatCache`, `CatalogRepository`, `ProductIndexRepository`, `ProductIndexSync` (hash-guarded + optional Phase 7 auto-fill), `CliCommands`, `JsonImporter`, `JsonExporter`, `ImportRunner`, `MatchEngine`, `GarageRepository`, `GarageEndpoint`, `Privacy`, `RestController`, `Storefront` (selector shortcode, `?moto=`/`&pos=`, `noindex`, 3 tabs), `CompatibilityAdmin`, `CompatibilityModule`, adapters `AttributeHooks` + `CompatibilityHooks`, 4 vanilla `defer` scripts (`compat-selector.js` 2,695 B, `compat-tab.js` 2,454 B, `compat-garage-page.js` 1,409 B, `compat-garage.js` 3,795 B), module README & skill, `uninstall.php` cleanup (preserves `pa_*`).
- Perf check by reading: `CompatibilityModule::register()` executes 0 DB queries and enqueues 0 scripts; `CompatCache` uses `Support\Cache` only when `Cache::is_persistent()` is true; `the_posts` primes loop fits in 1 query; ERP price/stock updates skip writes via `terms_hash`; storefront HTML uses uniform `<span hidden data-motored-fit>` markers (0 REST calls on normal browsing).
- Pending: VERIFY #93-#103 on staging across Storefront, block theme and Flatsome; `bin/i18n.sh` run where GNU gettext exists; independent `motored-review`.

### 2026-11-19 — Compatibility + Mi Garage hardening pass (all 9 external review findings resolved)
Results (PHP 8.3.36): `phpcs` clean (152/152 files), `phpstan` level 6 clean (146/146 files), `phpunit` 562 tests / 2156 assertions OK (32 skipped; +3 new regression tests in `ProductIndexSyncTest` and `JsonImporterTest`). `.pot`/`.po`/`.mo` updated (702 compiled MO entries, 0 missing Compatibility strings).
- Fixed:
  1. Enforced simple-product scope in `AttributeHooks::is_simple_product()`, `ProductIndexSync::on_product_save()`, and `ProductIndexSync::sync_product()` (deletes any non-simple product rows before hash short-circuit).
  2. Added synchronous Action Scheduler failure fallbacks in `ProductIndexSync::flush_dirty()`, `ProductIndexSync::run_reindex_action()`, `CompatibilityAdmin::handle_tool_action()`, and `ImportRunner::start()` / `run_batch()`.
  3. Added async import lifecycle tracking (`ImportRunner::get_last_status()`, `active_job()`, `resume_active()`, `cancel_active()`), concurrency lock, 15-minute stale job cleanup (`STALE_SECONDS = 900`), partial-import completion reporting (`status = 'partial'`), and admin controls (`Last import status` box with resume/cancel actions).
  4. Added write-time year-overlap guard in `JsonImporter::import_chunk()` when inserting new versions (`has_year_overlap()`).
  5. Updated `RestController::public_response()` to emit content-derived `ETag` and `Cache-Control: public, max-age=60, stale-while-revalidate=300`.
  6. Hardened frontend JS (`compat-selector.js` 2,788 B request sequence tokens `bSeq`/`mSeq`; `compat-garage.js` 4,101 B `try/catch` storage wrappers + in-memory fallback + `!r.ok` + post-success `mg_s` sync flag; `compat-garage-page.js` 1,542 B snapshot rollback on API error; all 4 scripts verified with `node --check`).

### 2026-11-19 — CatalogFilters module (Phases 1–5) + Upgraded Compatibility SSR Tab (self-review)
Results (PHP 8.3.36): `phpcs` clean (165/165 files), `phpstan` level 6 clean (159/159 files), `phpunit` 571 tests / 2221 assertions OK (32 pre-existing skips; +9 new unit tests across 5 suites under `tests/Unit/Modules/CatalogFilters/`, including a 3,000-product disjunctive facet benchmark). `.pot`/`.po`/`.mo` updated (755 compiled MO entries, 0 missing strings across Compatibility + CatalogFilters). JS syntax checked with `node --check`.
- Delivered:
  - Migration `2026112101`: `in_stock tinyint(1) unsigned NOT NULL DEFAULT 1` + `idx_stock` on `motored_compat_product_index`, updated exclusively on `woocommerce_product_set_stock_status`.
  - `src/Modules/CatalogFilters/`: `Config`, `CatalogFiltersAdmin`, `FilterState`, `SnapshotInvalidator` (anti-storm 60s cooldown + concurrency lock; price/qty never invalidate), `FacetEngine` (1-SQL parent-product snapshot, O(N) disjunctive counts across 11 facets, imperial no-profile sizes, kits excluded from unit-only facets, live `wc_get_product_ids_on_sale()`), `QueryFilter` (`?oferta=1` post__in intersection + `noindex, follow`), `RestController` (`GET /filters/sizes`, `GET /filters/suggest?q=` with 0 DB LIKE queries), `SearchShortcode` (`[motored_buscador layout="tabs|split"]`), `FilterRenderer` (`[motored_filtros]`), `FilterWidget`, `StorefrontAssets`, `CatalogFiltersModule`, `README.md`, `.claude/skills/motored-module-catalog-filters/SKILL.md`.
  - Upgraded `Storefront::render_compat_tab()` + `[motored_compat_list]` via `CompatListRenderer` (SSR HTML cached by spec, grouped by brand in 2 columns, collapsed to 6 brands, 120-item inline cap with on-demand REST hydration, instant alias search, client-side Mi Garage highlight).
  - Assets: `assets/css/catalog-filters.css` (theme-agnostic `.motored-*` + CSS variables), `assets/js/catalog-search.js`, `assets/js/catalog-filters.js`, updated `assets/js/compat-tab.js`.
- **DIVERGENCE:** Public storefront requests that render `[motored_buscador]`, `[motored_filtros]`, `FilterWidget`, or receive filter URL params (`?oferta=1`, `?filter_*`, `?product_brand=`) read non-autoloaded option `motored_catalog_filters` (and `woocommerce_hide_out_of_stock_items`) lazily inside the callback — never during `CatalogFiltersModule::register()`. Unfiltered public pages without shortcodes/widgets still execute 0 queries, 0 CSS and 0 JS.
- Pending: VERIFY #104–#109 on staging (stock status transitions, native `?product_brand=a,b`, disjunctive counts vs actual shop results, `?moto=` pagination/sorting, mobile off-canvas drawer, page cache + `noindex`).

### 2026-11-20 — Compatibility flow audit & natural admin/storefront UX improvements (self-review)
Results (PHP 8.3.36): `phpcs` clean (165/165 files), `phpstan` level 6 clean (159/159 files), `phpunit` 577 tests / 2629 assertions OK (32 pre-existing skips; +3 regression tests in `JsonImporterTest` covering cross-rim equivalence rejection, `force_draft` import override, and bundled `compat-base-ar.json` validation). `node --check` clean on `compat-selector.js` (2,909 B), `compat-garage.js` (4,107 B), `compat-tab.js` (3,381 B) and `catalog-search.js` (7,393 B). `.pot`/`.po`/`.mo` updated (862 compiled MO entries, 107/107 new Compatibility strings verified, 0 missing).
- Fixed:
  1. `src/Modules/Compatibility/data/compat-base-ar.json`: fixed `"schema_version": 3` -> `1` so `JsonImporter::parse_payload()` accepts the 20-brand / 179-model / 183-version / 366-fitment Argentine base catalog (47 published verified, 136 draft).
  2. `JsonImporter` & `CompatibilityAdmin` (`save_equivalence`): enforced same-rim diameter guard (`$src['rim'] === $dst['rim']`) and added `force_draft` prefill option + accurate published/draft counts + error/busy/queued links on WooCommerce Settings.
  3. `CatalogRepository` & `ProductIndexRepository`: added `summary_counts()`, `find_brand_by_id()`, `update_brand()`, `find_model_by_id()`, `update_model()`, `set_version_status()`, `fitments_for_versions()`, `set_equivalence_approved()`, `count_indexed()`, and alias search (`m.aliases LIKE`) inside `list_versions()`.
  4. `CompatibilityAdmin` + `assets/admin/motored-admin.css`: redesigned `Productos > Compatibilidad` around **Motorcycles** (`versions`) as default tab with overview/setup box, active background-import banner, 1-step **Quick add motorcycle** (`quick_add_moto` with `<datalist>` autocomplete), Brand/Status/Verified filters, batched inline Front/Rear sizes, bulk actions + 1-click Publish/Draft, live **Store matching preview** on version edit, Brand/Model edit forms, 1-click equivalence Approve/Pause, filter-preserving pagination, and read-only compatibility index status on WooCommerce simple product edit screens.
  5. Storefront JS: auto-selected single-range years in `compat-selector.js` and `catalog-search.js`, reset garage selector after save in `mode="garage"`, exposed `ev` in `compat-garage.js` and eliminated false-negative Mi Garage badges in `compat-tab.js` when products match > 120 bikes.
- Pending: VERIFY #93–#109 on staging; independent `motored-review`.

### 2026-11-20 — Product General field/filter clarification & initial catalog prefill reliability fix (self-review)
Results (PHP 8.3.36): `phpstan` level 6 clean (159/159 files), `phpunit` 578 tests / 2636 assertions OK (32 skipped; added `ImportRunner` sync test in `JsonImporterTest` and `notes` DDL assertion in `CompatibilityMigrationTest`, updated `PluginTest` schema version). `.pot`/`.po`/`.mo` updated (869 compiled MO entries, 341 Compatibility strings, 0 missing).
- Fixed:
  1. `CompatibilityMigration` & `Plugin`: registered migration `2026112201` changing `compat_versions.notes` from `varchar(255)` to `text DEFAULT NULL` (35 verified versions in `compat-base-ar.json` had notes of 262–379 chars) and added UTF-8 safe `CatalogRepository::truncate()` guards across brand/model/version/fitment/equivalence writes.
  2. `ImportRunner::start()` & `CompatibilityAdmin`: added `'sync' => true` option used by prefill confirmation so importing the 183-version base catalog executes immediately instead of stalling in Action Scheduler queues; removed transient dependency from prefill preview/confirm by running `JsonImporter::dry_run()` directly on `load_base_json()`; added `formnovalidate` to tool buttons in WooCommerce Settings and supported prefill execution from the Compatibility `Import/Export` tab (`return_to=import`).
  3. `CompatibilityAdmin::render_product_meta_field()`: clarified `_motored_full_size` as an optional display override for the Technical Information tab (not a filter taxonomy), pointing explicitly to the Attributes tab (`pa_posicion`, `pa_medida-delantera`, `pa_medida-trasera`) and adding a live **Catalog filters (`pa_ancho`/`pa_perfil`/`pa_rodado`)** readiness indicator alongside the compatibility index readout.
- Pending: VERIFY #93–#109 on staging; independent `motored-review`.

### Compatibility simplification (2026-11-20)
- Settings button "Prefill base catalog" used a two-step preview/confirm flow that was easy to miss; replaced by a direct import (same handler as Productos > Compatibilidad). Admin tabs reduced to Motorcycles / Import-Export / Advanced. Version edit form now sets OEM front/rear sizes directly.
- Base JSON: 4 fitments with `fit_type: compatible` (BMW S1000RR 2010-2014) were being imported as OEM; now alternatives. 136 of 183 versions are drafts by design (legacy data pending verification) and stay hidden in the store until published.
- Not verified in a browser/WordPress runtime: composer check parts run (phpunit, phpcs, phpstan) only.

### Compatibility: OEM designation (2026-11-20)
- Size key = width/profile/rim only; ZR/R/B is construction, not part of the size. Added per-fitment `designation` + derived `req_construction`. Remaining published JSON rows keep canonical sizes until each manual is checked: re-enter them as printed (e.g. `120/70ZR17`) in the edit form or the JSON and re-run the base import (idempotent).

### Review: Compatibility + Mi Garage + CatalogFilters (2026-11-20)
- Fixed: CatalogFilters smart-search labels diverged from Compatibility labels; guest prompt linked to the shop (no selector there); "Active" translated as plural. Added per-request memo for matching ids. Open: guests have no place to see/switch their saved bikes outside the selector flow (Mi Garage is My Account only); `auto_fill_dimensions` defaults to `no`, so the size search needs Ancho/Perfil/Rodado filled on products (CatalogFilters readiness status shows it); products indexed before the construction rule need a reindex; not browser-tested.

### Compatibility: product tire meta box (2026-11-20)
- Owner request: tire technical data must come pre-listed, outside "Datos de producto". Added `ProductTireAdmin` (meta box `motored-tire`, hook `add_meta_boxes_product`, save on `woocommerce_admin_process_product_object`) + `TireAttributePlan` (pure planner). Removed the general-tab fields and `AttributeHooks::register_product_meta_hooks()` (replaced by `register_product_box_hooks()`). Existing products read back from their attribute terms; old-style products (Width/Profile/Rim only) are rebuilt for display. Not browser-tested; `add_meta_boxes_product` and `WC_Admin_Meta_Boxes::add_error` listed in VERIFY #104.

### 2026-11-20 — Compatibility + Mi Garage UX, REST cascade & Technical Info overhaul
- Fixed & improved across product edit, storefront inline checker, Mi Garage, and Technical information tab:
  1. **Product edit meta box (`ProductTireAdmin`, `motored-admin.css`, `motored-tire-box.js`):** Positioned `#motored-tire-pos` inline right next to the postbox title (`motored-box--postbox`) with drag/toggle propagation stopped and full untruncated labels; aligned all inputs/selects to 32px height in a balanced 2-row grid with dynamic size label (`Medida` vs `Medida delantera`), km/h hints in speed ratings, collapsible `_motored_full_size` override, storefront summary preview, 2-column compatible moto list, `visible=false` on `pa_ancho`/`pa_perfil`/`pa_rodado` & single-tire `pa_medida-*` (preserving taxonomy terms for `CatalogFilters`), and clean attribute cleanup when switching back to `No es una cubierta`.
  2. **Product-page compatibility checker (`Storefront.php`, `compat-garage.js`, `compat-selector.js`, `Config.php`):** Replaced the home-page redirect on `[¿Sirve para tu moto? Elegí tu moto]` with a lazy inline `.motored-compat-banner__picker` (`mode="check"`, 0 REST calls until opened) showing saved-bike quick chips + brand/model/year/version selector + OEM sizes on incompatible badges (`show_incompatible_badge` default `yes`).
  3. **Mi Garage & REST cascade (`RestController.php`, `GarageEndpoint.php`, `compat-garage-page.js`, `catalog-search.js`, `catalog-filters.css`):** Added path-based REST routes (`/compat/brands/{id}/models`, `/compat/models/{id}/years`) and `?`/`&`-safe query URL helpers so cascades never break after Brands on Plain `?rest_route=` permalinks; added `oem` factory sizes to bike profiles; enqueued `catalog-filters.css` in `GarageEndpoint`, `Storefront::shortcode_selector()`, `render_active_bike_bar()`, and `render_single_fit_banner()`; styled Mi Garage cards, active badge pill, OEM sizes, and empty state.
  4. **Technical information tab (`TireSpecs.php`, `AttributeHooks.php`, `AttributeInstaller.php`, `Storefront.php`):** Added verified ETRTO `LOAD_KG` table + `TireSpecs::format_load_speed()` (`69H (hasta 325 kg · 210 km/h)`), renamed `pa_linea` to `Modelo` (with automatic `wc_update_attribute()` migration on Step 1), enforced fixed row order (`Marca -> Modelo -> Medida completa -> [Medida delantera/trasera for kits] -> Posición -> Índice -> Uso con cámara -> Construcción -> Tipo de uso -> extras`), and hid redundant `Ancho`, `Perfil`, `Rodado`, and separate load/speed rows.
- Checks run after final code/test edits (PHP 8.3.36, Windows):
  - `phpcs src/Modules/Compatibility src/Integration/WooCommerce/AttributeHooks.php`: 25/25 files clean (0 errors, 0 warnings). Full-repo `phpcs` reported 0 errors/warnings in all touched files and 1 pre-existing unrelated error in `src/Modules/ReviewPhotos/ReviewUploads.php:59` (`WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound`).
  - `phpstan analyse --memory-limit=1G`: 161/161 files `[OK] No errors`.
  - `phpunit`: 589 tests, 2742 assertions, 0 errors, 0 failures (32 pre-existing unrelated skips); `phpunit tests/Unit/Admin tests/Unit/Modules/Compatibility`: 66 tests, 966 assertions, 0 skipped, 0 failures.
  - `node --check` clean on `assets/admin/motored-tire-box.js`, `assets/js/compat-selector.js`, `assets/js/compat-garage.js`, `assets/js/compat-garage-page.js`, `assets/js/catalog-search.js`.
  - `.pot`/`.po`/`.mo` updated (`languages/motored-core-es_AR.mo`: 941 compiled entries verified from binary).
- Pending: Live WordPress/WooCommerce staging verification of VERIFY #93–#110 (both Pretty and Plain permalinks, Flatsome/Storefront/block themes).

### 2026-11-20 — `[motored_buscador]` REST cascade & `split` layout/CSS fixes
- Fixed & improved `[motored_buscador]` (`CatalogFilters`):
  1. **Path-based REST & resilient cascade (`CatalogFilters\RestController`, `StorefrontAssets`, `catalog-search.js`):** Added `/motored/v1/filters/sizes/{ver}` alongside `/filters/sizes`, reset cached promises on failure (`szP = null`, `brP = null`), added `Loading...`, `No models available`, `No tire sizes available yet.`, and inline error feedback (`[data-role="size-msg"]`, `[data-role="moto-msg"]`), and switched motorcycle cascade to `/compat/brands/{id}/models` and `/compat/models/{id}/years` so brand/model/year cascades work on both Pretty and Plain (`?rest_route=`) permalinks.
  2. **Responsive `split` & `tabs` layout (`SearchShortcode`, `assets/css/catalog-filters.css`):** Converted `.motored-search` to container queries (`@container (min-width: 780px)` with `@supports not` `@media` fallback), prevented 2+1 orphan wrapping with 3-column field grids (`2x2` via `[data-has-variant="1"]` when multi-variant years appear; 1-col under `520px`), added `min-width: 0` + `text-overflow: ellipsis` + normalized `0.9rem` / `2.5rem` controls and explicit `.motored-search__btn` styling so both `Buscar` buttons align cleanly at the bottom of split cards.
- Checks run after final code/test edits (PHP 8.3.36, Windows):
  - `phpcs src/Modules/CatalogFilters src/Modules/Compatibility src/Integration/WooCommerce/AttributeHooks.php`: 37/37 files clean (0 errors, 0 warnings).
  - `phpstan analyse --memory-limit=1G`: 161/161 files `[OK] No errors`.
  - `phpunit`: 590 tests, 2745 assertions, 0 errors, 0 failures (32 pre-existing skips in unrelated modules); `phpunit tests/Unit/Admin tests/Unit/Modules/Compatibility tests/Unit/Modules/CatalogFilters`: 78 tests, 1041 assertions, 0 skips, 0 failures (includes new route test in `SuggestAndModuleTest`).
  - `node --check assets/js/catalog-search.js`: exit 0.
  - `.pot`/`.po`/`.mo` updated (`languages/motored-core-es_AR.mo`: 942 compiled entries).

### 2026-11-20 — InstallGallery module (second product gallery, phase 1; self-review, needs independent `motored-review`)
- Decision: ONE extra gallery "Customer and installation photos" = installation photos picked by the owner (`_motored_install_photos`, native `wp.media` box on the product screen, caption = motorcycle) + photos of approved reviews (ReviewPhotos comment meta). The review screen is unchanged. No tables, no lightbox library, no jQuery.
- Performance: the product page reads two product meta keys already primed with the product (0 queries); `_motored_review_gallery` is DERIVED and rebuilt only on moderation events (`GallerySync`), never on a page view. Product without photos: no option read, no script. With photos: 1 option read, 1 attachment priming query, 1 script (< 2 KB, `defer`); the strip prints `thumbnail` images only (rest are plain links, "+N" chip); the native `<dialog>` loads `large`, never the original.
- ReviewPhotos change: new review photos only generate the `thumbnail`, `medium` and `large` sub-sizes (`FileRules::KEEP_SIZES`, filter `intermediate_image_sizes_advanced`) instead of every registered size.
- Not done (by design): compressing/WebP-converting photos the owner picks and a store-wide compressor = phase 2 module `ImageOptimizer` (shared `Support\ImageProcessor` extracted from `PhotoProcessor` first). Review re-encoding stays synchronous in `comment_post`; measure on staging before moving it to Action Scheduler.
- Admin classes: `.motored-box__thumbs` / `.motored-box__rm` added to the shared stylesheet (`SharedAdminUiTest` only allows the existing prefixes). VERIFY #112-#114. Tests: `tests/Unit/Modules/InstallGallery`, `FileRulesTest::test_keep_sizes_*`. i18n: 17 strings in `.pot`/`.po`/`.mo`.

### 2026-11-20 — Storefront review fixes — Compatibility + Mi Garage + CatalogFilters (all phases of `docs/REVIEW-FRONTEND-COMPAT.md`)
- Findings A1–A5, M1–M12, B1–B9 and improvements 1–8 implemented; see the module READMEs (Compatibility 0.6.0, CatalogFilters changelog) and `STAGING-CHECKLIST.md` "Storefront review fixes (2026-11-20)".
- Root causes worth remembering: WooCommerce does not fire `woocommerce_before_shop_loop` on empty results; the accent color was shadowed by a component-level `--motored-accent: currentColor`; loop badges had no CSS on plain shop pages; kit label came from the axle count instead of the product position.
- Decisions: product page always honest about the active bike (setting now catalog-only); best-fit ordering only when the shopper did not choose a sorting; no auto-reload in the sidebar; JS kept readable (no hand minification), ~4.5 KB gzip for `compat-garage.js`.
- New: `Support\SearchText`, `assets/js/motored-ui.js`, `assets/css/compat.css`, settings `garage_cta`/`oem_first_sort`/`analytics_events`, VERIFY #115 (block themes) and #116 (no-products hook), parity fixture + node test.
- Not verified: no real WordPress/browser run in this session (unit tests, phpstan, phpcs and node only). Theme independence kept: only WordPress/WooCommerce core hooks and block names; no theme selectors (the old `li.product` CSS selector was replaced by our own `--loop` class).

### 2026-11-20 — InstallGallery repair & upgrade + Shared ImageProcessor + ImageOptimizer (`motored-review` audit)
- **Scope audited:** `src/Support/ImageProcessor.php`, `src/Modules/InstallGallery/`, `src/Modules/ImageOptimizer/`, `src/Modules/ReviewPhotos/` (`FileRules`, `PhotoProcessor`, `ReviewUploads`, `ReviewPhotosModule`), `src/Integration/WooCommerce/ProductPageHooks.php`, `src/Plugin.php`, `uninstall.php`, `assets/admin/{motored-admin.css,motored-install-gallery.js}`, `assets/css/install-gallery.css`, `assets/js/install-gallery.js`, `languages/*`, and module docs/skills.
- **Verification (PHP 8.3.36, Windows):**
  - `phpcs` (full repo): **184/184 files clean** (0 errors, 0 warnings; fixed pre-existing `ReviewUploads.php:59` array spacing and `CompatListRenderer.php:181` `@param` type).
  - `phpstan analyse --memory-limit=1G` (level 6): **178/178 files `[OK] No errors`**.
  - `phpunit`: **658 tests, 3079 assertions, 0 errors, 0 failures** (`33 skipped`: 32 `libsodium` unavailable in local PHP, 1 `node` unavailable in PHPUnit subshell). Targeted run (`ImageProcessorTest`, `InstallGallery`, `ImageOptimizer`, `Admin`, `PluginTest`, `FlowsTest`): **88 tests, 540 assertions, 0 skipped, 0 failures**.
  - `node --check`: `assets/js/install-gallery.js` (**3,049 B**, `< 3 KiB`, `defer`, 0 syntax errors) and `assets/admin/motored-install-gallery.js` (**3,313 B**, 0 syntax errors).
  - `i18n`: `.pot`/`.po`/`.mo` compiled cleanly (`1,052` MO entries, **0 missing** translatable strings across `src/`, `templates/`, `motored-core.php`).
- **Audit findings & fixes:**
  1. **InstallGallery root causes fixed:** Added missing `media-views` dependency & `post` context to `wp_enqueue_media()` without `defer` (resolved `wp.media` race on product edit), added quick-edit `present` guard, added caption display & reorder arrows (`←`/`→`), replaced prohibited admin CSS rules in `motored-admin.css` with shared `Admin\Ui` classes, added `placement` options (`before_tabs` default, `after_tabs`, `tab`, `shortcode` via `ProductPageHooks`), fixed `<dialog>` top-layer centering (moved `position: relative` off `<dialog>`), isolated clicks in capture phase + `.no-lightbox` against theme lightboxes, created `assets/css/install-gallery.css`, added filter pills & star ratings, and added `motored_ig_sync_reviews` backfill + migration `2026112401`.
  2. **ImageOptimizer + ImageProcessor:** Extracted `Support\ImageProcessor` (`ReviewPhotos` uploads + `ImageOptimizer` bulk runs share GD/Imagick/WebP/EXIF-strip pipeline); `ImageOptimizerModule::should_load()` skips `$context->is_frontend()` (0 public overhead); native upload filters (`big_image_size_threshold`, `wp_editor_set_quality`, `image_editor_output_format`, `intermediate_image_sizes_advanced`, `image_strip_meta`, `wp_generate_attachment_metadata`); cursor-batched Action Scheduler worker (`motored_image_opt_batch`) with dry-run scan and ERP-safe in-place defaults (`bulk_webp_subsizes` OFF); `uninstall.php` unschedules `motored_image_opt_batch` and cleans `_motored_install_photos`, `_motored_review_gallery`, `_motored_img_opt`.
  3. **No blocking violations found** in new/modified files (pre-existing frontend inline `style=` on `ReviewDisplay.php:119` and `ReviewForm.php:92` left untouched; admin files pass `SharedAdminUiTest` + `AdminAssetsTest` 100%).
- **Pending:** Live WordPress/WooCommerce staging verification of `VERIFY.md` #112–#114, #117–#120 (Flatsome, Storefront, block themes, real GD/Imagick upload + WebP sub-sizes, Action Scheduler batching).

### 2026-11-20 — InstallGallery + ImageOptimizer review (fixes and improvements)
- **Critical (ImageOptimizer):** with "JPEG → WebP" on, the in-place bulk optimizer saved through WordPress' `image_editor_output_format` mapping and could rename WebP bytes to the original `.jpg` URL (wrong `Content-Type`, broken for some clients/feeds). Fixed in `Support\ImageProcessor` (forced MIME + discard on mismatch) with a regression test.
- ImageOptimizer: 3% minimum gain, `bulk_resize` opt-in (default off), cancel race fixed with an uncached DB read, 20 s batch time budget, translated job status.
- InstallGallery: block-theme placement (`woocommerce/product-details`), cheap `deleted_comment`, no-op rebuilds skip writes, cache purge on change, uncapped one-query backfill, native alt text + product-name fallback, retina `sizes`, accessible and readable lightbox JS, accent/focus/reduced-motion CSS.
- i18n: removed 24 duplicate msgids in `.pot`/`.po` (both tools had added the same Compatibility strings; the first translation was kept), 8 new strings, `.mo` recompiled (1060 messages, round-trip checked with Python gettext).
- New VERIFY rows #121 (page-cache purge on `clean_post_cache`) and #122 (Product Details block). Not verified on a real WordPress.

### 2026-11-24 — Core and repository skills audit

- Scope: bootstrap, module contract and registration, shared settings and assets, options, cache, migrations, queue integration, uninstall, development workflow, and repository skills. Reviewed the existing working tree. No runtime or test code was changed.
- Preserve the single plugin and explicit module registry, native WooCommerce settings and logging, WordPress options and image APIs, and the Action Scheduler adapter. The ZIP build already selects runtime files independently of the development dependencies.
- High priority: `src/Plugin.php:175` attaches migrations on AJAX and admin-post requests as well as admin screens. `src/Support/Migrations.php:81` relies on a best-effort cache lock and `src/Support/Migrations.php:104` counts an unchecked version write. `src/Modules/Compatibility/CompatibilityMigration.php:28` and the Recovery schema migrations assume successful DDL. Verify migration outcomes before advancing the version, protect against parallel runs, and keep the gallery backfill (`src/Modules/InstallGallery/GalleryStore.php:119`) out of the interactive upgrade request.
- High priority: `motored-core.php:34` declares Blocks compatibility for the whole plugin, while PaymentPlans documents classic-only checkout support and uses classic checkout hooks (`src/Modules/PaymentPlans/PaymentPlansModule.php:68`). Confirm supported configurations with a real checkout. HPOS and Blocks require separate verification.
- Medium priority: defer REST-specific initialization to `rest_api_init` instead of using request URI guesses as the route registration gate (`src/Support/Context.php:50`). Measure frontend overhead with warm and cold caches. Document explicit feature allowances and keep the base budget for pages where no feature runs. Keep module-specific admin hooks near their modules.
- Medium priority: use the dedicated Action Scheduler group for cancellation (`src/Plugin.php:129`, `uninstall.php:13`), verify scheduler readiness and make recurring scheduling idempotent (`src/Integration/ActionScheduler.php:22`). Publish the complete uninstall retention policy and use native option deletion to invalidate option caches (`uninstall.php:32`).
- Medium priority: define the supported WooCommerce range. The dev lock requires PHP 8.2 or later for PHPUnit 11 (`composer.lock:1300`), while the plugin runtime declares PHP 8.1. Keep PHP 8.1 runtime verification separate or make the development dependency lock installable on 8.1. Verify site and user locales separately and check WordPress's current autoload values in the database.
- Skills: all 14 skill folders have matching names and descriptions, with one skill and README per registered module. They live under `.claude/skills`. Expose one maintained copy through Codex's `.agents/skills` discovery path if both clients are used. Update Stage 1 claims in the overview, correct the add-module migration registration example, and align the review checklist with real performance budgets, feature declarations, and native integrations. Resolve the InstallGallery media dependency mismatch against actual WordPress behavior before updating its skill or test.
- Checks on Windows with PHP 8.3.36 and existing dev dependencies: PHPCS passed for 184 files, PHPStan passed, and all 13 Node parity cases passed. PHPUnit ran 663 tests with 3,111 assertions, one InstallGallery dependency expectation error, and 33 skips. Composer was not on PATH, so its checks were invoked directly.
- Verified a temporary Windows directory junction to this checkout, including the plugin entry point, admin stylesheet and es_AR MO file. Pending runtime verification: link the checkout under WordPress Studio's `wp-content/plugins/motored-core` and smoke-test activation, settings, assets, locale and checkout. Verify migrations, queue behavior and persistent caching on a production-like environment.

### 2026-11-24 — Core audit remediation

- Implemented admin-screen maintenance gates, a database-backed migration lock, checked version writes and schema verification. Added migration `2026112402` to verify schema on sites that already recorded the previous upgrades. The independent review identified lock contention, backfill write failure and SQLite SQL portability issues, which were addressed in the patch.
- InstallGallery queues the upgrade backfill in 50-product batches and retains a pending marker for recovery. Action Scheduler uses the group to cancel pending jobs and uses unique recurring actions. Uninstall deletes options through the native Options API.
- Motored Core declares Cart and Checkout Blocks incompatible while PaymentPlans uses classic checkout hooks. HPOS remains a separate declaration to verify in staging. REST controllers initialize on `rest_api_init`. Compatibility owns its product edit script, and shared CSS stays in the core admin loader.
- Updated affected repository skills, the PHP development requirement, data retention policy and the no-ZIP WordPress Studio setup in README. Codex skill discovery under the protected `.agents` path and real WordPress acceptance checks remain pending.
- Verification on PHP 8.3.36: PHPCS passed for 184 files, PHPStan passed, and PHPUnit with Sodium enabled reported 665 tests and 3,567 assertions, with 2 skipped and no errors or failures. The 13 Node parity cases passed separately. Composer was unavailable on PATH, so the individual tools were invoked directly.

### 2026-11-24 — CatalogFilters module audit

- Reviewed the storefront PHP, REST routes, browser scripts, snapshot invalidation and Compatibility/Mi Garage boundaries. CatalogFilters PHPUnit suite passed (12 tests, 75 assertions). No runtime files were changed during this audit.
- Correctness findings: smart suggestions build a second query delimiter with plain REST permalinks; filter links strip the query from their base; PHP and JS force OR even when WooCommerce interprets an existing attribute filter as AND. The headline count is reconstructed independently from the grid, with a different visibility model on search. Smart Enter can navigate to a partial match, and stale asynchronous results can replace suggestions after input changes.
- Data freshness and performance findings: the client sizes list has no expiration, the no-Redis version falls back to a fixed token, and an invalidation written during a rebuild can be cleared by the rebuilding request. Snapshot reads conflate a failed query with an empty catalog. Autocomplete builds and sorts size combinations before determining whether the query is for a size.
- Recommended scope: retain CatalogFilters, use the WooCommerce query as the grid authority, preserve existing URLs and features, and remove the dependency on Compatibility storefront internals. Compatibility should own the motorcycle catalog and its lookup. Assess moving account, privacy and garage state out of Compatibility during the next module audit. Verify archive and Product Collection parity in a real WooCommerce installation.

### 2026-11-24 — CatalogFilters fixes and Compatibility review

- CatalogFilters now uses complete REST URLs and HTTP caching for sizes, preserves routing parameters and native attribute operators, takes the headline total from the WooCommerce query where available, and respects existing robots directives. Snapshot failures preserve cached data and concurrent catalog changes stay pending for another rebuild.
- The suggestion lookup skips size computation for non-size queries, uses Compatibility's motorcycle catalog lookup, and only routes Enter to an unambiguous exact match. Independent review caught exact aliases, stale submit responses and older cached HTML configuration. These cases were addressed. Tests added for plain archive routing and concurrent invalidation. Full PHPCS and PHPStan passed. PHPUnit reported 667 tests with 2 skipped and no failures. All 13 Node parity cases passed.
- Compatibility and Mi Garage review: prioritize truthful REST mutation responses and safe guest/account synchronization, then bound import and reindex work, preserve existing job progress when scheduling fails, and scope index hooks to relevant product queries and changes. Existing simple-product and one-hop equivalence rules are worth retaining. Split Mi Garage account state, REST callbacks and privacy from the compatibility engine inside the same plugin, retaining current route paths and user meta.
- Pending WordPress verification: Classic and inherited Product Collection archives, plain and pretty permalinks, real cache behavior after stock changes, Garage login/logout on shared devices, and import/reindex retry and cancellation.

### 2026-11-24 — Mi Garage separation and focused Compatibility audit

- Mi Garage is a registered module with account endpoint, authenticated REST controller, repository, browser assets and privacy callbacks. Existing browser-facing paths and server user meta are retained. Guest drafts and account session caches are distinct, and the old ambiguous shared storage is not merged into accounts. CatalogFilters and Compatibility share core storefront assets.
- Garage mutations are serialized per account and failures are returned to the client. Import starts, batches and cancellation are serialized, batch checkpoints survive a scheduling failure, and the admin resume action processes only one batch. Reindex scheduling failures save a resume cursor instead of repeating batches synchronously. Index priming is scoped to the main query and the existing Product Collection integration.
- Focused Compatibility findings for a subsequent fix: index storage failures can still look like successful updates or a finished SQL scan (`ProductIndexSync.php:226`, `:259`); equivalent sizes reached from multiple OEM sizes can use whichever row is visited first (`MatchEngine.php:303`); public product compatibility responses trust an index row without checking current product visibility (`RestController.php:200`); the block query filter can affect secondary product grids (`Storefront.php:281`). Separate the remaining storefront integration responsibilities before considering deeper changes to the matcher.
- Pending live verification: Studio database locking and WooCommerce route/collection behavior, classic/block parity, logged-in page-cache exclusion and guest/account synchronization on real devices.
- Validation using the Studio PHP 8.3.36 runtime: PHPCS passed for 188 files, PHPStan passed, PHPUnit reported 668 tests with one skipped and no failures, and all 13 direct Node matching parity cases passed.

### 2026-11-24 — Compatibility follow-up fixes

- Reindexing distinguishes SQL failure, failed writes and completion. Deferred cursor markers preserve earlier outstanding work, failed removals are retried, and full reindexing includes orphaned or unpublished index rows. The admin action keeps reindex work in the queue and CLI reports an interrupted batch.
- Forward and reverse matching choose direct fitments first and resolve equivalent routes by exactness, then canonical OEM size, before checking that route's requirements. Server cache keys and the compact browser profile schema were updated so the earlier result is not reused.
- The public product-motos endpoint checks the current WooCommerce product and disables HTTP storage of the response. Only the main catalog and inherited Product Collection queries receive the motorcycle filter. The selector has an independent public configuration and Mi Garage attaches its optional save integration using a WordPress action.
- Independent review checked the Product Collection context in the installed WooCommerce source and flagged obsolete browser profiles and failed index removals. Both were handled in this patch. Runtime verification of queue recovery, SQLite query compatibility and Classic/Block storefront behavior remains pending.

### 2026-11-24 — Compatibility: kit per-axle technical sheet (+ CatalogFilters kits-by-size)

- Scope: a kit (front + rear tire sold as one simple product) now has one technical sheet per tire. `KitSpec` (pure) stores `_motored_tire_spec`; the product box shows Front tire / Rear tire sections (size as printed, model, load, speed, construction, tube) with a "copy from front" button; `62H` typed after a size fills load/speed (also for single tires). Index columns `front_construction` / `rear_construction` (migration `2026112403`); `MatchEngine` and `ev()` check each axle with its own construction (`fc`/`rc` marker keys, fallback `c`), reverse cache key `pm3_`. Technical information shows one row per tire for kits with a sheet. Advanced > Product Index lists kits without a sheet.
- Interference review (CatalogFilters / Mi Garage): kits get NO load/speed/width/profile/rim terms (facets exclude kits there, a native `filter_indice-carga` would otherwise list kits the counts ignore); construction/tube terms only when both axles agree. Kits-by-size is a separate `?kit_medida=` view (QueryFilter `post__in` intersection preserving `?moto=`/`?oferta=`, facet `kit_ids` context, chip, `data-kit` kept by `catalog-filters.js`, `noindex`). Mi Garage storage and the bike profile schema are unchanged; cached HTML without `fc`/`rc` still evaluates through the `c` fallback. The index ignores a sheet whose sizes no longer equal the attributes (ERP rewrite safety); products without a sheet keep their previous `terms_hash`.
- Self-review findings fixed during the change: a Brain Monkey stub of `wp_strip_all_tags` in the new test leaked into later suites that probe `function_exists()` (removed the dependency: the line is sanitized on save and escaped on output); kit technical-tab test mock needed the new meta read.
- Validation with the Studio PHP 8.3.36 binary (Composer not on PATH, tools invoked directly): PHPCS clean, PHPStan no errors, PHPUnit 683 tests / 3,698 assertions, 1 skipped (Node parity via shell), 0 failures; `node tests/js/compat-parity.test.js` 17/17 (4 new kit cases). `.pot` regenerated with `wp i18n make-pot` (WP-CLI ordering, hence the large diff), es_AR `.po` merged and filled, `.mo` compiled with `wp i18n make-mo`.
- Bug found while verifying in the browser (pre-existing): `CatalogRepository::ensure_size()` read `$wpdb->insert_id` after `AttributeInstaller::sync_size_term()` had inserted the size terms, so the first product saved with a NEW size got a term ID as `front_size_id`/`rear_size_id` (the product was indexed but had no size; matching and labels silently failed until the size already existed). Fixed (ID read before the term sync) with `EnsureSizeTest`; migration `2026112404` clears every index `terms_hash` and queues a full reindex, because a re-sync alone would see an unchanged hash and keep the wrong IDs. Production sites should let the queued reindex finish after updating.
- Browser verification (Studio site, Classic admin): Kit position shows the Front tire / Rear tire sections, the copy button copies model/construction/tube, `100/80-17 52S` + `130/70-17 62H` saved as load/speed per axle in the sheet and the index, the box summary reads `100/80-17 + 130/70-17 · Kit · Front 52S · Rear 62H`, migrations `2026112403`/`2026112404` applied on `admin_init`, `kit_ids_with_size('130/70-17')` returned the kit and the filter hint linked to `?kit_medida=130/70-17`. The test product was deleted afterwards. The storefront technical rows were checked by unit test only (Compatibility is disabled in this site's settings and was left disabled).
- Pending: storefront check with Compatibility enabled (Technical information rows per tire, `?kit_medida=` grid in Classic and block archives), filling the sheets of existing kits. Still open from the evaluation (phase 2): Quick add replaces the axle OEM and stores no min load/speed; deleting a motorcycle that customers saved in Mi Garage leaves a silent orphan.

### 2026-11-24 — Compatibility admin usability (motorcycle sizes, product box, 2017+)

- Motorcycle edit: data and sizes are separate forms. The data form no longer replaces the OEM sizes on every save (this silently wiped their min load/speed). Sizes are an editable table (`save_fitments`, `FitmentRow`): change position, size, type, limits or note in place, tick Remove, or fill one of the two empty rows; a changed position/size replaces the row because it is part of the unique key. Rows of other motorcycles are ignored. Quick add and the table read `120/70ZR17 58W` as minimum load 58 / speed W. Open ranges are labelled `2017+` (`MatchEngine::format_years()`), "Year to" may be left empty.
- Product box: per-position intro, one bordered panel per tire plus a "Whole product" panel (`.motored-box__panel*`, `.motored-box__grid--wide/--ratings`, `.motored-field__hint` in the shared admin CSS), live load index → kg hint (table passed once as a data attribute), kit preview with kg and km/h per tire, inline warning when Compatibility is disabled.
- Storefront report from the owner ("Additional information" with raw rows): on this site Compatibility is enabled and the tab renders correctly now; the raw rows matched the `ensure_size()` insert_id bug fixed earlier today (the product's index row had no size, so the label was empty and the rows were left untouched). Verified on the Twenty Twenty-Five block theme: single tire (Full size, Index 69H with kg/km/h) and a temporary kit (Front tire / Rear tire rows, matched Benelli TRK 502 2017+ and CFMoto 700MT); the test kit was deleted.
- Browser checks of the sizes table on Benelli TRK 502: add (`120/80ZR17 58W` as Alternative), edit (size and min load) and remove all worked; the data ended as before the test.
- Validation: PHPCS clean, PHPStan no errors, PHPUnit 688 tests (1 skipped: Node via shell), Node parity 17/17; es_AR complete (0 untranslated), `.mo` recompiled.

### 2026-11-24 — Compatibility full audit & Blocks 1–3 remediation

- **Block 1 (critical admin & matching fixes):**
  - Fixed fitments table column clipping on Motorcycle Edit (`Ui::table_open( false, true )` + `.motored-table--wide { min-width: 1040px }` in `assets/admin/motored-admin.css`, `Type`/`Construction` widened to `md`, `Position` to `sm`, `Note` has >= 200 px before horizontal scroll).
  - Unified motorcycle data + tire sizes into ONE `<form>` (`op=save_version`, `has_fitments=1`, `Save motorcycle` + `Save & publish`), preventing silent loss of edits across boxes and blocking publishing motorcycles with 0 tire sizes (`no_sizes` warning).
  - Made `CompatibilityAdmin::save_fitment_rows()` two-phase (deletes before upserts) so swapping sizes/positions across rows in one save never deletes a newly written row.
  - Extracted `MatchEngine::build_axes_from_fitments()` and wired `CompatibilityAdmin::compute_store_match_preview()` to it (fixed stale `'l'`/`'s'`/`'c'` keys that previously ignored load/speed/construction and OEM equivalence inheritance).
  - Fixed `Storefront::resolve_full_size_label()` for kits with a valid `KitSpec` so it preserves saved axle designations (e.g. `120/70R17 + 160/60R17`) instead of rewriting `R` to `ZR`.
- **Block 2 (product screen WP/WooCommerce integration & safety):**
  - Moved `ProductTireAdmin` meta box priority from `high` to `default` (below WooCommerce `Product data`), replaced nested bordered panels inside `.motored-box--flush` with clean hairline dividers, normalized controls to `30px`, replaced the 3-line Load index help text with a 1-line dynamic hint, added live `blur` size-suffix extraction in `assets/admin/motored-tire-box.js`, made compatible motorcycles clickable admin links (via `version_id` in `MatchEngine`), guarded non-simple products on render & save, and validated `TireAttributePlan` before mutating `_motored_full_size` or attributes.
- **Block 3 (robustness, cleanup, i18n, storefront polish):**
  - Applied `CatalogRepository::clean_variant()` before `has_year_overlap()` in `quick_add_moto` and `save_version`; preserved existing model `cc`/`aliases` in `CatalogRepository::upsert_model()` when omitted on partial JSON imports; added delete confirmation dialogs; pruned deleted/draft bikes in `GarageRestController::hydrate_garage_profiles()` and guest 404 revalidation in `compat-garage.js`; removed dead `selector_url` setting/accessor, `FitmentRow::display()`, and legacy CRUD/tool ops; replaced flat pagination loop with `paginate_links()`; translated dry-run summaries, `JsonImporter` dry-run diagnostics and import status labels; guarded `Compatibility\RestController::register_routes()` with `Config::enabled()`; updated `compat.css` tokens for dark-theme safety and added `restUrl()` to `motored-ui.js`.
- **Tests & i18n:** Added regression tests in `StorefrontTabsTest`, `MatchEngineTest`, `JsonImporterTest`, `SharedAdminUiTest`, and updated `FitmentRowTest`. Updated `.pot`, `es_AR.po`, and compiled `motored-core-es_AR.mo` (1,032 entries, 0 missing `es_AR` strings, binary round-trip verified).
- **Checks run:** `node --check` passed on all 4 modified JS files; `node tests/js/compat-parity.test.js` passed **17/17**; static `SharedAdminUiTest` check passed. **Pending:** `composer check` (`phpcs`, `phpstan`, `phpunit`) could not be executed here because `php`/`composer` were unavailable in PATH/sandbox; run `composer check` and verify the visual changes in WordPress Studio.

### 2026-11-24 — Mi Garage 404 endpoint fix

- **Root causes:** `GarageEndpoint` only called `add_rewrite_endpoint( 'mi-garage', ... )` at `init@10` without registering `mi-garage` in `woocommerce_get_query_vars` or `query_vars`; `MiGarageModule` skipped CLI/cron/REST/AJAX contexts so any rewrite flush outside frontend/admin erased `mi-garage` from `wp_options.rewrite_rules`; `maybe_flush_rewrite_rules()` only checked `motored_compat_rewrite_ver === '1'` and never self-healed missing rules; `Plugin::activate()` did not reset `motored_compat_rewrite_ver`.
- **Fixed:**
  - `src/Modules/MiGarage/GarageEndpoint.php`: added `register_rewrite_hooks()` (`init@0` `add_endpoint`, `query_vars@0` `add_query_vars`, `woocommerce_get_query_vars` `add_wc_query_vars`), hooked `woocommerce_endpoint_mi-garage_title`, bumped `REWRITE_VERSION` to `'2'`, added `is_missing_from_rewrite_rules()` self-heal (with 5-minute `motored_garage_rewrite_heal` lock transient, skipping AJAX and Plain permalinks).
  - `src/Modules/MiGarage/MiGarageModule.php`: loads in all contexts and registers the zero-cost rewrite/query-var hooks even in CLI/cron/REST/AJAX so flushes never wipe `mi-garage` (cron/CLI return immediately afterwards with 0 queries/assets).
  - `src/Plugin.php`: `activate()` deletes `GarageEndpoint::REWRITE_OPT` so plugin reactivation always flushes on the next `admin_init`.
  - Added unit tests in `tests/Unit/Modules/MiGarage/GarageEndpointTest.php` and updated `docs/VERIFY.md` #100 and module READMEs.

### 2026-11-24 — Compatibility, Mi Garage, CatalogFilters, BikePages & Seo implementation (study remediation)

- **Phase 0 (critical fixes, perf, copy, scorecard):**
  - Fixed S0-2 (`Storefront::robots_noindex()` gated with `is_catalog_request()`), S1-4 (`GarageEndpoint::robots_noindex()` on `mi-garage`), P1-2 & P1-3 (`Storefront::maybe_enqueue_catalog_assets()` loads `compat.css` in `<head>` and gates JS/CSS to catalog views, indexed products, `mi-garage`, or logout cookie), P1-1 & B7 (`FacetEngine::snapshot( bool $search )` cached/memoized with isolated `SnapshotInvalidator` search tracking; deterministic `size_combinations()` sort), Decision 5 (`LayeredNavHooks` + `replace_native_layered_nav`), P2-3 (`MatchEngine::bike_profile_many()` + `CatalogRepository` batch helpers in `<= 3` queries), B1 & §5 copy (`TireSpecs::position_label()` single source of truth, `RestController::bike_counts()`, disabled zero-count tabs with counts, no dead-end pair links on product banners, notify triggers), and B4 (`ReadinessReport` scorecard in `Productos > Compatibilidad > Readiness`).
- **Phase 1 (`Modules\Seo`):** Added lightweight core SEO module (`Config`, `SeoContext`, `HeadRenderer`, `SitemapFilters`, `SeoMigration` `2026112405`, `Redirects` 404-only, `SeoAdmin`, `SeoModule`).
- **Phases 2–5 (`Modules\BikePages` + Guest Garage):**
  - Emitted `motored_compat_catalog_changed` across all 12 write methods of `CatalogRepository` (with pre-delete/pre-update context), `motored_compat_product_index_changed` in `ProductIndexRepository`, and `motored_compat_import_chunk_done` in `ImportRunner`.
  - Added `PostType` (`motored_bike`, `motored_bike_brand`), `Signature` (per-model OEM size signature grouping & year merging), `BikePageSync` (batched Action Scheduler sync preserving manual edits, 301 redirects via `Seo\Redirects`, `_motored_indexable`), `HubLinks` (single-query cached URL map powering clickable per-motorcycle links in `CompatListRenderer` + `compat-tab.js` and brand/archive hubs), `BikeRenderer` (`[motored_moto]` conversion anatomy, "Complete the set" pair block, FAQPage/ItemList/BreadcrumbList/Motorcycle JSON-LD, zero `<h1>`, zero `Product` schema), `DemandMigration` (`2026112406`), `BikeDemandHooks`, `DemandStore`, `DemandRestController` (`POST /compat/notify`), `DemandPrivacy`, `BikePagesAdmin`, `SizePages` (Stage 6 `/medidas/` opt-in), and `[motored_mi_garage]` guest garage management (`GarageEndpoint` + `compat-garage-page.js`).
- **Docs, Skills & i18n:** Added `Modules\Seo` and `Modules\BikePages` READMEs and skills, updated `docs/VERIFY.md` (#123–#129), `docs/ARCHITECTURE.md`, and synced `.pot`/`.po`/`.mo` (`1,191` entries, `1,167` scanned `motored-core` strings, 0 missing/untranslated, binary `.mo` round-trip verified).
- **Review & Verification (PHP 8.4.27 via Studio bundled binary with `mbstring` + `sodium`, Composer not on PATH):**
  - Fixed static-analysis and coding-standards issues surfaced by `composer check` tooling: void Action Scheduler wrappers (`BikePageSync::sync_model_action()`, `BikePagesModule`), `term_exists()` return handling (`BikePageSync::ensure_brand_term()`), `DAY_IN_SECONDS` constant guard (`DemandStore::schedule_purge()`), PHPDoc type/alignment fixes (`SeoContext`, `BikeRenderer`, `Signature`), bounded brand directory query (`posts_per_page => 100`, `no_found_rows => true` in `HubLinks`), Yoda condition (`PostType`), removed forbidden `background`/`font-size` properties from `.motored-box__preview`/`.motored-box__panel-title` in `assets/admin/motored-admin.css`, and test isolation fixes (`BikePagesModuleTest`, `GarageEndpointTest`).
  - Audited `Modules\Seo`, `Modules\BikePages`, and `src/Integration/` for `style=`, theme names/hooks (`get_header`, `get_footer`), `admin-ajax`, and PII masking: clean.
  - `phpcs`: **214/214 files passed** (0 errors, 0 warnings).
  - `phpstan analyse --memory-limit=1G` (level 6): **208/208 files passed** (0 errors).
  - `phpunit`: **707 tests, 3,899 assertions, 0 errors, 0 failures, 2 skipped**.
  - `node --check` (`compat-garage.js`, `compat-garage-page.js`, `compat-tab.js`, `compat-selector.js`) passed; `node --test tests/js/compat-parity.test.js` passed **17/17**.
  - Pending live staging checks in WordPress Studio per `docs/STAGING-CHECKLIST.md` and `docs/VERIFY.md` (#123–#129).

### 2026-11-24 — BikePages/Compatibility visual review: Phase 1 fixes

Found by visual testing + code review of the study implementation (see review in chat / `STUDY-COMPAT-GARAGE-FILTERS.md`).
- **`(%d)` shown literally** on the product banner: `compat-garage.js` `fmt()` replaced only `%s`. Now `%[sd]`; covered by
  `tests/js/compat-parity.test.js` (`fmt` exposed on `motoredGarageApi`).
- **"Delanteras (1)" with no front tire:** `MatchEngine::matching_product_counts()` added kits to front AND rear, while the
  `?pos=` filter excludes kits. Extracted pure `count_by_position()` (kits count only as kits), cache key `mcnt2_`;
  regression test in `MatchEngineTest`. Also removes the dead-end "Completá el juego: delanteras" link.
- **Banner/garage/tab links went to `?moto=` instead of the bike page:** `HubLinks` filter was frontend-only, but those
  payloads come from REST. Now `register_url_filter()` in REST; test in `BikePagesModuleTest`.
- **Masculine tire labels** ("Trasero", "Delantero 120/70ZR17"): `TireSpecs::position_label()` uses
  `_x( …, 'tire position' )` → Delantera / Trasera / Delantera / Trasera. Admin/attribute "Front"/"Rear" unchanged.
- **English H1 "Tires for …":** sync builds titles in the site locale; `SYNC_VERSION = 2` queues one rebuild on the next
  plugin admin screen (edited titles preserved by `_motored_synced_title`).
- **"Written by in" on bike pages (block themes):** plugin `single-motored_bike` block template (VERIFY #130).
- **Unstyled chip/buttons:** `.motored-bike-page`/`-hub`/`.motored-notify-form` now share the token block (`--motored-ok`,
  `--motored-focus`…); bar tabs and bike-page jump links are theme-independent chips (`.is-active`, `.is-disabled`);
  primary CTAs add core `wp-element-button`. Asset versions bumped (`-compat2`, `-garage4`).
- i18n: 4 entries added to `.pot`/`.po`; `.mo` recompiled with the Python writer, previous catalog unchanged (diffed).
- Checks (PHP 8.4.27 Studio): phpcs OK, phpstan L6 0 errors, phpunit 709 tests OK (2 skipped), node parity + fmt OK.
- Next (Phase 2): slug without year (sticky, 301), shorter `<title>`, single "indexable" rule (in stock), 410, SEO-plugin guard.

### 2026-11-24 — BikePages/Seo: Phase 2 (URLs, title, indexability, 410, SEO-plugin coexistence)

- **Slug without the year:** `/cubiertas-para/benelli-trk-502/` instead of `…-2017/`. The page covers a year range
  ("2017+"), riders search "<marca> <modelo>", and `year_from` in the URL changed whenever an older year was added.
  Year stays in H1/`<title>`. `Signature::assign_post_slugs()`: one page per model owns the clean slug (sticky —
  never handed to a newer generation); other signatures use `-{year_from}[-variant]` (year_to never in the URL).
  `SYNC_VERSION = 3` rebuilds once; every rename leaves a 301 through the existing `Seo\Redirects` path.
- **`<title>`:** `BikeRenderer::seo_title()` → "Cubiertas Benelli TRK 502 (2017+): medidas y precios | Sitio", dropping
  the site name, then the topic, to stay <= 60 chars. Obsolete "Tires for … · Sizes and prices" string removed.
- **Indexability = one rule:** new `BikePages\Availability` (published + visible + in stock/backorder) used by the
  sync flag, the sitemap flag and the hero "N cubiertas en stock". Before, the flag counted out-of-stock tires.
  **Bug fixed:** the flag was only recomputed when the product INDEX row changed; stock flips and product
  publish/unpublish never triggered it. Now `woocommerce_product_set_stock_status` + `transition_post_status`
  (indexed tires only, all contexts, queue-only) schedule the debounced sweep (VERIFY #133).
- **410 Gone:** `BikePages\GoneHandler` (template_redirect@20, 404-only, 1 query) for trashed bike pages without a
  301 target (VERIFY #132).
- **SEO-plugin coexistence:** `HeadRenderer::external_seo_plugin()` (lazy constant check + filter
  `motored_seo_external_plugin`): title/description/canonical/OG and `WebPage`/`BreadcrumbList` are left to
  Yoast/Rank Math/AIOSEO/SEOPress/TSF; we keep robots + `ItemList`/`FAQPage` (VERIFY #131).
- Tests: slug assignment (fresh/migration/sticky), title widths, 410 path parser, external-plugin head output.

### 2026-11-24 — BikePages/Compatibility: Phase 3 (performance)

Measured on the local site (no object cache, TT5) with a temporary query-count mu-plugin (removed afterwards).
| Page | Before | After | Baseline (plain page) |
|---|---|---|---|
| `/cubiertas-para/` hub | 263 queries, 171 KB | 93 queries, 106 KB | 88 |
| `/cubiertas-para/marca/benelli/` | 141 | 94 | 88 |
| `/cubiertas-para/benelli-trk-502/` | 114 | 111 | 88 |
| `/shop/?moto=…` | 109 | 108 | — |
- **Hub bug:** the theme archive loop printed every bike post's content, so `[motored_moto]` rendered 10 FULL bike pages
  (10 product grids) on `/cubiertas-para/`, and the brands directory never showed (TT5 has no archive-description
  block; `core/term-description` skips our filter). Fix: outside its singular page the shortcode prints a 0-query
  card (`_motored_oem_sizes` meta, sync v4); block themes get plugin hub templates (`archive-motored_bike`,
  `taxonomy-motored_bike_brand`) with H1 + `[motored_bike_hub]` (brands + every bike page, 2 queries).
- **One partition per bike:** `MatchEngine::matching_ids_by_position()` replaces 4–5 candidate queries (ids per
  position + counts) with one; counts and grids now come from the same lists, and a dual tire is listed only on the
  axles it actually fits. Bar fallback counting removed.
- **Mi Garage strings:** 11 page-only strings moved to `GarageAssets::page_i18n()` (inline only with
  `compat-garage-page.js`); unused `frontPlural`/`rearPlural` dropped.
- **Meta description:** `BikeRenderer::seo_description()` keeps it <= 160 chars (Benelli was 166).
- **Data found while testing (not code):** BMW S1000RR (2010–2011 and 2012–2014) have no OEM fitments; Bajaj Dominar
  D400 lists `150/90-15` as a second OEM front and `100/80-17` as a second OEM rear. Fix in Compatibility > Motos.

### 2026-11-24 — Review (motored-review): Compatibility, Mi Garage, CatalogFilters, BikePages, Seo after Phases 1–3

Scope: the study implementation + Phases 1–3. Tools: phpcs OK, phpstan L6 0 errors, phpunit 714 tests OK (2 skipped),
node parity + fmt OK, i18n script: 1,159 code strings, 0 missing in `.pot`/`.po`, 0 untranslated.
Measured on the local site (TT5, no object cache) with a temporary localhost-only mu-plugin (plugin on vs off via
`option_active_plugins` filter, query count + SAVEQUERIES trace); the mu-plugin was removed afterwards.

Findings — fixed:
- **N+1 on every product page:** `HubLinks::version_slug_url_map()` called `get_permalink()` per bike page = 46 queries
  without Redis. Now selects `p.*` and primes `update_post_cache()` in the same query (cache key `bike_page_urls_v2`).
  Product page 178 -> 134 queries (plugin off: 118).
- **Rule 1 (0 queries on pages without Motored features):** option reads moved behind cheap context checks:
  `Compatibility\Storefront::maybe_enqueue_catalog_assets()` (context first), `Seo\Redirects::maybe_redirect()`
  (`is_404()` first), `Seo\HeadRenderer` title/canonical/robots/head (no context and no search/paged -> return before
  the option), CatalogFilters native layered nav (frontend: `widget_display_callback` only when such a widget renders;
  admin keeps `widgets_init` unregister). Home/page/checkout: +4 -> +1 query.
- **Copy:** kit products said "Esta cubierta sirve…" -> new `compatWithKit` "Este kit sirve para tu %s".
  Factory sizes in the product banner / Mi Garage now "Delantera 120/70ZR17" (no colon), same as bar and bike page.

Verified OK: one `<h1>`, one canonical, JSON-LD `BreadcrumbList/WebPage/ItemList/FAQPage` (no `Product`) on bike pages;
`noindex,follow` on `?moto=`, `?pos=`, search, non-indexable bike pages, Mi Garage; sitemap lists only the 6 indexable
bike pages; banner/tab links point to bike pages (rear, kit); Mi Garage page strings load only there; notify REST
rejects invalid contact / missing consent (422, es_AR); admin handlers check `manage_woocommerce` + nonce; CSV export
formula-safe; demand logs masked; no theme names/selectors, no inline `style=`; new options autoload=false.

Pending / decisions:
- **+1 query on every page** (`motored_bike_pages`): the CPT/taxonomy rewrite slugs are configurable, so
  `register_post_type()` needs the option on every request. Options: make that one option autoload=yes (small, ~1 KB),
  or fix the slugs (no setting). Owner decision; rule 4 says autoload=false.
- Cart page +2 queries: Compatibility primes fits for the cross-sells Product Collection block (feature-adjacent).
- Product page still +16 over plugin-off (index lookups, compatible-motorcycles list, attribute row, payment plans,
  review photos) — feature page, within expectations; not profiled further.
- Not run here: Redis on/off cold+warm, Classic vs Blocks checkout, HPOS, another block theme and a classic theme
  (STAGING-CHECKLIST + VERIFY #124–#134), notify insert + privacy export/erase (avoided writing PII on the local DB).
- Catalog data: BMW S1000RR (2010–2011, 2012–2014) without OEM fitments; Bajaj Dominar D400 with suspicious extra
  OEM sizes (150/90-15 front, 100/80-17 rear).

### 2026-11-24 — Decision: `motored_bike_pages` is autoloaded (rule 4 exception)

- Owner decision after the review: the BikePages option is read on every request by `register_post_type()`
  (configurable `/cubiertas-para/` and brand slugs), which cost +1 query on every page. It is now autoloaded.
- Implemented as an explicit allow-list, not a per-call flag: `Support\Options::AUTOLOAD_MODULES = array( 'bike_pages' )`;
  `Options::update()`/`ensure()` and the WooCommerce settings fields (`BikePagesAdmin`, `'autoload' =>
  Options::autoload(...)`) use it; `Options::sync_autoload()` flips existing installs on `admin_init` using the in-memory
  alloptions (no query when already right). AGENTS.md rule 4 and the review skill name the exception.
- Found while verifying: on this install the option had never been saved (defaults only), and a MISSING option is
  also 1 query per request (`notoptions` is not persistent without an object cache). `sync_autoload()` therefore also
  creates it EMPTY + autoloaded (defaults stay in code, so later default changes still apply).
- Result (local, plugin on vs off): home 86/86, page 84/84, checkout 60/60 — **0 added queries** (was +4 before the
  review, +1 after it). Cart +1 (cross-sells Product Collection, feature-adjacent).
- Test: `OptionsTest::test_only_bike_pages_is_autoloaded_and_sync_flips_it_once`.

### 2026-11-24 — Stock alerts and integrated storefront remediation

- Product decision: one Avisame CTA. Logged-in customers use saved WC_Customer billing phone + account email without a channel selector. Guests use an accessible native dialog with one contact, explicit consent and optional native WooCommerce registration/login. Existing accounts are never linked without authentication. My Account registration is enabled on the local site; there is no custom auto-registration API or automatic login.
- WooCommerce core provides customer CRUD, registration, stock hooks, email transport/templates and Action Scheduler, but the installed core does not provide this customer-facing motorcycle/axle compatibility waitlist. No new plugin was installed.
- `OrderNotifications\\BikeDemandNotifier` consumes BikePages events; WAHA remains transport-only. Migration `2026112407` extends demand state without storing contacts/text in AS arguments. Claims are atomic, send-time availability is checked, WAHA has first priority, and email is a terminal fallback (or the only supplied contact). OFF/test modes must never be bypassed through real email. Signed cancellation works without login. Email acceptance is not a read/delivery receipt; process-crash recovery of ambiguous `sending` rows remains an operator decision.
- Captured contacts remain the consented snapshot; changing an account later does not silently rewrite existing subscriptions. Guest optional signup uses the native form and is not an inline quick-account conversion experiment.
- Commercial BikePages counts/grids/recommendations/summaries now share published + visible + purchasable + stock availability. Summaries no longer stop at 24; longer grids link to the native paginated filtered shop. Missing technical data is explicitly qualified. Signatures include approved alternatives (OEM-only hashes preserved); sync version 5 queues reconciliation.
- Scoped CSS fixes classic WC card grids in block themes and contains the technical table in a keyboard-scrollable mobile region. CatalogFilters preloads styles in head for explicit shortcode pages/configured shop widgets, but remains disabled on the local site; the no-Redis full-snapshot scaling strategy was not changed without representative data.
- Public guest garage: local page `/mi-garage/` (ID 118, validated core paragraph + shortcode); non-autoloaded `motored_mi_garage_page_id` selects its guest link. Utility page is noindex and omitted from the core sitemap. Account garage endpoint is unchanged.
- Landing sitemap requires a positive stored indexability flag and respects explicit noindex. A manual index cannot override live availability noindex. Stored sitemap metadata still reconciles asynchronously and external HTML cache purge remains provider/plugin-specific.
- Verification: 726 PHPUnit tests / 3986 assertions, one skipped; PHPStan clean; 17 JS parity cases + formatter cases; local REST/queue/mail integration passed with HTTP blocked and mail intercepted. Browser checks at 1040/390px covered modal focus/Escape, guest submit (mock response), account direct request (simulated UI configuration), public guest garage and no JS errors. Server-side account ownership was tested with a temporary native customer; fixtures were deleted.
- Operational block: local WAHA is OFF with no endpoint configured. No real WhatsApp or email was sent; enabling production delivery requires endpoint/session/key setup, a controlled-recipient test and healthy cron/AS. Redis cold/down, thousands of products, external cache purge and additional themes remain staging checks.
- Existing unrelated working-tree edits were preserved; no bulk reset or commit was performed.

### 2026-11-25 — Report remediation: remaining gaps closed

- Verified against the technical report: Avisame (single CTA, account contacts, guest dialog, WAHA-first `BikeDemandNotifier`, email fallback, signed cancel), spec qualification, shared commercial availability, untruncated summaries, OEM+alternatives signature, mobile table, block-theme grids, "Entendé los tipos de compatibilidad" link, sitemap/robots policy, public guest garage, atomic rate limit and specific 503 storage errors were already in place (entry of 2026-11-24).
- **Paged archives (report §4.5):** `motored_seo[noindex_paged]` default is now `no`; `HeadRenderer::clean_current_url()` keeps `/page/N/` (or `?paged=N` without pretty permalinks) instead of canonicalizing page N to page 1. Sites that saved the option keep their stored value. Opt-in noindex still covered by `SeoModuleTest`.
- **Mi Garage orphan bikes (report §6):** `GarageRestController::hydrate_garage_profiles()` returns `unavailable` labels; `compat-garage.js` emits `motored:garage-unavailable` and the garage page shows an es_AR notice. Not persisted (a version may be republished).
- **Analytics dependency (report §6):** `GarageAssets::enqueue()` loads `motored-ui` as a dependency only when `analytics_events` is on (0 extra assets otherwise).
- i18n: 2 strings added/changed; two duplicate msgids appended to the `.po` by the previous stage were removed (first translation kept, matches the shipped `.mo`); `.mo` recompiled with the Python writer and round-trip checked.
- Still open (unchanged): FacetEngine full snapshot without Redis needs a representative catalog to measure; CatalogFilters disabled locally; real WAHA/email delivery needs endpoint/session configuration and a controlled-recipient test; catalog data (images, OEM data, specific content) is editorial work.

### 2026-11-25 — Review (motored-review): Avisame, availability, Seo, Mi Garage after the report remediation

Scope: BikePages demand capture/cancel, `OrderNotifications\BikeDemandNotifier`, `Waha\Sender` contract, `Availability`, `BikeRenderer` data path, `Seo\SitemapFilters`, garage JS. Tools (Studio PHP 8.3.36; `composer` not on PATH, binaries run directly): phpcs clean, PHPStan L6 0 errors, PHPUnit 729 tests OK (1 pre-existing skip), node parity 17 cases OK. Local site probes: guest POST without nonce reaches validation, invalid phone -> 422 es_AR, GET on the old unsubscribe route -> 404, cancel page renders in es_AR.

Findings — fixed:
- **Undeliverable phones accepted** (`DemandStore::parse_contacts()`): any 7+ digits were stored and only failed later in WAHA (customer told "saved", never notified when no e-mail). Now `Waha\Phone::normalize()` at capture; "011 15…" and "+54 9 11…" dedupe to one E.164.
- **Accounts without a phone silently got e-mail only** (`DemandRestController`): the button now opens the dialog asking for WhatsApp (empty = e-mail); the typed number is used for this alert only (account is not modified).
- **Generic success/error copy**: success now states the real channel; errors are specific (consent, contact, unknown bike, storage).
- **Guest nonce vs page cache**: guests no longer need `X-Motored-Nonce` (a cached nonce > 24 h returned 403 "session expired"). Accounts keep `wp_rest` + the custom nonce. Guest abuse control = honeypot + atomic rate limit.
- **Cancel link deleted on GET and answered raw JSON**: mail scanners/link previews would cancel alerts. New `DemandCancelHandler` (frontend, isset-only on other requests): GET = confirmation, POST = delete; REST route is POST-only.
- **N+1 in availability** (`Availability::ids()/count()`): `wc_get_product()` per product without Redis (~3 queries each). Now `_prime_post_caches()` (verified in wordpress-stubs) in one batch / chunks of 20.
- **Repeated availability checks in the sweep** (`BikeDemandNotifier::scan()`): evaluated per row; now once per bike+axle per batch.

Reviewed OK: atomic CAS state machine (`transition()`), no contacts in AS args, WAHA OFF/test/auth/not_configured never fall back to e-mail, send-time availability recheck, terminal-only email fallback with 3 bounded attempts, stock flips trigger a scan via `motored_compat_product_index_changed`, sitemap requires flag=1 and respects the (constrained) robots override, masked logs.

Pending / suggestions (not changed):
- Rows stuck in `sending` after a worker crash need an operator action (no automatic recovery: re-sending could duplicate a WhatsApp).
- An `all`-position alert fires when ANY axle has stock; the message says "delantera y trasera". Consider per-axle wording.
- E-mail uses `wc_mail()` + WC header/footer; a registered `WC_Email` subclass would give the owner WooCommerce > Settings > Emails control (enable, subject, heading, preview) and inlined styles — the more native option.
- No confirmation message at subscription time (a WhatsApp "te vamos a avisar" would validate the number on the spot; costs a message and WAHA rate).
- "View all" link goes to the shop `?moto=&pos=`: the shop may list out-of-stock items the bike page excluded (counts can differ). FacetEngine snapshot without Redis still unmeasured at scale.
- Not run: real WAHA delivery, real e-mail, Redis cold/down, other themes, browser check of the new account dialog (site JS verified with `node --check` only).

### 2026-11-25 — Avisame e-mail moved to a native WC_Email

- Owner decision (follow-up of the review): the stock-alert e-mail is now `OrderNotifications\Emails\StockAlertEmail extends WC_Email`, registered via `woocommerce_email_classes` and fetched from `WC()->mailer()->get_emails()` so the owner's saved settings apply. Replaces the hand-built `wc_mail()` + header/footer body.
- Owner controls: enable/disable, subject (`{site_title}`, `{bike}`), heading, additional content, HTML/plain; theme-overridable templates. WhatsApp-first order and all safety switches unchanged.
- Disabled e-mail: e-mail-only alerts are held as `paused/email_disabled` (re-enabled -> next sweep sends); WhatsApp terminal failure no longer queues a disabled e-mail (row `failed`).
- Verified: PHPUnit (3 new notifier tests), phpcs, PHPStan; on the local site (WordPress bootstrapped in admin context, nothing sent) the e-mail is listed and enabled, subject/plain/HTML render in es_AR with the store's WooCommerce e-mail styles. Not verified: a real delivery.

### 2026-11-25 — Mi Garage storefront drawer

- The desktop and mobile header now open one native dialog. The original page remains a direct entry point, including checkout. The panel prioritizes the active motorcycle and reuses the cards, compatible tire links, and selector of the full page.
- Independent review caught the late mobile-sidebar render and the competing focus trap. The drawer mounts before footer assets print (`GarageEndpoint.php:231`), and the theme closes its menu overlay before opening the drawer (block "Release the mobile menu's focus trap" in the theme's `assets/js/flatsome.js`). An existing desktop-only header configuration now gets a mobile shortcut.
- The list responds to matching browser storage events (`compat-garage.js:793`). The drawer fetches current account data on open (`compat-garage-page.js:219`). Reviewed existing REST permissions and es_AR translations used by the drawer.
- Studio Chromium checks passed at 320, 390 and 1440 px for opening, lazy picker loading, closing and focus restoration. Verified the complete guest selector and save, tire link, updates between open tabs, shared list rendering, delayed removal and undo, authenticated reads, and the direct header link with JavaScript disabled. Live checkout, cold Redis and other themes remain staging checks.
- PHPUnit reported 732 tests and one skip. The changed plugin PHP files passed PHPCS and the theme files passed PHP lint. Global checks found existing CRLF line endings and unrelated findings in the in-progress `CatalogFilters/RestController.php` changes.

### 2026-11-25 — Catalog and selector control spacing

- Scoped control margins to the catalog accordion, filter footer and search (assets/css/catalog-filters.css:342), and to the shared compatibility selector actions (assets/css/compat.css:181). The existing wrapper gaps and padding supply spacing. Updated both stylesheet versions.
- Independent review found no cascade or scope regressions. Studio Chromium showed zero bottom margin on all eight shop accordion buttons and the apply button, working accordion toggles, and zero margin on the selector actions in Mi Garage. A button outside the components retained its theme margin.
- Follow-up: scoped the left-margin reset to direct facet list items (assets/css/catalog-filters.css:368), preserving the grid gap. Independent review verified selector specificity. In Studio Chromium, all nine shop facet items measured 0 px inside a Flatsome content wrapper and an ordinary list item kept its 20.8 px margin. Bumped only the catalog stylesheet version.
- Follow-up: kept the localized result count together in the catalog header (assets/css/catalog-filters.css:300). Studio Chromium confirmed singular and plural counters fit on one line in a 235 px filter. Independent review checked the scope and stylesheet version. Targeted PHPCS passed and PHPUnit reported one skip. The existing PHPStan findings in the catalog REST controller remain.
- Accordion follow-up: removed the saved expansion map. Native details open on initial render only for active facets (src/Modules/CatalogFilters/FilterRenderer.php:154). Independent review identified the need to preserve access without JavaScript. Studio Chromium verified application and removal with and without JavaScript. Targeted PHPCS and PHPUnit passed with one skip.

### 2026-11-25 — Shared motorcycle position and Spanish facet labels

- The compatibility bar and sidebar share the motorcycle position, including kit selection, while retaining native taxonomy positions without a motorcycle. Legacy single-position URLs normalize at template redirect (src/Modules/Compatibility/Storefront.php:1015). Explicit compatibility position takes priority on conflicting URLs. Legacy multi-position and combined-axle URLs retain their taxonomy behavior until a position is selected in the bar.
- Independent review verified the bike position active state, AJAX response URL handling, and scoped sidebar counts. Bar totals retain their motorcycle-wide meaning. With the local Benelli fixture, Chromium verified switching from rear to kit, returning to all, handling both legacy URL forms, mobile Apply, and no-JavaScript links.
- Position options and active chips now use the existing es_AR tire-position translations for known taxonomy slugs. Studio Chromium confirmed the same labels with and without a motorcycle and the localized active chip on the unchanged native `filter_posicion=trasero` URL. Targeted PHPCS and PHPUnit passed with one skip. PHPStan still reports the two prior findings in CatalogFilters/RestController.php.
- Composer was unavailable. Ran its checks directly with Studio PHP. The changed PHP files passed PHPCS and PHPUnit reported 732 tests with one skip. Global PHPCS and PHPStan still reported pre-existing findings outside these edits, including CRLF and issues in CatalogFilters/RestController.php.

### 2026-11-27 — Engine/skin contract between motored-core and the store theme

- New `docs/THEME-CONTRACT.md` (contract 1.0) and `Motored\Core\Api` (`CONTRACT`, `supports()`, `garage_url()`), autoloaded only when the theme calls it. AGENTS.md rule 9, ARCHITECTURE.md, `motored-review`, the CatalogFilters and MiGarage READMEs/skills and the theme AGENTS.md now state the split.
- `compat.css`, `catalog-filters.css`, `install-gallery.css`: every brand value became a public input token (`--motored-*-color` / `--motored-*-size`) with neutral fallbacks; `install-gallery.css` no longer reads the theme's `--mt-*`; the 13 `!important` of the garage card/badge were replaced by specificity; the garage CTA keeps WooCommerce `.button.alt` colors (the theme paints it red). The smart-search panel structure (`.motored-suggest__*`, `.motored-trending__*`), which existed only in the theme, moved to `catalog-filters.css`. Versions: compat10, filters7, garage11.
- The accent setting now prints `:where(:root){--motored-accent-color}` (zero specificity) so a theme value wins regardless of load order.
- `catalog-filters.js` no longer calls `Flatsome.attach` or clicks Magnific's close button; it adds `detail.root` to `motored:filters-updated` and the theme does both. `compat-garage.js` exposes the read-only `window.Motored.garage` (`version`, `active()`, `bikes()`).
- Theme: tokens on `:root` of the Motored block in `flatsome.css`; the per-component radius reset and every `!important` on `.motored-*` selectors removed (153 → 26 in the block, the rest on Flatsome's own elements); header search rules are contextual only; `flatsome.js` reads `window.Motored.garage` (no localStorage/private globals) and handles `motored:filters-updated`; `flatsome_motored_garage_url()` uses `Api::garage_url()` instead of `get_option()`.
- Guard: `tests/Unit/Support/ThemeContractTest.php` (6 tests: no `!important`, no theme tokens/selectors, literals only as token fallbacks, no theme calls in JS, no theme names in PHP, documented contract version).
- Checks (Studio PHP 8.4, composer unavailable): PHPUnit 747 tests; the 4 errors + 2 failures + 1 risky are identical on a clean HEAD worktree (pre-existing: GalleryAdmin, GalleryDisplay, Seo ×2, AdminAssets CSS rule, SnapshotInvalidator). PHPCS clean on the changed files; PHPStan 24 pre-existing findings, none in changed files. `compat-parity.test.js` passes. Studio Chromium on home and a product page: tokens resolve to the previous look (square, black accent, #f5f5f5 bands, white drawer, no shadows); header search geometry unchanged at the same viewport; `Motored.garage` present; garage link = `/mi-garage/`.
- Not verified: the red garage-card CTA with a saved motorcycle (pane closed before that check; selector 0,3,0 vs Flatsome `.button.alt`), AJAX filter swap + off-canvas close on mobile, checkout header (shows the empty garage state by design: the garage script does not load there). Gallery thumbnail borders are a little darker (`--mt-line-strong` instead of `#e2e2e2`).
- Paired-package awareness: the theme has its side of the contract (`themes/flatsome/docs/MOTORED-CONTRACT.md`, "Implements contract version: **1**"); `ThemeContractTest::test_paired_theme_implements_the_same_contract_major` fails when the theme major and `Api::CONTRACT` disagree (skipped when the theme is not next to the plugin). Both `AGENTS.md` have a "Paired package" section, both `CLAUDE.md` import their own rules plus both contract files, the overview/add-module/theme-edit skills point across, and the site root `AGENTS.md` (Studio, not versioned) has a project map.
- Pending (planned in the contract): theme-overridable storefront templates (`locate_template( 'motored/…' )`).

### 2026-11-27 — Post-contract storefront & asset audit (button alignment + paired-package fixes)

- Fixed button alignment/reset bugs under Flatsome's base `.button`/`button` rules (`min-height: 2.5em; padding: 0 1.2em; line-height: 2.4em; margin-right: 1em; text-transform: uppercase`):
  - `assets/css/compat.css`: `.motored-compat-badge .motored-compat-badge__btn` now uses `display: inline-flex; align-items: center; justify-content: center; padding-block: 0.5em` so `line-height: 1.2` vertically centers "Elegí tu moto" / incompatible CTA text cleanly on single and multi-line labels.
  - `assets/css/compat.css`: reset `min-height: 0; text-transform: none; letter-spacing: 0` on `.motored-compat-badge__link, button.motored-compat-badge__link` ("Deshacer", "Intentar de nuevo", fallback trigger) and `.motored-compat-banner__chip` (plus `display: inline-flex; align-items: center; margin: 0`); fixed `.motored-notify-dialog__close` right margin (`margin: 0 0 0.5rem auto`); removed duplicate `.motored-garage-card__actions` rule. Bumped `compat10` → `compat11`.
  - `assets/css/catalog-filters.css`: reset `min-height: 0; line-height: 1.25; text-transform: none; letter-spacing: 0` + `inline-flex` on `.motored-search .motored-search__tab`; raised `.motored-search__btn` specificity to `.motored-search .motored-search__btn`. Bumped `filters7` → `filters8`.
- Fixed undefined `MOTORED_CORE_DIR` → `MOTORED_CORE_PATH` in `src/Admin/AdminAssets.php`, `src/Modules/InstallGallery/GalleryAdmin.php`, `src/Modules/InstallGallery/GalleryDisplay.php`, and `src/Modules/Seo/SeoAdmin.php` (assets previously always fell back to `0.1.0` instead of `filemtime()`).
- Cleaned empty `<ul class="motored-garage-guest__perks"></ul>` in `src/Modules/MiGarage/GarageEndpoint.php`.
- Moved `PaymentPlans\PlanDialog` off inline `style=` onto contract BEM classes (`.motored-plans-dialog`, `__title`, `__table`, `__actions`, `__close` in `assets/css/compat.css`, enqueued by `PlanDialog::request()`); added `.motored-plans` / `.motored-plans-dialog` to `docs/THEME-CONTRACT.md`, bumped `Api::CONTRACT` to `1.1` (major `1` unchanged for the theme), and updated `StorefrontTest`.
- Replaced Spanish PHP source strings with English msgids in `GarageEndpoint.php`, `CatalogFilters/StorefrontAssets.php`, and `SeoAdmin.php` (plus English fallbacks in `assets/js/catalog-search.js`); updated `languages/motored-core.pot`, `languages/motored-core-es_AR.po`, and recompiled `languages/motored-core-es_AR.mo` (1,341 entries, binary round-trip verified).
- Paired theme fix (`themes/flatsome/assets/css/flatsome.css`): restored `padding: 16px` on mobile (`<= 849px`) `.motored-search__suggest--mega` (was overridden to `0` by the `(0,3,0)` base rule) and included `#search-lightbox` in the mobile full-width rule.
- Checks: `node --check assets/js/catalog-search.js` clean; `node tests/js/compat-parity.test.js` (17 parity + fmt cases pass); all `ThemeContractTest` invariants (CSS/JS/PHP/contract version + paired theme major) verified cleanly; `.mo` binary round-trip verified (1,341 entries, 0 lost). PHP / Composer / Studio CLI were not available in this shell PATH, so `composer check` (`phpcs`/`phpstan`/`phpunit`) and browser verification remain to run locally.

### 2026-11-27 — Contract 1.2: `motored:filters-loading`

- `assets/js/catalog-filters.js` dispatches `motored:filters-loading` (`detail.url`) when an AJAX catalog swap starts, so the theme can show its top progress bar during catalog updates (it already gets `motored:filters-updated` at the end). Additive → `Api::CONTRACT` 1.1 → 1.2; `docs/THEME-CONTRACT.md`, the module README/skill and the theme's `docs/MOTORED-CONTRACT.md` updated together.

### 2026-11-27 — Contract 1.3: centered garage dialog (`motored-garage-modal`)

- `GarageEndpoint::modal_layout()` reads `current_theme_supports( 'motored-garage-modal' )`. With it the garage dialog is centered (`.motored-garage-drawer--modal`): title "Add your motorcycle" with an empty `__icon` span for the theme, a lead line, the active motorcycle (`.motored-garage-current`: choose another / remove, name, factory sizes, "View tires"), the other saved motorcycles (`.motored-garage-others`, "Use this motorcycle"), the cascade picker (`.motored-garage-pick`, shown when the garage is empty or after "Choose another", with Cancel) and an inline login hint for guests. Without the support the side drawer is unchanged (theme-agnostic). Structural CSS in `compat.css` (tokens only); `compat-garage-page.js` draws the new state and the backdrop click now checks both axes. New es_AR strings (Agregá tu moto, Encontrá las cubiertas que le van a tu moto, Elegir otra moto, Quitar moto, Tus otras motos, Iniciar sesión); `.mo` recompiled with the Python writer (old entries verified identical). Additive → `Api::CONTRACT` 1.3. The store theme declares the support and styles it like motocard.com.


### 2026-11-27 — Decision: garage "Ver cubiertas" opens the filtered catalog
The Mi Garage CTA (centered dialog and `/mi-garage/` cards) went to the bike landing page (`pf.pageUrl`,
`/cubiertas-para/{slug}/`) since the BikePages phase. It now always opens `shop?moto={slug}`, as before and as
`STUDY-COMPAT-GARAGE-FILTERS.md` S1-2 already said (garage = user-initiated in-store filter). Reasons: the shopper
lands on the full catalog with the motorcycle chip, facets, sort and pagination instead of a fixed editorial grid;
it matches the product-page badge, the search suggestion and the loop CTA (all `?moto=`); and the garage is
per-visitor JS state, so crawlers never followed that link (no SEO loss — landing pages keep their links from the
compatibility tab, hubs and sitemap). `pageUrl` stays in the REST profile; the garage no longer reads it.

### 2026-11-27 — Contract 1.4: catalog quick bar, `?stock=1`, `data-motored-swap`, `Api::active_filter_labels()`

- Catalog redesign (store theme, motocard.com): the theme shows "Tenemos N artículos con estas características:" with the active filter values, an "En stock" box and quick filters left of the sort, and a motocard-style sidebar.
- `FilterState` gains `?stock=1` (in_stock); `QueryFilter` keeps `_stock_status != outofstock` (missing = in stock, same source as the facet snapshot so grid and counts agree; demo data had an out-of-stock product without the `outofstock` visibility term); facet counts use `only_in_stock` when it is on; chip "In stock".
- `FilterRenderer::context()` memoizes the facet computation per request for the sidebar, the new `render_quick()` (`[motored_filtros layout="quick"]`, `.motored-quick*`) and `active_labels()`; chips carry a plain `value`. Sidebar head: count badge + "Clear all" link (the foot keeps only the Apply button).
- `catalog-filters.js`: quick links apply at once by AJAX; after every swap, `[data-motored-swap]` regions are replaced from the new page; the Apply URL keeps `stock=1`; "Clear all" applies by AJAX.
- `Api::active_filter_labels()`; `Api::CONTRACT` 1.3 → 1.4; es_AR: Eliminar todos, Filtros rápidos (.mo recompiled, old entries identical). `composer check` could not run here (no PHP CLI on this machine); ThemeContractTest rules checked by hand (no `!important`, no color literals, contract version documented).

### 2026-11-27 — Contract 1.5: `Api::tire_card()` and the payment block on product cards

- `Compatibility\TireCard::for_product()` (exposed as `Api::tire_card()`): full size from the compatibility index (`Storefront::resolve_full_size_label`, batch-primed in loops; R / ZR / B / imperial rules of `SizeParser::format_full_size`) + load/speed code ("69H", no kg/km/h text) + tube code from the tube attribute ("Sin cámara (TL)" -> TL) + the line attribute as `model`; kits keep "front + rear". The store theme shows it as the card title; the product name is untouched (SEO).
- PaymentPlans: `ProductView` adds `c` (best active cash plan with a discount: total + percent); in loops `Storefront::html_for()` prints `.motored-plans--loop` with the list line, the cash price ("$540.000 10% OFF con transferencia o dinero en cuenta") and the "Ver medios de pago" button for the existing dialog. Structural CSS in `compat.css`. es_AR strings added, .mo recompiled (old entries identical).
- Noticed, not changed: the default plan names "9 cuotas sin interés" / "12 cuotas sin interés" carry +10% / +20%; they are editable data in Motored > Planes de pago.

### 2026-11-27 — Contract 1.6: compact compatibility icon on product cards

- `compat-garage.js` `drawLoopBadge()`: when the loop product (`.product`) has a `[data-motored-fit-slot]`, the badge is drawn there as `.motored-compat-badge--icon` (bike icon + `__tip` tooltip, `aria-label` with the same text: "Compatible con tu X · Trasera" / "No coincide con tu X"); otherwise the text badge stays. Tap toggles `.is-open` (touch has no hover); tap elsewhere or Escape closes. Structural CSS in `compat.css` (tokens only). The store theme puts the slot right of the technical card title and shows its own Mi moto icon coloured by the state.

### 2026-11-27 — Payment plans: installment amount stressed on product cards

- `ProductView::build()` also returns `n` (list plan name) and `a` (amount of each installment) when the list plan has 2+ installments; `Storefront` prints the loop line as "6 cuotas sin interés de <strong class="motored-plans-line__amount">$X</strong>" (new string `%1$s of %2$s`, es_AR "%1$s de %2$s"). The theme styles `.motored-plans-line__amount`.

### 2026-11-28 — Contract 1.7: catalog motorcycle bar for themes

- `Api::active_bike_bar()` returns `Storefront::active_bike_bar_html()` (bar with `?moto=`, CTA mount otherwise). It is printed once per request, so a theme that prints it above the catalog moves it there; other themes keep it above the grid (`woocommerce_before_shop_loop`).
- The bar opens with `.motored-compat-bar__actions`: "Elegir otra moto" (`__change`, `[data-motored-garage-open]`, link to Mi Garage) / "Quitar moto" (`__clear`), then the title and the count. "Clear filter" stays only for the "motorcycle not found" notice.

### 2026-11-28 — Contract 1.8: product page payment block like the cards

- `Storefront::html_for()` builds the stressed installment line (`.motored-plans-line__amount`) and the transfer / account money price (`.motored-plans-cash`) for the product page too; `payment-plans.js` updates the cash price of the chosen variation (hidden when it has no discount) and restores both on reset (`-plans2`). `compat.css` hides `.motored-plans-cash[hidden]` (`-compat18`).

### 2026-11-28 — Contract 1.9: load and speed indexes in Api::tire_card()

- `TireCard::indexes()` turns the load index and speed rating attributes into `{code, kg}` / `{code, kmh}` (ETRTO tables in `TireSpecs`); `for_product()` returns them as `load` / `speed` for single tires. The store theme shows them as black code badges with "Índice de carga: 325 kg" / "Índice de velocidad: 210 km/h" on the product page. Test in `TireCardTest`.

