<?php
/**
 * The single shared <dialog> and its script (only on pages that render a plans link).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

use Motored\Core\Support\StorefrontAssets;

defined( 'ABSPATH' ) || exit;

/**
 * First link on a page requests the dialog; later links reuse it.
 */
final class PlanDialog {

	public const HANDLE = 'motored-payment-plans';
	public const DOM_ID = 'motored-plans-dialog';

	/**
	 * Whether this request already asked for the dialog.
	 *
	 * @var bool
	 */
	private static bool $requested = false;

	/**
	 * Enqueues the shared storefront CSS + deferred script and schedules the footer markup (once per request).
	 */
	public static function request(): void {
		if ( self::$requested ) {
			return;
		}
		self::$requested = true;
		StorefrontAssets::enqueue_compat_css();
		wp_enqueue_script(
			self::HANDLE,
			MOTORED_CORE_URL . 'assets/js/payment-plans.js',
			array(),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		add_action( 'wp_footer', array( self::class, 'print_dialog' ), 5 );
	}

	/**
	 * Prints the (empty) dialog; the script fills it from the clicked link's data attribute.
	 */
	public static function print_dialog(): void {
		printf(
			'<dialog id="%1$s" class="motored-plans-dialog" aria-labelledby="%1$s-title">'
			. '<h2 id="%1$s-title" class="motored-plans-dialog__title">%2$s</h2>'
			. '<table class="motored-plans-dialog__table"><tbody></tbody></table>'
			. '<form method="dialog" class="motored-plans-dialog__actions"><button type="submit" class="button wp-element-button motored-plans-dialog__close">%3$s</button></form>'
			. '</dialog>',
			esc_attr( self::DOM_ID ),
			esc_html__( 'Payment methods', 'motored-core' ),
			esc_html__( 'Close', 'motored-core' )
		);
	}

	/**
	 * Resets the per-request flag (tests).
	 */
	public static function reset(): void {
		self::$requested = false;
	}
}
