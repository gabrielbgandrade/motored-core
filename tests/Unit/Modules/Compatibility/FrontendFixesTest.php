<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Integration\WooCommerce\CompatibilityHooks;
use Motored\Core\Modules\CatalogFilters\FacetEngine;
use Motored\Core\Modules\CatalogFilters\FilterState;
use Motored\Core\Modules\CatalogFilters\RestController as FiltersRest;
use Motored\Core\Modules\CatalogFilters\StorefrontAssets;
use Motored\Core\Modules\Compatibility\CompatListRenderer;
use Motored\Core\Modules\Compatibility\Config;
use Motored\Core\Modules\MiGarage\GarageRepository;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\RestController;
use Motored\Core\Modules\Compatibility\Storefront;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Support\Options;
use Motored\Core\Support\SearchText;
use Motored\Core\Tests\Unit\TestCase;

/**
 * Storefront review fixes (docs/REVIEW-FRONTEND-COMPAT.md): parity, labels, garage order, bar once, blocks, facets, search.
 */
final class FrontendFixesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MOTORED_CORE_URL' ) ) {
			define( 'MOTORED_CORE_URL', 'https://example.test/wp-content/plugins/motored-core/' );
		}
		if ( ! defined( 'MOTORED_CORE_VERSION' ) ) {
			define( 'MOTORED_CORE_VERSION', 'test' );
		}
		$this->fake_storage();
		Storefront::reset();
		StorefrontAssets::reset();
		FacetEngine::reset();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$this->options['motored_compatibility'] = array_merge( Config::DEFAULTS, array( 'enabled' => 'yes' ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) $s ) ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $s ) => strtolower( trim( (string) $s ) ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'sanitize_hex_color' )->returnArg();
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_add_inline_script' )->justReturn( true );
		Functions\when( 'rest_url' )->alias( static fn( $p = '' ) => 'https://example.test/wp-json/' . $p );
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/' ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://example.test/my-account/' );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.test' . $p );
	}

	protected function tearDown(): void {
		unset( $_GET['moto'], $_GET['pos'], $_GET['orderby'] );
		Storefront::reset();
		StorefrontAssets::reset();
		parent::tearDown();
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private static function cases(): array {
		$json = (string) file_get_contents( dirname( __DIR__, 4 ) . '/tests/fixtures/compat-cases.json' );
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data['cases'] : array();
	}

	public function test_php_match_engine_agrees_with_shared_parity_fixture(): void {
		$cases = self::cases();
		$this->assertNotEmpty( $cases );
		foreach ( $cases as $tc ) {
			$r = MatchEngine::evaluate_product( $tc['fit'], $tc['profile'], (bool) $tc['strict'] );
			$this->assertSame(
				$tc['expect'],
				array(
					'ok'        => $r['compatible'],
					'positions' => $r['positions'],
					'match'     => $r['match'],
					'caution'   => $r['caution'],
					'from'      => $r['equivalent_from'],
				),
				(string) $tc['name']
			);
		}
	}

	public function test_client_profile_uses_the_compact_mapping_the_js_parity_test_assumes(): void {
		foreach ( self::cases() as $tc ) {
			$client = RestController::client_profile( array( 'slug' => 'x', 'label' => 'X' ) + $tc['profile'] );
			foreach ( array( 'front', 'rear' ) as $axis ) {
				$expected = array();
				foreach ( $tc['profile'][ $axis ] as $size => $e ) {
					$expected[ (string) $size ] = array(
						't' => (string) $e['type'],
						'f' => (string) $e['from'],
						'l' => null === $e['min_load'] ? null : (int) $e['min_load'],
						's' => null === $e['min_speed'] ? null : (int) $e['min_speed'],
						'c' => '' === (string) $e['req_const'] ? null : (string) $e['req_const'],
					);
				}
				$this->assertSame( $expected, $client[ $axis ], (string) $tc['name'] );
			}
		}
	}

	public function test_js_parity_script_passes_when_node_is_available(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'node not installed; run `node tests/js/compat-parity.test.js` manually.' );
		}
		$out  = array();
		$code = 1;
		exec( escapeshellarg( $node ) . ' ' . escapeshellarg( dirname( __DIR__, 4 ) . '/tests/js/compat-parity.test.js' ) . ' 2>&1', $out, $code );
		$this->assertSame( 0, $code, implode( "\n", $out ) );
	}

	public function test_kit_label_only_for_kit_products_and_front_rear_for_dual_axle_tires(): void {
		$both = array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR );
		$this->assertSame( 'Complete kit', CompatListRenderer::position_label( $both, TireSpecs::POS_KIT ) );
		$this->assertSame( 'Front / Rear', CompatListRenderer::position_label( $both, TireSpecs::POS_BOTH ) );
		$this->assertSame( 'Front / Rear', CompatListRenderer::position_label( $both, '' ) );
		$this->assertSame( 'Rear', CompatListRenderer::position_label( array( TireSpecs::POS_REAR ), TireSpecs::POS_REAR ) );

		$entry = array(
			'model'     => 'CG 150',
			'years'     => '2010–2020',
			'positions' => $both,
			'match'     => TireSpecs::FIT_OEM,
		);
		$this->assertStringContainsString( 'Front / Rear', CompatListRenderer::format_entry( $entry, TireSpecs::POS_BOTH ) );
		$this->assertStringNotContainsString( 'kit', CompatListRenderer::format_entry( $entry, TireSpecs::POS_BOTH ) );
	}

	public function test_build_rows_are_sorted_naturally_and_searchable_without_accents_or_spaces(): void {
		$rows = CompatListRenderer::build_rows(
			array(
				array( 'slug' => 'zan', 'brand' => 'zanella', 'model' => 'RX 150', 'aliases' => '', 'variant' => '', 'years' => '2015', 'positions' => array( 'rear' ), 'match' => 'oem' ),
				array( 'slug' => 'hon', 'brand' => 'Honda', 'model' => 'CB 500 F', 'aliases' => 'Cebé', 'variant' => '', 'years' => '2019', 'positions' => array( 'rear' ), 'match' => 'alt' ),
			),
			TireSpecs::POS_REAR
		);
		$this->assertSame( array( 'Honda', 'zanella' ), array_column( $rows, 'brand' ) );
		$this->assertSame( array( 'hon' ), $rows[0]['slugs'] );
		$this->assertTrue( SearchText::matches( $rows[0]['search'], 'cb500f' ) );
		$this->assertTrue( SearchText::matches( $rows[0]['search'], 'CB-500' ) );
		$this->assertTrue( SearchText::matches( $rows[0]['search'], 'cebe' ) );
		$this->assertFalse( SearchText::matches( $rows[0]['search'], 'tornado' ) );
	}

	public function test_garage_merge_moves_readded_bike_to_newest_and_drops_oldest(): void {
		$server = array(
			'active' => 'a',
			'bikes'  => array(
				array( 'slug' => 'a', 'label' => 'A' ),
				array( 'slug' => 'b', 'label' => 'B' ),
				array( 'slug' => 'c', 'label' => 'C' ),
			),
		);
		$merged = GarageRepository::merge( $server, array( array( 'slug' => 'a', 'label' => 'A' ), array( 'slug' => 'd', 'label' => 'D' ) ), 3, 'd' );
		$this->assertSame( array( 'c', 'a', 'd' ), array_column( $merged['bikes'], 'slug' ) );
		$this->assertSame( 'd', $merged['active'] );
	}

	public function test_intersect_post_in_keeps_best_fit_order(): void {
		$this->assertSame( array( 9, 3 ), Storefront::intersect_post_in( array( 3, 4, 9 ), array( 9, 5, 3 ) ) );
		$this->assertSame( array( 0 ), Storefront::intersect_post_in( array( 1 ), array( 2 ) ) );
	}

	public function test_best_fit_order_respects_shopper_sorting_and_setting(): void {
		$this->assertTrue( Storefront::best_fit_order_applies() );
		$_GET['orderby'] = 'price';
		$this->assertFalse( Storefront::best_fit_order_applies() );
		unset( $_GET['orderby'] );
		$this->options['motored_compatibility']['oem_first_sort'] = 'no';
		Options::reset();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$this->assertFalse( Storefront::best_fit_order_applies() );
	}

	public function test_bar_is_registered_on_no_results_and_printed_only_once(): void {
		$cb = static function (): void {};
		CompatibilityHooks::register_display_hooks( $cb, $cb, $cb, $cb );
		$this->assertNotFalse( has_action( 'woocommerce_no_products_found', $cb ) );
		$this->assertNotFalse( has_action( 'woocommerce_before_shop_loop', $cb ) );

		// Not a catalog request ([products] shortcode on a page): no CTA.
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		$this->assertSame( '', Storefront::active_bike_bar_html() );

		// Shop without ?moto=: the cache-safe garage CTA mount is printed once per request.
		Functions\when( 'is_shop' )->justReturn( true );
		$first = Storefront::active_bike_bar_html();
		$this->assertStringContainsString( 'data-role="garage-cta"', $first );
		$this->assertSame( '', Storefront::active_bike_bar_html() );
	}

	public function test_cta_is_omitted_when_disabled(): void {
		$this->options['motored_compatibility']['garage_cta'] = 'no';
		$this->assertSame( '', Storefront::active_bike_bar_html() );
	}

	public function test_independent_product_collection_keeps_its_original_query(): void {
		$_GET['moto'] = 'moto-1';
		$block = new \WP_Block();
		$block->context['query'] = array( 'isProductCollectionBlock' => true, 'inherit' => false );
		$args = array( 'post_type' => 'product', 'post__in' => array( 11, 12 ), 'orderby' => 'date' );

		$result = ( new Storefront() )->filter_block_query_vars( $args, $block );

		$this->assertSame( $args + array( 'motored_prime_fits' => true ), $result );
	}

	public function test_block_hooks_registered_and_price_block_appends_loop_marker(): void {
		$cb = static fn( $c ) => $c;
		CompatibilityHooks::register_block_hooks( $cb, $cb );
		$this->assertNotFalse( has_filter( 'render_block_woocommerce/product-collection', $cb ) );
		$this->assertNotFalse( has_filter( 'render_block_woocommerce/product-price', $cb ) );

		Functions\when( 'is_product' )->justReturn( false );
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'product_id'       => 21,
				'position'         => TireSpecs::POS_FRONT,
				'front_size_id'    => 1,
				'front_canonical'  => '90/90-21',
				'rear_size_id'     => null,
				'rear_canonical'   => null,
				'front_load'       => 54,
				'rear_load'        => null,
				'front_speed_rank' => 5,
				'rear_speed_rank'  => null,
				'construction'     => TireSpecs::CONST_DIAGONAL,
				'terms_hash'       => 'x',
			)
		);
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				array(
					'product_id'       => 21,
					'position'         => TireSpecs::POS_FRONT,
					'front_size_id'    => 1,
					'front_canonical'  => '90/90-21',
					'rear_size_id'     => null,
					'rear_canonical'   => null,
					'front_load'       => 54,
					'rear_load'        => null,
					'front_speed_rank' => 5,
					'rear_speed_rank'  => null,
					'construction'     => TireSpecs::CONST_DIAGONAL,
					'terms_hash'       => 'x',
				),
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		$block                     = new \WP_Block();
		$block->context['postId'] = 21;
		$html                      = ( new Storefront() )->filter_price_block( '<div class="price">$1</div>', array(), $block );
		unset( $GLOBALS['wpdb'] );
		$this->assertStringStartsWith( '<div class="price">$1</div>', $html );
		$this->assertStringContainsString( 'motored-fit-marker', $html );
		$this->assertStringContainsString( 'data-ctx="loop"', $html );

		// No block instance -> untouched.
		$this->assertSame( 'x', ( new Storefront() )->filter_price_block( 'x', array(), null ) );
	}

	public function test_facets_count_child_categories_and_respect_universe_ids(): void {
		$snapshot = array(
			'products' => array(
				array( 'id' => 1, 'in_stock' => true, 'is_kit' => false, 'cats' => array( 'cubiertas' ), 'brands' => array( 'pirelli' ), 'attrs' => array() ),
				array( 'id' => 2, 'in_stock' => true, 'is_kit' => false, 'cats' => array( 'cubiertas-calle' ), 'brands' => array( 'pirelli' ), 'attrs' => array() ),
				array( 'id' => 3, 'in_stock' => true, 'is_kit' => false, 'cats' => array( 'accesorios' ), 'brands' => array( 'metzeler' ), 'attrs' => array() ),
			),
			'labels'   => array( 'product_brand' => array( 'pirelli' => 'Pirelli', 'metzeler' => 'Metzeler' ) ),
		);
		$state    = FilterState::from_query( array() );

		$parent_only = FacetEngine::compute_facets( $snapshot, $state, array( 'cat_slug' => 'cubiertas' ) );
		$with_kids   = FacetEngine::compute_facets( $snapshot, $state, array( 'cat_slugs' => array( 'cubiertas', 'cubiertas-calle' ) ) );
		$this->assertSame( 1, $parent_only['total'] );
		$this->assertSame( 2, $with_kids['total'] );

		$universe = FacetEngine::compute_facets( $snapshot, $state, array( 'universe_ids' => array( 3 => true ) ) );
		$this->assertSame( 1, $universe['total'] );
	}

	public function test_suggestions_match_motorcycles_ignoring_spaces_and_accents(): void {
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( (array) $args ) );
		$bikes = array(
			array(
				'slug'     => 'honda-cb500f-2019',
				'label'    => 'Honda CB500F 2019–2024',
				'haystack' => SearchText::haystack( 'Honda CB500F  2019–2024' ),
			),
			array(
				'slug'     => 'motomel-skua-150',
				'label'    => 'Motomel Skua 150',
				'haystack' => SearchText::haystack( 'Motomel Skúa 150' ),
			),
		);
		$snap  = array(
			'products' => array(),
			'labels'   => array(),
		);
		foreach ( array( 'cb 500', 'CB-500', 'cb500f' ) as $q ) {
			$items = FiltersRest::build_suggestions( $q, 'https://example.test/shop/', true, $snap, $bikes );
			$this->assertSame( 'honda-cb500f-2019', $items[0]['slug'] ?? '', $q );
		}
		$items = FiltersRest::build_suggestions( 'skua', 'https://example.test/shop/', true, $snap, $bikes );
		$this->assertSame( 'motomel-skua-150', $items[0]['slug'] ?? '' );
	}

	public function test_compat_list_rows_include_per_motorcycle_page_links_and_gating_skips_non_tires(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $val, ...$args ) => 'motored_compat_bike_page_url' === $hook
				? 'https://example.test/cubiertas-para/' . (string) ( $args[0] ?? '' ) . '/'
				: $val
		);
		$rows = CompatListRenderer::build_rows(
			array(
				array(
					'slug'      => 'benelli-trk-502-2017',
					'brand'     => 'Benelli',
					'model'     => 'TRK 502',
					'aliases'   => '',
					'variant'   => '',
					'years'     => '2017+',
					'positions' => array( TireSpecs::POS_REAR ),
					'match'     => TireSpecs::FIT_OEM,
				),
			),
			TireSpecs::POS_REAR
		);
		$this->assertSame(
			'https://example.test/cubiertas-para/benelli-trk-502-2017/',
			$rows[0]['entries'][0]['url']
		);

		$this->assertSame( 'Front tires', TireSpecs::position_label( TireSpecs::POS_FRONT, true ) );
		$this->assertSame( 'Rear tires', TireSpecs::position_label( TireSpecs::POS_REAR, true ) );

		// P1-2/P1-3 gating: non-tire product page enqueues neither compat.css nor compat-garage.js.
		$enqueued = 0;
		Functions\when( 'wp_enqueue_style' )->alias(
			static function () use ( &$enqueued ): void {
				++$enqueued;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function () use ( &$enqueued ): void {
				++$enqueued;
			}
		);
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 999 );
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;
		( new Storefront() )->maybe_enqueue_catalog_assets();
		unset( $GLOBALS['wpdb'] );
		$this->assertSame( 0, $enqueued );
	}
}
