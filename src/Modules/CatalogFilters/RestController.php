<?php
/**
 * Public REST endpoints for CatalogFilters: versioned size combinations JSON and smart search suggestions.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Modules\Compatibility\CatalogRepository;
use Motored\Core\Modules\Compatibility\SizeParser;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Support\SearchText;

defined( 'ABSPATH' ) || exit;

/**
 * Serves cache-friendly public GET endpoints without running LIKE queries against wp_posts.
 */
final class RestController {

	public const NAMESPACE       = 'motored/v1';
	public const MAX_SUGGESTIONS = 8;

	/**
	 * Registers REST routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declares GET /filters/sizes and GET /filters/suggest.
	 */
	public function register_routes(): void {
		if ( ! Config::enabled() ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			'/filters/sizes/(?P<ver>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_sizes' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/filters/sizes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_sizes' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/filters/suggest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_suggestions' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/filters/trending',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_trending' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Public GET /motored/v1/filters/trending — cached top sizes, brands and best-selling tires
	 * for the RevZilla-style search focus dropdown (lazy-loaded on first focus, 0 cost on page load).
	 */
	public function get_trending(): \WP_REST_Response|\WP_Error {
		$ver       = SnapshotInvalidator::version();
		$cache_key = 'motored_search_trending2_' . md5( $ver . '|' . ( Config::only_in_stock() ? '1' : '0' ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return self::public_response( $cached, 300, 600 );
		}

		$shop_url = Config::shop_url();
		$sizes    = array();
		$brands   = array();

		try {
			$snapshot = FacetEngine::snapshot();
			$combos   = FacetEngine::size_combinations( $snapshot, Config::only_in_stock() );
			usort(
				$combos,
				static fn( array $a, array $b ): int => ( (int) ( $b['c'] ?? 0 ) ) <=> ( (int) ( $a['c'] ?? 0 ) )
			);
			foreach ( array_slice( $combos, 0, 6 ) as $c ) {
				$w_lbl = (string) ( $c['wl'] ?? $c['w'] ?? '' );
				$p_lbl = (string) ( $c['pl'] ?? $c['p'] ?? '' );
				$r_lbl = (string) ( $c['rl'] ?? $c['r'] ?? '' );
				if ( '' === $w_lbl || '' === $r_lbl ) {
					continue;
				}
				$fallback_lbl = '' !== $p_lbl ? $w_lbl . '/' . $p_lbl . '-' . $r_lbl : $w_lbl . '-' . $r_lbl;
				$top_variant  = ! empty( $c['d'][0] ) && is_array( $c['d'][0] ) ? $c['d'][0] : null;
				$label        = null !== $top_variant && ! empty( $top_variant['l'] ) ? (string) $top_variant['l'] : $fallback_lbl;
				$query        = array(
					'filter_' . TireSpecs::ATTR_WIDTH => (string) $c['w'],
					'filter_' . TireSpecs::ATTR_RIM   => (string) $c['r'],
				);
				if ( ! empty( $c['p'] ) ) {
					$query[ 'filter_' . TireSpecs::ATTR_PROFILE ] = (string) $c['p'];
				}
				if ( null !== $top_variant && ! empty( $top_variant['k'] ) && count( $c['d'] ?? array() ) > 1 ) {
					$query[ 'filter_' . TireSpecs::ATTR_CONSTRUCTION ] = (string) $top_variant['k'];
				}
				$sizes[] = array(
					'label' => $label,
					'count' => (int) ( $c['c'] ?? 0 ),
					'url'   => add_query_arg( $query, $shop_url ),
				);
			}

			$brand_counts = array();
			$brand_labels = $snapshot['labels']['product_brand'] ?? array();
			foreach ( ( $snapshot['products'] ?? array() ) as $prod ) {
				if ( Config::only_in_stock() && empty( $prod['in_stock'] ) ) {
					continue;
				}
				foreach ( ( $prod['brands'] ?? array() ) as $slug ) {
					$brand_counts[ $slug ] = ( $brand_counts[ $slug ] ?? 0 ) + 1;
				}
			}
			arsort( $brand_counts );
			foreach ( array_slice( $brand_counts, 0, 5, true ) as $slug => $cnt ) {
				$brands[] = array(
					'label' => (string) ( $brand_labels[ $slug ] ?? ucfirst( (string) $slug ) ),
					'count' => (int) $cnt,
					'url'   => add_query_arg( 'product_brand', (string) $slug, $shop_url ),
				);
			}
		} catch ( \RuntimeException $exception ) {
			// Continue so products can still be returned even if snapshot is warming.
		}

		$products = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$wc_args = array(
				'status'       => 'publish',
				'visibility'   => 'catalog',
				'limit'        => 4,
				'stock_status' => Config::only_in_stock() ? 'instock' : null,
				'meta_key'     => 'total_sales',
				'orderby'      => 'meta_value_num',
				'order'        => 'DESC',
			);
			$found   = wc_get_products( array_filter( $wc_args, static fn( $v ) => null !== $v ) );
			if ( empty( $found ) ) {
				unset( $wc_args['meta_key'] );
				$wc_args['orderby'] = 'date';
				$found              = wc_get_products( array_filter( $wc_args, static fn( $v ) => null !== $v ) );
			}
			foreach ( $found as $p ) {
				if ( ! $p instanceof \WC_Product ) {
					continue;
				}
				$img_id     = $p->get_image_id();
				$img        = $img_id ? (string) wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : ( function_exists( 'wc_placeholder_img_src' ) ? (string) wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '' );
				$products[] = array(
					'title' => $p->get_name(),
					'url'   => (string) get_permalink( $p->get_id() ),
					'price' => html_entity_decode( wp_strip_all_tags( (string) wc_price( (float) wc_get_price_to_display( $p ) ) ), ENT_QUOTES, 'UTF-8' ),
					'img'   => $img,
				);
			}
		}

		$payload = array(
			'sizes'    => $sizes,
			'brands'   => $brands,
			'products' => $products,
		);
		set_transient( $cache_key, $payload, 10 * MINUTE_IN_SECONDS );

		return self::public_response( $payload, 300, 600 );
	}

	/**
	 * Public GET /motored/v1/filters/sizes — compact versioned Ancho/Perfil/Rodado cascade JSON.
	 */
	public function get_sizes(): \WP_REST_Response|\WP_Error {
		try {
			$snapshot = FacetEngine::snapshot();
		} catch ( \RuntimeException $exception ) {
			return self::snapshot_error();
		}
		$combos = FacetEngine::size_combinations( $snapshot, Config::only_in_stock() );
		return self::public_response(
			array(
				'version' => SnapshotInvalidator::version(),
				'sizes'   => $combos,
			),
			300,
			600
		);
	}

	/**
	 * Public GET /motored/v1/filters/suggest?q= — smart search returning suggestions + live product matches.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_suggestions( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$raw_q = (string) $request->get_param( 'q' );
		$q     = self::normalize_query( $raw_q );
		try {
			$snap     = FacetEngine::snapshot();
			$items    = self::build_suggestions( $raw_q, Config::shop_url(), Config::only_in_stock() );
			$products = strlen( $q ) >= 2 ? self::search_products( $raw_q, $snap, Config::only_in_stock(), 4 ) : array();
		} catch ( \RuntimeException $exception ) {
			return self::snapshot_error();
		}
		return self::public_response(
			array(
				'q'          => $q,
				'items'      => $items,
				'products'   => $products,
				'search_url' => add_query_arg(
					array(
						's'         => trim( $raw_q ),
						'post_type' => 'product',
					),
					home_url( '/' )
				),
			),
			60,
			300
		);
	}

	/**
	 * Reports temporary catalog failure without caching an empty result.
	 */
	private static function snapshot_error(): \WP_Error {
		return new \WP_Error( 'motored_catalog_unavailable', __( 'Action could not be completed.', 'motored-core' ), array( 'status' => 503 ) );
	}

	/**
	 * Pure smart-search matcher over cached sizes, brands and published motorcycle versions.
	 *
	 * @param string                                                                                   $raw_q         Raw user query.
	 * @param string                                                                                   $shop_url      Base catalog URL.
	 * @param bool                                                                                     $only_in_stock Filter to in-stock products.
	 * @param array{products:list<array<string,mixed>>,labels:array<string,array<string,string>>}|null $snapshot      Optional preloaded snapshot (tests).
	 * @param list<array{slug:string,label:string,haystack:string}>|null                               $bikes         Optional preloaded bikes index (tests).
	 * @return list<array{type:string,label:string,meta:string,url:string,slug?:string,exact?:bool}>
	 */
	public static function build_suggestions(
		string $raw_q,
		string $shop_url,
		bool $only_in_stock = true,
		?array $snapshot = null,
		?array $bikes = null
	): array {
		$q = self::normalize_query( $raw_q );
		if ( strlen( $q ) < 2 ) {
			return array();
		}

		$snap    = null !== $snapshot ? $snapshot : FacetEngine::snapshot();
		$combos  = preg_match( '/\d/', $q ) ? FacetEngine::size_combinations( $snap, $only_in_stock ) : array();
		$results = array();
		$seen    = array();

		// 1. Exact or parsed tire sizes (supports single "120/70ZR17", "120/70 17" AND kits "160/60 17 + 120/70 17").
		$parsed_list = SizeParser::parse_all( $raw_q );
		$pq          = ProductSearch::analyze_query( $raw_q );
		$real_kits   = null === $snapshot ? ProductSearch::kit_suggestions( $raw_q, $shop_url, $only_in_stock ) : array();

		// When the shopper clearly searches for a kit/pair (2 sizes, "+ 120", "kit", "combo"), put real matching kits first.
		if ( $pq['wants_kit'] ) {
			foreach ( $real_kits as $kit_item ) {
				$k = 'kit:' . $kit_item['label'];
				if ( ! isset( $seen[ $k ] ) ) {
					$seen[ $k ] = true;
					$results[]  = $kit_item;
				}
			}
		}

		// Fallback synthetic kit suggestion when 2+ sizes were typed and no real kit matched in stock yet.
		if ( count( $parsed_list ) >= 2 && empty( $results ) ) {
			$kit_labels = array_map( static fn( array $p ): string => (string) ( $p['designation'] ?? $p['canonical'] ), array_slice( $parsed_list, 0, 2 ) );
			$kit_label  = sprintf(
				/* translators: 1: first tire size, 2: second tire size. */
				__( 'Kit %1$s + %2$s', 'motored-core' ),
				$kit_labels[0],
				$kit_labels[1]
			);
			$kit_url    = add_query_arg(
				array(
					's'         => trim( $raw_q ),
					'post_type' => 'product',
				),
				home_url( '/' )
			);
			$seen[ 'kit:' . $kit_label ] = true;
			$results[]                   = array(
				'type'  => 'kit',
				'label' => $kit_label,
				'meta'  => __( 'Complete kit', 'motored-core' ),
				'url'   => $kit_url,
			);
		}

		$is_single_size = 1 === count( $parsed_list ) && ! $pq['wants_kit'];
		foreach ( $parsed_list as $parsed ) {
			$w_slug        = FilterState::sanitize_term_slug( $parsed['width'] );
			$p_slug        = null !== $parsed['aspect'] ? (string) $parsed['aspect'] : null;
			$r_slug        = (string) $parsed['rim'];
			$typed_desig   = (string) ( $parsed['designation'] ?? $parsed['canonical'] );
			$typed_const   = (string) ( $parsed['construction'] ?? '' );
			$matched_combo = false;
			foreach ( $combos as $c ) {
				if ( $c['w'] === $w_slug && $c['r'] === $r_slug && ( null === $p_slug || $c['p'] === $p_slug ) ) {
					$matched_combo = true;
					$variants      = self::combo_variants_for_suggest( $c, $typed_desig, $typed_const );
					$multi_const   = count( $variants ) > 1;
					foreach ( $variants as $idx => $v ) {
						$args = array(
							'filter_' . TireSpecs::ATTR_WIDTH      => $c['w'],
							'query_type_' . TireSpecs::ATTR_WIDTH  => 'or',
							'filter_' . TireSpecs::ATTR_RIM        => $c['r'],
							'query_type_' . TireSpecs::ATTR_RIM    => 'or',
						);
						if ( null !== $c['p'] && '' !== $c['p'] ) {
							$args[ 'filter_' . TireSpecs::ATTR_PROFILE ]     = $c['p'];
							$args[ 'query_type_' . TireSpecs::ATTR_PROFILE ] = 'or';
						}
						if ( '' !== $v['k'] && ( $multi_const || '' !== $typed_const ) ) {
							$args[ 'filter_' . TireSpecs::ATTR_CONSTRUCTION ]     = $v['k'];
							$args[ 'query_type_' . TireSpecs::ATTR_CONSTRUCTION ] = 'or';
						}
						$label = $v['l'];
						$url   = add_query_arg( $args, $shop_url );
						$key   = 'size:' . $label;
						if ( ! isset( $seen[ $key ] ) ) {
							$seen[ $key ] = true;
							$results[]    = array(
								'type'  => 'size',
								'exact' => $is_single_size && 0 === $idx && ( ! $multi_const || '' !== $typed_const || $label === $typed_desig ),
								'label' => $label,
								'meta'  => self::size_suggest_meta( $v['k'], $v['c'] ),
								'url'   => $url,
							);
						}
					}
				}
			}
			// Fallback: if parsed size wasn't in attribute combos (e.g. kit-only size), still offer a direct search suggestion.
			if ( ! $matched_combo && $is_single_size ) {
				$key = 'size:' . $typed_desig;
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$results[]    = array(
						'type'  => 'size',
						'label' => $typed_desig,
						'meta'  => __( 'Search', 'motored-core' ),
						'url'   => add_query_arg(
							array(
								's'         => $typed_desig,
								'post_type' => 'product',
							),
							home_url( '/' )
						),
					);
				}
			}
		}

		// When searching a single size or partial size, also suggest any in-stock Kits containing that size.
		if ( ! $pq['wants_kit'] && ! empty( $real_kits ) ) {
			foreach ( $real_kits as $kit_item ) {
				if ( count( $results ) >= self::MAX_SUGGESTIONS ) {
					break;
				}
				$k = 'kit:' . $kit_item['label'];
				if ( ! isset( $seen[ $k ] ) ) {
					$seen[ $k ] = true;
					$results[]  = $kit_item;
				}
			}
		}

		// 2. Match explicit rim diameter queries such as rodado 17 or r17.
		if ( 1 === preg_match( '/^(?:rodado|aro|r)\s*(\d{1,2})$/', $q, $rm ) ) {
			$rim_slug = (string) (int) $rm[1];
			$rim_cnt  = 0;
			foreach ( $combos as $c ) {
				if ( $c['r'] === $rim_slug ) {
					$rim_cnt += $c['c'];
				}
			}
			if ( $rim_cnt > 0 ) {
				$results[] = array(
					'type'  => 'rim',
					'exact' => true,
					/* translators: %s: rim diameter in inches. */
					'label' => sprintf( __( 'Rim %s', 'motored-core' ), $rim_slug ),
					/* translators: %d: number of available tires for this rim. */
					'meta'  => sprintf( __( '%d tires', 'motored-core' ), $rim_cnt ),
					'url'   => add_query_arg(
						array(
							'filter_' . TireSpecs::ATTR_RIM => $rim_slug,
							'query_type_' . TireSpecs::ATTR_RIM => 'or',
						),
						$shop_url
					),
				);
			}
		}

		// 3. Partial size prefix match in cached combos (e.g. "120/70", "120/70zr", "120 70", "90/90", "4.10").
		if ( count( $results ) < self::MAX_SUGGESTIONS && 1 === preg_match( '/^\d/', $q ) ) {
			$q_digits    = (string) preg_replace( '/[^0-9.,]+/', '-', str_replace( ',', '.', $q ) );
			$q_digits    = trim( $q_digits, '-' );
			$partial_con = '';
			if ( 1 === preg_match( '/\d\s*(zr|vr|r|vb|b)\b/i', $q, $pm ) ) {
				$marker      = strtoupper( $pm[1] );
				$partial_con = in_array( $marker, array( 'B', 'VB' ), true ) ? TireSpecs::CONST_BELTED : TireSpecs::CONST_RADIAL;
			}
			foreach ( $combos as $c ) {
				if ( count( $results ) >= self::MAX_SUGGESTIONS ) {
					break;
				}
				$base_label   = '' !== $c['pl'] ? $c['wl'] . '/' . $c['pl'] . '-' . $c['rl'] : $c['wl'] . '-' . $c['rl'];
				$label_digits = (string) preg_replace( '/[^0-9.]+/', '-', strtolower( $base_label ) );
				if ( '' === $q_digits || ! str_contains( $label_digits, $q_digits ) ) {
					continue;
				}
				$variants    = self::combo_variants_for_suggest( $c, '', $partial_con );
				$multi_const = count( $variants ) > 1;
				foreach ( $variants as $v ) {
					if ( count( $results ) >= self::MAX_SUGGESTIONS ) {
						break;
					}
					$label = $v['l'];
					$key   = 'size:' . $label;
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
					$args         = array(
						'filter_' . TireSpecs::ATTR_WIDTH     => $c['w'],
						'query_type_' . TireSpecs::ATTR_WIDTH => 'or',
						'filter_' . TireSpecs::ATTR_RIM       => $c['r'],
						'query_type_' . TireSpecs::ATTR_RIM   => 'or',
					);
					if ( null !== $c['p'] && '' !== $c['p'] ) {
						$args[ 'filter_' . TireSpecs::ATTR_PROFILE ]     = $c['p'];
						$args[ 'query_type_' . TireSpecs::ATTR_PROFILE ] = 'or';
					}
					if ( '' !== $v['k'] && ( $multi_const || '' !== $partial_con ) ) {
						$args[ 'filter_' . TireSpecs::ATTR_CONSTRUCTION ]     = $v['k'];
						$args[ 'query_type_' . TireSpecs::ATTR_CONSTRUCTION ] = 'or';
					}
					$results[] = array(
						'type'  => 'size',
						'label' => $label,
						'meta'  => self::size_suggest_meta( $v['k'], $v['c'] ),
						'url'   => add_query_arg( $args, $shop_url ),
					);
				}
			}
		}

		// 4. Published motorcycles (brands, models, aliases, years) from cached array.
		if ( count( $results ) < self::MAX_SUGGESTIONS ) {
			$bike_list = null !== $bikes ? $bikes : CatalogRepository::published_bike_search_index();
			foreach ( $bike_list as $bk ) {
				if ( count( $results ) >= self::MAX_SUGGESTIONS ) {
					break;
				}
				// Accent/spacing insensitive: "cb 500", "CB-500" and "cb500" all match.
				if ( SearchText::matches( $bk['haystack'], $raw_q ) ) {
					$results[] = array(
						'type'  => 'moto',
						'label' => $bk['label'],
						'meta'  => __( 'Motorcycle', 'motored-core' ),
						'url'   => add_query_arg( array( 'moto' => $bk['slug'] ), $shop_url ),
						'slug'  => $bk['slug'],
					);
				}
			}
		}

		// 5. Tire brands (product_brand) from snapshot labels + counts.
		if ( count( $results ) < self::MAX_SUGGESTIONS ) {
			$brand_counts = array();
			foreach ( $snap['products'] as $p ) {
				if ( $only_in_stock && empty( $p['in_stock'] ) ) {
					continue;
				}
				foreach ( $p['brands'] as $bs ) {
					$brand_counts[ $bs ] = ( $brand_counts[ $bs ] ?? 0 ) + 1;
				}
			}
			foreach ( $brand_counts as $b_slug => $cnt ) {
				if ( count( $results ) >= self::MAX_SUGGESTIONS ) {
					break;
				}
				$b_label = (string) ( $snap['labels']['product_brand'][ $b_slug ] ?? $b_slug );
				if ( SearchText::matches( SearchText::haystack( $b_label . ' ' . (string) $b_slug ), $raw_q ) ) {
					$results[] = array(
						'type'  => 'brand',
						'label' => $b_label,
						/* translators: %d: number of available tires for this brand. */
						'meta'  => sprintf( __( 'Brand (%d)', 'motored-core' ), $cnt ),
						'url'   => add_query_arg( array( 'product_brand' => (string) $b_slug ), $shop_url ),
					);
				}
			}
		}

		return array_slice( $results, 0, self::MAX_SUGGESTIONS );
	}

	/**
	 * Normalizes a search query (lowercase, trimmed, collapsed spaces).
	 *
	 * @param string $raw Raw input.
	 */
	public static function normalize_query( string $raw ): string {
		return SearchText::normalize( $raw );
	}

	/**
	 * Resolves the size designation variants of a combo for Smart Search suggestions (preserving R / ZR / B / -).
	 *
	 * @param array<string,mixed> $combo             Combo from FacetEngine::size_combinations().
	 * @param string              $typed_designation Printed designation typed by the shopper (e.g. "120/70ZR17").
	 * @param string              $typed_const       Construction implied by the shopper query ('radial'|'cinturado'|'diagonal'|'').
	 * @return list<array{l:string,k:string,c:int}>
	 */
	private static function combo_variants_for_suggest( array $combo, string $typed_designation = '', string $typed_const = '' ): array {
		$base_label = '' !== (string) ( $combo['pl'] ?? '' )
			? (string) $combo['wl'] . '/' . (string) $combo['pl'] . '-' . (string) $combo['rl']
			: (string) $combo['wl'] . '-' . (string) $combo['rl'];
		$variants   = ! empty( $combo['d'] ) && is_array( $combo['d'] )
			? $combo['d']
			: array(
				array(
					'l' => $base_label,
					'k' => '',
					'c' => (int) ( $combo['c'] ?? 0 ),
				),
			);

		if ( '' !== $typed_const ) {
			// If the catalog products lack a construction attribute (only untagged "-" variant exists), adopt the shopper's typed designation label.
			if ( 1 === count( $variants ) && '' === $variants[0]['k'] && '' !== $typed_designation ) {
				$variants[0]['l'] = $typed_designation;
			} else {
				usort(
					$variants,
					static function ( array $a, array $b ) use ( $typed_designation, $typed_const ): int {
						$a_exact = ( '' !== $typed_designation && $a['l'] === $typed_designation ) ? 2 : ( $a['k'] === $typed_const ? 1 : 0 );
						$b_exact = ( '' !== $typed_designation && $b['l'] === $typed_designation ) ? 2 : ( $b['k'] === $typed_const ? 1 : 0 );
						return ( $b_exact <=> $a_exact ) ?: ( $b['c'] <=> $a['c'] );
					}
				);
			}
		}

		return $variants;
	}

	/**
	 * Formats the right-hand meta label for a size suggestion, including the construction when known.
	 *
	 * @param string $const Normalized construction ('radial'|'diagonal'|'cinturado'|'').
	 * @param int    $count Product count.
	 */
	private static function size_suggest_meta( string $const, int $count ): string {
		$fixed = \Motored\Core\Modules\Compatibility\AttributeInstaller::fixed_term_sets();
		if ( '' !== $const && isset( $fixed[ TireSpecs::ATTR_CONSTRUCTION ][ $const ] ) ) {
			return (string) $fixed[ TireSpecs::ATTR_CONSTRUCTION ][ $const ] . ' (' . $count . ')';
		}
		/* translators: %d: number of available tires for this size. */
		return sprintf( __( 'Size (%d)', 'motored-core' ), $count );
	}

	/**
	 * Fast live product search for the smart-search mega-dropdown (supports single sizes, kits/pairs, brands and titles).
	 *
	 * @param string                                                                              $raw_q         Raw user query.
	 * @param array{products:list<array<string,mixed>>,labels:array<string,array<string,string>>} $snap          Catalog snapshot.
	 * @param bool                                                                                $only_in_stock Filter to in-stock products.
	 * @param int                                                                                 $limit         Max products to return.
	 * @return list<array{id:int,title:string,url:string,price:string,img:string}>
	 */
	public static function search_products( string $raw_q, array $snap, bool $only_in_stock = true, int $limit = 4 ): array {
		$q = self::normalize_query( $raw_q );
		if ( strlen( $q ) < 2 || ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$ver       = SnapshotInvalidator::version();
		$cache_key = 'motored_search_prods3_' . md5( $ver . '|' . ( $only_in_stock ? '1' : '0' ) . '|' . $q . '|' . $limit );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Unified broad search across titles, SKUs, descriptions, technical attributes, kits and compatible bikes.
		$ids = ProductSearch::search( $raw_q, $only_in_stock, $limit );

		$products = array();
		foreach ( $ids as $pid ) {
			$p = wc_get_product( $pid );
			if ( ! $p instanceof \WC_Product ) {
				continue;
			}
			$img_id     = $p->get_image_id();
			$img        = $img_id ? (string) wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : ( function_exists( 'wc_placeholder_img_src' ) ? (string) wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '' );
			$products[] = array(
				'id'    => $p->get_id(),
				'title' => $p->get_name(),
				'url'   => (string) get_permalink( $p->get_id() ),
				'price' => html_entity_decode( wp_strip_all_tags( (string) wc_price( (float) wc_get_price_to_display( $p ) ) ), ENT_QUOTES, 'UTF-8' ),
				'img'   => $img,
			);
		}

		set_transient( $cache_key, $products, 60 );
		return $products;
	}

	/**
	 * Builds a public HTTP-cacheable REST response with ETag.
	 *
	 * @param array<string,mixed> $data   Payload.
	 * @param int                 $max_age Max-age seconds.
	 * @param int                 $swr    Stale-while-revalidate seconds.
	 */
	private static function public_response( array $data, int $max_age, int $swr ): \WP_REST_Response {
		$res  = new \WP_REST_Response( $data, 200 );
		$etag = '"' . substr( md5( (string) wp_json_encode( $data ) ), 0, 16 ) . '"';
		$res->header( 'Cache-Control', 'public, max-age=' . $max_age . ', stale-while-revalidate=' . $swr );
		$res->header( 'ETag', $etag );
		return $res;
	}
}
