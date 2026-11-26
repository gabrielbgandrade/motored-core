<?php
/**
 * Enriches WooCommerce's native Product JSON-LD with brand, condition, gallery images and tire specs.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Enhances WooCommerce's own `Product` structured data (never emits a duplicate Product node).
 * Uses primed product taxonomy/attribute data (0 extra SQL queries on product page views).
 */
final class ProductSchemaEnhancer {

	/**
	 * Registers WooCommerce structured data filter.
	 */
	public function register(): void {
		add_filter( 'woocommerce_structured_data_product', array( $this, 'enhance_product' ), 20, 2 );
	}

	/**
	 * Adds Brand, MPN, itemCondition, seller reference, gallery images and tire technical PropertyValues.
	 *
	 * @param mixed $markup  Existing WC Product schema array.
	 * @param mixed $product WC_Product instance.
	 * @return mixed
	 */
	public function enhance_product( $markup, $product ) {
		if ( ! is_array( $markup ) || ! $product instanceof \WC_Product || ! Config::enhance_wc_product_schema() ) {
			return $markup;
		}

		$pid = (int) $product->get_id();

		// Brand from product_brand taxonomy or pa_marca attribute.
		if ( empty( $markup['brand'] ) ) {
			$brand = self::resolve_brand( $product );
			if ( '' !== $brand ) {
				$markup['brand'] = array(
					'@type' => 'Brand',
					'name'  => $brand,
				);
			}
		}

		// MPN fallback from SKU for Google Merchant / Rich Snippets completeness.
		$sku = trim( (string) $product->get_sku() );
		if ( '' !== $sku && empty( $markup['mpn'] ) ) {
			$markup['mpn'] = $sku;
		}

		// Product gallery images (helps Google pick crisp product photography).
		$images = self::product_images( $product );
		if ( count( $images ) > 1 ) {
			$markup['image'] = $images;
		}

		// Enrich Offers with NewCondition, seller Organization reference, ReturnPolicy and ShippingDetails.
		if ( ! empty( $markup['offers'] ) && is_array( $markup['offers'] ) ) {
			$seller   = array(
				'@type' => 'Organization',
				'@id'   => trailingslashit( home_url( '/' ) ) . '#organization',
				'name'  => Config::org_name(),
			);
			$policy   = self::return_policy_node();
			$shipping = self::shipping_details_node();
			$markup['offers'] = self::enrich_offers( $markup['offers'], $seller, $policy, $shipping );
		}

		// Technical tire attributes as schema.org PropertyValue entries.
		$props = self::tire_properties( $product );
		if ( array() !== $props ) {
			$existing = isset( $markup['additionalProperty'] ) && is_array( $markup['additionalProperty'] )
				? $markup['additionalProperty']
				: array();
			$markup['additionalProperty'] = array_merge( $existing, $props );
		}

		// Clean empty description fallback so Google Rich Results never flags a missing description.
		if ( empty( $markup['description'] ) && $pid > 0 ) {
			$custom_desc = trim( (string) get_post_meta( $pid, HeadRenderer::META_DESC, true ) );
			if ( '' !== $custom_desc ) {
				$markup['description'] = $custom_desc;
			}
		}

		return $markup;
	}

