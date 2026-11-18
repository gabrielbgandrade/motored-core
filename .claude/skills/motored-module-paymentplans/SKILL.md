---
name: motored-module-paymentplans
description: Rules for the PaymentPlans module (ERP list price, plans cash/3/6/9/12, storefront line + dialog, classic checkout selector and fee). Use when touching src/Modules/PaymentPlans or Integration/WooCommerce/{PlanHooks,CheckoutState}.
---

# Module PaymentPlans
Read `src/Modules/PaymentPlans/README.md` first. Parts A and B done; Blocks checkout is NOT.

- The ERP price = list price = `c6` (6 cuotas sin interés). Plans adjust it by `pct` (negative = discount).
- Money is integer cents in `PlanCalculator` (pure); never floats, never recalculate in JS. One source of truth: server text (`Presenter`).
- Discounts show "$total (X% OFF)"; surcharges NEVER show the percentage (storefront, dialog, selector).
- Storefront: core hooks only + `[motored_payment_plans]`; ONE shared `<dialog>` in `wp_footer`, script only if a link exists; data in `data-motored-plans`.
- Checkout base = product lines after coupons + taxes, WITHOUT shipping or fees. Fee is untaxed (`fees_api()->add_fee`).
- Plan lives in the WC session only for `woo-mercado-pago-basic`; always re-validated (exists + active, else default `c6`).
- Order meta `_motored_plan_*` via CRUD in `woocommerce_checkout_create_order`; pay-for-order reuses it (no hook on `order-pay`).
- Hook names/session/cart reads stay in `Integration/`; MP options are read-only (`Integration/MercadoPago/CheckoutPro`).
- `register()` reads no options; check the session before `Config` in cart paths (runs on every cart calculation).
- MP limit: `PreferenceFilter` (http_request_args 9999 + pre_http_request) rewrites only POST /v1/asgard/preferences; FAIL CLOSED, never send a plan order without the cap.
- Cash plans merge `credit_card`+`debit_card` exclusions, never `account_money`; keep other keys; items sum must equal the order total.
- `CheckoutPro::VALIDATED_VERSION` = 8.10.2: re-read the MP source and VERIFY #80-#85 before bumping it.
- `Reconciler` reads order meta only; on divergence on-hold + note + admin mail; NEVER cancel/refund.
- Admin table: `manage_woocommerce` + nonce; sanitize through `Plan::from_array`.
- Strings: English source + es_AR voseo in `.pot/.po/.mo`; plan names are editable data. VERIFY #73-#85.
- Tests: `tests/Unit/Modules/PaymentPlans`. After changes: `composer check`, `motored-review`, update PROJECT.md.
