<?php
/**
 * Product meta of the gallery.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\InstallGallery;

use Motored\Core\Modules\ReviewPhotos\PhotoMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Two product meta keys, both read from the post meta the product page already primed (0 queries):
 * - `_motored_install_photos`: attachment ids chosen by the owner (source of truth, written by the product box).
 * - `_motored_review_gallery`: DERIVED list of `{id,c}` from approved reviews with photos; rebuilt whenever a review
 *   changes (see GallerySync). It is a cache of data that lives in comment meta and can be rebuilt at any time.
 */
final class GalleryStore {

	public const INSTALL_META = '_motored_install_photos';
	public const REVIEW_META  = '_motored_review_gallery';

	/**
	 * Installation photo ids of a product.
	 *
	 * @param int $product_id Product id.
	 * @return list<int>
	 */
	public static function install_ids( int $product_id ): array {
		return GalleryItems::install_ids( get_post_meta( $product_id, self::INSTALL_META, true ) );
	}

	/**
	 * Review photo entries of a product (derived list).
	 *
	 * @param int $product_id Product id.
	 * @return list<array{id:int,c:int,r:int}>
	 */
	public static function review_entries( int $product_id ): array {
		return GalleryItems::review_entries( get_post_meta( $product_id, self::REVIEW_META, true ) );
	}

	/**
	 * Rebuilds the derived list from the approved reviews of a product (one comment query, admin/cron/comment requests only).
	 *
	 * @param int $product_id Product id.
	 */
	public static function rebuild( int $product_id ): void {
		if ( $product_id < 1 || 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		$comments = get_comments(
			array(
				'post_id'  => $product_id,
				'status'   => 'approve',
				'type__in' => array( 'review', 'comment', '' ),
				'parent'   => 0,
				'meta_key' => PhotoMeta::KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Rare event (a review changes), never a page view.
				'orderby'  => 'comment_date_gmt',
				'order'    => 'DESC',
				'number'   => GalleryItems::MAX_REVIEWS,
			)
		);

		$entries = array();
		foreach ( is_array( $comments ) ? $comments : array() as $comment ) {
			if ( ! is_object( $comment ) ) {
				continue;
			}
			$comment_id = (int) ( $comment->comment_ID );
			$raw_rating = get_comment_meta( $comment_id, 'rating', true );
			$rating     = is_scalar( $raw_rating ) ? max( 0, min( 5, (int) $raw_rating ) ) : 0;
			foreach ( PhotoMeta::ids( $comment_id ) as $id ) {
				$entries[] = array(
					'id' => $id,
					'c'  => $comment_id,
					'r'  => $rating,
				);
			}
		}
		$entries = GalleryItems::review_entries( $entries );
		$current = self::review_entries( $product_id );
		if ( $current === $entries ) {
			return; // Unchanged: no write, no cache purge.
		}
		if ( array() === $entries ) {
			delete_post_meta( $product_id, self::REVIEW_META );
		} else {
			update_post_meta( $product_id, self::REVIEW_META, $entries );
		}
		self::purge( $product_id );
	}

	/**
	 * The gallery is part of the product HTML: tell object/page caches the product changed.
	 *
	 * `clean_post_cache` fires the core action page-cache plugins listen to (VERIFY #121); the product
	 * transients cover WooCommerce's own fragments.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function purge( int $product_id ): void {
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $product_id );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}

	/**
	 * Rebuilds `_motored_review_gallery` for every product that has approved review photos
	 * (admin tool & one-off upgrade migration only; never runs on frontend page views).
	 *
	 * @return int Number of products rebuilt.
	 */
	public static function rebuild_all(): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->comments, $wpdb->commentmeta, $wpdb->postmeta ) ) {
			return 0;
		}
		// One indexed query, no cap: products with approved photo reviews PLUS products whose derived list may
		// be stale (so leftovers of unapproved/deleted reviews are cleared too).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin tool / one-off migration.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT c.comment_post_ID FROM {$wpdb->comments} c
				INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID AND cm.meta_key = %s
				WHERE c.comment_approved = '1' AND c.comment_parent = 0
				UNION
				SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = %s",
				PhotoMeta::KEY,
				self::REVIEW_META
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$product_ids = array();
		foreach ( is_array( $ids ) ? $ids : array() as $raw ) {
			$pid = is_scalar( $raw ) ? (int) $raw : 0;
			if ( $pid > 0 ) {
				$product_ids[ $pid ] = $pid;
			}
		}
		foreach ( $product_ids as $pid ) {
			self::rebuild( $pid );
		}
		return count( $product_ids );
	}
}
