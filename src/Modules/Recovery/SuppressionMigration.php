<?php
/**
 * Schema of the suppression table for opt-outs.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Creates motored_suppressions table (idempotent dbDelta).
 * Runs only on activation / admin_init through Plugin::migrations().
 */
final class SuppressionMigration {

	/**
	 * Migration callable.
	 */
	public static function up(): bool {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( self::statement( Db::table( 'suppressions' ), Db::charset_collate() ) );
		return true;
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
  hash varchar(64) NOT NULL,
  channel varchar(10) NOT NULL DEFAULT 'all',
  created_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash_channel (hash,channel)
) {$charset};";
	}
}
