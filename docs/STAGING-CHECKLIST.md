# Staging checklist (manual, real WordPress)

Environment: staging clone with WooCommerce, Flatsome fork, Mercado Pago (Checkout Pro), Redis, WAHA.
Tick each item; record failures in `REVIEW-LOG.md`.

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

## VERIFY.md
- [ ] Work through every `unverified`/`partial` row; update statuses.
