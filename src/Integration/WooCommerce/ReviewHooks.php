<?php
/**
 * WooCommerce product review hooks (isolated: names are NOT in the stubs, see docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for the native WooCommerce review form and review list.
 */
final class ReviewHooks {

	/**
	 * Hooks a filter on the arguments of the product review form (single-product-reviews.php).
	 *
	 * @param callable $callback fn( array $args ): array.
	 */
	public static function register_form( callable $callback ): void {
		add_filter( 'woocommerce_product_review_comment_form_args', $callback );
	}

	/**
	 * Hooks an action printed after the text of each review (review.php template).
	 *
	 * @param callable $callback fn( \WP_Comment $comment ): void.
	 */
	public static function register_after_comment_text( callable $callback ): void {
		add_action( 'woocommerce_review_after_comment_text', $callback );
	}

	/**
	 * Fallback for themes that replace the review template: core filter on the comment textarea markup.
	 *
	 * @param callable $callback fn( string $field ): string.
	 */
	public static function register_form_fallback( callable $callback ): void {
		add_filter( 'comment_form_field_comment', $callback, 20 );
	}

	/**
	 * Hooks an action printed BEFORE the text of each review (review.php template): proves that the
	 * template fires the WooCommerce hooks, so the after-text action will run too.
	 *
	 * @param callable $callback fn( \WP_Comment $comment ): void.
	 */
	public static function register_before_comment_text( callable $callback ): void {
		add_action( 'woocommerce_review_before_comment_text', $callback );
	}

	/**
	 * Fallback for themes that replace the review template: core filter on the comment text.
	 *
	 * @param callable $callback fn( string $text, \WP_Comment $comment ): string.
	 */
	public static function register_text_fallback( callable $callback ): void {
		add_filter( 'comment_text', $callback, 20, 2 );
	}

	/**
	 * Whether the current request is a single product page with open comments (no queries).
	 */
	public static function is_review_form_page(): bool {
		return function_exists( 'is_product' ) && is_product() && comments_open();
	}

	/**
	 * Whether a comment is a product review: type "review", or a top-level "comment" on a product
	 * (WooCommerce versions differ on the stored type, VERIFY.md).
	 *
	 * @param object $comment WP_Comment.
	 */
	public static function is_review( object $comment ): bool {
		$type = isset( $comment->comment_type ) ? (string) $comment->comment_type : '';
		if ( 'review' !== $type && ! ( in_array( $type, array( '', 'comment' ), true ) && 0 === (int) ( $comment->comment_parent ?? 0 ) ) ) {
			return false;
		}
		return 'product' === get_post_type( (int) ( $comment->comment_post_ID ?? 0 ) );
	}
}
