<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\Config;
use Motored\Core\Modules\Compatibility\RestController;
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

		Functions\when( 'taxonomy_exists' )->justReturn( false );
		$rows = $sf->append_full_size_attribute_row(
			array(
				'attribute_pa_ancho'            => array( 'label' => 'Ancho', 'value' => '<p>120</p>' ),
				'attribute_pa_perfil'           => array( 'label' => 'Perfil', 'value' => '<p>70</p>' ),
				'attribute_pa_rodado'           => array( 'label' => 'Rodado', 'value' => '<p>17</p>' ),
				'attribute_pa_medida-delantera' => array( 'label' => 'Medida delantera', 'value' => '<p>120/70-17</p>' ),
				'attribute_pa_uso'              => array( 'label' => 'Tipo de uso', 'value' => '<p>Calle</p>' ),
				'attribute_pa_construccion'     => array( 'label' => 'Construcción', 'value' => '<p>Radial</p>' ),
				'attribute_pa_indice-carga'     => array( 'label' => 'Índice de carga', 'value' => '<p>58</p>' ),
				'attribute_pa_indice-velocidad' => array( 'label' => 'Índice de velocidad', 'value' => '<p>W</p>' ),
				'attribute_pa_camara'           => array( 'label' => 'Uso con cámara', 'value' => '<p>Sin cámara (TL)</p>' ),
				'attribute_pa_posicion'         => array( 'label' => 'Posición', 'value' => '<p>Delantero</p>' ),
				'attribute_pa_linea'            => array( 'label' => 'Línea', 'value' => '<p>M7</p>' ),
				'attribute_pa_marca'            => array( 'label' => 'Marca', 'value' => '<p>Metzeler</p>' ),
				'weight'                        => array( 'label' => 'Peso', 'value' => '4 kg' ),
			),
			$product
		);

		$this->assertSame(
			array(
				'attribute_pa_marca',
				'attribute_pa_linea',
				'motored_full_size',
				'attribute_pa_posicion',
				'motored_load_speed',
				'attribute_pa_camara',
				'attribute_pa_construccion',
				'attribute_pa_uso',
				'weight',
			),
			array_keys( $rows )
		);
		$this->assertSame( 'Model', $rows['attribute_pa_linea']['label'] );
		$this->assertSame( '<p>120/70ZR17</p>', $rows['motored_full_size']['value'] );
		$this->assertSame( 'Index', $rows['motored_load_speed']['label'] );
		$this->assertSame( '<p>58W (up to 236 kg · 270 km/h)</p>', $rows['motored_load_speed']['value'] );
	}

	public function test_formats_kit_technical_rows_with_axles_and_omits_combined_index(): void {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 22 );
		$product->shouldReceive( 'get_meta' )->with( Storefront::FULL_SIZE_META )->andReturn( '' );
		$GLOBALS['product'] = $product;

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'product_id'       => 22,
					'position'         => TireSpecs::POS_KIT,
					'front_size_id'    => 1,
					'front_canonical'  => '120/70-17',
					'rear_size_id'     => 2,
					'rear_canonical'   => '180/55-17',
					'front_load'       => null,
					'rear_load'        => null,
					'front_speed_rank' => null,
					'rear_speed_rank'  => null,
					'construction'     => null,
					'terms_hash'       => 'kit',
				),
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_get_object_terms' )->justReturn( array( (object) array( 'term_id' => 9, 'name' => 'Pirelli', 'slug' => 'pirelli' ) ) );

		$rows = ( new Storefront() )->append_full_size_attribute_row(
			array(
				'attribute_pa_posicion'         => array( 'label' => 'Posición', 'value' => '<p>Kit</p>' ),
				'attribute_pa_medida-delantera' => array( 'label' => 'Medida delantera', 'value' => '<p>120/70-17</p>' ),
				'attribute_pa_medida-trasera'   => array( 'label' => 'Medida trasera', 'value' => '<p>180/55-17</p>' ),
				'attribute_pa_linea'            => array( 'label' => 'Línea', 'value' => '<p>Angel ST</p>' ),
			),
			$product
		);

		$this->assertSame(
			array(
				'motored_brand',
				'attribute_pa_linea',
				'motored_full_size',
				'attribute_pa_medida-delantera',
				'attribute_pa_medida-trasera',
				'attribute_pa_posicion',
			),
			array_keys( $rows )
		);
		$this->assertSame( '<p>Pirelli</p>', $rows['motored_brand']['value'] );
		$this->assertSame( '<p>120/70-17 + 180/55-17</p>', $rows['motored_full_size']['value'] );
		$this->assertArrayNotHasKey( 'motored_load_speed', $rows );
	}

	public function test_leaves_non_tire_product_attributes_untouched(): void {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( 33 );
		$product->shouldReceive( 'get_meta' )->with( Storefront::FULL_SIZE_META )->andReturn( '' );

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );
		$GLOBALS['wpdb'] = $wpdb;

		$input = array( 'attribute_pa_talle' => array( 'label' => 'Talle', 'value' => '<p>L</p>' ) );
		$this->assertSame( $input, ( new Storefront() )->append_full_size_attribute_row( $input, $product ) );
	}

	public function test_rest_controller_registers_path_routes_and_returns_bike_oem_sizes(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $ns, $route ) use ( &$routes ): void {
				$routes[] = $ns . $route;
			}
		);
		( new RestController() )->register_routes();
		$this->assertContains( 'motored/v1/compat/brands/(?P<brand_id>\d+)/models', $routes );
		$this->assertContains( 'motored/v1/compat/models/(?P<model_id>\d+)/years', $routes );

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			array(
				'id'         => 10,
				'model_id'   => 3,
				'slug'       => 'benelli-trk-502-2017-actual',
				'year_from'  => 2017,
				'year_to'    => 0,
				'variant'    => '',
				'status'     => 'published',
				'brand_name' => 'Benelli',
				'model_name' => 'TRK 502',
			)
		);
		$wpdb->shouldReceive( 'get_results' )->times( 3 )->andReturn(
			array(
				array(
					'id'               => 1,
					'version_id'       => 10,
					'position'         => TireSpecs::POS_FRONT,
					'size_id'          => 100,
					'canonical'        => '120/70-17',
					'fit_type'         => TireSpecs::FIT_OEM,
					'min_load'         => null,
					'min_speed_rank'   => null,
					'req_construction' => TireSpecs::CONST_RADIAL,
					'designation'      => '120/70ZR17',
					'source'           => 'manual',
					'note'             => '',
				),
				array(
					'id'               => 2,
					'version_id'       => 10,
					'position'         => TireSpecs::POS_REAR,
					'size_id'          => 101,
					'canonical'        => '160/60-17',
					'fit_type'         => TireSpecs::FIT_OEM,
					'min_load'         => null,
					'min_speed_rank'   => null,
					'req_construction' => TireSpecs::CONST_RADIAL,
					'designation'      => '160/60ZR17',
					'source'           => 'manual',
					'note'             => '',
				),
			),
			array(),
			array()
		);
		$GLOBALS['wpdb'] = $wpdb;

		if ( ! class_exists( 'WP_REST_Request' ) ) {
			eval( 'class WP_REST_Request { public function __construct( public string $slug = "" ) {} public function get_param( string $k ) { return "slug" === $k ? $this->slug : null; } }' );
		}
		if ( ! class_exists( 'WP_REST_Response' ) ) {
			eval( 'class WP_REST_Response { public array $headers = array(); public function __construct( public mixed $data = null, public int $status = 200 ) {} public function header( string $k, string $v ): void { $this->headers[ $k ] = $v; } }' );
		}

		$req = new \WP_REST_Request( 'benelli-trk-502-2017-actual' );
		$res = ( new RestController() )->get_bike( $req );
		$this->assertSame( 200, $res->status );
		$this->assertSame(
			array(
				'f' => '120/70ZR17',
				'r' => '160/60ZR17',
			),
			$res->data['oem']
		);
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
