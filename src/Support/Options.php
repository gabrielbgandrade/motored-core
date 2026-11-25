<?php
/**
 * Per-module options storage.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One option per module ("motored_{module}"), autoload=false — except the modules listed in AUTOLOAD_MODULES.
 *
 * Values are memoized per request. Defaults are registered in memory (no I/O).
 * Reading an option costs at most one query (then object cache); never call
 * this from public page requests unless the module truly needs it.
 */
final class Options {

	/**
	 * The ONLY modules whose option is autoloaded (AGENTS.md rule 4 exception).
	 *
	 * `bike_pages`: its CPT/taxonomy rewrite slugs are configurable and register_post_type() needs them on EVERY
	 * request; autoloading this small option (~0.5 KB) makes that read free instead of +1 query on every page.
	 * Add a module here only when its option is read on every request by design.
	 *
	 * @var list<string>
	 */
	public const AUTOLOAD_MODULES = array( 'bike_pages' );

	/**
	 * Whether a module's option is stored with autoload=yes.
	 *
	 * @param string $module Module slug.
	 */
	public static function autoload( string $module ): bool {
		return in_array( $module, self::AUTOLOAD_MODULES, true );
	}

	/**
	 * Admin-only self-heal for AUTOLOAD_MODULES: creates the option (empty) when missing, or flips
	 * an existing one to autoload=yes (no query when already right: the check reads the in-memory alloptions).
	 * Call from admin_init, never from public requests.
	 *
	 * @param string $module Module slug.
	 */
	public static function sync_autoload( string $module ): void {
		if ( ! self::autoload( $module ) || ! function_exists( 'wp_set_option_autoload' ) || ! function_exists( 'wp_load_alloptions' ) ) {
			return;
		}
		$name = self::option_name( $module );
		if ( array_key_exists( $name, wp_load_alloptions() ) ) {
			return;
		}
		// Never saved (defaults only): a missing option is still 1 query per request ("notoptions" is not persistent
		// without an object cache), so create it EMPTY and autoloaded; defaults keep coming from register_defaults()
		// (storing them would freeze today's defaults).
		if ( false === get_option( $name, false ) ) {
			add_option( $name, array(), '', true );
			return;
		}
		wp_set_option_autoload( $name, true );
	}

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
	 * Merges values into the stored option (autoload per autoload()).
	 *
	 * @param string              $module Module slug.
	 * @param array<string,mixed> $values Values to merge.
	 */
	public static function update( string $module, array $values ): bool {
		$name   = self::option_name( $module );
		$stored = get_option( $name, array() );
		$merged = array_merge( is_array( $stored ) ? $stored : array(), $values );
		$result = update_option( $name, $merged, self::autoload( $module ) );
		unset( self::$memo[ $module ] );
		return $result;
	}

	/**
	 * Creates the option with defaults if missing (autoload per autoload()). For activation.
	 *
	 * @param string $module Module slug.
	 */
	public static function ensure( string $module ): void {
		add_option( self::option_name( $module ), self::$defaults[ $module ] ?? array(), '', self::autoload( $module ) );
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
