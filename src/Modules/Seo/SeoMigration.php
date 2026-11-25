<?php
/**
 * Schema of the SEO redirects table.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Creates motored_seo_redirects table (idempotent dbDelta).
 * Runs only on activation / admin_init through Plugin::migrations().
 */
final class SeoMigration {

	/**
	 * Migration callable.
	 */
	public static function up(): bool {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		return Db::apply_schema( self::statement( Db::table( 'seo_redirects' ), Db::charset_collate() ) );
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
  source_path varchar(191) NOT NULL,
  target_url varchar(500) NOT NULL,
  status_code smallint(5) unsigned NOT NULL DEFAULT 301,
  created_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_path (source_path)
) {$charset};";
	}
}
