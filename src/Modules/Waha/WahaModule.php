<?php
/**
 * WAHA (WhatsApp transport) module.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Waha;

use Motored\Core\Contracts\Module;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Transport only: client, phone resolution, send queue. No public-page work at all:
 * it loads in admin and in the contexts where Action Scheduler runs jobs.
 */
final class WahaModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'waha';
	}

	/**
	 * Admin (settings, tests), cron / ajax / CLI (Action Scheduler runners). Never public pages or REST.
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		return $context->is_admin() || $context->is_cron() || $context->is_ajax() || $context->is_cli();
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		Options::register_defaults( 'waha', Config::DEFAULTS );

		add_action( Sender::HOOK, array( Sender::class, 'run' ), 10, 4 );

		$context = new Context();
		if ( $context->is_admin() ) {
			( new WahaAdmin() )->register();
		}
	}
}
