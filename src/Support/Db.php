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
