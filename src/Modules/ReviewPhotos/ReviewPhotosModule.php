<?php
/**
 * ReviewPhotos module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

use Motored\Core\Contracts\Module;
use Motored\Core\Integration\WooCommerce\ReviewHooks;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Photos on native WooCommerce reviews (Media Library attachments + comment meta, no tables).
 */
final class ReviewPhotosModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'review_photos';
	}

	/**
	 * Everything except WP-CLI: the delete hook must also run in cron/REST/admin; the rest is per context.
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		return ! $context->is_cli();
	}

	/**
	 * Registers hooks only (no I/O).
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );

		// Photos never outlive their comment, whichever context deletes it (admin, cron trash purge, REST).
		add_action( 'delete_comment', array( PhotoMeta::class, 'on_delete_comment' ) );

		$context = new Context();
		if ( $context->is_frontend() ) {
			$form    = new ReviewForm();
			$display = new ReviewDisplay();
			ReviewHooks::register_form( array( $form, 'filter_args' ) );
			ReviewHooks::register_form_fallback( array( $form, 'filter_field' ) );
			ReviewHooks::register_before_comment_text( array( $display, 'mark_template_hooked' ) );
			ReviewHooks::register_after_comment_text( array( $display, 'render' ) );
			ReviewHooks::register_text_fallback( array( $display, 'filter_text' ) );
			$script = new FormScript( $form );
			add_action( 'wp_enqueue_scripts', array( $script, 'enqueue' ) );
			add_action( 'wp_footer', array( $script, 'add_fallback' ), 5 );
		}
		if ( $context->is_frontend() || $context->is_ajax() ) {
			add_action( 'comment_post', array( new ReviewUploads(), 'handle' ), 10, 2 );
		}
		if ( $context->is_admin() ) {
			( new ReviewPhotosAdmin() )->register();
			( new Privacy() )->register();
		}
	}
}
