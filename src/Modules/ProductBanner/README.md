# Module: ProductBanner

## Purpose
A promo banner at the top of the product summary, like motocard.com's sale banner: an image or animated GIF,
optional link and optional end date with a live countdown. One general banner for every product plus a per-product
choice (use the general one, use its own, or none).

## Public contracts
- Markup `.motored-promo-banner` (`__link`, `__img`, `__countdown[data-motored-countdown]`, `__ends`, `__unit`,
  `__num[data-u]`, `__lbl`) — `docs/THEME-CONTRACT.md` 1.10. The banner gets `[hidden]` when the countdown ends.
- `BannerResolver::resolve()` / `remaining()` (pure rules).

## Hooks and filters used
| Hook/filter | Context | Why | Verification status |
|-------------|---------|-----|------|
| `woocommerce_single_product_summary` (3) via `ProductPageHooks::register_summary_top()` | frontend | Print the banner above the title | partial (classic template; same hook as the other product modules) |
| `add_meta_boxes_product`, `woocommerce_admin_process_product_object` via `AttributeHooks::register_product_box_hooks()` | admin | Product box and save (CRUD, HPOS safe) | verified |
| `woocommerce_admin_field_motored_banner_image` | admin | Image picker row of the settings section | verified |
| `admin_enqueue_scripts` | admin | Picker script on the product screen and the Banners section only | verified |

## Data model
- Option `motored_product_banner` (autoload=false): `enabled` yes/no, `image_id`, `link`, `until` ("Y-m-dTH:i", site
  time), `countdown` yes/no.
- Product meta `_motored_banner`: `mode` (default|own|none), `image_id`, `link`, `until`, `countdown` (bool). Deleted
  when the box is left at "general" with nothing filled.

## Configuration keys
WooCommerce > Settings > Motored > Banners (general) and the "Banner del producto" box on each product.

## Main flows
1. Product page renders → `BannerDisplay::render()` → `Config::own()` + `Config::general()` → `BannerResolver`.
2. "none" hides; "own" with an image wins; otherwise the general banner when enabled with an image; past end date
   hides it. The countdown shows only with an end date.
3. Assets enqueue only when a banner prints; the countdown script ticks every second and hides the banner at zero.

## Performance and security notes
0 queries on products without banner beyond the product meta already loaded with the product (one option read,
autoload=false, memoized by `Options`). Admin: nonce + `edit_post` capability, `absint` / `esc_url_raw` /
`sanitize_text_field`, attachments must be images. Output escaped.

## How to test
`tests/Unit/Modules/ProductBanner/BannerResolverTest.php`. Manual: set a general banner with an end date 2 minutes
ahead, open a product, watch the countdown reach zero and the banner disappear; set a product to "none" and to its
own GIF.

## Known limitations
Classic product template only (no block for block themes yet). One banner per product.

## Changelog
- 0.1.0 — 2026-11-28 — first version (contract 1.10).
