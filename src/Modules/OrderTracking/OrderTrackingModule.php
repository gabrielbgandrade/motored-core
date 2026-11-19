<?php
/**
 * OrderTracking module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderTracking;

use Motored\Core\Contracts\Module;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Tracking code per order: admin meta box, customer display, dedicated WhatsApp + e-mail. No tables, no carrier APIs.
 */
final class OrderTrackingModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'order_tracking';
	}

	/**
	 * Every context: the order page is a frontend GET and e-mails are rendered anywhere. Registration is hooks only
	 * (no query); the callbacks read the order meta first and the option only when the order has tracking.
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		unset( $context );
		return true;
	}

	/**
	 * Registers hooks by context.
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );

		add_filter( 'woocommerce_email_classes', array( self::class, 'register_email_class' ) );
		add_action( 'woocommerce_email_order_meta', array( CustomerDisplay::class, 'render_email' ), 20, 4 );

		$context = new Context();
		if ( $context->is_frontend() ) {
			add_action( 'woocommerce_order_details_after_order_table', array( CustomerDisplay::class, 'render_order_page' ), 20 );
		}
		if ( $context->is_admin() || $context->is_cron() || $context->is_ajax() || $context->is_cli() ) {
			$notifier = new TrackingNotifier();
			Sender::register_handler( TrackingNotifier::KIND, array( $notifier, 'build_message' ) );
			add_action( Sender::RESULT_HOOK, array( $notifier, 'on_result' ), 10, 4 );
			add_action( TrackingNotifier::EMAIL_HOOK, array( $notifier, 'send_scheduled_email' ), 10, 2 );
		}
		if ( $context->is_admin() ) {
			( new OrderTrackingAdmin() )->register();
		}
	}

	/**
	 * Adds the dedicated e-mail class (file loaded late, only when WooCommerce builds its mailer).
	 *
	 * @param mixed $emails E-mail classes.
	 * @return array<string,mixed>
	 */
	public static function register_email_class( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();
		if ( ! class_exists( Emails\TrackingEmail::class ) ) {
			require_once __DIR__ . '/Emails/TrackingEmail.php';
		}
		$emails[ Emails\TrackingEmail::CLASS_KEY ] = new Emails\TrackingEmail();
		return $emails;
	}
}
