<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\Config;
use Motored\Core\Modules\Compatibility\Storefront;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class StorefrontTabsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Storefront::reset();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$this->options['motored_compatibility'] = array_merge( Config::DEFAULTS, array( 'enabled' => 'yes' ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) $s ) ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $s ) => strtolower( trim( (string) $s ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['product'], $GLOBALS['wpdb'], $_GET['moto'], $_GET['pos'] );
		Storefront::reset();
		parent::tearDown();
	}

	public function test_renames_additional_info_to_technical_info_and_adds_compat_tab_only_for_indexed_tires(): void {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 15 );
		$product->shouldReceive( 'get_meta' )->with( Storefront::FULL_SIZE_META )->andReturn( '120/70ZR17' );
		$GLOBALS['product'] = $product;

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'product_id'       => 15,
					'position'         => TireSpecs::POS_FRONT,
					'front_size_id'    => 1,
					'front_canonical'  => '120/70-17',
					'rear_size_id'     => null,
					'rear_canonical'   => null,
					'front_load'       => 58,
					'rear_load'        => null,
					'front_speed_rank' => TireSpecs::speed_rank( 'W' ),
					'rear_speed_rank'  => null,
					'construction'     => TireSpecs::CONST_RADIAL,
					'terms_hash'       => 'abc',
				),
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		$sf   = new Storefront();
		$tabs = $sf->filter_product_tabs(
			array(
				'description'            => array( 'title' => 'Descripción', 'priority' => 10 ),
				'additional_information' => array( 'title' => 'Información adicional', 'priority' => 20 ),
				'reviews'                => array( 'title' => 'Valoraciones', 'priority' => 30 ),
			)
		);

		$this->assertSame( 'Technical information', $tabs['additional_information']['title'] );
		$this->assertArrayHasKey( 'motored_compatibility', $tabs );
		$this->assertSame( 25, $tabs['motored_compatibility']['priority'] );
		$this->assertSame( 'Technical information', $sf->filter_tech_tab_heading( 'Información adicional' ) );

		$rows = $sf->append_full_size_attribute_row( array( 'pa_linea' => array( 'label' => 'Línea', 'value' => 'M7' ) ), $product );
		$this->assertArrayHasKey( 'motored_full_size', $rows );
		$this->assertSame( '<p>120/70ZR17</p>', $rows['motored_full_size']['value'] );
	}

	public function test_omits_compatibility_tab_for_non_tire_products(): void {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 99 );
		$GLOBALS['product'] = $product;

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		$tabs = ( new Storefront() )->filter_product_tabs(
			array(
				'description'            => array( 'title' => 'Descripción', 'priority' => 10 ),
				'additional_information' => array( 'title' => 'Información adicional', 'priority' => 20 ),
			)
		);
		$this->assertArrayNotHasKey( 'motored_compatibility', $tabs );
	}

	public function test_robots_noindex_and_intersect_post_in_and_nav_link_preservation(): void {
		$sf = new Storefront();
		$this->assertSame( array(), $sf->robots_noindex( array() ) );

		$_GET['moto'] = 'honda-tornado';
		$_GET['pos']  = 'rear';
		$robots       = $sf->robots_noindex( array( 'max-image-preview' => 'large' ) );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['follow'] );

		$this->assertSame( array( 0 ), Storefront::intersect_post_in( array(), array() ) );
		$this->assertSame( array( 10, 20 ), Storefront::intersect_post_in( array(), array( 10, 20 ) ) );
		$this->assertSame( array( 20 ), Storefront::intersect_post_in( array( 5, 20 ), array( 10, 20 ) ) );
		$this->assertSame( array( 0 ), Storefront::intersect_post_in( array( 5, 6 ), array( 10, 20 ) ) );

		Functions\when( 'add_query_arg' )->alias(
			static fn( $args, $url ) => $url . '?' . http_build_query( $args )
		);
		$this->assertSame(
			'https://example.test/shop/?moto=honda-tornado&pos=rear',
			$sf->preserve_layered_nav_link( 'https://example.test/shop/' )
		);
	}
}
