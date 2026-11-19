<?php
/**
 * Schema v1 JSON exporter for the Compatibility motorcycle catalog and equivalences.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Exports all brands, models, versions, fitments and equivalences in the exact JSON format accepted by JsonImporter.
 */
final class JsonExporter {

	/**
	 * Builds the schema v1 payload array from CatalogRepository::export_all().
	 *
	 * @return array<string,mixed>
	 */
	public static function build_payload(): array {
		$raw = CatalogRepository::export_all();

		$fits_by_version = array();
		foreach ( $raw['fitments'] as $f ) {
			$vid                       = (int) $f['version_id'];
			$fits_by_version[ $vid ][] = array(
				'position'         => (string) $f['position'],
				'size'             => (string) $f['canonical'],
				'fit_type'         => (string) $f['fit_type'],
				'min_load'         => null !== $f['min_load'] ? (int) $f['min_load'] : null,
				'min_speed'        => null !== $f['min_speed_rank'] ? TireSpecs::speed_code_for_rank( (int) $f['min_speed_rank'] ) : null,
				'req_construction' => null !== $f['req_construction'] ? (string) $f['req_construction'] : null,
				'source'           => (string) $f['source'],
				'note'             => (string) $f['note'],
			);
		}

		$versions_by_model = array();
		foreach ( $raw['versions'] as $v ) {
			$vid                         = (int) $v['id'];
			$mid                         = (int) $v['model_id'];
			$versions_by_model[ $mid ][] = array(
				'year_from' => (int) $v['year_from'],
				'year_to'   => (int) $v['year_to'],
				'variant'   => (string) $v['variant'],
				'status'    => (string) $v['status'],
				'verified'  => (bool) (int) $v['verified'],
				'notes'     => (string) $v['notes'],
				'fitments'  => $fits_by_version[ $vid ] ?? array(),
			);
		}

		$models_by_brand = array();
		foreach ( $raw['models'] as $m ) {
			$mid                       = (int) $m['id'];
			$bid                       = (int) $m['brand_id'];
			$models_by_brand[ $bid ][] = array(
				'name'     => (string) $m['name'],
				'slug'     => (string) $m['slug'],
				'cc'       => null !== $m['cc'] ? (int) $m['cc'] : null,
				'aliases'  => (string) $m['aliases'],
				'versions' => $versions_by_model[ $mid ] ?? array(),
			);
		}

		$brands = array();
		foreach ( $raw['brands'] as $b ) {
			$bid      = (int) $b['id'];
			$brands[] = array(
				'name'   => (string) $b['name'],
				'slug'   => (string) $b['slug'],
				'models' => $models_by_brand[ $bid ] ?? array(),
			);
		}

		$equivs = array();
		foreach ( $raw['equivalences'] as $eq ) {
			$equivs[] = array(
				'source'         => (string) $eq['source'],
				'target'         => (string) $eq['target'],
				'position_scope' => (string) $eq['position_scope'],
				'is_approved'    => (bool) (int) $eq['is_approved'],
				'is_approximate' => (bool) (int) $eq['is_approximate'],
				'note'           => (string) $eq['note'],
			);
		}

		return array(
			'schema_version' => JsonImporter::SCHEMA_VERSION,
			'exported_gmt'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'brands'         => $brands,
			'equivalences'   => $equivs,
		);
	}

	/**
	 * Encodes the payload as pretty-printed JSON.
	 */
	public static function to_json(): string {
		return (string) wp_json_encode( self::build_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
