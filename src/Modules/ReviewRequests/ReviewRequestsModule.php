<?php
/**
 * ReviewRequests module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewRequests;

use Motored\Core\Contracts\Module;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\OrderNotifications\OrderNotificationsModule;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One WhatsApp message some days after an order reaches the trigger status, asking for reviews.
 */
final class ReviewRequestsModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'review_requests';
	}

	/**
	 * Status changes (any context but public GET), the Sender worker, the opt-out link (frontend) and admin.
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		return $context->is_admin() || $context->is_ajax() || $context->is_rest() || $context->is_cron() || $context->is_cli() || $context->is_frontend();
	}

	/**
	 * Registers hooks only (no I/O).
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$context = new Context();

		if ( ! $context->is_frontend() || OrderNotificationsModule::is_wc_api_or_post() ) {
			OrderHooks::register_status_changed( array( new Scheduler(), 'on_status_changed' ) );
		}
		if ( $context->is_admin() || $context->is_ajax() || $context->is_cron() || $context->is_cli() ) {
			Sender::register_handler( Scheduler::KIND, array( new RequestHandler(), 'handle' ) );
			add_action( Sender::RESULT_HOOK, array( RequestResult::class, 'on_result' ), 10, 4 );
		}
		if ( $context->is_frontend() ) {
			add_action( 'template_redirect', array( ReviewOptout::class, 'handle' ) );
		}
		if ( $context->is_admin() ) {
			( new ReviewRequestsAdmin() )->register();
		}
	}
}
