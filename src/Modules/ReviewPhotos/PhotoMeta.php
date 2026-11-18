<?php
/**
 * Comment meta of review photos.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

defined( 'ABSPATH' ) || exit;

/**
 * Comment meta `_motored_review_photos` = list of attachment ids. Attachments carry post meta
 * `_motored_review_photo` so only our own files are ever deleted.
 */
final class PhotoMeta {

	public const KEY  = '_motored_review_photos';
	public const FLAG = '_motored_review_photo';

	/**
	 * Clean list of attachment ids from any raw meta value.
	 *
	 * @param mixed $raw Meta value.
	 * @return list<int>
	 */
	public static function normalize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $value ) {
			$id = is_scalar( $value ) ? (int) $value : 0;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		return array_values( $ids );
	}

	/**
	 * Attachment ids of a comment (comment meta is already primed by the comment list).
	 *
	 * @param int $comment_id Comment id.
	 * @return list<int>
	 */
	public static function ids( int $comment_id ): array {
		return self::normalize( get_comment_meta( $comment_id, self::KEY, true ) );
	}

	/**
	 * Stores the ids.
	 *
	 * @param int              $comment_id Comment id.
	 * @param array<int,mixed> $ids Attachment ids.
	 */
	public static function save( int $comment_id, array $ids ): void {
		$ids = self::normalize( $ids );
		if ( array() !== $ids ) {
			update_comment_meta( $comment_id, self::KEY, $ids );
		}
	}

	/**
	 * Deletes the attachments of a comment (only ours) and its meta.
	 *
	 * @param int $comment_id Comment id.
	 * @return int Attachments deleted.
	 */
	public static function delete_all( int $comment_id ): int {
		$deleted = 0;
		foreach ( self::ids( $comment_id ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) && '1' === (string) get_post_meta( $id, self::FLAG, true ) && false !== wp_delete_attachment( $id, true ) ) {
				++$deleted;
			}
		}
		delete_comment_meta( $comment_id, self::KEY );
		return $deleted;
	}

	/**
	 * Hooked on delete_comment.
	 *
	 * @param mixed $comment_id Comment id.
	 */
	public static function on_delete_comment( $comment_id ): void {
		self::delete_all( (int) $comment_id );
	}
}
