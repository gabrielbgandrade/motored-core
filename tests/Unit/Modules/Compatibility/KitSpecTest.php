<?php
/**
 * Tests for the per-axle kit sheet (KitSpec), the load/speed suffix parser and their use in the index and matching.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\KitSpec;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\ProductIndexSync;
use Motored\Core\Modules\Compatibility\TireAttributePlan;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Tests\Unit\TestCase;

/**
 * Kit products carry one technical sheet per tire.
 */
final class KitSpecTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
	}

	public function test_suffix_parser_reads_load_and_speed_printed_after_the_size(): void {
		$this->assertSame( array( 'load' => 62, 'speed' => 'H' ), TireSpecs::parse_load_speed_suffix( '130/70-17 62H' ) );
		$this->assertSame( array( 'load' => 58, 'speed' => 'W' ), TireSpecs::parse_load_speed_suffix( '120/70ZR17 M/C (58W) TL' ) );
		$this->assertSame( array( 'load' => 73, 'speed' => '(W)' ), TireSpecs::parse_load_speed_suffix( '180/55ZR17 73(W)' ) );
		$this->assertSame( array( 'load' => 60, 'speed' => 'P' ), TireSpecs::parse_load_speed_suffix( '4.10-18 60P' ) );
		// The size itself never counts as a suffix.
		$this->assertSame( array( 'load' => null, 'speed' => '' ), TireSpecs::parse_load_speed_suffix( '120/70R17' ) );
		$this->assertSame( array( 'load' => null, 'speed' => '' ), TireSpecs::parse_load_speed_suffix( '110/80-19' ) );
	}

	public function test_axle_takes_fields_first_then_the_printed_suffix_and_derives_construction(): void {
		$a = KitSpec::axle( '120/70ZR17 58W', 'MT60 RS', '', '', '', 'tl' );
		$this->assertNotNull( $a );
		$this->assertSame( '120/70-17', $a['size'] );
		$this->assertSame( '120/70ZR17', $a['designation'] );
		$this->assertSame( 58, $a['load'] );
		$this->assertSame( 'W', $a['speed'] );
		$this->assertSame( TireSpecs::CONST_RADIAL, $a['construction'] );
		$this->assertSame( 'tl', $a['tube'] );

		$b = KitSpec::axle( '130/70-17 62H', '', '64', 'S', 'diagonal', 'bad' );
		$this->assertNotNull( $b );
		$this->assertSame( 64, $b['load'] );
		$this->assertSame( 'S', $b['speed'] );
		$this->assertSame( TireSpecs::CONST_DIAGONAL, $b['construction'] );
		$this->assertSame( '', $b['tube'] );

		$this->assertNull( KitSpec::axle( 'not a size', '', '', '', '', '' ) );
	}

	public function test_normalize_accepts_array_or_json_and_rejects_incomplete_sheets(): void {
		$spec = $this->spec();
		$this->assertSame( $spec, KitSpec::normalize( $spec ) );
		$this->assertSame( $spec, KitSpec::normalize( (string) json_encode( $spec ) ) );
		$this->assertNull( KitSpec::normalize( '' ) );
		$this->assertNull( KitSpec::normalize( array( 'front' => $spec['front'] ) ) );
		$this->assertTrue( KitSpec::matches_sizes( $spec, '100/80-17', '130/70-17' ) );
		$this->assertFalse( KitSpec::matches_sizes( $spec, '100/80-17', '140/70-17' ) );
		$this->assertSame( 'diagonal', KitSpec::shared( $spec, 'construction' ) );
		$this->assertSame( '', KitSpec::shared( $spec, 'line' ) );
		$this->assertTrue( KitSpec::has_ratings( $spec ) );
	}

	public function test_plan_for_kit_builds_the_sheet_and_only_shared_attributes(): void {
		$plan = TireAttributePlan::build(
			array(
				'position'          => 'kit',
				'size'              => '100/80-17 52H',
				'line'              => 'City Dragon',
				'tube'              => 'tl',
				'rear_size'         => '130/70-17 62H',
				'rear_line'         => 'Super City',
				'rear_construction' => 'radial',
				'rear_tube'         => 'tl',
			)
		);
		$this->assertSame( array(), $plan['errors'] );
		$t = $plan['terms'];
		$this->assertSame( 'kit', $t['posicion']['slug'] );
		$this->assertSame( '100/80-17', $t['medida-delantera']['name'] );
		$this->assertSame( '130/70-17', $t['medida-trasera']['name'] );
		// Never on kits: the facets exclude kits from these, so a term would desync filter and facet counts.
		foreach ( array( 'ancho', 'perfil', 'rodado', 'indice-carga', 'indice-velocidad' ) as $unit_only ) {
			$this->assertNull( $t[ $unit_only ], $unit_only );
		}
		// Front construction unknown ("-" in the size), rear radial: not shared, so no attribute.
		$this->assertNull( $t['construccion'] );
		$this->assertSame( 'tl', $t['camara']['slug'] );
		$this->assertSame( 'City Dragon', $t['linea']['name'] );
		$this->assertSame( 'Super City', $plan['extra_terms']['linea'][0]['name'] );

		$spec = $plan['spec'];
		$this->assertIsArray( $spec );
		$this->assertSame( 52, $spec['front']['load'] );
		$this->assertSame( 'H', $spec['front']['speed'] );
		$this->assertSame( 62, $spec['rear']['load'] );
		$this->assertSame( 'radial', $spec['rear']['construction'] );
	}

	public function test_plan_for_single_tire_reads_the_suffix_and_has_no_sheet(): void {
		$plan = TireAttributePlan::build(
			array(
				'position' => 'front',
				'size'     => '120/70ZR17 58W',
			)
		);
		$this->assertSame( array(), $plan['errors'] );
		$this->assertSame( '58', $plan['terms']['indice-carga']['name'] );
		$this->assertSame( 'w', $plan['terms']['indice-velocidad']['slug'] );
		$this->assertNull( $plan['spec'] );
		$this->assertSame( array(), $plan['extra_terms'] );
	}

	public function test_kit_plan_reports_a_bad_rear_load(): void {
		$plan = TireAttributePlan::build(
			array(
				'position'  => 'kit',
				'size'      => '100/80-17',
				'rear_size' => '130/70-17',
				'rear_load' => '300',
			)
		);
		$this->assertNotEmpty( $plan['errors'] );
		$this->assertNull( $plan['spec']['rear']['load'] );
	}

	public function test_index_row_uses_the_sheet_only_when_it_matches_the_attribute_sizes(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			static function ( $sql ) {
				unset( $sql );
				static $i = 0;
				++$i;
				return 1 === $i % 2
					? array( 'id' => 10, 'canonical' => '100/80-17', 'notation' => 'metric', 'width' => '100', 'aspect' => 80, 'rim' => 17 )
					: array( 'id' => 20, 'canonical' => '130/70-17', 'notation' => 'metric', 'width' => '130', 'aspect' => 70, 'rim' => 17 );
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		$raw = array(
			TireSpecs::ATTR_POSITION   => array( array( 'name' => 'Kit', 'slug' => 'kit' ) ),
			TireSpecs::ATTR_FRONT_SIZE => array( array( 'name' => '100/80-17', 'slug' => '100-80-17' ) ),
			TireSpecs::ATTR_REAR_SIZE  => array( array( 'name' => '130/70-17', 'slug' => '130-70-17' ) ),
		);

		$row = ProductIndexSync::build_index_row( 5, $raw, 'h', $this->spec() );
		$this->assertNotNull( $row );
		$this->assertSame( 52, $row['row']['front_load'] );
		$this->assertSame( 62, $row['row']['rear_load'] );
		$this->assertSame( TireSpecs::speed_rank( 'S' ), $row['row']['front_speed_rank'] );
		$this->assertSame( TireSpecs::speed_rank( 'H' ), $row['row']['rear_speed_rank'] );
		$this->assertSame( 'diagonal', $row['row']['front_construction'] );
		$this->assertSame( 'diagonal', $row['row']['rear_construction'] );

		// The ERP rewrote the rear size: the stale sheet is ignored (sizes only, like a legacy kit).
		$stale = $this->spec();
		$stale['rear']['size'] = '140/70-17';
		$row2  = ProductIndexSync::build_index_row( 5, $raw, 'h', $stale );
		$this->assertNotNull( $row2 );
		$this->assertNull( $row2['row']['front_load'] );
		$this->assertNull( $row2['row']['rear_construction'] );
	}

	public function test_hash_is_unchanged_without_a_sheet_and_changes_with_it(): void {
		$raw = array( TireSpecs::ATTR_POSITION => array( array( 'name' => 'Kit', 'slug' => 'kit' ) ) );
		$this->assertSame( ProductIndexSync::compute_terms_hash( $raw ), ProductIndexSync::compute_terms_hash( $raw, null ) );
		$this->assertNotSame( ProductIndexSync::compute_terms_hash( $raw ), ProductIndexSync::compute_terms_hash( $raw, $this->spec() ) );
	}

	public function test_axle_construction_prefers_the_sheet_value(): void {
		$fit = array(
			'construction'       => 'radial',
			'front_construction' => 'diagonal',
			'rear_construction'  => null,
		);
		$this->assertSame( 'diagonal', MatchEngine::axle_construction( $fit, TireSpecs::POS_FRONT ) );
		$this->assertSame( 'radial', MatchEngine::axle_construction( $fit, TireSpecs::POS_REAR ) );
		$this->assertSame( '', MatchEngine::axle_construction( array(), TireSpecs::POS_REAR ) );
	}

	/**
	 * A complete, normalized kit sheet (front 100/80-17 52S, rear 130/70-17 62H, both diagonal).
	 *
	 * @return array<string,mixed>
	 */
	private function spec(): array {
		return array(
			'v'     => 1,
			'front' => array(
				'size'         => '100/80-17',
				'designation'  => '100/80-17',
				'line'         => 'City Dragon',
				'load'         => 52,
				'speed'        => 'S',
				'construction' => 'diagonal',
				'tube'         => 'tl',
			),
			'rear'  => array(
				'size'         => '130/70-17',
				'designation'  => '130/70-17',
				'line'         => 'Super City',
				'load'         => 62,
				'speed'        => 'H',
				'construction' => 'diagonal',
				'tube'         => 'tl',
			),
		);
	}
}
