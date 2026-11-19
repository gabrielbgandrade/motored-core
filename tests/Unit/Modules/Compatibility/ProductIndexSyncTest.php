<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\ProductIndexSync;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Tests\Unit\TestCase;

final class ProductIndexSyncTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		ProductIndexSync::reset();
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		ProductIndexSync::reset();
		parent::tearDown();
	}

	public function test_sync_product_skips_db_write_when_terms_hash_is_unchanged(): void {
		$this->fake_storage();
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 55, 'post_type' => 'product', 'post_status' => 'publish' ) );
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wc_attribute_taxonomy_name' )->alias( static fn( $s ) => 'pa_' . $s );
		Functions\when( 'wp_get_object_terms' )->alias(
			static function ( $pid, $tax ) {
				unset( $pid );
				if ( 'pa_' . TireSpecs::ATTR_POSITION === $tax ) {
					return array( (object) array( 'term_id' => 1, 'name' => 'Delantero', 'slug' => 'delantero' ) );
				}
				if ( 'pa_' . TireSpecs::ATTR_FRONT_SIZE === $tax ) {
					return array( (object) array( 'term_id' => 2, 'name' => '120/70-17', 'slug' => '120-70-17' ) );
				}
				return array();
			}
		);

		$expected_hash = ProductIndexSync::compute_terms_hash(
			array(
				TireSpecs::ATTR_POSITION   => array( array( 'name' => 'Delantero', 'slug' => 'delantero' ) ),
				TireSpecs::ATTR_FRONT_SIZE => array( array( 'name' => '120/70-17', 'slug' => '120-70-17' ) ),
			)
		);

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( $expected_hash );
		$wpdb->shouldReceive( 'query' )->never();
		$GLOBALS['wpdb'] = $wpdb;

		$this->assertSame( 'unchanged', ProductIndexSync::sync_product( 55 ) );
	}

	public function test_kit_never_infers_shared_load_or_speed_and_both_mirrors_single_size(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 10, 'canonical' => '120/70-17', 'notation' => 'metric', 'width' => '120', 'aspect' => 70, 'rim' => 17 ),
			array( 'id' => 20, 'canonical' => '180/55-17', 'notation' => 'metric', 'width' => '180', 'aspect' => 55, 'rim' => 17 ),
			array( 'id' => 30, 'canonical' => '90/90-18', 'notation' => 'metric', 'width' => '90', 'aspect' => 90, 'rim' => 18 )
		);
		$GLOBALS['wpdb'] = $wpdb;

		// Kit with front + rear size and shared load/speed: must keep per-axle load/speed strictly null.
		$kit = ProductIndexSync::build_index_row(
			101,
			array(
				TireSpecs::ATTR_POSITION     => array( array( 'name' => 'Kit', 'slug' => 'kit' ) ),
				TireSpecs::ATTR_FRONT_SIZE   => array( array( 'name' => '120/70-17', 'slug' => '120-70-17' ) ),
				TireSpecs::ATTR_REAR_SIZE    => array( array( 'name' => '180/55-17', 'slug' => '180-55-17' ) ),
				TireSpecs::ATTR_LOAD_INDEX   => array( array( 'name' => '58', 'slug' => '58' ) ),
				TireSpecs::ATTR_SPEED_RATING => array( array( 'name' => 'W', 'slug' => 'w' ) ),
				TireSpecs::ATTR_CONSTRUCTION => array( array( 'name' => 'Radial', 'slug' => 'radial' ) ),
			),
			'abc'
		);
		$this->assertNotNull( $kit );
		$this->assertSame( TireSpecs::POS_KIT, $kit['row']['position'] );
		$this->assertSame( 10, $kit['row']['front_size_id'] );
		$this->assertSame( 20, $kit['row']['rear_size_id'] );
		$this->assertNull( $kit['row']['front_load'] );
		$this->assertNull( $kit['row']['rear_load'] );
		$this->assertNull( $kit['row']['front_speed_rank'] );
		$this->assertNull( $kit['row']['rear_speed_rank'] );
		$this->assertNull( $kit['parsed_single'] );

		// Universal (ambas) mirrors the single size onto both front_size_id and rear_size_id.
		$both = ProductIndexSync::build_index_row(
			102,
			array(
				TireSpecs::ATTR_POSITION   => array( array( 'name' => 'Delantero / Trasero', 'slug' => 'delantero-trasero' ) ),
				TireSpecs::ATTR_FRONT_SIZE => array( array( 'name' => '90/90-18', 'slug' => '90-90-18' ) ),
				TireSpecs::ATTR_LOAD_INDEX => array( array( 'name' => '51', 'slug' => '51' ) ),
			),
			'def'
		);
		$this->assertNotNull( $both );
		$this->assertSame( 30, $both['row']['front_size_id'] );
		$this->assertSame( 30, $both['row']['rear_size_id'] );
		$this->assertSame( 51, $both['row']['front_load'] );
		$this->assertSame( 51, $both['row']['rear_load'] );
	}

	public function test_phase7_autofill_dimensions_runs_for_single_tires_only_when_enabled(): void {
		$this->fake_storage();
		$this->options['motored_compatibility'] = array_merge( \Motored\Core\Modules\Compatibility\Config::DEFAULTS, array( 'auto_fill_dimensions' => 'yes' ) );

		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 77, 'post_type' => 'product', 'post_status' => 'publish' ) );
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wc_attribute_taxonomy_name' )->alias( static fn( $s ) => 'pa_' . $s );
		Functions\when( 'wc_attribute_taxonomy_slug' )->alias( static fn( $s ) => str_replace( 'pa_', '', $s ) );
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->justReturn( 1 );
		Functions\when( 'wp_get_object_terms' )->alias(
			static function ( $pid, $tax ) {
				unset( $pid );
				if ( 'pa_posicion' === $tax ) {
					return array( (object) array( 'term_id' => 1, 'name' => 'Delantero', 'slug' => 'delantero' ) );
				}
				if ( 'pa_medida-delantera' === $tax ) {
					return array( (object) array( 'term_id' => 2, 'name' => '120/70-17', 'slug' => '120-70-17' ) );
				}
				return array();
			}
		);

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 10, 'canonical' => '120/70-17', 'notation' => 'metric', 'width' => '120', 'aspect' => 70, 'rim' => 17 ) );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;

		if ( ! class_exists( 'WC_Product_Attribute' ) ) {
			eval( 'class WC_Product_Attribute { public function set_id($v){} public function set_name($v){} public function set_options($v){} public function set_position($v){} public function set_visible($v){} public function set_variation($v){} }' );
		}

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'is_type' )->with( 'simple' )->andReturn( true );
		$product->shouldReceive( 'get_id' )->andReturn( 77 );
		$product->shouldReceive( 'get_attributes' )->with( 'edit' )->andReturn( array() );
		$product->shouldReceive( 'set_attributes' )->times( 3 );
		$product->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$assigned = array();
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'wp_insert_term' )->justReturn( array( 'term_id' => 88 ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_set_object_terms' )->alias(
			static function ( $pid, $terms, $tax ) use ( &$assigned ): array {
				unset( $pid, $terms );
				$assigned[] = $tax;
				return array( 88 );
			}
		);

		$this->assertSame( 'updated', ProductIndexSync::sync_product( 77 ) );
		$this->assertSame( array( 'pa_ancho', 'pa_rodado', 'pa_perfil' ), $assigned );

		// Kits NEVER auto-fill ancho/perfil/rodado even when auto_fill_dimensions is enabled.
		$kit_assigned = array();
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 78, 'post_type' => 'product', 'post_status' => 'publish' ) );
		Functions\when( 'wp_get_object_terms' )->alias(
			static function ( $pid, $tax ) {
				unset( $pid );
				if ( 'pa_posicion' === $tax ) {
					return array( (object) array( 'term_id' => 1, 'name' => 'Kit', 'slug' => 'kit' ) );
				}
				if ( 'pa_medida-delantera' === $tax ) {
					return array( (object) array( 'term_id' => 2, 'name' => '120/70-17', 'slug' => '120-70-17' ) );
				}
				if ( 'pa_medida-trasera' === $tax ) {
					return array( (object) array( 'term_id' => 3, 'name' => '180/55-17', 'slug' => '180-55-17' ) );
				}
				return array();
			}
		);
		$wpdb2         = \Mockery::mock( 'wpdb_fake' );
		$wpdb2->prefix = 'wp_';
		$wpdb2->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb2->shouldReceive( 'get_var' )->andReturn( '' );
		$wpdb2->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 10, 'canonical' => '120/70-17', 'notation' => 'metric', 'width' => '120', 'aspect' => 70, 'rim' => 17 ),
			array( 'id' => 20, 'canonical' => '180/55-17', 'notation' => 'metric', 'width' => '180', 'aspect' => 55, 'rim' => 17 )
		);
		$wpdb2->shouldReceive( 'query' )->once()->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb2;
		Functions\when( 'wp_set_object_terms' )->alias(
			static function ( $pid, $terms, $tax ) use ( &$kit_assigned ): array {
				unset( $pid, $terms );
				$kit_assigned[] = $tax;
				return array( 88 );
			}
		);
		$this->assertSame( 'updated', ProductIndexSync::sync_product( 78 ) );
		$this->assertSame( array(), $kit_assigned );
	}

	public function test_non_simple_products_are_excluded_from_index_and_deleted(): void {
		$this->fake_storage();
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 91, 'post_type' => 'product', 'post_status' => 'publish' ) );
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wc_attribute_taxonomy_name' )->alias( static fn( $s ) => 'pa_' . $s );
		Functions\when( 'wp_get_object_terms' )->alias(
			static function ( $pid, $tax ) {
				unset( $pid );
				if ( 'product_type' === $tax ) {
					return array( (object) array( 'term_id' => 9, 'name' => 'Variable', 'slug' => 'variable' ) );
				}
				return array();
			}
		);

		if ( ! class_exists( 'WC_Product' ) ) {
			eval( 'class WC_Product { public function get_id(){return 0;} public function get_status(){return "publish";} public function is_type($t){return false;} }' );
		}
		$var_product = \Mockery::mock( 'WC_Product' );
		$var_product->shouldReceive( 'get_id' )->andReturn( 91 );
		$var_product->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$var_product->shouldReceive( 'is_type' )->with( 'simple' )->andReturn( false );
		Functions\when( 'wc_get_product' )->justReturn( $var_product );

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'delete' )->twice()->with( 'wp_motored_compat_product_index', array( 'product_id' => 91 ), array( '%d' ) )->andReturn( 1 );
		$wpdb->shouldReceive( 'get_var' )->never();
		$wpdb->shouldReceive( 'query' )->never();
		$GLOBALS['wpdb'] = $wpdb;

		$this->assertSame( 'deleted', ProductIndexSync::sync_product( 91 ) );
		( new ProductIndexSync() )->on_product_save( $var_product );
		ProductIndexSync::reset();
	}
}
