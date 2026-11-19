<?php
namespace Motored\Core\Tests\Unit\Modules\CatalogFilters;

use Brain\Monkey\Functions;
use Motored\Core\Modules\CatalogFilters\CatalogFiltersModule;
use Motored\Core\Modules\CatalogFilters\Config;
use Motored\Core\Modules\CatalogFilters\FacetEngine;
use Motored\Core\Modules\CatalogFilters\FilterRenderer;
use Motored\Core\Modules\CatalogFilters\QueryFilter;
use Motored\Core\Modules\CatalogFilters\RestController;
use Motored\Core\Modules\CatalogFilters\SearchShortcode;
use Motored\Core\Modules\CatalogFilters\StorefrontAssets;
use Motored\Core\Modules\Compatibility\CompatListRenderer;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class SuggestAndModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9\-]+/i', '-', trim( (string) $s ) ), '-' ) ) );
		Functions\when( 'add_query_arg' )->alias(
			static fn( $args, $url ) => $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args )
		);
	}

	public function test_smart_suggest_matches_sizes_rims_bikes_aliases_and_brands_with_zero_db_queries(): void {
		$snapshot = FacetEngine::assemble_snapshot_rows(
			array(
				array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'pa_posicion', 'slug' => 'delantero', 'name' => 'Delantero' ),
				array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'product_brand', 'slug' => 'pirelli', 'name' => 'Pirelli' ),
				array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'pa_ancho', 'slug' => '120', 'name' => '120' ),
				array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'pa_perfil', 'slug' => '70', 'name' => '70' ),
				array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'pa_rodado', 'slug' => '17', 'name' => '17' ),
			)
		);
		$bikes    = array(
			array(
				'slug'     => 'honda-xr-250-tornado-2001-2024',
				'label'    => 'Honda XR 250 Tornado (2001–2024)',
				'haystack' => 'honda xr 250 tornado xr250 2001-2024',
			),
		);

		$this->assertSame( array(), RestController::build_suggestions( 'a', 'https://example.test/tienda/', true, $snapshot, $bikes ) );

		$size_res = RestController::build_suggestions( '120/70ZR17', 'https://example.test/tienda/', true, $snapshot, $bikes );
		$this->assertNotEmpty( $size_res );
		$this->assertSame( 'size', $size_res[0]['type'] );
		$this->assertStringContainsString( 'filter_ancho=120', $size_res[0]['url'] );

		$rim_res = RestController::build_suggestions( 'r17', 'https://example.test/tienda/', true, $snapshot, $bikes );
		$this->assertSame( 'rim', $rim_res[0]['type'] );
		$this->assertStringContainsString( 'filter_rodado=17', $rim_res[0]['url'] );

		$moto_res = RestController::build_suggestions( 'xr250', 'https://example.test/tienda/', true, $snapshot, $bikes );
		$this->assertSame( 'moto', $moto_res[0]['type'] );
		$this->assertSame( 'honda-xr-250-tornado-2001-2024', $moto_res[0]['slug'] );

		$brand_res = RestController::build_suggestions( 'pirelli', 'https://example.test/tienda/', true, $snapshot, $bikes );
		$this->assertSame( 'brand', $brand_res[0]['type'] );
		$this->assertStringContainsString( 'product_brand=pirelli', $brand_res[0]['url'] );
	}

	public function test_module_register_performs_zero_option_reads_and_zero_asset_enqueues(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'add_shortcode' )->justReturn( null );
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		$mod = new CatalogFiltersModule();
		$this->assertSame( 'catalog_filters', $mod->id() );
		$this->assertTrue( $mod->should_load( new Context() ) );
		$mod->register();
	}

	public function test_compat_list_renderer_groups_by_brand_and_formats_entries(): void {
		$items   = array(
			array( 'brand' => 'Yamaha', 'model' => 'MT-03', 'variant' => '', 'years' => '2016–2024', 'positions' => array( TireSpecs::POS_FRONT ), 'match' => TireSpecs::FIT_OEM, 'equiv_from' => '' ),
			array( 'brand' => 'Honda', 'model' => 'XR 250 Tornado', 'variant' => '', 'years' => '2001–2024', 'positions' => array( TireSpecs::POS_REAR ), 'match' => TireSpecs::FIT_EQUIV, 'equiv_from' => '4.10-18' ),
		);
		$grouped = CompatListRenderer::group_by_brand( $items );
		$this->assertSame( array( 'Honda', 'Yamaha' ), array_keys( $grouped ) );

		$formatted = CompatListRenderer::format_entry( $grouped['Honda'][0] );
		$this->assertStringContainsString( 'XR 250 Tornado', $formatted );
		$this->assertStringContainsString( 'Rear', $formatted );
		$this->assertStringContainsString( '4.10-18', $formatted );
	}

	public function test_disabled_module_outputs_nothing_enqueues_no_assets_and_skips_query_hooks(): void {
		StorefrontAssets::reset();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$this->options['motored_catalog_filters'] = array_merge( Config::DEFAULTS, array( 'enabled' => 'no' ) );

		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( (string) $k ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'shortcode_atts' )->alias( static fn( $d, $a ) => array_merge( $d, is_array( $a ) ? $a : array() ) );
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wc_get_product_ids_on_sale' )->never();

		$this->assertSame( '', ( new SearchShortcode() )->render( array() ) );
		$this->assertSame( '', ( new FilterRenderer() )->shortcode( array() ) );

		if ( ! class_exists( 'WP_Query' ) ) {
			eval( 'class WP_Query { public array $vars = array(); public function get($k, $d = "") { return $this->vars[$k] ?? $d; } public function set($k, $v) { $this->vars[$k] = $v; } }' );
		}
		$_GET['oferta']        = '1';
		$_GET['filter_rodado'] = '17';
		$q                     = new \WP_Query();
		$qf                    = new QueryFilter();
		$qf->filter_product_query( $q );
		$this->assertSame( '', $q->get( 'post__in' ) );
		$this->assertSame( array(), $qf->robots_noindex( array() ) );
		$_GET = array();
	}
}
