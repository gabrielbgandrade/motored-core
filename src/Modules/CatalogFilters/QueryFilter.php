<?php
/**
 * Storefront query filter (?oferta=1) and robots noindex directive for filtered catalog URLs.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Modules\Compatibility\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Acts ONLY when filter parameters exist in the URL.
 *
 * - WooCommerce natively applies ?filter_{attr}=a,b&query_type_{attr}=or via its lookup table.
 * - WordPress natively applies ?product_brand=a,b via the product_brand taxonomy query var (verified in VERIFY.md).
 * - ?oferta=1 intersects wc_get_product_ids_on_sale() with existing post__in (preserving ?moto=).
 */
final class QueryFilter {

	/**
	 * Registers storefront query and robots hooks (zero option reads here).
	 */
	public function register(): void {
		add_action( 'woocommerce_product_query', array( $this, 'filter_product_query' ), 25 );
		add_filter( 'wp_robots', array( $this, 'robots_noindex' ), 20 );
	}

	/**
	 * Hook: woocommerce_product_query — intersects ?oferta=1 with existing post__in (never overwrites ?moto=).
	 *
	 * @param mixed $query WP_Query instance.
	 */
	public function filter_product_query( $query ): void {
		if ( ! $query instanceof \WP_Query ) {
			return;
		}
		$state = FilterState::from_query();
		if ( ! $state->on_sale() ) {
			return;
		}
		if ( ! Config::enabled() ) {
			return;
		}

		$sale_ids = array_keys( FacetEngine::on_sale_id_map() );
		$existing = $query->get( 'post__in' );
		$merged   = Storefront::intersect_post_in( is_array( $existing ) ? $existing : array(), $sale_ids );
		$query->set( 'post__in', $merged );
	}

	/**
	 * Hook: wp_robots — emits `noindex, follow` on filtered catalog URLs when enabled.
	 *
	 * @param mixed $robots Robots directives.
	 * @return array<string,mixed>
	 */
	public function robots_noindex( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();
		$state  = FilterState::from_query();
		if ( ! $state->has_catalog_filter() ) {
			return $robots;
		}
		if ( ! Config::enabled() || ! Config::noindex_filtered() ) {
			return $robots;
		}
		$robots['noindex']  = true;
		$robots['nofollow'] = false;
		$robots['follow']   = true;
		return $robots;
	}
}
