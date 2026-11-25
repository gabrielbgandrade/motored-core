<?php
/**
 * Mi Garage bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\MiGarage;

use Motored\Core\Contracts\Module;
use Motored\Core\Support\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Owns account garage routes, storage and privacy.
 */
final class MiGarageModule implements Module {

	/**
	 * Module identifier.
	 */
	public function id(): string {
		return 'mi_garage';
	}

	/**
	 * Loads in all contexts so rewrite/query-var hooks stay registered during CLI, cron,
	 * REST and AJAX rewrite flushes (register() stays 0-query and 0-asset).
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		unset( $context );
		return true;
	}

	/**
	 * Registers the garage hooks without queries or enqueues.
	 */
	public function register(): void {
		$context  = new Context();
		$endpoint = new GarageEndpoint();
		if ( $context->is_frontend() || $context->is_admin() ) {
			$endpoint->register();
		} else {
			$endpoint->register_rewrite_hooks();
		}
		if ( $context->is_cron() || $context->is_cli() ) {
			return;
		}
		add_action( 'motored_compat_selector_assets', array( GarageAssets::class, 'enqueue' ) );
		add_action(
			'rest_api_init',
			static function (): void {
				( new GarageRestController() )->register_routes();
			}
		);
		if ( $context->is_admin() || $context->is_ajax() ) {
			( new Privacy() )->register();
		}
	}
}
