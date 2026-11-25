<?php
/**
 * REST API endpoints for the vehicle cascade selector, product tab lazy-load.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Registers cacheable public catalog endpoints under motored/v1.
 */
final class RestController {

	public const NAMESPACE = 'motored/v1';

	/**
	 * Registers REST routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declares all compatibility routes when Compatibility is enabled.
	 */
	public function register_routes(): void {
		if ( ! Config::enabled() ) {
			return;
		}
		register_rest_route(
			self::NAMESPACE,
			'/compat/brands',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_brands' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/compat/brands/(?P<brand_id>\d+)/models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_models' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/compat/models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_models' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'brand_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $v ): bool => is_numeric( $v ) && (int) $v > 0,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/compat/models/(?P<model_id>\d+)/years',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_years' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/compat/years',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_years' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'model_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $v ): bool => is_numeric( $v ) && (int) $v > 0,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/compat/bike/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_bike' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/compat/product/(?P<id>\d+)/motos',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_product_motos' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Public GET /compat/brands.
	 */
	public function get_brands(): \WP_REST_Response {
		$items = CompatCache::remember(
			'rest_brands',
			static fn(): array => CatalogRepository::brands( true ),
			CompatCache::BUCKET_CATALOG,
			CompatCache::TTL_CATALOG
		);
		return self::public_response( array( 'brands' => $items ) );
	}

	/**
	 * Public GET /compat/models?brand_id=X.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_models( \WP_REST_Request $request ): \WP_REST_Response {
		$brand_id = absint( $request->get_param( 'brand_id' ) );
		if ( $brand_id <= 0 ) {
			return self::public_response( array( 'models' => array() ) );
		}
		$items = CompatCache::remember(
			'rest_models_' . $brand_id,
			static fn(): array => array_map(
				static fn( array $m ): array => array(
					'id'   => $m['id'],
					'name' => $m['name'],
					'slug' => $m['slug'],
				),
				CatalogRepository::models( $brand_id, true )
			),
			CompatCache::BUCKET_CATALOG,
			CompatCache::TTL_CATALOG
		);
		return self::public_response( array( 'models' => $items ) );
	}

	/**
	 * Public GET /compat/years?model_id=X.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_years( \WP_REST_Request $request ): \WP_REST_Response {
		$model_id = absint( $request->get_param( 'model_id' ) );
		if ( $model_id <= 0 ) {
			return self::public_response( array( 'versions' => array() ) );
		}
		$items = CompatCache::remember(
			'rest_years_' . $model_id,
			static function () use ( $model_id ): array {
				$out = array();
				foreach ( CatalogRepository::published_versions_for_model( $model_id ) as $v ) {
					$out[] = array(
						'slug'      => $v['slug'],
						'year_from' => $v['year_from'],
						'year_to'   => $v['year_to'],
						'years'     => MatchEngine::format_years( $v['year_from'], $v['year_to'] ),
						'variant'   => $v['variant'],
					);
				}
				return $out;
			},
			CompatCache::BUCKET_CATALOG,
			CompatCache::TTL_CATALOG
		);
		return self::public_response( array( 'versions' => $items ) );
	}

	/**
	 * Public GET /compat/bike/{slug}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_bike( \WP_REST_Request $request ): \WP_REST_Response {
		$slug    = sanitize_title( (string) $request->get_param( 'slug' ) );
		$profile = MatchEngine::bike_profile( $slug );
		if ( null === $profile ) {
			return new \WP_REST_Response( array( 'error' => __( 'Motorcycle not found.', 'motored-core' ) ), 404 );
		}
		return self::public_response( self::client_profile( $profile ) );
	}

	/**
	 * Public GET /compat/product/{id}/motos (lazy-loaded when the Compatibility product tab opens).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_product_motos( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = absint( $request->get_param( 'id' ) );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;
		$eligible   = $product instanceof \WC_Product && 'publish' === $product->get_status()
			&& $product->is_visible() && $product->is_type( 'simple' )
			&& '' === (string) get_post_field( 'post_password', $product_id, 'raw' );
		$data       = $eligible ? MatchEngine::compatible_motos_for_product( $product_id ) : null;
		$response   = new \WP_REST_Response(
			array(
				'position' => $data['position'] ?? '',
				'motos'    => $data['items'] ?? array(),
				'rows'     => null === $data ? array() : CompatListRenderer::build_rows( $data['items'], (string) $data['position'] ),
			),
			$eligible ? 200 : 404
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Strips unnecessary server fields before sending bike profile maps to localStorage.
	 *
	 * @param array<string,array<string,mixed>> $axis Axis map from MatchEngine::bike_profile().
	 * @return array<string,array{t:string,f:string,l:int|null,s:int|null,c:string|null}>
	 */
	private static function compact_axis_map( array $axis ): array {
		$out = array();
		foreach ( $axis as $canon => $entry ) {
			$out[ (string) $canon ] = array(
				't' => (string) $entry['type'],
				'f' => (string) ( $entry['from'] ?? '' ),
				'l' => isset( $entry['min_load'] ) ? (int) $entry['min_load'] : null,
				's' => isset( $entry['min_speed'] ) ? (int) $entry['min_speed'] : null,
				'c' => isset( $entry['req_const'] ) && '' !== (string) $entry['req_const'] ? (string) $entry['req_const'] : null,
			);
		}
		return $out;
	}

	/**
	 * Extracts factory (OEM) tire designations for front and rear axles from a bike profile.
	 *
	 * @param array<string,mixed> $profile Output of MatchEngine::bike_profile().
	 * @return array{f:string,r:string}
	 */
	private static function extract_oem_sizes( array $profile ): array {
		$out = array();
		foreach ( array(
			'f' => TireSpecs::POS_FRONT,
			'r' => TireSpecs::POS_REAR,
		) as $key => $axis ) {
			$oem = array();
			foreach ( is_array( $profile[ $axis ] ?? null ) ? $profile[ $axis ] : array() as $canon => $entry ) {
				if ( is_array( $entry ) && TireSpecs::FIT_OEM === ( $entry['type'] ?? '' ) ) {
					$oem[] = (string) ( $entry['desig'] ?? $canon );
				}
			}
			$out[ $key ] = implode( ' / ', array_unique( array_filter( $oem ) ) );
		}
		return $out;
	}

	/**
	 * Cached per-position matching product counts for a motorcycle slug.
	 *
	 * @param string $slug Version slug.
	 * @return array{all:int,front:int,rear:int,kit:int}|null
	 */
	public static function bike_counts( string $slug ): ?array {
		$clean = sanitize_title( $slug );
		if ( '' === $clean || ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			return null;
		}
		return MatchEngine::matching_product_counts( $clean );
	}

	/**
	 * Compact client-side profile consumed by compat-garage.js (`ev()` mirrors MatchEngine::evaluate_product()).
	 *
	 * @param array<string,mixed> $profile Output of MatchEngine::bike_profile().
	 * @return array<string,mixed>
	 */
	public static function client_profile( array $profile ): array {
		$slug     = (string) ( $profile['slug'] ?? '' );
		$counts   = self::bike_counts( $slug );
		$page_url = '' !== $slug && function_exists( 'apply_filters' )
			? (string) apply_filters( 'motored_compat_bike_page_url', '', $slug )
			: '';

		$out = array(
			'schema' => 2,
			'slug'   => $slug,
			'label'  => (string) ( $profile['label'] ?? '' ),
			'oem'    => self::extract_oem_sizes( $profile ),
			'front'  => self::compact_axis_map( is_array( $profile['front'] ?? null ) ? $profile['front'] : array() ),
			'rear'   => self::compact_axis_map( is_array( $profile['rear'] ?? null ) ? $profile['rear'] : array() ),
		);
		if ( null !== $counts ) {
			$out['counts'] = $counts;
		}
		if ( '' !== $page_url ) {
			$out['pageUrl'] = $page_url;
		}
		return $out;
	}

	/**
	 * Returns a public cache-friendly REST response with short TTL and ETag for prompt catalog updates.
	 *
	 * @param array<string,mixed> $data Response payload.
	 */
	private static function public_response( array $data ): \WP_REST_Response {
		$res  = new \WP_REST_Response( $data, 200 );
		$etag = '"' . substr( md5( (string) wp_json_encode( $data ) ), 0, 16 ) . '"';
		$res->header( 'Cache-Control', 'public, max-age=60, stale-while-revalidate=300' );
		$res->header( 'ETag', $etag );
		return $res;
	}
}
