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
			dbDelta( $sql );
		}
		return true;
	}

	/**
	 * Migration 2026112101: adds in_stock column and backfills existing index rows from _stock_status.
	 */
	public static function up_2026112101(): bool {
		self::up();
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->postmeta ) ) {
			$idx = Db::table( 'compat_product_index' );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time migration backfill over fixed tables.
			$wpdb->query(
				"UPDATE {$idx} i
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = i.product_id AND pm.meta_key = '_stock_status'
				SET i.in_stock = IF(pm.meta_value = 'outofstock', 0, 1)"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
  notes varchar(255) NOT NULL DEFAULT '',
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
