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
		echo '<p>';
		foreach ( PhotoMeta::ids( (int) $comment->comment_ID ) as $id ) {
			$url = wp_get_attachment_url( $id );
			if ( is_string( $url ) && '' !== $url ) {
				echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $id, 'thumbnail' ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core markup.
			}
		}
		echo '</p><p class="description">' . esc_html__( 'The photos are deleted together with the comment.', 'motored-core' ) . '</p>';
	}
}
