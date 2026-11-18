<?php
/**
 * Minimal cart snapshot built on the server.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Items keep ONLY product_id, variation_id, qty and attributes (no names, no prices).
 */
final class Snapshot {

	public const MAX_ITEMS = 50;

	/**
	 * Builds the items and a stable hash from raw cart lines.
	 *
	 * @param array<int,mixed> $lines Lines with product_id, variation_id, quantity, variation.
	 * @return array{items:list<array{product_id:int,variation_id:int,qty:int,attributes:array<string,string>}>,hash:string}
	 */
	public static function from_cart( array $lines ): array {
		$items = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$product_id = (int) ( $line['product_id'] ?? 0 );
			$qty        = (int) ( $line['quantity'] ?? 0 );
			if ( $product_id <= 0 || $qty <= 0 ) {
				continue;
			}
			$attributes = array();
			foreach ( is_array( $line['variation'] ?? null ) ? $line['variation'] : array() as $name => $value ) {
				if ( is_scalar( $value ) ) {
					$attributes[ substr( (string) $name, 0, 60 ) ] = substr( (string) $value, 0, 100 );
				}
			}
			ksort( $attributes );
			$items[] = array(
				'product_id'   => $product_id,
				'variation_id' => max( 0, (int) ( $line['variation_id'] ?? 0 ) ),
				'qty'          => min( $qty, 9999 ),
				'attributes'   => $attributes,
			);
		}
		usort(
			$items,
			static fn( array $a, array $b ): int => array( $a['product_id'], $a['variation_id'] ) <=> array( $b['product_id'], $b['variation_id'] )
		);
		$items = array_slice( $items, 0, self::MAX_ITEMS );

		return array(
			'items' => $items,
			'hash'  => array() === $items ? '' : sha1( (string) wp_json_encode( $items ) ),
		);
	}

	/**
	 * Decodes the stored JSON.
	 *
	 * @param string $json Stored items.
	 * @return list<array<string,mixed>>
	 */
	public static function decode( string $json ): array {
		$data = json_decode( $json, true );
		return is_array( $data ) ? array_values( array_filter( $data, 'is_array' ) ) : array();
	}
}
