<?php
/**
 * Admin side: settings section and comment meta box.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

use Motored\Core\Admin\ReviewsSection;

defined( 'ABSPATH' ) || exit;

/**
 * Only instantiated in admin requests.
 */
final class ReviewPhotosAdmin {

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		ReviewsSection::ensure();
		add_filter( 'motored_reviews_section_fields', array( $this, 'add_fields' ), 10 );
		add_action( 'add_meta_boxes_comment', array( $this, 'add_meta_box' ) );
		add_action( 'add_meta_boxes_product', array( $this, 'rename_reviews_box' ), 40 );
		add_filter( 'comment_text', array( $this, 'append_photos_to_list' ), 20, 2 );
	}

	/**
	 * Renames the native WooCommerce product reviews meta box (`commentsdiv`) using our plugin text domain
	 * so `es_AR` shows "Reseñas" instead of WooCommerce's default "Valoraciones" (runs after WC prio 30).
	 */
	public function rename_reviews_box(): void {
		global $wp_meta_boxes;
		if ( ! is_array( $wp_meta_boxes ) || empty( $wp_meta_boxes['product'] ) || ! is_array( $wp_meta_boxes['product'] ) ) {
			return;
		}
		foreach ( $wp_meta_boxes['product'] as $context => $priorities ) {
			if ( ! is_array( $priorities ) ) {
				continue;
			}
			foreach ( $priorities as $priority => $boxes ) {
				if ( is_array( $boxes ) && isset( $boxes['commentsdiv'] ) && is_array( $boxes['commentsdiv'] ) ) {
					$wp_meta_boxes['product'][ $context ][ $priority ]['commentsdiv']['title'] = __( 'Reviews', 'motored-core' );
				}
			}
		}
	}

	/**
	 * Shows lazy-loaded thumbnails under the review text in the WooCommerce Reviews list, Comments list,
	 * and the Product edit screen's Reviews meta box (including its `get-comments` AJAX loader).
	 *
	 * @param mixed $text    Comment text.
	 * @param mixed $comment WP_Comment.
	 * @return mixed
	 */
	public function append_photos_to_list( $text, $comment = null ) {
		if ( ! is_string( $text ) || ! is_object( $comment ) ) {
			return $text;
		}
		$allowed = false;
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only action check for WP core comments box AJAX.
			$action  = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			$allowed = in_array( $action, array( 'get-comments', 'replyto-comment', 'edit-comment' ), true );
		} elseif ( function_exists( 'get_current_screen' ) ) {
			$screen  = get_current_screen();
			$allowed = is_object( $screen ) && in_array( $screen->id, array( 'product_page_product-reviews', 'edit-comments', 'product' ), true );
		}
		if ( ! $allowed ) {
			return $text;
		}
		$ids = PhotoMeta::ids( (int) ( $comment->comment_ID ?? 0 ) );
		if ( array() === $ids ) {
			return $text;
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true );
		}
		$html = '';
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );
			if ( ! is_string( $url ) || '' === $url ) {
				$url = wp_get_attachment_url( $id );
			}
			$img = wp_get_attachment_image(
				$id,
				'thumbnail',
				false,
				array(
					'loading'  => 'lazy',
					'decoding' => 'async',
					'width'    => 48,
					'height'   => 48,
				)
			);
			if ( is_string( $url ) && '' !== $url && '' !== $img ) {
				$html .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $img . '</a>';
			}
		}
		return '' === $html ? $text : $text . '<div class="motored-box__rv-photos">' . wp_kses_post( $html ) . '</div>';
	}

	/**
	 * Adds this module's fields to the shared Reviews section.
	 *
	 * @param mixed $fields Fields of other modules.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_fields( $fields ): array {
		return array_merge( is_array( $fields ) ? $fields : array(), $this->settings() );
	}

	/**
	 * Fields of the Reviews section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		return array(
			array(
				'title' => __( 'Review photos', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Lets customers attach photos to the native WooCommerce review form.', 'motored-core' ),
				'id'    => 'motored_review_photos_title',
			),
			array(
				'title'    => __( 'Enable photos', 'motored-core' ),
				'desc'     => __( 'Show the photo field in product reviews.', 'motored-core' ),
				'id'       => 'motored_review_photos[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Maximum photos per review', 'motored-core' ),
				'id'                => 'motored_review_photos[max_photos]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['max_photos'],
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '5',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'             => __( 'Maximum size per photo (MB)', 'motored-core' ),
				'id'                => 'motored_review_photos[max_mb]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['max_mb'],
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '10',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'    => __( 'Show photos in reviews', 'motored-core' ),
				'desc'     => __( 'Show thumbnails under approved reviews.', 'motored-core' ),
				'id'       => 'motored_review_photos[show_photos]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['show_photos'],
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_review_photos_title',
			),
		);
	}

	/**
	 * Adds the meta box to the comment edit screen when the comment has photos.
	 *
	 * @param mixed $comment WP_Comment.
	 */
	public function add_meta_box( $comment ): void {
		if ( ! is_object( $comment ) || array() === PhotoMeta::ids( (int) $comment->comment_ID ) ) {
			return;
		}
		add_meta_box( 'motored-review-photos', __( 'Review photos', 'motored-core' ), array( $this, 'render_meta_box' ), 'comment', 'normal' );
	}

	/**
	 * Prints the thumbnails.
	 *
	 * @param mixed $comment WP_Comment.
	 */
	public function render_meta_box( $comment ): void {
		if ( ! is_object( $comment ) ) {
			return;
		}
		echo '<div class="motored-box motored-box--flush"><p>';
		foreach ( PhotoMeta::ids( (int) $comment->comment_ID ) as $id ) {
			$url = wp_get_attachment_url( $id );
			if ( is_string( $url ) && '' !== $url ) {
				echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $id, 'thumbnail' ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core markup.
			}
		}
		echo '</p><p class="description">' . esc_html__( 'The photos are deleted together with the comment.', 'motored-core' ) . '</p></div>';
	}
}
