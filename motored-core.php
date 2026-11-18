<?php
/**
 * Plugin Name:       Motored Core
 * Plugin URI:        https://motored.com.ar
 * Description:       Base platform for Motored store modules (WhatsApp, notifications, cart/order recovery). Lazy, cache-safe and HPOS-compatible.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Motored
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       motored-core
 * Domain Path:       /languages
 *
 * @package Motored\Core
 */

defined( 'ABSPATH' ) || exit;

define( 'MOTORED_CORE_VERSION', '0.1.0' );
define( 'MOTORED_CORE_FILE', __FILE__ );
define( 'MOTORED_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'MOTORED_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'MOTORED_CORE_BASENAME', plugin_basename( __FILE__ ) );

require_once MOTORED_CORE_PATH . 'src/Autoloader.php';
\Motored\Core\Autoloader::register( MOTORED_CORE_PATH . 'src/' );

/*
 * Declare compatibility with HPOS and Cart/Checkout Blocks. This plugin does
 * not touch order storage or checkout rendering directly, so both are safe.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MOTORED_CORE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MOTORED_CORE_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( \Motored\Core\Plugin::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\Motored\Core\Plugin::instance()->boot();
	},
	20
);
