<?php
/**
 * Pure domain logic for tire-size signatures and merging motorcycle versions per model page.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\TireSpecs;

defined( 'ABSPATH' ) || exit;

/**
 * Collapses versions that share identical OEM front + rear tire specifications into one page unit.
 */
final class Signature {

	/**
	 * Computes deterministic SHA-1 signature for a version's OEM front + rear fitments.
	 *
	 * Format: sha1( front_oem_lines . '·' . rear_oem_lines ), including min_load, min_speed_rank and req_construction.
	 *
	 * @param list<array<string,mixed>> $fitments Fitment rows for one version.
	 */
	public static function compute_for_fitments( array $fitments ): string {
		$by_axis      = array(
			TireSpecs::POS_FRONT => array(),
			TireSpecs::POS_REAR  => array(),
		);
		$alternatives = array();
		foreach ( $fitments as $row ) {
			$type = (string) ( $row['fit_type'] ?? '' );
			if ( ! in_array( $type, array( TireSpecs::FIT_OEM, TireSpecs::FIT_ALT ), true ) ) {
				continue;
			}
			$pos = (string) ( $row['position'] ?? '' );
			if ( ! isset( $by_axis[ $pos ] ) ) {
				continue;
			}
			$canon = (string) ( $row['canonical'] ?? '' );
			if ( '' === $canon ) {
				continue;
			}
			$line = implode(
				'|',
				array(
					$canon,
					isset( $row['min_load'] ) ? (string) (int) $row['min_load'] : '',
					isset( $row['min_speed_rank'] ) ? (string) (int) $row['min_speed_rank'] : '',
					isset( $row['req_construction'] ) ? (string) $row['req_construction'] : '',
				)
			);
			if ( TireSpecs::FIT_ALT === $type ) {
				$alternatives[] = $pos . '|' . $line;
			} else {
				$by_axis[ $pos ][] = $line;
			}
		}
		sort( $alternatives );
		sort( $by_axis[ TireSpecs::POS_FRONT ] );
		sort( $by_axis[ TireSpecs::POS_REAR ] );
		$raw = implode( '+', $by_axis[ TireSpecs::POS_FRONT ] ) . '·' . implode( '+', $by_axis[ TireSpecs::POS_REAR ] );
		// Preserve existing OEM-only signatures; differing approved alternatives must never share a grid.
		if ( ! empty( $alternatives ) ) {
			$raw .= '|alt:' . implode( '+', $alternatives );
		}
		return sha1( $raw );
	}

	/**
	 * OEM tire designations per axle as printed in the manual (e.g. "120/70ZR17"), for listing cards.
	 *
	 * @param list<array<string,mixed>> $fitments Fitment rows for one version.
	 * @return array{f:list<string>,r:list<string>}
	 */
	public static function oem_designations( array $fitments ): array {
		$out = array(
			'f' => array(),
			'r' => array(),
		);
		foreach ( $fitments as $row ) {
			if ( TireSpecs::FIT_OEM !== (string) ( $row['fit_type'] ?? '' ) ) {
				continue;
			}
			$pos   = (string) ( $row['position'] ?? '' );
			$key   = TireSpecs::POS_FRONT === $pos ? 'f' : ( TireSpecs::POS_REAR === $pos ? 'r' : '' );
			$desig = trim( (string) ( $row['designation'] ?? '' ) );
			$desig = '' !== $desig ? $desig : (string) ( $row['canonical'] ?? '' );
			if ( '' !== $key && '' !== $desig && ! in_array( $desig, $out[ $key ], true ) ) {
				$out[ $key ][] = $desig;
			}
		}
		return $out;
	}

	/**
	 * Unique storage key identifying a (model_id, signature_hash) page unit.
	 *
	 * @param int    $model_id Model ID.
	 * @param string $hash     SHA-1 fitment signature.
	 */
	public static function key( int $model_id, string $hash ): string {
		return $model_id . ':' . $hash;
	}

