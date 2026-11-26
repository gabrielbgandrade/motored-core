<?php
/**
 * Idempotent installer for global tire attributes and fixed initial terms.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Integration\WooCommerce\AttributeHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Installs the 12 global WooCommerce attributes and fixed terms in separate, idempotent steps
 * (never during activation or frontend page loads).
 */
final class AttributeInstaller {

	/**
	 * Registers all 12 global product attributes (slugs <= 28 chars, has_archives = false).
	 *
	 * @return array{created:int,existing:int,errors:list<string>}
	 */
	public static function install_attributes(): array {
		$labels  = self::attribute_labels();
		$orders  = TireSpecs::attribute_order_map();
		$created = 0;
		$exists  = 0;
		$errors  = array();

		foreach ( $orders as $slug => $order_by ) {
			if ( strlen( $slug ) > 28 ) {
				$errors[] = $slug;
				continue;
			}
			$id = AttributeHooks::attribute_id_by_slug( $slug );
			if ( $id > 0 ) {
				if ( isset( $labels[ $slug ] ) ) {
					AttributeHooks::update_attribute_label( $id, $labels[ $slug ] );
				}
				++$exists;
				continue;
			}
			$result = AttributeHooks::create_attribute(
				array(
					'name'         => $labels[ $slug ] ?? $slug,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => $order_by,
					'has_archives' => false,
				)
			);
			if ( is_wp_error( $result ) ) {
				$errors[] = $slug . ': ' . $result->get_error_message();
			} else {
				++$created;
				AttributeHooks::ensure_taxonomy_registered( AttributeHooks::taxonomy_name( $slug ) );
			}
		}

		AttributeHooks::clear_attribute_cache();

		return array(
			'created'  => $created,
			'existing' => $exists,
			'errors'   => $errors,
		);
	}

	/**
	 * Seeds fixed initial terms for Position, Construction, Tube, Usage, Speed rating, Rim and Profile.
	 *
	 * Does NOT pre-seed Línea or Medida attributes (those come from sizes/products).
	 *
	 * @return array{seeded:int,skipped:int}
	 */
	public static function seed_fixed_terms(): array {
		$seeded  = 0;
		$skipped = 0;

		foreach ( self::fixed_term_sets() as $slug => $terms ) {
			$taxonomy = AttributeHooks::taxonomy_name( $slug );
			if ( ! AttributeHooks::ensure_taxonomy_registered( $taxonomy ) ) {
				$skipped += count( $terms );
				continue;
			}
			$index = 1;
			foreach ( $terms as $term_slug => $name ) {
				$order = in_array( TireSpecs::attribute_order_map()[ $slug ] ?? '', array( 'menu_order' ), true ) ? $index : null;
				$id    = AttributeHooks::ensure_term( $taxonomy, $name, (string) $term_slug, $order );
				if ( $id > 0 ) {
					++$seeded;
				} else {
					++$skipped;
				}
				++$index;
			}
		}

		return array(
			'seeded'  => $seeded,
			'skipped' => $skipped,
		);
	}

	/**
	 * Ensures a canonical tire size exists as a term in both Medida delantera and Medida trasera.
	 *
	 * @param string $canonical Canonical key (e.g. "120/70-17" or "4.10-18").
	 */
	public static function sync_size_term( string $canonical ): void {
		$term_slug = self::size_slug( $canonical );
		foreach ( array( TireSpecs::ATTR_FRONT_SIZE, TireSpecs::ATTR_REAR_SIZE ) as $attr_slug ) {
			$taxonomy = AttributeHooks::taxonomy_name( $attr_slug );
			if ( taxonomy_exists( $taxonomy ) ) {
				AttributeHooks::ensure_term( $taxonomy, $canonical, $term_slug );
			}
		}
	}

	/**
	 * Deterministic URL-safe slug for a canonical tire size.
	 *
	 * @param string $canonical Canonical tire size.
	 */
	public static function size_slug( string $canonical ): string {
		$s = strtolower( trim( $canonical ) );
		$s = str_replace( array( '/', '.' ), '-', $s );
		$s = (string) preg_replace( '/[^a-z0-9-]+/', '-', $s );
		return trim( (string) preg_replace( '/-+/', '-', $s ), '-' );
	}

