<?php
/**
 * Database helpers.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Table-name helpers. Tables are only created by migrations (activation / admin_init).
 */
final class Db {

	/**
	 * Acquires a database-backed lock without overwriting another owner.
	 *
	 * @param string $name Option name reserved for the lock.
	 */
	public static function acquire_option_lock( string $name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic insert using the unique option_name index.
		return 1 === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $name, 'locked', 'off' ) );
	}

	/**
	 * Applies and verifies a dbDelta table definition.
	 *
	 * @param string $sql CREATE TABLE statement.
	 */
	public static function apply_schema( string $sql ): bool {
		global $wpdb;
		dbDelta( $sql );
		return empty( $wpdb->last_error ) && array() === dbDelta( $sql, false ) && empty( $wpdb->last_error );
	}

	/**
	 * Common prefix for plugin tables ("{wpdb prefix}motored_").
	 */
	public static function prefix(): string {
		global $wpdb;
		return $wpdb->prefix . 'motored_';
	}

	/**
	 * Full table name for a logical name.
	 *
	 * @param string $name Logical name (a-z, 0-9, underscore).
	 * @throws \InvalidArgumentException When the name is invalid.
	 */
	public static function table( string $name ): string {
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid table name.' );
		}
		return self::prefix() . $name;
	}

	/**
	 * Charset/collation clause for CREATE TABLE statements.
	 */
	public static function charset_collate(): string {
		global $wpdb;
		return $wpdb->get_charset_collate();
	}
}
