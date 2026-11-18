<?php
/**
 * PSR-4 autoloader for the Motored\Core namespace.
 *
 * @package Motored\Core
 */

namespace Motored\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PSR-4 autoloader. No Composer is needed at runtime.
 */
final class Autoloader {

	public const PREFIX = 'Motored\\Core\\';

	/**
	 * Registers the autoloader.
	 *
	 * @param string $base_dir Absolute path to src/ (with trailing slash).
	 */
	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				$file = self::map( $class_name, $base_dir );
				if ( null !== $file && is_file( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	/**
	 * Maps a fully-qualified class name to its file path (pure function).
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @param string $base_dir   Base directory for the namespace prefix.
	 * @return string|null Path, or null when the class is outside our namespace.
	 */
	public static function map( string $class_name, string $base_dir ): ?string {
		$class_name = ltrim( $class_name, '\\' );
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return null;
		}
		$relative = substr( $class_name, strlen( self::PREFIX ) );
		return rtrim( $base_dir, '/\\' ) . '/' . str_replace( '\\', '/', $relative ) . '.php';
	}
}
