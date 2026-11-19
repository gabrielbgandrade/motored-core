<?php
/**
 * REST API endpoints for the vehicle cascade selector, product tab lazy-load and Mi Garage.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Registers cacheable public catalog endpoints and authenticated Mi Garage endpoints under motored/v1.
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
	 * Declares all compatibility and garage routes.
	 */
	public function register_routes(): void {
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
			'/compat/models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_models' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'brand_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
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
						'type'     => 'integer',
						'required' => true,
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
		register_rest_route(
			self::NAMESPACE,
			'/garage',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_garage' ),
					'permission_callback' => static fn(): bool => is_user_logged_in(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'post_garage' ),
					'permission_callback' => static fn(): bool => is_user_logged_in(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/garage/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_garage_bike' ),
				'permission_callback' => static fn(): bool => is_user_logged_in(),
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
		return self::public_response(
			array(
				'slug'  => $profile['slug'],
				'label' => $profile['label'],
				'front' => self::compact_axis_map( $profile['front'] ),
				'rear'  => self::compact_axis_map( $profile['rear'] ),
			)
		);
	}

	/**
	 * Public GET /compat/product/{id}/motos (lazy-loaded when the Compatibility product tab opens).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_product_motos( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = absint( $request->get_param( 'id' ) );
		$data       = $product_id > 0 ? MatchEngine::compatible_motos_for_product( $product_id ) : null;
		if ( null === $data ) {
			return self::public_response(
				array(
					'position' => '',
					'motos'    => array(),
				)
			);
		}
		return self::public_response(
			array(
				'position' => $data['position'],
				'motos'    => $data['items'],
			)
		);
	}

	/**
	 * Authenticated GET /garage.
	 */
	public function get_garage(): \WP_REST_Response {
		$garage = GarageRepository::get( get_current_user_id() );
		return new \WP_REST_Response( $this->hydrate_garage_profiles( $garage ), 200 );
	}

	/**
	 * Authenticated POST /garage (add, set_active, merge).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function post_garage( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params();
		$params  = is_array( $body ) ? $body : $request->get_params();
		$action  = sanitize_key( (string) ( $params['action'] ?? 'add' ) );

		if ( 'merge' === $action ) {
			$guest_bikes = isset( $params['bikes'] ) && is_array( $params['bikes'] ) ? $params['bikes'] : array();
			$prefer      = sanitize_title( (string) ( $params['active'] ?? '' ) );
			$valid_guest = array();
			foreach ( $guest_bikes as $gb ) {
				if ( ! is_array( $gb ) ) {
					continue;
				}
				$prof = MatchEngine::bike_profile( (string) ( $gb['slug'] ?? '' ) );
				if ( null !== $prof ) {
					$valid_guest[] = array(
						'slug'  => $prof['slug'],
						'label' => $prof['label'],
					);
				}
			}
			$merged = GarageRepository::merge(
				GarageRepository::get( $user_id ),
				$valid_guest,
				Config::max_garage_bikes(),
				$prefer
			);
			GarageRepository::save( $user_id, $merged );
			return new \WP_REST_Response( $this->hydrate_garage_profiles( $merged ), 200 );
		}

		$slug = sanitize_title( (string) ( $params['slug'] ?? '' ) );
		if ( 'set_active' === $action ) {
			$garage = GarageRepository::set_active( $user_id, $slug );
			return new \WP_REST_Response( $this->hydrate_garage_profiles( $garage ), 200 );
		}

		$prof = MatchEngine::bike_profile( $slug );
		if ( null === $prof ) {
			return new \WP_REST_Response( array( 'error' => __( 'Motorcycle not found.', 'motored-core' ) ), 404 );
		}
		$garage = GarageRepository::add_bike( $user_id, $prof['slug'], $prof['label'], true );
		return new \WP_REST_Response( $this->hydrate_garage_profiles( $garage ), 200 );
	}

	/**
	 * Authenticated DELETE /garage/{slug}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function delete_garage_bike( \WP_REST_Request $request ): \WP_REST_Response {
		$slug   = sanitize_title( (string) $request->get_param( 'slug' ) );
		$garage = GarageRepository::remove_bike( get_current_user_id(), $slug );
		return new \WP_REST_Response( $this->hydrate_garage_profiles( $garage ), 200 );
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
	 * Attaches compact bike profiles so the client updates localStorage immediately on merge/login.
	 *
	 * @param array{active:string,bikes:list<array{slug:string,label:string,added_gmt:string}>} $garage Garage payload.
	 * @return array<string,mixed>
	 */
	private function hydrate_garage_profiles( array $garage ): array {
		$profiles = array();
		foreach ( $garage['bikes'] as $bike ) {
			$prof = MatchEngine::bike_profile( $bike['slug'] );
			if ( null !== $prof ) {
				$profiles[ $bike['slug'] ] = array(
					'slug'  => $prof['slug'],
					'label' => $prof['label'],
					'front' => self::compact_axis_map( $prof['front'] ),
					'rear'  => self::compact_axis_map( $prof['rear'] ),
				);
			}
		}
		$garage['profiles'] = $profiles;
		return $garage;
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
