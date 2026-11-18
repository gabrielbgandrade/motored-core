<?php
/**
 * Enqueues the checkout capture script.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\WooCommerce\CartHooks;

defined( 'ABSPATH' ) || exit;

/**
 * The only asset of the module: one vanilla `defer` script, only on the checkout page
 * (never on order-received). Nothing is read or queued elsewhere.
 */
final class CheckoutScript {

	public const HANDLE = 'motored-recovery-capture';

	/**
	 * Hooked on wp_enqueue_scripts.
	 */
	public function enqueue(): void {
		if ( ! CartHooks::is_capture_page() || ! Config::enabled() ) {
			return;
		}
		wp_register_script(
			self::HANDLE,
			MOTORED_CORE_URL . 'assets/js/checkout-capture.js',
			array(),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.motoredCapture=' . wp_json_encode(
				array(
					'url'   => CartHooks::capture_url(),
					'nonce' => wp_create_nonce( CaptureEndpoint::NONCE_ACTION ),
				)
			) . ';',
			'before'
		);
		wp_enqueue_script( self::HANDLE );
	}
}
