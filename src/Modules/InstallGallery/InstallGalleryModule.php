<?php
/**
 * InstallGallery module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\InstallGallery;

use Motored\Core\Contracts\Module;
use Motored\Core\Integration\WooCommerce\ProductPageHooks;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Second product gallery: installation photos chosen by the owner + photos of approved reviews (ReviewPhotos).
 * No tables: Media Library attachments and two product meta keys.
 */
final class InstallGalleryModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'install_gallery';
	}

	/**
	 * Everything except WP-CLI: reviews are moderated from admin, cron (trash purge) and the frontend.
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		return true;
	}

	/**
	 * Registers hooks only (no I/O).
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );

		// The derived review list follows moderation in any context (admin, cron trash purge, REST).
		$sync = new GallerySync();
		add_action( 'transition_comment_status', array( $sync, 'on_transition' ), 10, 3 );
		add_action( 'deleted_comment', array( $sync, 'on_deleted' ), 10, 2 );

		$context = new Context();
		if ( $context->is_cron() || $context->is_ajax() || $context->is_admin() || $context->is_cli() ) {
			add_action( GalleryStore::BACKFILL_HOOK, array( GalleryStore::class, 'backfill_batch' ) );
		}
		if ( $context->is_frontend() || $context->is_ajax() ) {
			add_action( 'comment_post', array( $sync, 'on_comment_post' ), 20, 2 ); // After ReviewPhotos (priority 10).
		}
		if ( $context->is_frontend() ) {
			$display = new GalleryDisplay();
			ProductPageHooks::register_after_gallery( array( $display, 'render_below_gallery' ) );
			ProductPageHooks::register_after_add_to_cart( array( $display, 'render_after_add_to_cart' ) );
			ProductPageHooks::register_before_tabs( array( $display, 'render_before_tabs' ) );
			ProductPageHooks::register_after_summary( array( $display, 'render_after_tabs' ) );
			ProductPageHooks::register_product_tab( array( $display, 'filter_product_tab' ) );
			ProductPageHooks::register_product_gallery_block( array( $display, 'filter_gallery_block' ) );
			ProductPageHooks::register_add_to_cart_block( array( $display, 'filter_add_to_cart_block' ) );
			ProductPageHooks::register_product_details_block( array( $display, 'filter_details_block' ) );
			add_shortcode( 'motored_install_gallery', array( $display, 'shortcode' ) );
		}
		if ( $context->is_admin() ) {
			( new GalleryAdmin() )->register();
		}
	}
}
