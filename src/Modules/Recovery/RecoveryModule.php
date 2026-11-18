<?php
/**
 * Recovery module (cart capture, scanner, messaging sequence, links, admin).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Contracts\Module;
use Motored\Core\Integration\WooCommerce\CartHooks;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Captures carts (checkout script + logged-in cart changes), scans for due recovery actions,
 * handles recovery/opt-out links, registers abandoned cart email, and coordinates message sending.
 */
final class RecoveryModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'recovery';
	}

	/**
	 * Needed in every context (checkout script, cart hooks, recovery links, email classes,
	 * Action Scheduler scanner, send handler, admin).
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		return $context->is_admin() || $context->is_ajax() || $context->is_rest() || $context->is_cron() || $context->is_cli() || $context->is_frontend();
	}

	/**
	 * Registers only the hooks each context needs.
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$context = new Context();

		// Register WooCommerce email class (loaded lazily only within callback).
		add_filter( 'woocommerce_email_classes', array( self::class, 'register_email_class' ) );

		if ( $context->is_frontend() ) {
			add_action( 'wp_enqueue_scripts', array( new CheckoutScript(), 'enqueue' ) );
			add_action( 'template_redirect', array( RecoveryLinkHandler::class, 'handle' ) );
		}
		if ( $context->is_ajax() ) {
			CartHooks::register_capture_endpoint( array( new CaptureEndpoint(), 'handle' ) );
		}
		if ( $context->is_frontend() || $context->is_ajax() || $context->is_rest() ) {
			( new CartChangeCapture() )->register();
			( new OrderConversion() )->register();
		}
		// Order status changes happen in any context (checkout, gateway callbacks, admin, cron).
		( new OrderSync() )->register();
		if ( $context->is_admin() || $context->is_ajax() ) {
			( new Privacy() )->register();
		}
		if ( $context->is_admin() || $context->is_ajax() || $context->is_cron() || $context->is_cli() ) {
			add_action( Retention::HOOK, array( Retention::class, 'run' ) );
			add_action( Scanner::HOOK, array( Scanner::class, 'run' ) );

			Sender::register_handler( RecoveryResultHandler::KIND, array( new RecoverySenderHandler(), 'handle' ) );
			add_action( Sender::RESULT_HOOK, array( RecoveryResultHandler::class, 'on_result' ), 10, 4 );
		}
		if ( $context->is_admin() ) {
			add_action( 'admin_init', array( Retention::class, 'ensure_scheduled' ) );
			add_action( 'admin_init', array( Scanner::class, 'ensure_scheduled' ) );
			( new RecoveryAdmin() )->register();
		}
	}

	/**
	 * Registers the Motored e-mail classes (abandoned cart, pending payment) with WooCommerce.
	 *
	 * @param array<string,mixed> $emails Existing email classes.
	 * @return array<string,mixed>
	 */
	public static function register_email_class( array $emails ): array {
		if ( ! class_exists( Emails\AbandonedCartEmail::class ) ) {
			require_once __DIR__ . '/Emails/AbandonedCartEmail.php';
		}
		if ( ! class_exists( Emails\PendingPaymentEmail::class ) ) {
			require_once __DIR__ . '/Emails/PendingPaymentEmail.php';
		}
		$emails['WC_Email_Motored_Abandoned_Cart']  = new Emails\AbandonedCartEmail();
		$emails['WC_Email_Motored_Pending_Payment'] = new Emails\PendingPaymentEmail();
		return $emails;
	}
}
