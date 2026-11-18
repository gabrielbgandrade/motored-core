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

- 2026-11-18 — Admin spacing: one stylesheet `assets/css/motored-admin.css` (CSS only, no JS/dependencies), enqueued by `Admin\AdminAssets` only on WooCommerce > Settings > Motored; every rule is scoped with `.motored-admin` (wrapper printed by `SettingsTab::output`), no own colors or fonts (borders derive from `currentColor`). Wide tables (plans, recovery steps) scroll horizontally inside `.motored-table-wrap`. Inline styles and the hard-coded notice colors of the WhatsApp tests were replaced by classes / WP `notice-*`. — Product request.

- 2026-11-18 — Settings menu: the "General" section was renamed "Avanzado" (id `advanced`, option `motored_general` unchanged) and is always the LAST section (priority 2000, after "Estado"). The tab's default section ('' in the URL) is now the first registered section instead of General. — Product request.

- 2026-11-18 — "Avanzado" needs `manage_options`: `SectionRegistry::add(..., $capability)` + `allowed()/visible()`; shop managers (`manage_woocommerce`) do not see it in the menu, get an error notice on the direct URL (nothing is read or rendered), and `SettingsTab::save()` (nonce `woocommerce-settings` + capability) ignores the POST with a notice; `advanced_settings()` returns no fields without the capability; `uninstall.php` also requires `delete_plugins` when a user is logged in. Reading the option `motored_general` by code (log level) is unchanged. — Product request.

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

