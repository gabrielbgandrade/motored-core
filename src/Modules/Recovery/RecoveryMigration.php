<?php
/**
 * Schema of the Recovery tables.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Creates motored_recoveries and motored_recovery_events (idempotent dbDelta).
 * Runs only on activation / admin_init through Plugin::migrations().
 */
final class RecoveryMigration {

	/**
	 * Migration callable.
	 */
	public static function up(): bool {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		foreach ( self::statements( Db::table( 'recoveries' ), Db::table( 'recovery_events' ), Db::charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		return true;
	}

	/**
	 * CREATE TABLE statements (dbDelta format).
	 *
	 * @param string $recoveries Table name.
	 * @param string $events     Events table name.
	 * @param string $charset    Charset/collate clause.
	 * @return list<string>
	 */
	public static function statements( string $recoveries, string $events, string $charset ): array {
		$main = "CREATE TABLE {$recoveries} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(10) NOT NULL DEFAULT 'cart',
  session_key varchar(64) DEFAULT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  email varchar(100) NOT NULL DEFAULT '',
  phone varchar(32) NOT NULL DEFAULT '',
  first_name varchar(60) NOT NULL DEFAULT '',
  cart_hash varchar(40) NOT NULL DEFAULT '',
  items longtext DEFAULT NULL,
  currency varchar(10) NOT NULL DEFAULT '',
  total decimal(19,4) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  step varchar(30) NOT NULL DEFAULT '',
  last_activity_gmt datetime NOT NULL,
  next_action_gmt datetime DEFAULT NULL,
  token_hash varchar(64) NOT NULL DEFAULT '',
  token_expires_gmt datetime DEFAULT NULL,
  clicked_gmt datetime DEFAULT NULL,
  recovered_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  wa_sent_count smallint(5) unsigned NOT NULL DEFAULT 0,
  email_sent_count smallint(5) unsigned NOT NULL DEFAULT 0,
  last_event_gmt datetime DEFAULT NULL,
  created_gmt datetime NOT NULL,
  updated_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY session_key (session_key),
  KEY status_next_action (status,next_action_gmt),
  KEY user_id (user_id),
  KEY updated_gmt (updated_gmt),
  KEY order_id (order_id)
) {$charset};";

		$log = "CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  recovery_id bigint(20) unsigned NOT NULL,
  event varchar(20) NOT NULL,
  channel varchar(10) NOT NULL DEFAULT 'none',
  source varchar(10) NOT NULL DEFAULT 'system',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  result varchar(10) NOT NULL DEFAULT 'ok',
  detail varchar(190) NOT NULL DEFAULT '',
  created_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY recovery_id (recovery_id)
) {$charset};";

		return array( $main, $log );
	}
}
