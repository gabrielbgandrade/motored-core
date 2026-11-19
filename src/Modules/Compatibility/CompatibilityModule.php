<?php
/**
 * Compatibility + Mi Garage module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Contracts\Module;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers hooks lazily by request context with zero option reads or asset loads during boot.
 */
final class CompatibilityModule implements Module {

	/**
	 * Module identifier.
	 */
	public function id(): string {
		return 'compatibility';
	}

	/**
	 * Determines whether hooks should be attached in the current context (zero I/O, zero translations).
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		return $context->is_frontend()
			|| $context->is_admin()
			|| $context->is_rest()
			|| $context->is_ajax()
			|| $context->is_cron()
			|| $context->is_cli();
	}

	/**
	 * Registers hooks by context without performing any database queries or asset enqueues.
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$context = new Context();

		if ( $context->is_rest() ) {
			( new RestController() )->register();
		}

		if ( $context->is_frontend() || $context->is_ajax() ) {
			( new Storefront() )->register();
		}

		if ( $context->is_frontend() || $context->is_admin() ) {
			( new GarageEndpoint() )->register();
		}

		if ( $context->is_admin() || $context->is_cron() || $context->is_ajax() || $context->is_rest() || $context->is_cli() ) {
			( new ProductIndexSync() )->register();
			( new ImportRunner() )->register();
		}

		if ( $context->is_admin() || $context->is_ajax() ) {
			( new Privacy() )->register();
		}

		if ( $context->is_admin() ) {
			( new CompatibilityAdmin() )->register();
		}

		if ( $context->is_cli() ) {
			CliCommands::register();
		}
	}
}