	/**
	 * Groups versions of a model by tire-size signature, merging year ranges and picking the primary version slug.
	 *
	 * @param array<string,mixed>                  $model              Model + brand info (`id`, `name`, `slug`, `brand_name`, `brand_slug`).
	 * @param list<array<string,mixed>>            $versions           Versions belonging to this model.
	 * @param array<int,list<array<string,mixed>>> $fitments_by_ver    Fitments keyed by version_id.
	 * @return list<array{
	 *   sig_key:string,
	 *   sig_hash:string,
	 *   primary_version_id:int,
	 *   primary_slug:string,
	 *   version_ids:list<int>,
	 *   version_slugs:list<string>,
	 *   has_published:bool,
	 *   year_from:int,
	 *   year_to:int,
	 *   years_label:string,
	 *   title:string,
	 *   base_slug:string,
	 *   year_slug:string,
	 *   post_slug:string
	 * }>
	 */
	public static function group_model_versions( array $model, array $versions, array $fitments_by_ver ): array {
		$model_id   = (int) ( $model['id'] ?? 0 );
		$brand_name = (string) ( $model['brand_name'] ?? '' );
		$model_name = (string) ( $model['name'] ?? '' );
		$brand_slug = (string) ( $model['brand_slug'] ?? sanitize_title( $brand_name ) );
		$model_slug = (string) ( $model['slug'] ?? sanitize_title( $model_name ) );

		$groups = array();
		foreach ( $versions as $ver ) {
			$vid  = (int) ( $ver['id'] ?? 0 );
			$fits = $fitments_by_ver[ $vid ] ?? array();
			if ( $vid <= 0 || empty( $fits ) ) {
				continue;
			}
			$hash    = self::compute_for_fitments( $fits );
			$sig_key = self::key( $model_id, $hash );
			$yf      = (int) ( $ver['year_from'] ?? 0 );
			$yt      = (int) ( $ver['year_to'] ?? 0 );
			$pub     = 'published' === (string) ( $ver['status'] ?? '' );

			if ( ! isset( $groups[ $sig_key ] ) ) {
				$groups[ $sig_key ] = array(
					'sig_key'            => $sig_key,
					'sig_hash'           => $hash,
					'primary_version_id' => $vid,
					'primary_slug'       => (string) $ver['slug'],
					'version_ids'        => array(),
					'version_slugs'      => array(),
					'has_published'      => false,
					'year_from'          => $yf,
					'year_to'            => $yt,
					'variants'           => array(),
				);
			}

			$groups[ $sig_key ]['version_ids'][]   = $vid;
			$groups[ $sig_key ]['version_slugs'][] = (string) $ver['slug'];
			if ( $pub ) {
				if ( ! $groups[ $sig_key ]['has_published'] ) {
					$groups[ $sig_key ]['primary_version_id'] = $vid;
					$groups[ $sig_key ]['primary_slug']       = (string) $ver['slug'];
				}
				$groups[ $sig_key ]['has_published'] = true;
			}
			if ( $yf > 0 && ( 0 === $groups[ $sig_key ]['year_from'] || $yf < $groups[ $sig_key ]['year_from'] ) ) {
				$groups[ $sig_key ]['year_from'] = $yf;
			}
			if ( 0 === $yt || 0 === $groups[ $sig_key ]['year_to'] ) {
				$groups[ $sig_key ]['year_to'] = 0;
			} elseif ( $yt > $groups[ $sig_key ]['year_to'] ) {
				$groups[ $sig_key ]['year_to'] = $yt;
			}
			$variant = trim( (string) ( $ver['variant'] ?? '' ) );
			if ( '' !== $variant ) {
				$groups[ $sig_key ]['variants'][] = $variant;
			}
		}

		$out         = array();
		$multi_group = count( $groups ) > 1;
		foreach ( $groups as $g ) {
			$years_label = MatchEngine::format_years( $g['year_from'], $g['year_to'] );
			$variants    = array_values( array_unique( $g['variants'] ) );
			$var_part    = ( $multi_group && 1 === count( $variants ) ) ? ' ' . $variants[0] : '';
			$title       = sprintf(
				/* translators: 1: brand + model (+ variant if distinct), 2: year range. */
				__( 'Tires for %1$s (%2$s)', 'motored-core' ),
				trim( $brand_name . ' ' . $model_name . $var_part ),
				$years_label
			);
			// URL without the year: the page covers a year RANGE ("2017+"), riders search "<brand> <model>", and a
			// year in the slug changes whenever an older year is added. The year slug only disambiguates extra
			// signatures of the same model (see assign_post_slugs()); it uses year_from only, so a later year_to
			// never renames the URL.
			$base_slug = sanitize_title( $brand_slug . '-' . $model_slug );
			$year_slug = sanitize_title( $base_slug . '-' . ( $g['year_from'] > 0 ? $g['year_from'] : '' ) . ( '' !== $var_part ? '-' . $var_part : '' ) );

			$out[] = array(
				'sig_key'            => $g['sig_key'],
				'sig_hash'           => $g['sig_hash'],
				'primary_version_id' => $g['primary_version_id'],
				'primary_slug'       => $g['primary_slug'],
				'version_ids'        => $g['version_ids'],
				'version_slugs'      => $g['version_slugs'],
				'has_published'      => $g['has_published'],
				'year_from'          => $g['year_from'],
				'year_to'            => $g['year_to'],
				'years_label'        => $years_label,
				'title'              => $title,
				'base_slug'          => $base_slug,
				'year_slug'          => $year_slug,
				'post_slug'          => $base_slug,
			);
		}

		usort(
			$out,
			static fn( array $a, array $b ): int => $b['year_from'] <=> $a['year_from']
		);

		return self::assign_post_slugs( $out, array() );
	}

	/**
	 * Decides each signature page's slug. Exactly one page per model owns the clean `{brand}-{model}` slug;
	 * the others get `{brand}-{model}-{year_from}[-variant]`.
	 *
	 * Owner = the page that already holds the clean slug (sticky: a ranking URL is never renamed because a new
	 * generation appears), otherwise the newest signature (groups arrive sorted by year_from DESC).
	 *
	 * @param list<array<string,mixed>> $groups          Output rows of group_model_versions() (newest first).
	 * @param array<string,string>      $current_by_sig  sig_key => current post_name of live (non-trashed) posts.
	 * @return list<array<string,mixed>>
	 */
	public static function assign_post_slugs( array $groups, array $current_by_sig ): array {
		if ( empty( $groups ) ) {
			return $groups;
		}
		$owner = '';
		foreach ( $groups as $g ) {
			if ( '' !== (string) $g['base_slug'] && ( $current_by_sig[ (string) $g['sig_key'] ] ?? '' ) === (string) $g['base_slug'] ) {
				$owner = (string) $g['sig_key'];
				break;
			}
		}
		if ( '' === $owner ) {
			$owner = (string) $groups[0]['sig_key'];
		}
		foreach ( $groups as $i => $g ) {
			$groups[ $i ]['post_slug'] = (string) $g['sig_key'] === $owner ? (string) $g['base_slug'] : (string) $g['year_slug'];
		}
		return $groups;
	}
}
