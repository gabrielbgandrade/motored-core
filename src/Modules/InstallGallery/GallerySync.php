<?php
/**
 * Keeps the derived review list of a product in step with its reviews.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\InstallGallery;

use Motored\Core\Modules\ReviewPhotos\PhotoMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Core comment hooks only. Never runs on a normal page view: these fire when a review is posted, moderated or deleted.
 */
final class GallerySync {

	/**
	 * A new review that is already approved (runs after ReviewPhotos stored the photos, priority 10).
	 *
	 * @param mixed $comment_id Comment id.
	 * @param mixed $approved   1, 0 or 'spam'.
	 */
	public function on_comment_post( $comment_id, $approved = 0 ): void {
		if ( 1 !== (int) $approved || ! is_scalar( $comment_id ) ) {
			return;
		}
		$this->rebuild_for( get_comment( (int) $comment_id ) );
	}

	/**
	 * Approve, unapprove, spam or trash: rebuild only when the review has photos.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $comment    WP_Comment.
	 */
	public function on_transition( $new_status, $old_status, $comment = null ): void {
		if ( $new_status === $old_status || ! is_object( $comment ) || array() === PhotoMeta::ids( (int) ( $comment->comment_ID ?? 0 ) ) ) {
			return;
		}
		$this->rebuild_for( $comment );
	}

	/**
	 * After a review is deleted for good (ReviewPhotos already removed its photos on delete_comment).
	 *
	 * @param mixed $comment_id Comment id.
	 * @param mixed $comment    WP_Comment (WordPress 4.9+).
	 */
	public function on_deleted( $comment_id = 0, $comment = null ): void {
		if ( ! is_object( $comment ) || ! is_scalar( $comment_id ) ) {
			return;
		}
		// The comment meta is already gone here, so check the derived list instead: emptying the spam/trash
		// (thousands of deletions in one cron run) then costs one cached meta read each, not one rebuild query.
		$cid = (int) $comment_id;
		$pid = (int) ( $comment->comment_post_ID ?? 0 );
		if ( $cid < 1 || $pid < 1 || ! in_array( $cid, array_column( GalleryStore::review_entries( $pid ), 'c' ), true ) ) {
			return;
		}
		GalleryStore::rebuild( $pid );
	}

	/**
	 * Rebuilds the product of a comment.
	 *
	 * @param mixed $comment WP_Comment or null.
	 */
	private function rebuild_for( $comment ): void {
		if ( is_object( $comment ) ) {
			GalleryStore::rebuild( (int) ( $comment->comment_post_ID ?? 0 ) );
		}
	}
}
