<?php
/**
 * Fresh product data for stored cart items.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Reads names, prices and stock by ID at display time (the table stores IDs only).
 */
final class ProductLookup {

	public const IN_STOCK     = 'in_stock';
	public const OUT_OF_STOCK = 'out_of_stock';
	public const UNAVAILABLE  = 'unavailable';

	/**
	 * Resolves stored items.
	 *
	 * @param list<array<string,mixed>> $items Stored items (product_id, variation_id, qty, attributes).
	 * @return list<array{name:string,qty:int,price:float,line_total:float,stock:string}>
	 */
	public static function resolve( array $items ): array {
		$lines = array();
		foreach ( $items as $item ) {
			$id      = (int) ( $item['variation_id'] ?? 0 ) > 0 ? (int) $item['variation_id'] : (int) ( $item['product_id'] ?? 0 );
			$qty     = max( 0, (int) ( $item['qty'] ?? 0 ) );
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
			if ( ! $product instanceof \WC_Product ) {
				$lines[] = array(
					'name'       => '#' . $id,
					'qty'        => $qty,
					'price'      => 0.0,
					'line_total' => 0.0,
					'stock'      => self::UNAVAILABLE,
				);
				continue;
			}
			$price = (float) $product->get_price();
			if ( ! $product->is_purchasable() ) {
				$stock = self::UNAVAILABLE;
			} else {
				$stock = $product->is_in_stock() ? self::IN_STOCK : self::OUT_OF_STOCK;
			}
			$lines[] = array(
				'name'       => wp_strip_all_tags( $product->get_name() ),
				'qty'        => $qty,
				'price'      => $price,
				'line_total' => $price * $qty,
				'stock'      => $stock,
			);
		}
		return $lines;
	}

	/**
	 * Sum of the line totals of resolved items.
	 *
	 * @param list<array{line_total:float}> $lines Resolved lines.
	 */
	public static function total( array $lines ): float {
		$total = 0.0;
		foreach ( $lines as $line ) {
			$total += $line['line_total'];
		}
		return $total;
	}
}
