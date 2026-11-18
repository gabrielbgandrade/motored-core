<?php
/**
 * Photo field of the native review form.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

use Motored\Core\Integration\WooCommerce\ReviewHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Appends the file input to the review form; the script sets enctype and validates in the browser.
 * Without JS the form posts normally and no photo is sent.
 */
final class ReviewForm {

	public const INPUT_ID = 'motored_review_photos';

	public const WRAPPER_CLASS = 'motored-rp-field';

	/**
	 * Whether the field was already printed in this request (never twice, whichever hook got there first).
	 *
	 * @var bool
	 */
	private bool $rendered = false;

	/**
	 * Whether the field was printed by PHP (the JS fallback is only needed when it was not).
	 */
	public function was_rendered(): bool {
		return $this->rendered;
	}

	/**
	 * Filter callback of the WooCommerce review form arguments (primary hook).
	 *
	 * @param mixed $args Form arguments.
	 * @return mixed
	 */
	public function filter_args( $args ) {
		if ( ! is_array( $args ) || ! ReviewHooks::is_review_form_page() || ! Config::enabled() ) {
			return $args;
		}
		$current               = isset( $args['comment_field'] ) && is_string( $args['comment_field'] ) ? $args['comment_field'] : '';
		$args['comment_field'] = $this->append( $current );
		return $args;
	}

	/**
	 * Fallback: core `comment_form_field_comment` filter, which still fires when a theme replaces the
	 * WooCommerce review template (or a block theme prints the comment form) and skips the WC args filter.
	 *
	 * @param mixed $field Comment textarea markup.
	 * @return mixed
	 */
	public function filter_field( $field ) {
		if ( ! is_string( $field ) || ! ReviewHooks::is_review_form_page() || ! Config::enabled() ) {
			return $field;
		}
		return $this->append( $field );
	}

	/**
	 * Appends the field unless it is already present in the markup or was printed before.
	 *
	 * @param string $html Existing markup.
	 */
	private function append( string $html ): string {
		if ( $this->rendered || str_contains( $html, self::WRAPPER_CLASS ) ) {
			$this->rendered = true;
			return $html;
		}
		$this->rendered = true;
		return $html . $this->field_html();
	}

	/**
	 * Markup of the field (also used by the JS fallback).
	 */
	public function field_html(): string {
		$max = Config::max_photos();
		$mb  = Config::max_mb();

		return sprintf(
			'<p class="%9$s" data-max="%1$d" data-mb="%2$d" data-e-count="%3$s" data-e-size="%4$s" data-e-type="%5$s">'
			. '<label for="%6$s">%7$s</label> '
			. '<input type="file" id="%6$s" name="%8$s[]" accept="image/jpeg,image/png,image/webp" multiple />'
			. '<span class="motored-rp-msg" role="alert" style="display:block;color:#b32d2e"></span><span class="motored-rp-thumbs"></span></p>',
			$max,
			$mb,
			/* translators: %d: maximum number of photos. */
			esc_attr( sprintf( __( 'You can add up to %d photos.', 'motored-core' ), $max ) ),
			/* translators: %d: maximum megabytes per photo. */
			esc_attr( sprintf( __( 'Each photo can be up to %d MB.', 'motored-core' ), $mb ) ),
			esc_attr__( 'Only JPG, PNG or WebP photos are allowed.', 'motored-core' ),
			esc_attr( self::INPUT_ID ),
			esc_html__( 'Add photos (optional)', 'motored-core' ),
			esc_attr( ReviewUploads::FIELD ),
			esc_attr( self::WRAPPER_CLASS )
		);
	}
}
