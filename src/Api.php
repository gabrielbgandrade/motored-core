<?php
/**
 * Public PHP API for the store theme (engine/skin contract).
 *
 * @package Motored\Core
 */

namespace Motored\Core;

use Motored\Core\Modules\MiGarage\GarageAssets;

defined( 'ABSPATH' ) || exit;

/**
 * The only plugin class a theme may call (see docs/THEME-CONTRACT.md). It is autoloaded on first use, so it
 * costs nothing on requests where the theme does not call it. Themes check `class_exists( '\Motored\Core\Api' )`
 * first and must degrade when it is missing. Everything else in the plugin is private to the plugin.
 */
final class Api {

	/**
	 * Contract version. Bump the minor for additions, the major for breaking changes
	 * (and update docs/THEME-CONTRACT.md plus the theme in the same change).
	 */
	public const CONTRACT = '1.1';

	/**
	 * Whether the given contract major version is the one this plugin implements.
	 *
	 * @param string $major Major version the theme was written for (e.g. '1').
	 */
	public static function supports( string $major ): bool {
		return strtok( self::CONTRACT, '.' ) === $major;
	}

	/**
	 * Mi Garage URL for the current visitor ('' when WooCommerce cannot build one).
	 */
	public static function garage_url(): string {
		return GarageAssets::garage_url();
	}
}
