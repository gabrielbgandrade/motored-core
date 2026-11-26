<?php
/**
 * Native Google Merchant Center RSS/XML product feed (Redis-cached, zero third-party plugins).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes `/feed/motored-merchant/` (and `/?feed=motored-merchant`) in Google Shopping RSS 2.0 XML format
 * (`xmlns:g="http://base.google.com/ns/1.0"`). Cached in Redis via Support\Cache; works cleanly without Redis.
 */
final class MerchantFeed {

	public const FEED_SLUG       = 'motored-merchant';
	public const CACHE_BUCKET    = 'seo_merchant_feed';
	public const REWRITE_VER_OPT = 'motored_seo_feed_rewrite_ver';
	private const REWRITE_VER    = '1';
	private const CACHE_TTL      = 1800;
	private const MAX_PRODUCTS   = 500;

	/**
	 * Registers the WordPress feed endpoint (safe on init; zero DB queries here).
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_feed' ) );
	}

	/**
	 * Registers the `motored-merchant` feed with WordPress core.
	 */
	public function register_feed(): void {
		if ( ! Config::merchant_feed_enabled() ) {
			return;
		}
		if ( function_exists( 'add_feed' ) ) {
			add_feed( self::FEED_SLUG, array( $this, 'render' ) );
		}
	}

	/**
	 * One-time admin_init rewrite flush so `/feed/motored-merchant/` resolves immediately without manual permalink save.
	 */
	public static function maybe_flush_rewrites(): void {
		if ( ! Config::merchant_feed_enabled() ) {
			return;
		}
		if ( self::REWRITE_VER === (string) get_option( self::REWRITE_VER_OPT, '' ) ) {
			return;
		}
		if ( function_exists( 'add_feed' ) ) {
			add_feed( self::FEED_SLUG, array( new self(), 'render' ) );
		}
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		update_option( self::REWRITE_VER_OPT, self::REWRITE_VER, false );
	}

	/**
	 * Invalidates the cached XML feed (called on product save/stock change/settings update).
	 */
	public static function invalidate_cache(): void {
		if ( function_exists( 'wp_using_ext_object_cache' ) ) {
			Cache::bump( self::CACHE_BUCKET );
		}
	}

