=== Motored Core ===
Contributors: motored
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Base platform for Motored store modules: lazy, cache-safe, HPOS-compatible.

== Description ==

Motored Core is the foundation for Motored Neumáticos' store features (WhatsApp, notifications,
cart and order recovery). Stage 1 ships only the infrastructure: module loader, settings tab
(WooCommerce > Settings > Motored), logging, cache and migration helpers.

It adds no queries, requests or assets on public pages.

== Installation ==

1. Make sure WooCommerce is active.
2. Upload the plugin and activate it.
3. Open WooCommerce > Settings > Motored.

== Changelog ==

= 0.1.0 =
* Initial base: bootstrap, autoloader, module contract, settings tab, i18n (es_AR).
