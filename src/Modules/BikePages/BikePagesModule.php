<?php
/**
 * BikePages module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Contracts\Module;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers CPT/taxonomy, automatic page sync, renderer, internal link graph and demand capture.
 */
final class BikePagesModule implements Module {

	/**
	 * Module identifier.
	 */
	public function id(): string {
		return 'bike_pages';
	}

	/**
	 * Loads across contexts needed for CPT registration, REST, admin, cron and CLI.
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
	 * Registers hooks by context without running queries or enqueues during boot.
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$context = new Context();

		( new PostType() )->register( $context->is_admin() );
		( new BikePageSync() )->register_availability_listeners();
		( new SizePages() )->register( $context->is_admin() );

		add_action(
			'rest_api_init',
			static function (): void {
				( new DemandRestController() )->register_routes();
			}
		);

		if ( $context->is_frontend() ) {
			( new HubLinks() )->register();
			( new BikeRenderer() )->register();
			( new GoneHandler() )->register();
			( new DemandCancelHandler() )->register();
		} elseif ( $context->is_rest() ) {
			// Compat REST payloads (bike profile, garage, product tab rows) must carry the bike page URLs.
			( new HubLinks() )->register_url_filter();
		}

		if ( $context->is_admin() || $context->is_cron() || $context->is_ajax() || $context->is_rest() || $context->is_cli() ) {
			( new BikePageSync() )->register();
			add_action(
				DemandStore::PURGE_HOOK,
				static function (): void {
					DemandStore::purge_expired();
				}
			);
		}

		if ( $context->is_admin() || $context->is_ajax() ) {
			( new DemandPrivacy() )->register();
		}

		if ( $context->is_admin() ) {
			( new BikePagesAdmin() )->register();
			if ( $context->is_admin_screen() ) {
				add_action( 'admin_init', array( DemandStore::class, 'schedule_purge' ) );
				add_action( 'admin_init', array( BikePageSync::class, 'maybe_schedule_resync' ) );
				add_action(
					'admin_init',
					static function (): void {
						Options::sync_autoload( Config::OPTION_KEY );
					}
				);
			}
		}
	}
}