	/**
	 * Resolves brand name from `product_brand` taxonomy or `pa_marca` attribute.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function resolve_brand( \WC_Product $product ): string {
		if ( function_exists( 'get_the_terms' ) ) {
			$terms = get_the_terms( (int) $product->get_id(), 'product_brand' );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( $term instanceof \WP_Term && '' !== trim( $term->name ) ) {
						return trim( $term->name );
					}
				}
			}
		}
		if ( method_exists( $product, 'get_attribute' ) ) {
			$attr = trim( (string) $product->get_attribute( 'pa_marca' ) );
			if ( '' !== $attr ) {
				return $attr;
			}
		}
		return '';
	}

	/**
	 * Collects main image + up to 5 gallery image URLs (`large` size).
	 *
	 * @param \WC_Product $product Product.
	 * @return list<string>
	 */
	public static function product_images( \WC_Product $product ): array {
		if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return array();
		}
		$ids  = array();
		$main = (int) $product->get_image_id();
		if ( $main > 0 ) {
			$ids[] = $main;
		}
		if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
			foreach ( array_slice( (array) $product->get_gallery_image_ids(), 0, 5 ) as $gid ) {
				$gid = (int) $gid;
				if ( $gid > 0 && ! in_array( $gid, $ids, true ) ) {
					$ids[] = $gid;
				}
			}
		}
		$urls = array();
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );
			if ( is_string( $url ) && '' !== $url ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * Builds MerchantReturnPolicy JSON-LD node when `return_days > 0`.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function return_policy_node(): ?array {
		$days = Config::return_days();
		if ( $days < 1 ) {
			return null;
		}
		return array(
			'@type'                => 'MerchantReturnPolicy',
			'applicableCountry'    => Config::org_country(),
			'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
			'merchantReturnDays'   => $days,
			'returnMethod'         => 'https://schema.org/ReturnByMail',
			'returnFees'           => 'https://schema.org/FreeReturn',
		);
	}

	/**
	 * Builds OfferShippingDetails JSON-LD node when a flat/free shipping cost is configured.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function shipping_details_node(): ?array {
		$cost = Config::shipping_cost();
		if ( '' === $cost ) {
			return null;
		}
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'ARS';
		return array(
			'@type'               => 'OfferShippingDetails',
			'shippingRate'        => array(
				'@type'    => 'MonetaryAmount',
				'value'    => number_format( (float) $cost, 2, '.', '' ),
				'currency' => $currency,
			),
			'shippingDestination' => array(
				'@type'          => 'DefinedRegion',
				'addressCountry' => Config::org_country(),
			),
			'deliveryTime'        => array(
				'@type'        => 'ShippingDeliveryTime',
				'handlingTime' => array(
					'@type'    => 'QuantitativeValue',
					'minValue' => 0,
					'maxValue' => Config::shipping_handling_days(),
					'unitCode' => 'DAY',
				),
				'transitTime'  => array(
					'@type'    => 'QuantitativeValue',
					'minValue' => 1,
					'maxValue' => Config::shipping_transit_days(),
					'unitCode' => 'DAY',
				),
			),
		);
	}

	/**
	 * Adds `itemCondition`, `seller`, `hasMerchantReturnPolicy` and `shippingDetails` to single or list Offer nodes.
	 *
	 * @param array<int|string,mixed>   $offers   Offers payload.
	 * @param array<string,string>      $seller   Organization seller node.
	 * @param array<string,mixed>|null  $policy   MerchantReturnPolicy node or null.
	 * @param array<string,mixed>|null  $shipping OfferShippingDetails node or null.
	 * @return array<int|string,mixed>
	 */
	private static function enrich_offers( array $offers, array $seller, ?array $policy, ?array $shipping ): array {
		$apply = static function ( array $offer ) use ( $seller, $policy, $shipping ): array {
			$offer['itemCondition'] = $offer['itemCondition'] ?? 'https://schema.org/NewCondition';
			if ( empty( $offer['seller'] ) && '' !== $seller['name'] ) {
				$offer['seller'] = $seller;
			}
			if ( null !== $policy && empty( $offer['hasMerchantReturnPolicy'] ) ) {
				$offer['hasMerchantReturnPolicy'] = $policy;
			}
			if ( null !== $shipping && empty( $offer['shippingDetails'] ) ) {
				$offer['shippingDetails'] = $shipping;
			}
			return $offer;
		};

		if ( isset( $offers['@type'] ) ) {
			return $apply( $offers );
		}
		foreach ( $offers as $i => $offer ) {
			if ( is_array( $offer ) && isset( $offer['@type'] ) ) {
				$offers[ $i ] = $apply( $offer );
			}
		}
		return $offers;
	}

	/**
	 * Builds schema.org PropertyValue items from visible product attributes (tire size, position, etc.).
	 *
	 * @param \WC_Product $product Product.
	 * @return list<array<string,string>>
	 */
	private static function tire_properties( \WC_Product $product ): array {
		if ( ! method_exists( $product, 'get_attributes' ) || ! method_exists( $product, 'get_attribute' ) ) {
			return array();
		}
		$props = array();
		foreach ( (array) $product->get_attributes() as $slug => $attribute ) {
			if ( ! is_object( $attribute ) || ( method_exists( $attribute, 'get_visible' ) && ! $attribute->get_visible() ) ) {
				continue;
			}
			$name  = function_exists( 'wc_attribute_label' ) ? trim( (string) wc_attribute_label( (string) $slug, $product ) ) : (string) $slug;
			$value = trim( (string) $product->get_attribute( (string) $slug ) );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$props[] = array(
				'@type' => 'PropertyValue',
				'name'  => $name,
				'value' => $value,
			);
			if ( count( $props ) >= 10 ) {
				break;
			}
		}
		return $props;
	}
}