	/**
	 * Public URL of the Google Merchant Center feed.
	 */
	public static function feed_url(): string {
		if ( function_exists( 'get_feed_link' ) ) {
			$url = get_feed_link( self::FEED_SLUG );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return add_query_arg( 'feed', self::FEED_SLUG, home_url( '/' ) );
	}

	/**
	 * Outputs the XML feed with proper headers (cached in Redis when persistent object cache is active).
	 */
	public function render(): void {
		if ( ! Config::merchant_feed_enabled() ) {
			if ( function_exists( 'status_header' ) ) {
				status_header( 404 );
			}
			return;
		}

		$producer = fn(): string => $this->generate_xml();
		if ( function_exists( 'wp_using_ext_object_cache' ) && Cache::is_persistent() ) {
			$xml = Cache::remember( 'xml:v1', $producer, self::CACHE_TTL, self::CACHE_BUCKET );
			$xml = is_string( $xml ) ? $xml : $producer();
		} else {
			$xml = $producer();
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8', true );
			header( 'X-Robots-Tag: noindex, follow', true );
			header( 'Cache-Control: public, max-age=' . self::CACHE_TTL, true );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped XML document built via build_xml().
		echo $xml;
	}

	/**
	 * Builds the RSS 2.0 + Google Merchant XML string from normalized item arrays (pure, unit-testable).
	 *
	 * @param list<array<string,mixed>> $items     Normalized product feed entries.
	 * @param string                    $site_name Store name.
	 * @param string                    $site_url  Store home URL.
	 * @param string                    $site_desc Store description.
	 */
	public static function build_xml( array $items, string $site_name, string $site_url, string $site_desc = '' ): string {
		$out   = array();
		$out[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$out[] = '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">';
		$out[] = '<channel>';
		$out[] = '<title>' . self::xml_text( $site_name ) . '</title>';
		$out[] = '<link>' . self::xml_text( $site_url ) . '</link>';
		$out[] = '<description>' . self::xml_text( '' !== $site_desc ? $site_desc : $site_name ) . '</description>';

		foreach ( $items as $item ) {
			$id    = (string) ( $item['id'] ?? '' );
			$title = (string) ( $item['title'] ?? '' );
			$link  = (string) ( $item['link'] ?? '' );
			$img   = (string) ( $item['image_link'] ?? '' );
			$price = (string) ( $item['price'] ?? '' );
			if ( '' === $id || '' === $title || '' === $link || '' === $img || '' === $price ) {
				continue;
			}

			$out[] = '<item>';
			$out[] = '<g:id>' . self::xml_text( $id ) . '</g:id>';
			if ( ! empty( $item['item_group_id'] ) ) {
				$out[] = '<g:item_group_id>' . self::xml_text( (string) $item['item_group_id'] ) . '</g:item_group_id>';
			}
			$out[] = '<g:title>' . self::xml_text( self::cut( $title, 150 ) ) . '</g:title>';
			$out[] = '<g:description>' . self::xml_text( self::cut( (string) ( $item['description'] ?? $title ), 5000 ) ) . '</g:description>';
			$out[] = '<g:link>' . self::xml_text( $link ) . '</g:link>';
			$out[] = '<g:image_link>' . self::xml_text( $img ) . '</g:image_link>';

			if ( ! empty( $item['additional_images'] ) && is_array( $item['additional_images'] ) ) {
				foreach ( array_slice( $item['additional_images'], 0, 5 ) as $extra_img ) {
					if ( is_string( $extra_img ) && '' !== $extra_img ) {
						$out[] = '<g:additional_image_link>' . self::xml_text( $extra_img ) . '</g:additional_image_link>';
					}
				}
			}

			$out[] = '<g:availability>' . self::xml_text( (string) ( $item['availability'] ?? 'in_stock' ) ) . '</g:availability>';
			$out[] = '<g:price>' . self::xml_text( $price ) . '</g:price>';
			if ( ! empty( $item['sale_price'] ) ) {
				$out[] = '<g:sale_price>' . self::xml_text( (string) $item['sale_price'] ) . '</g:sale_price>';
			}
			$out[] = '<g:condition>new</g:condition>';

			$brand = (string) ( $item['brand'] ?? '' );
			if ( '' !== $brand ) {
				$out[] = '<g:brand>' . self::xml_text( self::cut( $brand, 70 ) ) . '</g:brand>';
			}
			$mpn = (string) ( $item['mpn'] ?? '' );
			if ( '' !== $mpn ) {
				$out[] = '<g:mpn>' . self::xml_text( self::cut( $mpn, 70 ) ) . '</g:mpn>';
			} else {
				$out[] = '<g:identifier_exists>no</g:identifier_exists>';
			}

			$gcat = (string) ( $item['google_product_category'] ?? '' );
			if ( '' !== $gcat ) {
				$out[] = '<g:google_product_category>' . self::xml_text( $gcat ) . '</g:google_product_category>';
			}
			$ptype = (string) ( $item['product_type'] ?? '' );
			if ( '' !== $ptype ) {
				$out[] = '<g:product_type>' . self::xml_text( self::cut( $ptype, 750 ) ) . '</g:product_type>';
			}

			if ( isset( $item['shipping_price'] ) && '' !== (string) $item['shipping_price'] ) {
				$out[] = '<g:shipping>';
				$out[] = '<g:country>' . self::xml_text( (string) ( $item['shipping_country'] ?? 'AR' ) ) . '</g:country>';
				$out[] = '<g:price>' . self::xml_text( (string) $item['shipping_price'] ) . '</g:price>';
				$out[] = '</g:shipping>';
			}

			$out[] = '</item>';
		}

		$out[] = '</channel>';
		$out[] = '</rss>';
		return implode( "\n", $out ) . "\n";
	}

	/**
	 * Queries published visible WooCommerce products and compiles the XML payload.
	 */
	private function generate_xml(): string {
		$site_name = Config::org_name();
		$site_url  = home_url( '/' );
		$site_desc = Config::home_description();
		if ( '' === $site_desc && function_exists( 'get_bloginfo' ) ) {
			$site_desc = trim( (string) get_bloginfo( 'description' ) );
		}

		if ( ! function_exists( 'wc_get_products' ) ) {
			return self::build_xml( array(), $site_name, $site_url, $site_desc );
		}

		$currency  = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'ARS';
		$country   = Config::org_country();
		$gcat      = Config::merchant_google_category();
		$ship_cost = Config::shipping_cost();
		$ship_str  = '' !== $ship_cost ? number_format( (float) $ship_cost, 2, '.', '' ) . ' ' . $currency : '';

		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => self::MAX_PRODUCTS,
				'orderby' => 'ID',
				'order'   => 'DESC',
			)
		);

		$items = array();
		foreach ( is_array( $products ) ? $products : array() as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			if ( method_exists( $product, 'get_catalog_visibility' ) && 'hidden' === (string) $product->get_catalog_visibility() ) {
				continue;
			}
			$robots = (string) get_post_meta( (int) $product->get_id(), HeadRenderer::META_ROBOTS, true );
			if ( str_contains( $robots, 'noindex' ) ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_children' ) ) {
				$parent_data = $this->shared_product_meta( $product, $site_name );
				foreach ( (array) $product->get_children() as $child_id ) {
					$var = wc_get_product( (int) $child_id );
					if ( ! $var instanceof \WC_Product || 'publish' !== $var->get_status() ) {
						continue;
					}
					$entry = $this->product_to_item( $var, $currency, $country, $gcat, $ship_str, $parent_data, (int) $product->get_id() );
					if ( null !== $entry ) {
						$items[] = $entry;
					}
				}
				continue;
			}

			$parent_data = $this->shared_product_meta( $product, $site_name );
			$entry       = $this->product_to_item( $product, $currency, $country, $gcat, $ship_str, $parent_data, 0 );
			if ( null !== $entry ) {
				$items[] = $entry;
			}
		}

		return self::build_xml( $items, $site_name, $site_url, $site_desc );
	}

	/**
	 * Extracts shared parent product metadata (brand, category breadcrumb, gallery images, fallback description).
	 *
	 * @param \WC_Product $product   Product.
	 * @param string      $site_name Fallback brand name.
	 * @return array{brand:string,product_type:string,images:list<string>,description:string}
	 */
	private function shared_product_meta( \WC_Product $product, string $site_name ): array {
		$pid   = (int) $product->get_id();
		$brand = ProductSchemaEnhancer::resolve_brand( $product );
		if ( '' === $brand ) {
			$brand = $site_name;
		}

		$ptype = '';
		if ( function_exists( 'get_the_terms' ) ) {
			$terms = get_the_terms( $pid, 'product_cat' );
			if ( is_array( $terms ) ) {
				$names = array();
				foreach ( $terms as $term ) {
					if ( $term instanceof \WP_Term && 'uncategorized' !== $term->slug && 'sin-categorizar' !== $term->slug ) {
						$names[] = trim( wp_strip_all_tags( $term->name ) );
					}
				}
				$ptype = implode( ' > ', array_slice( $names, 0, 3 ) );
			}
		}

		$desc = trim( (string) get_post_meta( $pid, HeadRenderer::META_DESC, true ) );
		if ( '' === $desc ) {
			$desc = self::plain( $product->get_short_description() );
		}
		if ( '' === $desc ) {
			$desc = self::plain( $product->get_description() );
		}
		if ( '' === $desc ) {
			$desc = self::plain( $product->get_name() );
		}

		return array(
			'brand'        => $brand,
			'product_type' => $ptype,
			'images'       => ProductSchemaEnhancer::product_images( $product ),
			'description'  => $desc,
		);
	}

	/**
	 * Converts one product or variation into a Google Merchant feed item array (null when ineligible).
	 *
	 * @param \WC_Product                                                                    $product     Product or variation.
	 * @param string                                                                         $currency    ISO currency code.
	 * @param string                                                                         $country     ISO-2 country code.
	 * @param string                                                                         $gcat        Google product category ID/name.
	 * @param string                                                                         $ship_str    Formatted shipping price or ''.
	 * @param array{brand:string,product_type:string,images:list<string>,description:string} $parent_data Shared parent metadata.
	 * @param int                                                                            $group_id    Parent ID for variations (0 for simple).
	 * @return array<string,mixed>|null
	 */
	private function product_to_item( \WC_Product $product, string $currency, string $country, string $gcat, string $ship_str, array $parent_data, int $group_id ): ?array {
		$raw_price = $product->get_price();
		if ( ! is_numeric( $raw_price ) || (float) $raw_price <= 0 ) {
			return null;
		}
		$regular_raw = $product->get_regular_price();
		$regular     = is_numeric( $regular_raw ) && (float) $regular_raw > 0 ? (float) $regular_raw : (float) $raw_price;
		$sale        = $product->is_on_sale() && (float) $raw_price < $regular ? (float) $raw_price : 0.0;

		$images = $parent_data['images'];
		if ( $group_id > 0 && (int) $product->get_image_id() > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$vimg = wp_get_attachment_image_url( (int) $product->get_image_id(), 'large' );
			if ( is_string( $vimg ) && '' !== $vimg ) {
				array_unshift( $images, $vimg );
				$images = array_values( array_unique( $images ) );
			}
		}
		if ( array() === $images ) {
			return null; // Google Merchant requires a product image.
		}

		$stock_status = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : ( $product->is_in_stock() ? 'instock' : 'outofstock' );
		if ( 'onbackorder' === $stock_status ) {
			$avail = 'backorder';
		} elseif ( 'instock' === $stock_status || $product->is_in_stock() ) {
			$avail = 'in_stock';
		} else {
			$avail = 'out_of_stock';
		}

		$link = (string) get_permalink( (int) $product->get_id() );
		if ( '' === $link ) {
			return null;
		}

		return array(
			'id'                      => (string) $product->get_id(),
			'item_group_id'           => $group_id > 0 ? (string) $group_id : '',
			'title'                   => self::plain( $product->get_name() ),
			'description'             => $parent_data['description'],
			'link'                    => $link,
			'image_link'              => $images[0],
			'additional_images'       => array_slice( $images, 1, 5 ),
			'availability'            => $avail,
			'price'                   => number_format( $regular, 2, '.', '' ) . ' ' . $currency,
			'sale_price'              => $sale > 0 ? number_format( $sale, 2, '.', '' ) . ' ' . $currency : '',
			'brand'                   => $parent_data['brand'],
			'mpn'                     => trim( (string) $product->get_sku() ),
			'google_product_category' => $gcat,
			'product_type'            => $parent_data['product_type'],
			'shipping_country'        => $country,
			'shipping_price'          => $ship_str,
		);
	}

	/**
	 * Strips tags/control characters for clean XML text nodes.
	 *
	 * @param string $raw Raw HTML/text.
	 */
	private static function plain( string $raw ): string {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $raw ) : strip_tags( $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback.
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $text );
		$text = is_string( $text ) ? preg_replace( '/\s+/u', ' ', $text ) : '';
		return is_string( $text ) ? trim( $text ) : '';
	}

	/**
	 * Truncates text safely to Google Merchant's field length limit.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 */
	private static function cut( string $text, int $max ): string {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $max ? rtrim( mb_substr( $text, 0, $max, 'UTF-8' ) ) : $text;
		}
		return strlen( $text ) > $max ? rtrim( substr( $text, 0, $max ) ) : $text;
	}

	/**
	 * Escapes XML special characters (`&`, `<`, `>`, `"`, `'`).
	 *
	 * @param string $value Raw string.
	 */
	private static function xml_text( string $value ): string {
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
