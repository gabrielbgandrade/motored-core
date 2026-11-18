<?php
/**
 * Photo thumbnails under approved reviews.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

use Motored\Core\Integration\WooCommerce\ReviewHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Plain thumbnails linking to the full image (new tab): no lightbox, no stylesheet, no theme dependency.
 * Primary: the WooCommerce action; fallback: the core comment_text filter. Never both for the same review.
 */
final class ReviewDisplay {

	/**
	 * Review ids whose photos were already printed (or are left to the WooCommerce action).
	 *
	 * @var array<int,bool>
	 */
	private array $done = array();

	/**
	 * Prints the photos of a review (WooCommerce action after the review text; primary hook).
	 *
	 * @param mixed $comment WP_Comment.
	 */
	public function render( $comment ): void {
		if ( ! is_object( $comment ) ) {
			return;
		}
		$id = (int) ( $comment->comment_ID ?? 0 );
		if ( isset( $this->done[ $id ] ) && true === $this->done[ $id ] ) {
			return;
		}
		$this->done[ $id ] = true;
		echo $this->html( $comment ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in html().
	}

	/**
	 * Marks a review whose template fires the WooCommerce hooks: the action prints the photos there.
	 *
	 * @param mixed $comment WP_Comment.
	 */
	public function mark_template_hooked( $comment ): void {
		if ( is_object( $comment ) && ! isset( $this->done[ (int) ( $comment->comment_ID ?? 0 ) ] ) ) {
			$this->done[ (int) ( $comment->comment_ID ?? 0 ) ] = false;
		}
	}

	/**
	 * Fallback: appends the photos to the review text when the theme template does not fire the
	 * WooCommerce review hooks (core `comment_text` filter, reviews only).
	 *
	 * @param mixed $text    Comment text.
	 * @param mixed $comment WP_Comment.
	 * @return mixed
	 */
	public function filter_text( $text, $comment = null ) {
		if ( ! is_string( $text ) || ! is_object( $comment ) ) {
			return $text;
		}
		$id = (int) ( $comment->comment_ID ?? 0 );
		if ( isset( $this->done[ $id ] ) ) {
			return $text; // Printed already, or the WooCommerce action will print it.
		}
		$html = $this->html( $comment );
		if ( '' === $html ) {
			return $text;
		}
		if ( ! ReviewHooks::is_review( $comment ) ) {
			return $text;
		}
		$this->done[ $id ] = true;
		return $text . $html;
	}

	/**
	 * Markup of the photos of an approved review ('' when none).
	 *
	 * @param object $comment WP_Comment.
	 */
	private function html( object $comment ): string {
		if ( '1' !== (string) ( $comment->comment_approved ?? '' ) ) {
			return '';
		}
		$ids = PhotoMeta::ids( (int) $comment->comment_ID ); // Comment meta is primed by the comment list: no query.
		if ( array() === $ids || ! Config::show_photos() ) {
			return '';
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true );
		}

		$html = '';
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_url( $id );
			$img = wp_get_attachment_image(
				$id,
				'thumbnail',
				false,
				array(
					'alt'     => __( 'Customer photo', 'motored-core' ),
					'loading' => 'lazy',
					'style'   => 'width:72px;height:72px;object-fit:cover;border-radius:4px',
				)
			);
			if ( is_string( $url ) && '' !== $url && '' !== $img ) {
				$html .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $img . '</a> ';
			}
		}
		if ( '' === $html ) {
			return '';
		}
		return '<div class="motored-review-photos" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">' . wp_kses_post( $html ) . '</div>';
	}
}