	/**
	 * Translated labels for the 12 global WooCommerce attributes.
	 *
	 * @return array<string,string>
	 */
	public static function attribute_labels(): array {
		return array(
			TireSpecs::ATTR_POSITION     => __( 'Position', 'motored-core' ),
			TireSpecs::ATTR_FRONT_SIZE   => __( 'Front size', 'motored-core' ),
			TireSpecs::ATTR_REAR_SIZE    => __( 'Rear size', 'motored-core' ),
			TireSpecs::ATTR_WIDTH        => __( 'Width', 'motored-core' ),
			TireSpecs::ATTR_PROFILE      => __( 'Aspect ratio', 'motored-core' ),
			TireSpecs::ATTR_RIM          => __( 'Rim diameter', 'motored-core' ),
			TireSpecs::ATTR_LOAD_INDEX   => __( 'Load index', 'motored-core' ),
			TireSpecs::ATTR_SPEED_RATING => __( 'Speed rating', 'motored-core' ),
			TireSpecs::ATTR_CONSTRUCTION => __( 'Construction', 'motored-core' ),
			TireSpecs::ATTR_TUBE         => __( 'Tube type', 'motored-core' ),
			TireSpecs::ATTR_USAGE        => __( 'Riding style', 'motored-core' ),
			TireSpecs::ATTR_LINE         => __( 'Model', 'motored-core' ),
		);
	}

	/**
	 * Fixed initial terms grouped by attribute slug.
	 *
	 * @return array<string,array<int|string,string>>
	 */
	public static function fixed_term_sets(): array {
		$speed_terms = array();
		foreach ( TireSpecs::SPEED_RANKS as $code => $rank ) {
			unset( $rank );
			$slug                 = '(W)' === $code ? 'w-par' : strtolower( $code );
			$speed_terms[ $slug ] = $code;
		}

		$rims = array();
		foreach ( range( 8, 23 ) as $rim ) {
			$rims[ (string) $rim ] = (string) $rim;
		}

		$profiles = array();
		for ( $p = 30; $p <= 100; $p += 5 ) {
			$profiles[ (string) $p ] = (string) $p;
		}

		return array(
			TireSpecs::ATTR_POSITION     => array(
				'delantero'         => __( 'Front', 'motored-core' ),
				'trasero'           => __( 'Rear', 'motored-core' ),
				'delantero-trasero' => __( 'Front / Rear', 'motored-core' ),
				'kit'               => __( 'Kit', 'motored-core' ),
			),
			TireSpecs::ATTR_CONSTRUCTION => array(
				'radial'    => __( 'Radial', 'motored-core' ),
				'diagonal'  => __( 'Diagonal', 'motored-core' ),
				'cinturado' => __( 'Belted (B)', 'motored-core' ),
			),
			TireSpecs::ATTR_TUBE         => array(
				'tl'    => __( 'Tubeless (TL)', 'motored-core' ),
				'tt'    => __( 'Tube type (TT)', 'motored-core' ),
				'tl-tt' => __( 'TL / TT', 'motored-core' ),
			),
			TireSpecs::ATTR_USAGE        => array(
				'calle'   => __( 'Street', 'motored-core' ),
				'sport'   => __( 'Sport', 'motored-core' ),
				'touring' => __( 'Touring', 'motored-core' ),
				'trail'   => __( 'Trail', 'motored-core' ),
				'enduro'  => __( 'Enduro', 'motored-core' ),
				'cross'   => __( 'Motocross', 'motored-core' ),
				'scooter' => __( 'Scooter', 'motored-core' ),
				'custom'  => __( 'Custom', 'motored-core' ),
				'pista'   => __( 'Track', 'motored-core' ),
			),
			TireSpecs::ATTR_SPEED_RATING => $speed_terms,
			TireSpecs::ATTR_RIM          => $rims,
			TireSpecs::ATTR_PROFILE      => $profiles,
		);
	}
}
