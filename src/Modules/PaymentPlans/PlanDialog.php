<?php
/**
 * The single shared <dialog> and its script (only on pages that render a plans link).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

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
	 * Enqueues the deferred script and schedules the footer markup (once per request).
	 */
	public static function request(): void {
		if ( self::$requested ) {
			return;
		}
		self::$requested = true;
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
			'<dialog id="%1$s" aria-labelledby="%1$s-title" style="max-width:32rem;width:92%%"><h2 id="%1$s-title" style="margin-top:0">%2$s</h2><table style="width:100%%"><tbody></tbody></table><form method="dialog"><button type="submit">%3$s</button></form></dialog>',
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
