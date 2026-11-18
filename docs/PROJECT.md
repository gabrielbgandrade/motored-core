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
