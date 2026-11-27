# Module: PaymentPlans

## Purpose
The ERP price is the list price = 6 interest-free installments. Configurable plans (cash, 3, 6, 9, 12 installments) adjust that price by a
percentage (negative = discount, positive = surcharge). The storefront shows the list-plan line and a dialog with all plans; the classic
checkout lets the customer pick a plan when paying with Mercado Pago Checkout Pro and applies it as a native cart fee.
Part B limits the Mercado Pago Checkout Pro payment to the chosen plan (installments ceiling, cash = no cards), fails closed and reconciles paid orders.

## Public contracts
- `PlanCalculator` (pure, integer cents): `to_cents`, `total`, `split`, `quote( Plan, base ): Quote`.
- Order meta written at checkout: `_motored_plan_type` (cash|card), `_motored_plan_id`, `_motored_plan_max_installments`, `_motored_plan_pct`, `_motored_plan_base`, `_motored_plan_fee`.
- Shortcode `[motored_payment_plans id="123"]` (current product when `id` is omitted).
- Everything else is private.

## Hooks and filters used
| Hook/filter | Context | Why | Verification status |
|-------------|---------|-----|------|
| `woocommerce_single_product_summary` (11), `woocommerce_after_shop_loop_item_title` (11) | frontend/ajax | line + link | unverified (#73) |
| `woocommerce_available_variation` | frontend/ajax | variation plans in `motored_plans` | unverified (#74) |
| `woocommerce_checkout_update_order_review`, `woocommerce_update_order_review_fragments`, `woocommerce_review_order_before_payment` | frontend/ajax | selector, session | unverified (#75) |
| `woocommerce_checkout_posted_data` | ajax (place order) | read the plan on submit | unverified (#76) |
| `woocommerce_cart_calculate_fees`, `woocommerce_checkout_create_order` | frontend/ajax | fee, order meta | unverified (#76) |
| `woocommerce_admin_field_motored_payment_plans_table`, `woocommerce_update_options_motored_payment_plans` | admin | plans table | unverified (#79) |
| `http_request_args` (9999), `pre_http_request` (1) | frontend/ajax | rewrite / block the MP preference POST | partial (#80) |
| `woocommerce_order_status_changed`, `motored_plans_reconcile` (Action Scheduler) | all loaded contexts incl. cron | reconciliation | unverified (#83, #84) |
Names live in `Integration\WooCommerce\PlanHooks`; session/cart reading in `CheckoutState`; MP settings in `Integration\MercadoPago\CheckoutPro` (#77).

## Data model
Option `motored_payment_plans` (autoload=false): `enabled` (`yes`/`no`, default `no`) and `plans` (list of `{id, name, pct, max, type, active, credit}`).
Defaults: `cash` -10% x1, `c3` -5% x3, `c6` 0% x6 (list/default plan), `c9` +10% x9, `c12` +20% x12. Names are data (es_AR, editable).
WC session key `motored_plan` (plan id). No tables, no cache keys.

## Configuration keys
`enabled`, `plans` (edited in Motored > Planes de pago). Percentage clamped to -90..+100; installments 1..24 (cash always 1); ids `[a-z0-9_]{1,20}`.

## Main flows
- Calculation: `total = base + base * pct` (exact integer math, half up); installment = `total / N`, leftover cents on the last one (sum is exact).
- Product cards (loop): `.motored-plans--loop` = list line + the best active cash plan with a discount as "$total X% OFF with transfer or account money" (`.motored-plans-cash*`, view key `c`) + the "See payment methods" button opening the dialog.
- Storefront: base = `wc_get_price_to_display()` (variable: minimum price). The server formats every text; the link carries it in `data-motored-plans`
  (JSON); the JS only fills the shared `<dialog>` and swaps the data on `found_variation` / `reset_data`. Discounts: "$total (X% OFF)"; surcharges
  never show the percentage. No price / out of stock: nothing.
- Checkout (classic): `update_order_review` stores the validated plan in the session only when the posted gateway is `woo-mercado-pago-basic`
  (default `c6`); other gateway clears it. `woocommerce_cart_calculate_fees` adds the untaxed fee (base = product lines after coupons + taxes, no shipping,
  no fees); the plan is re-validated on every calculation. `woocommerce_checkout_create_order` saves the plan meta (or clears stale meta).
- Paying an unpaid order reuses the saved plan: the order already holds the fee, no hook runs on `order-pay`.
- Admin: notice when the MP gateway has a discount/commission (when its checkbox is on) or fewer max installments than the longest active plan.

## Mercado Pago limit (part B)
- `Integration\MercadoPago\PreferenceFilter` acts ONLY on POST `https://api.mercadopago.com/v1/asgard/preferences`: decodes the JSON, resolves the order from `external_reference`
  (`<store id><order id>`), reads `_motored_plan_*`. No plan on the order: body untouched. With a plan, `PreferenceRewriter` sets `payment_methods.installments = min(plugin value, plan max)`,
  clamps `default_installments`, and for cash plans MERGES `credit_card` and `debit_card` into `excluded_payment_types` (never `account_money`); every plan with the table setting "Excluir Cuotas sin Tarjeta" ON (default) adds `consumer_credits` to `excluded_payment_methods` (merge, no duplicates; saved on the order as `_motored_plan_excl_credit`; OFF = not added and not a divergence; VERIFY #86); every other key is kept (objects decoded as objects, so `{}` stays `{}`).
- Before sending it checks that the sum of `unit_price * quantity` equals the order total (1 cent).
- FAIL CLOSED: plan present but body unreadable/unchangeable, bad plan meta, total mismatch -> the args get a flag and `pre_http_request` returns a `WP_Error` (es_AR message); log without PII + private order note.
  Order not identifiable: blocked while plans are enabled (a saved plan is enforced even if the module is disabled later).
- Version guard: `CheckoutPro::VALIDATED_VERSION` (8.10.2); another active version only warns (Status row + section notice).
- `Reconciler`: on processing/completed and 120 s later, reads plain order meta (`Mercado Pago - <id> - installments`, `Mercado Pago - Payment <id>`): installments <= plan max, no card on cash plans,
  no Mercado Pago credit payment (`consumer_credits`) on plans with max < 12, amount and paid ~ order total. Divergence: meta `_motored_plan_mismatch` (set first), private note, `on-hold`, e-mail to the admin, state meta `_motored_plan_recon`; never cancels or refunds. The flag pauses customer notices (OrderNotifications) and the Recovery sequence of that order. No data at the second look: note only.

## Performance and security notes
Loads on frontend, ajax and admin only. `register()` reads no options. Public pages read the option only when a product price/cart is processed
(0 queries with the object cache; otherwise one option read); the script (< 3 KB, `defer`) and the dialog exist only on pages with a link; no HTTP.
The cart fee path checks the session first (no option read for most requests). Admin: `manage_woocommerce` + nonce, rows sanitized by `Plan::from_array`.
Posted plan ids are only matched against configured ids. No PII anywhere.

## How to test
`tests/Unit/Modules/PaymentPlans`: calculator (rounding, sums, taxes base), plan sanitizing/selection, fee per plan, order meta, selector, storefront,
gateway warnings, contexts. Manual: `docs/STAGING-CHECKLIST.md` (Payment plans), VERIFY #73-#79.

## Known limitations
- Cart/Checkout Blocks: no selector and no fee (the module does not load in REST). Planned for a later stage.
- ReviewRequests is not paused by the mismatch flag.
- Stores with a currency ratio or a discount/commission inside the MP gateway are blocked by the total check (#85).
- Variable products add about 0.4 KB per variation to the page data.
- The block-theme product template needs the shortcode; the variation script needs jQuery (WooCommerce's own events), otherwise the line keeps the minimum price.

## Changelog
- 0.2.0 — 2026-11-18 — part B: Checkout Pro preference limit (fail closed), version guard, reconciliation, Status row.
- 0.1.0 — 2026-11-18 — part A: plans, calculator, storefront, classic checkout selector and fee, admin warnings.
