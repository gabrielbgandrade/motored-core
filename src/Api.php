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
	public const CONTRACT = '1.8';

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

	/**
	 * Plain values of the catalog filters active in this request, in the order of the sidebar chips
	 * (e.g. "Michelin", "Rodado 17", "En stock"), for catalog headings. Empty when no filter is active or the
	 * catalog filters are off.
	 *
	 * @return list<string>
	 */
	public static function active_filter_labels(): array {
		return \Motored\Core\Modules\CatalogFilters\FilterRenderer::active_labels();
	}

	/**
	 * Short technical title of a tire for product cards (1.5): `size` "160/60R17 69H TL" (kits: "120/70ZR17 +
	 * 160/60ZR17"), `model` "MT60 RS" (may be ''), `kit`. Empty array when the product is not an indexed tire or
	 * compatibility is off; the product name itself never changes.
	 *
	 * @param \WC_Product|int $product Product or product ID.
	 * @return array{size:string,model:string,kit:bool}|array{}
	 */
	public static function tire_card( $product ): array {
		$product = is_numeric( $product ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product ) : $product;
		return $product instanceof \WC_Product ? \Motored\Core\Modules\Compatibility\TireCard::for_product( $product ) : array();
	}

	/**
	 * Catalog motorcycle bar (1.7): with `?moto=` the active-bike bar (`.motored-compat-bar`), otherwise the
	 * cache-safe Mi Garage CTA mount (`.motored-garage-cta`); '' when it does not apply. Printed once per request:
	 * a theme that prints it above the catalog (before the loop) takes it from its default place above the grid.
	 */
	public static function active_bike_bar(): string {
		return \Motored\Core\Modules\Compatibility\Storefront::active_bike_bar_html();
	}
}
