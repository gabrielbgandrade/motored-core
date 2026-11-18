<?php
/**
 * Personal data eraser for review photos.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes the photos of every review written with an e-mail address.
 */
final class Privacy {

	public const BATCH = 25;

	/**
	 * Registers the eraser (admin / ajax, where WordPress runs privacy tools).
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Adds the eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array<string,mixed>
	 */
	public function add_eraser( $erasers ): array {
		$erasers                          = is_array( $erasers ) ? $erasers : array();
		$erasers['motored-review-photos'] = array(
			'eraser_friendly_name' => __( 'Review photos', 'motored-core' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Eraser callback: photos are removed in batches (the meta is deleted, so page 1 always has the next ones).
	 *
	 * @param mixed $email E-mail address.
	 * @param mixed $page  Page (unused).
	 * @return array{items_removed:int,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$ids     = '' === (string) $email ? array() : get_comments(
			array(
				'author_email' => (string) $email,
				'meta_key'     => PhotoMeta::KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Rare privacy request.
				'number'       => self::BATCH,
				'fields'       => 'ids',
			)
		);
		$ids     = is_array( $ids ) ? $ids : array();
		$removed = 0;
		foreach ( $ids as $comment_id ) {
			$removed += PhotoMeta::delete_all( (int) $comment_id );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::BATCH,
		);
	}
}
