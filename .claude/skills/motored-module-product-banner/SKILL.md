---
name: motored-module-product-banner
description: How the ProductBanner module of motored-core works (general + per-product promo banner with countdown at the top of the product page). Use before changing it.
---

# ProductBanner module

Read `src/Modules/ProductBanner/README.md` first.

- Rules live in `BannerResolver` (pure, unit tested): none > own (with image) > general (enabled, with image);
  past end date = hidden; countdown only with an end date.
- Settings: `BannerAdmin::settings()` (WooCommerce > Settings > Motored > Banners). Product box: `BannerAdmin::render()`
  / `save()` through the WC CRUD. Picker: `assets/admin/motored-product-banner.js` + shared `.motored-box__picker`.
- Storefront: `BannerDisplay` prints `.motored-promo-banner` (contract 1.10) with the full-size image (GIFs keep
  their animation); `product-banner.css` is structural, the store theme styles it.
- Changing markup or classes = update `docs/THEME-CONTRACT.md`, bump `Api::CONTRACT`, update the theme.
- After changes: `composer check`, then `motored-review`.
