<?php
/**
 * CatalogFilters module bootstrap (option motored_catalog_filters, autoload=false).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Contracts\Module;
use Motored\Core\Integration\WooCommerce\LayeredNavHooks;
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
		add_filter( 'motored_storefront_accent', array( Config::class, 'accent_color' ), 10, 0 );

		add_action(
			'rest_api_init',
			static function (): void {
				( new RestController() )->register_routes();
			}
		);

		if ( $context->is_frontend() ) {
			add_action( 'wp_enqueue_scripts', array( StorefrontAssets::class, 'preload' ), 15 );
		}
		if ( $context->is_frontend() || $context->is_ajax() ) {
			( new QueryFilter() )->register();
			( new SearchShortcode() )->register();
			( new FilterRenderer() )->register();
		}

		if ( $context->is_frontend() || $context->is_admin() ) {
			add_action( 'widgets_init', array( $this, 'register_widget' ) );
		}
		if ( $context->is_admin() ) {
			// Admin: remove the native widgets from the picker. Frontend: hide them only when one actually renders,
			// so pages without that widget never read the option (widgets_init runs on every request).
			add_action( 'widgets_init', array( $this, 'maybe_replace_native_layered_nav' ), 20 );
		} elseif ( $context->is_frontend() ) {
			add_filter( 'widget_display_callback', array( $this, 'maybe_hide_native_layered_nav' ), 10, 2 );
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

	/**
	 * Unregisters WooCommerce native layered navigation widgets when configured as the single facet system.
	 */
	public function maybe_replace_native_layered_nav(): void {
		if ( Config::enabled() && Config::replace_native_layered_nav() ) {
			LayeredNavHooks::unregister_native_widgets();
		}
	}

	/**
	 * Hook widget_display_callback: suppresses a native layered-nav widget instance when CatalogFilters replaces it.
	 *
	 * @param mixed $instance Widget instance settings (false = do not display).
	 * @param mixed $widget   WP_Widget object.
	 * @return mixed
	 */
	public function maybe_hide_native_layered_nav( $instance, $widget = null ) {
		if ( false === $instance || ! LayeredNavHooks::is_native_layered_nav( $widget ) ) {
			return $instance;
		}
		return Config::enabled() && Config::replace_native_layered_nav() ? false : $instance;
	}
}
