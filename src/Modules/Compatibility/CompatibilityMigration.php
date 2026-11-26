<?php
/**
 * Schema of the Compatibility tables.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the seven motored_compat_* tables (idempotent dbDelta).
 * Runs only on activation / admin_init through Plugin::migrations().
 */
final class CompatibilityMigration {

	/**
	 * Migration callable.
	 */
	public static function up(): bool {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		foreach ( self::statements( Db::prefix(), Db::charset_collate() ) as $sql ) {
			if ( ! Db::apply_schema( $sql ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Migration 2026112101: adds in_stock column and backfills existing index rows from _stock_status.
	 */
	public static function up_2026112101(): bool {
		if ( ! self::up() ) {
			return false;
		}
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->postmeta ) ) {
			$idx = Db::table( 'compat_product_index' );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time migration backfill over fixed tables.
			$result = $wpdb->query(
				"UPDATE {$idx}
				SET in_stock = CASE WHEN EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = {$idx}.product_id AND pm.meta_key = '_stock_status' AND pm.meta_value = 'outofstock'
				) THEN 0 ELSE 1 END
				WHERE EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = {$idx}.product_id AND pm.meta_key = '_stock_status'
				)"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return false !== $result;
		}
		return true;
	}

	/**
	 * Migration 2026112301: drops model-year variants ("MY2017+") duplicated by the year range and rebuilds the
	 * version slug when no other version collides (catalog is not public yet, so slugs are safe to refresh).
	 */
	public static function up_2026112301(): bool {
		if ( ! self::up() ) {
			return false;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return true;
		}
		$v = Db::table( 'compat_versions' );
		$m = Db::table( 'compat_models' );
		$b = Db::table( 'compat_brands' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time migration over fixed tables.
		$rows = $wpdb->get_results(
			"SELECT v.id, v.model_id, v.year_from, v.year_to, v.variant, b.slug AS brand_slug, m.slug AS model_slug
			FROM {$v} v INNER JOIN {$m} m ON m.id = v.model_id INNER JOIN {$b} b ON b.id = m.brand_id
			WHERE v.variant <> ''",
			ARRAY_A
		);
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			return false;
		}
		foreach ( $rows as $row ) {
			if ( '' !== CatalogRepository::clean_variant( (string) $row['variant'] ) ) {
				continue;
			}
			$taken = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$v} WHERE model_id = %d AND year_from = %d AND year_to = %d AND variant_slug = '' AND id <> %d",
					(int) $row['model_id'],
					(int) $row['year_from'],
					(int) $row['year_to'],
					(int) $row['id']
				)
			);
			if ( ! empty( $wpdb->last_error ) ) {
				return false;
			}
			if ( $taken > 0 ) {
				continue;
			}
			$slug = CatalogRepository::build_version_slug( (string) $row['brand_slug'], (string) $row['model_slug'], (int) $row['year_from'], (int) $row['year_to'] );
			$free = 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$v} WHERE slug = %s AND id <> %d", $slug, (int) $row['id'] ) );
			$data = array(
				'variant'      => '',
				'variant_slug' => '',
			);
			if ( $free ) {
				$data['slug'] = $slug;
			}
			if ( ! empty( $wpdb->last_error ) || false === $wpdb->update( $v, $data, array( 'id' => (int) $row['id'] ) ) ) {
				return false;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		CompatCache::bump_catalog();
		return true;
	}

	/**
	 * Rebuilds the derived product index: until this release ensure_size() could store a term ID instead of the new
	 * size ID (it read $wpdb->insert_id after inserting the size terms). The index is derived data, so every hash is
	 * cleared and a full reindex is queued (admin_init only).
	 */
	public static function up_2026112404(): bool {
		if ( ! self::up() ) {
			return false;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return true;
		}
		$idx = Db::table( 'compat_product_index' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table name from Db::table(); no user input.
		if ( false === $wpdb->query( "UPDATE {$idx} SET terms_hash = ''" ) ) {
			return false;
		}
		ProductIndexSync::schedule_full_reindex();
		return true;
	}

	/**
	 * Migration 2026112601: refreshes installed tire attribute labels and seeded fixed terms in the active locale
	 * (repairs stores where attributes/terms were initially created with English labels).
	 */
	public static function up_2026112601(): bool {
		if ( \Motored\Core\Integration\WooCommerce\AttributeHooks::attribute_id_by_slug( TireSpecs::ATTR_POSITION ) > 0 ) {
			AttributeInstaller::install_attributes();
			AttributeInstaller::seed_fixed_terms();
			\Motored\Core\Modules\CatalogFilters\SnapshotInvalidator::invalidate_now();
		}
		return true;
	}

	/**
	 * CREATE TABLE statements in dbDelta format.
	 *
	 * @param string $prefix  Table prefix including "motored_" (e.g. "wp_motored_").
	 * @param string $charset Charset/collation clause.
	 * @return list<string>
	 */
	public static function statements( string $prefix, string $charset ): array {
		$brands = "CREATE TABLE {$prefix}compat_brands (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL,
  slug varchar(120) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY name (name)
) {$charset};";

		$models = "CREATE TABLE {$prefix}compat_models (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  brand_id bigint(20) unsigned NOT NULL,
  name varchar(160) NOT NULL,
  slug varchar(160) NOT NULL,
  cc smallint(5) unsigned DEFAULT NULL,
  aliases varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY brand_slug (brand_id,slug),
  KEY brand_name (brand_id,name)
) {$charset};";

		$versions = "CREATE TABLE {$prefix}compat_versions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  model_id bigint(20) unsigned NOT NULL,
  slug varchar(190) NOT NULL,
  year_from smallint(5) unsigned NOT NULL,
  year_to smallint(5) unsigned NOT NULL DEFAULT 0,
  variant varchar(120) NOT NULL DEFAULT '',
  variant_slug varchar(120) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  verified tinyint(1) unsigned NOT NULL DEFAULT 0,
  notes text DEFAULT NULL,
  updated_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  UNIQUE KEY natural_key (model_id,year_from,year_to,variant_slug),
  KEY model_status (model_id,status)
) {$charset};";

		$sizes = "CREATE TABLE {$prefix}compat_sizes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  canonical varchar(32) NOT NULL,
  notation varchar(16) NOT NULL,
  width varchar(16) NOT NULL,
  aspect smallint(5) unsigned DEFAULT NULL,
  rim tinyint(3) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY canonical (canonical),
  KEY rim_notation (rim,notation)
) {$charset};";

		$fitments = "CREATE TABLE {$prefix}compat_fitments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  version_id bigint(20) unsigned NOT NULL,
  position varchar(10) NOT NULL,
  size_id bigint(20) unsigned NOT NULL,
  fit_type varchar(10) NOT NULL DEFAULT 'oem',
  min_load smallint(5) unsigned DEFAULT NULL,
  min_speed_rank tinyint(3) unsigned DEFAULT NULL,
  req_construction varchar(20) DEFAULT NULL,
  designation varchar(24) DEFAULT NULL,
  source varchar(30) NOT NULL DEFAULT 'manual',
  note varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY version_pos_size (version_id,position,size_id),
  KEY size_pos (size_id,position),
  KEY version_id (version_id)
) {$charset};";

		$equivalences = "CREATE TABLE {$prefix}compat_equivalences (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_size_id bigint(20) unsigned NOT NULL,
  target_size_id bigint(20) unsigned NOT NULL,
  position_scope varchar(10) NOT NULL DEFAULT 'any',
  is_approved tinyint(1) unsigned NOT NULL DEFAULT 1,
  is_approximate tinyint(1) unsigned NOT NULL DEFAULT 1,
  note varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY src_dst_scope (source_size_id,target_size_id,position_scope),
  KEY target_size_id (target_size_id)
) {$charset};";

		$product_index = "CREATE TABLE {$prefix}compat_product_index (
  product_id bigint(20) unsigned NOT NULL,
  position varchar(10) NOT NULL,
  front_size_id bigint(20) unsigned DEFAULT NULL,
  rear_size_id bigint(20) unsigned DEFAULT NULL,
  front_load smallint(5) unsigned DEFAULT NULL,
  rear_load smallint(5) unsigned DEFAULT NULL,
  front_speed_rank tinyint(3) unsigned DEFAULT NULL,
  rear_speed_rank tinyint(3) unsigned DEFAULT NULL,
  construction varchar(20) DEFAULT NULL,
  front_construction varchar(20) DEFAULT NULL,
  rear_construction varchar(20) DEFAULT NULL,
  in_stock tinyint(1) unsigned NOT NULL DEFAULT 1,
  terms_hash char(32) NOT NULL,
  updated_gmt datetime NOT NULL,
  PRIMARY KEY  (product_id),
  KEY idx_front (position,front_size_id),
  KEY idx_rear (position,rear_size_id),
  KEY idx_kit (position,front_size_id,rear_size_id),
  KEY idx_stock (in_stock,position)
) {$charset};";

		return array( $brands, $models, $versions, $sizes, $fitments, $equivalences, $product_index );
	}
}
