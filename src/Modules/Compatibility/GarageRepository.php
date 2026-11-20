<?php
/**
 * User-meta persistence and pure merge logic for Mi Garage.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Stores each logged-in customer's saved motorcycles and active bike in _motored_garage user_meta.
 */
final class GarageRepository {

	public const META_KEY = '_motored_garage';

	/**
	 * Reads a user's garage payload (normalized).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array{active:string,bikes:list<array{slug:string,label:string,added_gmt:string}>}
	 */
	public static function get( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array(
				'active' => '',
				'bikes'  => array(),
			);
		}
		$raw = get_user_meta( $user_id, self::META_KEY, true );
		return self::normalize( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Persists a user's garage payload.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $garage  Garage structure.
	 */
	public static function save( int $user_id, array $garage ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$clean = self::normalize( $garage );
		if ( empty( $clean['bikes'] ) ) {
			delete_user_meta( $user_id, self::META_KEY );
			return true;
		}
		return false !== update_user_meta( $user_id, self::META_KEY, $clean );
	}

	/**
	 * Adds a bike (deduplicating by slug) and caps to Config::max_garage_bikes().
	 *
	 * @param int    $user_id    User ID.
	 * @param string $slug       Version slug.
	 * @param string $label      Human-readable label.
	 * @param bool   $set_active Whether to make this bike active.
	 * @return array{active:string,bikes:list<array{slug:string,label:string,added_gmt:string}>}
	 */
	public static function add_bike( int $user_id, string $slug, string $label, bool $set_active = true ): array {
		$current = self::get( $user_id );
		$merged  = self::merge(
			$current,
			array(
				array(
					'slug'  => $slug,
					'label' => $label,
				),
			),
			Config::max_garage_bikes(),
			$set_active ? $slug : $current['active']
		);
		self::save( $user_id, $merged );
		return $merged;
	}

	/**
	 * Removes a bike from the user's garage and falls back active to the first remaining bike.
	 *
	 * @param int    $user_id User ID.
	 * @param string $slug    Version slug.
	 * @return array{active:string,bikes:list<array{slug:string,label:string,added_gmt:string}>}
	 */
	public static function remove_bike( int $user_id, string $slug ): array {
		$clean_slug = sanitize_title( $slug );
		$current    = self::get( $user_id );
		$next       = array();
		foreach ( $current['bikes'] as $bike ) {
			if ( $bike['slug'] !== $clean_slug ) {
				$next[] = $bike;
			}
		}
		$active = $current['active'] === $clean_slug ? ( $next[0]['slug'] ?? '' ) : $current['active'];
		$out    = self::normalize(
			array(
				'active' => $active,
				'bikes'  => $next,
			)
		);
		self::save( $user_id, $out );
		return $out;
	}

	/**
	 * Changes the active bike slug when present in the saved list.
	 *
	 * @param int    $user_id User ID.
	 * @param string $slug    Version slug.
	 * @return array{active:string,bikes:list<array{slug:string,label:string,added_gmt:string}>}
	 */
	public static function set_active( int $user_id, string $slug ): array {
		$clean_slug = sanitize_title( $slug );
		$current    = self::get( $user_id );
		foreach ( $current['bikes'] as $bike ) {
			if ( $bike['slug'] === $clean_slug ) {
				$current['active'] = $clean_slug;
				self::save( $user_id, $current );
				return $current;
			}
		}
		return $current;
	}

	/**
	 * Pure merge helper: merges guest localStorage bikes into existing server garage, deduplicates and enforces $max_bikes.
	 *
	 * @param array<string,mixed>       $server_garage Existing garage.
	 * @param list<array<string,mixed>> $guest_bikes   Guest bikes to merge.
	 * @param int                       $max_bikes     Maximum bikes allowed.
	 * @param string                    $prefer_active Optional preferred active slug.
	 * @return array{active:string,bikes:list<array{slug:string,label:string,added_gmt:string}>}
	 */
	public static function merge( array $server_garage, array $guest_bikes, int $max_bikes, string $prefer_active = '' ): array {
		$base   = self::normalize( $server_garage );
		$by_key = array();
		foreach ( $base['bikes'] as $bike ) {
			$by_key[ $bike['slug'] ] = $bike;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $guest_bikes as $guest ) {
			$slug  = sanitize_title( (string) ( $guest['slug'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $guest['label'] ?? $slug ) );
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( $by_key[ $slug ] ) ) {
				// Re-added bikes become the most recent (same rule as compat-garage.js put()), so the
				// entry dropped at capacity is always the oldest one on every device.
				$existing = $by_key[ $slug ];
				unset( $by_key[ $slug ] );
				if ( '' !== $label ) {
					$existing['label'] = $label;
				}
				$by_key[ $slug ] = $existing;
				continue;
			}
			$by_key[ $slug ] = array(
				'slug'      => $slug,
				'label'     => '' !== $label ? $label : $slug,
				'added_gmt' => sanitize_text_field( (string) ( $guest['added_gmt'] ?? $now ) ),
			);
		}

		$list  = array_values( $by_key );
		$limit = max( 1, $max_bikes );
		if ( count( $list ) > $limit ) {
			// Keep newest entries when over capacity, preserving the preferred active slug if needed.
			$list = array_slice( $list, -$limit );
		}

		$active    = sanitize_title( '' !== $prefer_active ? $prefer_active : $base['active'] );
		$active_ok = false;
		foreach ( $list as $bike ) {
			if ( $bike['slug'] === $active ) {
				$active_ok = true;
				break;
			}
		}
		if ( ! $active_ok ) {
			$active = $list[0]['slug'] ?? '';
		}

		return array(
			'active' => $active,
			'bikes'  => $list,
		);
	}

	/**
	 * Normalizes a raw garage array.
	 *
	 * @param array<string,mixed> $raw Raw array.
	 * @return array{active:string,bikes:list<array{slug:string,label:string,added_gmt:string}>}
	 */
	private static function normalize( array $raw ): array {
		$bikes  = array();
		$seen   = array();
		$raw_bs = isset( $raw['bikes'] ) && is_array( $raw['bikes'] ) ? $raw['bikes'] : array();
		foreach ( $raw_bs as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug = sanitize_title( (string) ( $item['slug'] ?? '' ) );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$label         = sanitize_text_field( (string) ( $item['label'] ?? $slug ) );
			$bikes[]       = array(
				'slug'      => $slug,
				'label'     => '' !== $label ? $label : $slug,
				'added_gmt' => sanitize_text_field( (string) ( $item['added_gmt'] ?? '' ) ),
			);
		}

		$active = sanitize_title( (string) ( $raw['active'] ?? '' ) );
		if ( '' !== $active && ! isset( $seen[ $active ] ) ) {
			$active = $bikes[0]['slug'] ?? '';
		}

		return array(
			'active' => $active,
			'bikes'  => $bikes,
		);
	}
}
