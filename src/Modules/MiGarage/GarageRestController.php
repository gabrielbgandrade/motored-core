<?php
/**
 * Authenticated Mi Garage REST endpoints.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\MiGarage;

use Motored\Core\Modules\Compatibility\Config;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\RestController as CompatibilityRest;
use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Handles account garage reads and mutations.
 */
final class GarageRestController {

	/**
	 * Registers the existing garage routes.
	 */
	public function register_routes(): void {
		if ( ! Config::enabled() ) {
			return;
		}
		register_rest_route(
			CompatibilityRest::NAMESPACE,
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
			CompatibilityRest::NAMESPACE,
			'/garage/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_garage_bike' ),
				'permission_callback' => static fn(): bool => is_user_logged_in(),
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
		return self::with_account_lock( fn(): \WP_REST_Response => $this->apply_post( $request ) );
	}

	/**
	 * Applies an authenticated garage update.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function apply_post( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$body    = $request->get_json_params();
		$params  = is_array( $body ) ? $body : $request->get_params();
		$action  = sanitize_key( (string) ( $params['action'] ?? 'add' ) );

		if ( 'merge' === $action ) {
			if ( 'guest-v2' !== ( $params['source'] ?? '' ) ) {
				return new \WP_REST_Response( array( 'error' => __( 'Your session expired. Reload the page to save your changes.', 'motored-core' ) ), 409 );
			}
			$guest_bikes = isset( $params['bikes'] ) && is_array( $params['bikes'] ) ? $params['bikes'] : array();
			// Never validate more entries than the garage can hold (each one costs a profile lookup).
			$guest_bikes = array_slice( array_values( $guest_bikes ), -1 * Config::max_garage_bikes() );
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
			if ( ! GarageRepository::save( $user_id, $merged ) ) {
				return self::storage_error();
			}
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
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		return self::with_account_lock(
			function () use ( $slug ): \WP_REST_Response {
				$garage = GarageRepository::remove_bike( get_current_user_id(), $slug );
				return new \WP_REST_Response( $this->hydrate_garage_profiles( $garage ), 200 );
			}
		);
	}

	/**
	 * Serializes read-modify-write operations for the current account.
	 *
	 * @param callable():\WP_REST_Response $mutation Garage update.
	 */
	private static function with_account_lock( callable $mutation ): \WP_REST_Response {
		$lock = 'motored_garage_lock_' . get_current_user_id();
		if ( ! Db::acquire_option_lock( $lock ) ) {
			return self::storage_error();
		}
		try {
			wp_cache_delete( get_current_user_id(), 'user_meta' );
			return $mutation();
		} catch ( \RuntimeException $exception ) {
			return self::storage_error();
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Reports unsuccessful persistence to the client.
	 */
	private static function storage_error(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'error' => __( 'Action could not be completed.', 'motored-core' ) ), 503 );
	}

	/**
	 * Attaches compact bike profiles and drops motorcycles that were deleted or reverted to draft.
	 *
	 * @param array{active:string,bikes:list<array{slug:string,label:string,added_gmt:string}>} $garage Garage payload.
	 * @return array<string,mixed>
	 */
	private function hydrate_garage_profiles( array $garage ): array {
		$slugs       = array_values( array_map( static fn( array $b ): string => (string) $b['slug'], $garage['bikes'] ) );
		$loaded      = MatchEngine::bike_profile_many( $slugs );
		$profiles    = array();
		$valid       = array();
		$unavailable = array();
		foreach ( $garage['bikes'] as $bike ) {
			$slug = (string) $bike['slug'];
			if ( isset( $loaded[ $slug ] ) ) {
				$profiles[ $slug ] = CompatibilityRest::client_profile( $loaded[ $slug ] );
				$valid[]           = $bike;
			} else {
				// Not persisted: the version may be republished. The client tells the customer instead of hiding it.
				$unavailable[] = (string) $bike['label'];
			}
		}
		$garage['bikes']       = $valid;
		$garage['profiles']    = $profiles;
		$garage['unavailable'] = $unavailable;
		if ( '' !== $garage['active'] && ! isset( $profiles[ $garage['active'] ] ) ) {
			$garage['active'] = $valid[0]['slug'] ?? '';
		}
		return $garage;
	}
}
