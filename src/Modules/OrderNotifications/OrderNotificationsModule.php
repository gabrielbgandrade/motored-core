<?php
/**
 * OrderNotifications module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderNotifications;

use Motored\Core\Contracts\Module;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Sends plain-text WhatsApp order notifications equivalent to standard WooCommerce customer emails.
 * Never loads on public GET page views.
 */
final class OrderNotificationsModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'order_notifications';
	}

	/**
	 * Loads in admin, ajax (wc-ajax checkout / AS), REST (Store API / webhooks), cron, cli,
	 * and wc-api / POST requests (e.g. Mercado Pago IPN or non-AJAX checkout). Never public GET pages.
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		if ( $context->is_admin() || $context->is_ajax() || $context->is_rest() || $context->is_cron() || $context->is_cli() ) {
			return true;
		}
		return self::is_wc_api_or_post();
	}

	/**
	 * Registers lightweight order listeners, send-time handler, result listener, and admin settings.
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );

		$notifier = new Notifier();
		OrderHooks::register_status_changed( array( $notifier, 'on_status_changed' ) );
		OrderHooks::register_customer_note( array( $notifier, 'on_customer_note' ) );

		$context = new Context();
		if ( $context->is_admin() || $context->is_cron() || $context->is_ajax() || $context->is_cli() ) {
			Sender::register_handler( EventMap::KIND, array( $notifier, 'build_message' ) );
			add_action( Sender::RESULT_HOOK, array( $notifier, 'on_result' ), 10, 4 );
		}

		if ( $context->is_admin() ) {
			( new OrderNotificationsAdmin() )->register();
		}
	}

	/**
	 * Detects WooCommerce wc-api endpoints (e.g. payment gateway IPN) and POST checkouts.
	 */
	public static function is_wc_api_or_post(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only.
		if ( isset( $_GET['wc-api'] ) ) {
			return true;
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			if ( false !== strpos( $uri, '/wc-api/' ) ) {
				return true;
			}
		}
		return isset( $_SERVER['REQUEST_METHOD'] )
			&& 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}
}
