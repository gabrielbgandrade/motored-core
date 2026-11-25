<?php
/**
 * Schema of the motorcycle demand capture table (motored_bike_demand).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Creates motored_bike_demand table (idempotent dbDelta).
 * Runs only on activation / admin_init through Plugin::migrations().
 */
final class DemandMigration {

	/**
	 * Migration callable.
	 */
	public static function up(): bool {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		return Db::apply_schema( self::statement( Db::table( 'bike_demand' ), Db::charset_collate() ) );
	}

	/**
	 * CREATE TABLE statement (dbDelta format).
	 *
	 * @param string $table   Table name.
	 * @param string $charset Charset/collate clause.
	 */
	public static function statement( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  signature varchar(80) NOT NULL,
  model_id bigint(20) unsigned NOT NULL DEFAULT 0,
  brand_name varchar(120) NOT NULL DEFAULT '',
  model_name varchar(160) NOT NULL DEFAULT '',
  years_label varchar(60) NOT NULL DEFAULT '',
  position varchar(16) NOT NULL DEFAULT 'all',
  contact_hash varchar(64) NOT NULL,
  email varchar(191) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  consent_gmt datetime NOT NULL,
  created_gmt datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  version_slug varchar(191) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  channel varchar(16) NOT NULL DEFAULT '',
  last_error varchar(60) NOT NULL DEFAULT '',
  updated_gmt datetime DEFAULT NULL,
  notified_gmt datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_contact_sig_pos (contact_hash,signature,position),
  KEY idx_sig_pos (signature,position),
  KEY idx_status_id (status,id),
  KEY idx_created (created_gmt)
) {$charset};";
	}
}
