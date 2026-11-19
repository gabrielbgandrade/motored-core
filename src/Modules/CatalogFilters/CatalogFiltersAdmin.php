<?php
/**
 * Admin settings section for CatalogFilters under WooCommerce > Settings > Motored > Filters.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Admin\SectionRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Uses WooCommerce Settings API and shared motored-admin.css classes only (no inline styles).
 */
final class CatalogFiltersAdmin {

	public const SECTION = 'filters';

	/**
	 * Registers the Filters settings section.
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'Filters', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			60
		);
	}

	/**
	 * WooCommerce > Settings > Motored > Filters fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		return array(
			array(
				'title' => __( 'Catalog filters & storefront search', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Theme-agnostic tire search ([motored_buscador]), faceted sidebar filters ([motored_filtros] and classic widget) and smart suggestions.', 'motored-core' ),
				'id'    => 'motored_catalog_filters_title',
			),
			array(
				'title'    => __( 'Enable catalog filters', 'motored-core' ),
				'desc'     => __( 'Enable [motored_buscador], [motored_filtros], classic filter widget, ?oferta=1 query filter and smart search.', 'motored-core' ),
				'id'       => 'motored_catalog_filters[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Only show in-stock products in search & counts', 'motored-core' ),
				'desc'     => __( 'Exclude out-of-stock tires from size dropdowns and facet counts (WooCommerce catalog out-of-stock visibility is always respected).', 'motored-core' ),
				'id'       => 'motored_catalog_filters[only_in_stock]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['only_in_stock'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Noindex filtered catalog URLs', 'motored-core' ),
				'desc'     => __( 'Add noindex, follow via wp_robots when attribute, brand or sale filter parameters are active.', 'motored-core' ),
				'id'       => 'motored_catalog_filters[noindex_filtered]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['noindex_filtered'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Default search layout', 'motored-core' ),
				'desc'     => __( 'Used by [motored_buscador] when no layout attribute is passed.', 'motored-core' ),
				'id'       => 'motored_catalog_filters[default_layout]',
				'type'     => 'select',
				'default'  => Config::DEFAULTS['default_layout'],
				'options'  => array(
					'tabs'  => __( 'Tabs (Motorcycle / Size / Smart search)', 'motored-core' ),
					'split' => __( 'Side by side (Size + Motorcycle + Smart search)', 'motored-core' ),
				),
				'autoload' => false,
			),
			array(
				'title'       => __( 'Accent color (optional)', 'motored-core' ),
				'desc'        => __( 'Optional hex color (e.g. #00ae4e) passed to --motored-accent. Leave blank to inherit from the active theme.', 'motored-core' ),
				'id'          => 'motored_catalog_filters[accent_color]',
				'type'        => 'text',
				'placeholder' => '#00ae4e',
				'default'     => '',
				'autoload'    => false,
			),
			array(
				'title'    => __( 'Catalog base URL (optional)', 'motored-core' ),
				'desc'     => __( 'Overrides the destination shop URL for [motored_buscador] (defaults to Compatibility / WooCommerce shop URL).', 'motored-core' ),
				'id'       => 'motored_catalog_filters[shop_url]',
				'type'     => 'url',
				'class'    => 'motored-input-wide',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_catalog_filters_end',
			),
		);
	}
}
