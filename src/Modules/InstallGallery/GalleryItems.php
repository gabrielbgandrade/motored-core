<?php
/**
 * Pure gallery rules (no WordPress calls).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\InstallGallery;

use Motored\Core\Modules\ReviewPhotos\PhotoMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes the stored lists, merges installation photos with review photos and plans the thumbnail strip.
 * An item is `array{id:int,c:int}`: attachment id and the review (comment) id it came from, 0 for installation photos.
 */
final class GalleryItems {

	public const MAX_INSTALL = 12;
	public const MAX_REVIEWS = 12;
	public const MAX_TOTAL   = 24;

	/**
	 * Clean list of attachment ids from any raw value, capped at MAX_INSTALL.
	 *
	 * @param mixed $raw Meta value.
	 * @return list<int>
	 */
	public static function install_ids( $raw ): array {
		return array_slice( PhotoMeta::normalize( $raw ), 0, self::MAX_INSTALL );
	}

	/**
	 * Ids from the comma separated hidden field of the product box.
	 *
	 * @param mixed $csv Posted value.
	 * @return list<int>
	 */
	public static function parse_csv( $csv ): array {
		return is_string( $csv ) ? self::install_ids( explode( ',', $csv ) ) : array();
	}

	/**
	 * Clean list of review entries from any raw meta value (invalid pairs dropped, ids unique).
	 *
	 * @param mixed $raw Meta value.
	 * @return list<array{id:int,c:int,r:int}>
	 */
	public static function review_entries( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$entries = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id      = isset( $entry['id'] ) && is_scalar( $entry['id'] ) ? (int) $entry['id'] : 0;
			$comment = isset( $entry['c'] ) && is_scalar( $entry['c'] ) ? (int) $entry['c'] : 0;
			$rating  = isset( $entry['r'] ) && is_scalar( $entry['r'] ) ? max( 0, min( 5, (int) $entry['r'] ) ) : 0;
			if ( $id > 0 && $comment > 0 ) {
				$entries[ $id ] = array(
					'id' => $id,
					'c'  => $comment,
					'r'  => $rating,
				);
			}
		}
		return array_slice( array_values( $entries ), 0, self::MAX_TOTAL );
	}

	/**
	 * Installation photos first, then review photos; an attachment appears once; at most MAX_TOTAL.
	 *
	 * @param array<int,int>                        $install Installation photo ids.
	 * @param array<int,array{id:int,c:int,r?:int}> $reviews Review photo entries.
	 * @return list<array{id:int,c:int,r:int}>
	 */
	public static function merge( array $install, array $reviews ): array {
		$items = array();
		foreach ( $install as $id ) {
			$items[ $id ] = array(
				'id' => $id,
				'c'  => 0,
				'r'  => 0,
			);
		}
		foreach ( $reviews as $entry ) {
			if ( ! isset( $items[ $entry['id'] ] ) ) {
				$items[ $entry['id'] ] = array(
					'id' => $entry['id'],
					'c'  => $entry['c'],
					'r'  => isset( $entry['r'] ) ? max( 0, min( 5, (int) $entry['r'] ) ) : 0,
				);
			}
		}
		return array_slice( array_values( $items ), 0, self::MAX_TOTAL );
	}

	/**
	 * How each item is printed: `thumb` (image), `chip` ("+N" link replacing the last visible slot) or
	 * `hidden` (link only, no image: reachable from the dialog without costing a single byte of image).
	 *
	 * @param int $count   Items.
	 * @param int $visible Slots in the strip.
	 * @return list<string>
	 */
	public static function layout( int $count, int $visible ): array {
		$visible = max( 1, $visible );
		$modes   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			if ( $count <= $visible || $i < $visible - 1 ) {
				$modes[] = 'thumb';
			} elseif ( $i === $visible - 1 ) {
				$modes[] = 'chip';
			} else {
				$modes[] = 'hidden';
			}
		}
		return $modes;
	}

	/**
	 * Number shown on the "+N" chip (0 when everything fits).
	 *
	 * @param int $count   Items.
	 * @param int $visible Slots in the strip.
	 */
	public static function more( int $count, int $visible ): int {
		$visible = max( 1, $visible );
		return $count > $visible ? $count - $visible + 1 : 0;
	}
}
