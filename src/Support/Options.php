<?php
/**
 * Per-module options storage.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One option per module ("motored_{module}"), always autoload=false.
 *
 * Values are memoized per request. Defaults are registered in memory (no I/O).
 * Reading an option costs at most one query (then object cache); never call
 * this from public page requests unless the module truly needs it.
 */
final class Options {

	/**
	 * Registered defaults, per module.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $defaults = array();

	/**
	 * Per-request memo of stored values, per module.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $memo = array();

	/**
	 * WordPress option name for a module.
	 *
	 * @param string $module Module slug.
	 * @throws \InvalidArgumentException When the slug is invalid.
	 */
	public static function option_name( string $module ): string {
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $module ) ) {
			throw new \InvalidArgumentException( 'Invalid module slug.' );
		}
		return 'motored_' . $module;
	}

	/**
	 * Registers default values for a module (memory only).
	 *
	 * @param string              $module   Module slug.
	 * @param array<string,mixed> $defaults Defaults.
	 */
	public static function register_defaults( string $module, array $defaults ): void {
		self::option_name( $module );
		self::$defaults[ $module ] = $defaults;
	}

	/**
	 * All values for a module: defaults overlaid with stored values.
	 *
	 * @param string $module Module slug.
	 * @return array<string,mixed>
	 */
	public static function all( string $module ): array {
		if ( ! isset( self::$memo[ $module ] ) ) {
			$stored                = get_option( self::option_name( $module ), array() );
			self::$memo[ $module ] = is_array( $stored ) ? $stored : array();
		}
		return array_merge( self::$defaults[ $module ] ?? array(), self::$memo[ $module ] );
	}

	/**
	 * Reads one value.
	 *
	 * @param string $module  Module slug.
	 * @param string $key     Key.
	 * @param mixed  $fallback Fallback when neither stored nor registered.
	 * @return mixed
	 */
	public static function get( string $module, string $key, $fallback = null ) {
		$all = self::all( $module );
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Whether a 'yes'/'no' (WooCommerce style) or boolean value is enabled.
	 *
	 * @param string $module Module slug.
	 * @param string $key    Key.
	 */
	public static function is_enabled( string $module, string $key ): bool {
		$value = self::get( $module, $key, 'no' );
		return true === $value || 'yes' === $value;
	}

	/**
	 * Merges values into the stored option. Always autoload=false.
	 *
	 * @param string              $module Module slug.
	 * @param array<string,mixed> $values Values to merge.
	 */
	public static function update( string $module, array $values ): bool {
		$name                  = self::option_name( $module );
		$stored                = get_option( $name, array() );
		$merged                = array_merge( is_array( $stored ) ? $stored : array(), $values );
		$result                = update_option( $name, $merged, false );
		self::$memo[ $module ] = $merged;
		return $result;
	}

	/**
	 * Creates the option with defaults if missing (autoload=false). For activation.
	 *
	 * @param string $module Module slug.
	 */
	public static function ensure( string $module ): void {
		add_option( self::option_name( $module ), self::$defaults[ $module ] ?? array(), '', false );
	}

	/**
	 * Deletes a module's option.
	 *
	 * @param string $module Module slug.
	 */
	public static function delete( string $module ): bool {
		unset( self::$memo[ $module ] );
		return delete_option( self::option_name( $module ) );
	}

	/**
	 * Clears in-memory state (tests and long-running processes).
	 */
	public static function reset(): void {
		self::$defaults = array();
		self::$memo     = array();
	}
}
