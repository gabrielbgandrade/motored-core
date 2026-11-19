<?php
/**
 * WooCommerce product attribute & product editor adapter (unverified assumptions tracked in docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Single point of contact for WooCommerce attribute CRUD, taxonomy registration and simple-product meta fields.
 */
final class AttributeHooks {

	/**
	 * Returns an existing global attribute ID by slug, or 0 if not registered.
	 *
	 * @param string $slug Unprefixed attribute slug.
	 */
	public static function attribute_id_by_slug( string $slug ): int {
		if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
			return 0;
		}
		return (int) wc_attribute_taxonomy_id_by_name( $slug );
	}

	/**
	 * Creates a global WooCommerce attribute.
	 *
	 * @param array{name:string,slug:string,type:string,order_by:string,has_archives:bool} $args Attribute arguments.
	 * @return int|\WP_Error Created attribute ID or error.
	 */
	public static function create_attribute( array $args ) {
		if ( ! function_exists( 'wc_create_attribute' ) ) {
			return new \WP_Error( 'missing_wc', 'WooCommerce attribute API is not available.' );
		}
		return wc_create_attribute( $args );
	}

	/**
	 * Flushes WooCommerce's attribute taxonomies transient so newly created attributes become visible.
	 */
	public static function clear_attribute_cache(): void {
		delete_transient( 'wc_attribute_taxonomies' );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'woocommerce-attributes', 'options' );
		}
	}

	/**
	 * Prefixes an attribute slug with "pa_".
	 *
	 * @param string $slug Unprefixed attribute slug.
	 */
	public static function taxonomy_name( string $slug ): string {
		if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
			return (string) wc_attribute_taxonomy_name( $slug );
		}
		return 'pa_' . $slug;
	}

	/**
	 * Ensures a taxonomy is temporarily registered in WP during term seeding right after creation.
	 *
	 * @param string $taxonomy Full pa_* taxonomy name.
	 */
	public static function ensure_taxonomy_registered( string $taxonomy ): bool {
		if ( taxonomy_exists( $taxonomy ) ) {
			return true;
		}
		$result = register_taxonomy(
			$taxonomy,
			array( 'product' ),
			array(
				'hierarchical' => false,
				'show_ui'      => false,
				'query_var'    => false,
				'rewrite'      => false,
			)
		);
		return ! is_wp_error( $result );
	}

	/**
	 * Inserts a term if missing and optionally sets its term meta order (for menu_order attributes).
	 *
	 * @param string   $taxonomy Full pa_* taxonomy name.
	 * @param string   $name     Human-readable label.
	 * @param string   $slug     Term slug.
	 * @param int|null $order    Optional order integer saved in term meta 'order'.
	 * @return int Term ID or 0 on failure.
	 */
	public static function ensure_term( string $taxonomy, string $name, string $slug, ?int $order = null ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing instanceof \WP_Term ) {
			return (int) $existing->term_id;
		}
		$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $inserted ) ) {
			$dup = (int) $inserted->get_error_data( 'term_exists' );
			return $dup > 0 ? $dup : 0;
		}
		if ( ! is_array( $inserted ) ) {
			return 0;
		}
		$term_id = (int) ( $inserted['term_id'] ?? 0 );
		if ( $term_id > 0 && null !== $order && function_exists( 'update_term_meta' ) ) {
			update_term_meta( $term_id, 'order', $order );
		}
		return $term_id;
	}

	/**
	 * Reads terms attached to a product for a given taxonomy.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $taxonomy   Taxonomy name.
	 * @return list<array{term_id:int,name:string,slug:string}>
	 */
	public static function product_terms( int $product_id, string $taxonomy ): array {
		if ( $product_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = wp_get_object_terms( $product_id, $taxonomy );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$out[] = array(
					'term_id' => (int) $term->term_id,
					'name'    => (string) $term->name,
					'slug'    => (string) $term->slug,
				);
			} elseif ( is_object( $term ) ) {
				$out[] = array(
					'term_id' => (int) ( $term->term_id ?? 0 ),
					'name'    => (string) ( $term->name ?? '' ),
					'slug'    => (string) ( $term->slug ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Checks whether a product ID belongs to a simple WooCommerce product.
	 *
	 * @param int $product_id Product post ID.
	 */
	public static function is_simple_product( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product ) {
				return (bool) $product->is_type( 'simple' );
			}
		}
		if ( function_exists( 'wp_get_object_terms' ) ) {
			$terms = wp_get_object_terms( $product_id, 'product_type' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( $term instanceof \WP_Term ) {
						$slug = (string) $term->slug;
					} elseif ( is_object( $term ) && property_exists( $term, 'slug' ) ) {
						$slug = (string) $term->slug;
					} else {
						$slug = is_scalar( $term ) ? (string) $term : '';
					}
					if ( 'simple' === $slug ) {
						return true;
					}
				}
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether WooCommerce native product brands taxonomy is available.
	 */
	public static function has_native_brand_taxonomy(): bool {
		return taxonomy_exists( 'product_brand' );
	}

	/**
	 * Attaches a global taxonomy attribute term to a WC_Product if not already assigned.
	 *
	 * @param \WC_Product $product  Product object.
	 * @param string      $taxonomy Full pa_* taxonomy name.
	 * @param int         $term_id  Term ID.
	 */
	public static function set_product_attribute_term( \WC_Product $product, string $taxonomy, int $term_id ): bool {
		if ( $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$attributes = $product->get_attributes( 'edit' );
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}
		$attr = new \WC_Product_Attribute();
		$slug = function_exists( 'wc_attribute_taxonomy_slug' ) ? wc_attribute_taxonomy_slug( $taxonomy ) : str_replace( 'pa_', '', $taxonomy );
		$attr->set_id( self::attribute_id_by_slug( (string) $slug ) );
		$attr->set_name( $taxonomy );
		$attr->set_options( array( $term_id ) );
		$attr->set_position( count( $attributes ) );
		$attr->set_visible( true );
		$attr->set_variation( false );
		$attributes[ $taxonomy ] = $attr;
		$product->set_attributes( $attributes );
		wp_set_object_terms( $product->get_id(), array( $term_id ), $taxonomy, false );
		return true;
	}

	/**
	 * Registers product data hooks for the optional _motored_full_size meta input.
	 *
	 * @param callable $render_callback Hooked on woocommerce_product_options_general_product_data.
	 * @param callable $save_callback   Hooked on woocommerce_admin_process_product_object.
	 */
	public static function register_product_meta_hooks( callable $render_callback, callable $save_callback ): void {
		add_action( 'woocommerce_product_options_general_product_data', $render_callback );
		add_action( 'woocommerce_admin_process_product_object', $save_callback );
	}
}
