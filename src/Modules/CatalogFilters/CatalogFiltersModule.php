<?php
/**
 * CatalogFilters module bootstrap (option motored_catalog_filters, autoload=false).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Contracts\Module;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers hooks lazily by request context with zero option reads or asset enqueues during register().
 */
final class CatalogFiltersModule implements Module {

	/**
	 * Module identifier.
	 */
	public function id(): string {
		return 'catalog_filters';
	}

	/**
	 * Loads across contexts that handle storefront views, REST, admin settings or product updates.
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
	 * Attaches hooks by context (0 option reads, 0 DB queries, 0 asset enqueues).
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$context = new Context();

		if ( $context->is_rest() ) {
			( new RestController() )->register();
		}

		if ( $context->is_frontend() || $context->is_ajax() ) {
			( new QueryFilter() )->register();
			( new SearchShortcode() )->register();
			( new FilterRenderer() )->register();
		}

		if ( $context->is_frontend() || $context->is_admin() ) {
			add_action( 'widgets_init', array( $this, 'register_widget' ) );
		}

		if ( $context->is_admin() || $context->is_cron() || $context->is_ajax() || $context->is_rest() || $context->is_cli() ) {
			( new SnapshotInvalidator() )->register();
		}

		if ( $context->is_admin() ) {
			( new CatalogFiltersAdmin() )->register();
		}
	}

	/**
	 * Registers the classic sidebar widget on widgets_init.
	 */
	public function register_widget(): void {
		if ( class_exists( 'WP_Widget' ) && function_exists( 'register_widget' ) ) {
			register_widget( FilterWidget::class );
		}
	}
}
