<?php
/**
 * Single "can a rider buy it now" rule shared by the bike page hero, the indexability flag and the sitemap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

defined( 'ABSPATH' ) || exit;

/**
 * A compatible product counts as available when it is published, visible in the catalog and purchasable now
 * (`instock` or `onbackorder` — WC_Product::is_in_stock() is true for both).
 */
final class Availability {

	/**
	 * Whether one product is available to buy.
	 *
	 * @param mixed $product WC_Product (anything else is not available).
	 */
	public static function is_available( $product ): bool {
		return $product instanceof \WC_Product
			&& 'publish' === $product->get_status()
			&& $product->is_visible()
			&& $product->is_purchasable()
			&& $product->is_in_stock();
	}

	/**
	 * Commercial subset of technical matches, shared by counts, grids and demand delivery.
	 *
	 * @param array<int,int> $product_ids Technical matches.
	 * @return list<int>
	 */
	public static function ids( array $product_ids ): array {
		$out = array();
		self::prime( $product_ids );
		foreach ( $product_ids as $id ) {
			if ( self::is_available( wc_get_product( (int) $id ) ) ) {
				$out[] = (int) $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Counts available products, stopping early once `$stop_at` is reached (0 = count all).
	 *
	 * @param array<int,int> $product_ids Product IDs.
	 * @param int            $stop_at     Early-exit threshold.
	 */
	public static function count( array $product_ids, int $stop_at = 0 ): int {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}
		$count = 0;
		// Chunks keep the early exit cheap (stop_at = 1 rarely needs more than the first chunk).
		foreach ( array_chunk( array_values( $product_ids ), 20 ) as $chunk ) {
			self::prime( $chunk );
			foreach ( $chunk as $pid ) {
				if ( self::is_available( wc_get_product( (int) $pid ) ) ) {
					++$count;
					if ( $stop_at > 0 && $count >= $stop_at ) {
						return $count;
					}
				}
			}
		}
		return $count;
	}

	/**
	 * Loads posts, meta and terms (catalog visibility) in 3 queries instead of ~3 per product without Redis.
	 *
	 * @param array<int,int> $product_ids Product IDs.
	 */
	private static function prime( array $product_ids ): void {
		$ids = array_values( array_filter( array_map( 'intval', $product_ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( count( $ids ) > 1 && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, true, true );
		}
	}
}
