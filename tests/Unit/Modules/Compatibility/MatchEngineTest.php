<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Tests\Unit\TestCase;

final class MatchEngineTest extends TestCase {

	private function sample_bike(): array {
		return array(
			'slug'  => 'honda-xr-250-tornado-2001-actual',
			'label' => 'Honda XR 250 Tornado (2001–actual)',
			'front' => array(
				'90/90-21' => array(
					'size_id'   => 10,
					'type'      => TireSpecs::FIT_OEM,
					'from'      => '',
					'approx'    => 0,
					'min_load'  => 54,
					'min_speed' => TireSpecs::speed_rank( 'H' ),
					'req_const' => TireSpecs::CONST_RADIAL,
				),
			),
			'rear'  => array(
				'4.10-18' => array(
					'size_id'   => 20,
					'type'      => TireSpecs::FIT_OEM,
					'from'      => '',
					'approx'    => 0,
					'min_load'  => null,
					'min_speed' => null,
					'req_const' => null,
				),
				'120/80-18' => array(
					'size_id'   => 21,
					'type'      => TireSpecs::FIT_EQUIV,
					'from'      => '4.10-18',
					'approx'    => 1,
					'min_load'  => null,
					'min_speed' => null,
					'req_const' => null,
				),
			),
		);
	}

	public function test_evaluates_single_dual_kit_and_rating_thresholds(): void {
		$bike = $this->sample_bike();

		// Front tire with higher speed rating (V >= H) passes cleanly.
		$front_v = array(
			'position'         => TireSpecs::POS_FRONT,
			'front_canonical'  => '90/90-21',
			'rear_canonical'   => null,
			'front_load'       => 54,
			'rear_load'        => null,
			'front_speed_rank' => TireSpecs::speed_rank( 'V' ),
			'rear_speed_rank'  => null,
			'construction'     => TireSpecs::CONST_RADIAL,
		);
		$res = MatchEngine::evaluate_product( $front_v, $bike, false );
		$this->assertTrue( $res['compatible'] );
		$this->assertSame( array( 'front' ), $res['positions'] );
		$this->assertSame( 'oem', $res['match'] );
		$this->assertFalse( $res['caution'] );

		// Speed rating below minimum (S < H) ALWAYS fails even when strict_specs is false.
		$front_s = $front_v;
		$front_s['front_speed_rank'] = TireSpecs::speed_rank( 'S' );
		$this->assertFalse( MatchEngine::evaluate_product( $front_s, $bike, false )['compatible'] );

		// Missing rating passes with caution when strict_specs=false, fails when strict_specs=true.
		$front_missing = $front_v;
		$front_missing['front_speed_rank'] = null;
		$lenient = MatchEngine::evaluate_product( $front_missing, $bike, false );
		$this->assertTrue( $lenient['compatible'] );
		$this->assertTrue( $lenient['caution'] );
		$this->assertFalse( MatchEngine::evaluate_product( $front_missing, $bike, true )['compatible'] );

		// Kit matches ONLY when BOTH front and rear match; inherits equiv when one axle is equiv.
		$kit_ok = array(
			'position'         => TireSpecs::POS_KIT,
			'front_canonical'  => '90/90-21',
			'rear_canonical'   => '120/80-18',
			'front_load'       => null,
			'rear_load'        => null,
			'front_speed_rank' => null,
			'rear_speed_rank'  => null,
			'construction'     => TireSpecs::CONST_RADIAL,
		);
		$kit_res = MatchEngine::evaluate_product( $kit_ok, $bike, false );
		$this->assertTrue( $kit_res['compatible'] );
		$this->assertSame( 'equiv', $kit_res['match'] );
		$this->assertSame( '4.10-18', $kit_res['equivalent_from'] );
		$this->assertTrue( $kit_res['caution'] );

		// Kit with mismatched rear fails completely even though front fits.
		$kit_bad = $kit_ok;
		$kit_bad['rear_canonical'] = '180/55-17';
		$this->assertFalse( MatchEngine::evaluate_product( $kit_bad, $bike, false )['compatible'] );

		// Dual (ambas) tire matches rear via equivalence.
		$both = array(
			'position'         => TireSpecs::POS_BOTH,
			'front_canonical'  => '120/80-18',
			'rear_canonical'   => '120/80-18',
			'front_load'       => null,
			'rear_load'        => null,
			'front_speed_rank' => null,
			'rear_speed_rank'  => null,
			'construction'     => null,
		);
		$both_res = MatchEngine::evaluate_product( $both, $bike, false );
		$this->assertTrue( $both_res['compatible'] );
		$this->assertSame( array( 'rear' ), $both_res['positions'] );
		$this->assertSame( 'equiv', $both_res['match'] );
	}

	public function test_bike_profile_applies_one_hop_equivalences_from_oem_only_and_never_transitive(): void {
		$this->fake_storage();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) $s ) ) );
		Functions\when( '__' )->returnArg();

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'id'           => 1,
				'model_id'     => 2,
				'slug'         => 'moto-1',
				'year_from'    => 2020,
				'year_to'      => 0,
				'variant'      => '',
				'variant_slug' => '',
				'status'       => 'published',
				'verified'     => 1,
				'notes'        => '',
				'model_name'   => 'XR',
				'model_slug'   => 'xr',
				'cc'           => 250,
				'aliases'      => '',
				'brand_id'     => 1,
				'brand_name'   => 'Honda',
				'brand_slug'   => 'honda',
			)
		);
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			// 1st call: fitments_for_version (1 OEM + 1 ALT).
			array(
				array(
					'id'               => 1,
					'version_id'       => 1,
					'position'         => 'rear',
					'size_id'          => 20,
					'canonical'        => '4.10-18',
					'fit_type'         => 'oem',
					'min_load'         => null,
					'min_speed_rank'   => null,
					'req_construction' => null,
					'source'           => 'manual',
					'note'             => '',
				),
				array(
					'id'               => 2,
					'version_id'       => 1,
					'position'         => 'rear',
					'size_id'          => 30,
					'canonical'        => '110/90-18',
					'fit_type'         => 'alt',
					'min_load'         => null,
					'min_speed_rank'   => null,
					'req_construction' => null,
					'source'           => 'manual',
					'note'             => '',
				),
			),
			// 2nd call: equivalences_from_sizes for rear OEM (4.10-18 -> 120/80-18 only, no 2-hop chain).
			array(
				array(
					'source_size_id'   => 20,
					'source_canonical' => '4.10-18',
					'target_size_id'   => 21,
					'target_canonical' => '120/80-18',
					'is_approximate'   => 1,
					'note'             => '',
				),
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		$prof = MatchEngine::bike_profile( 'moto-1' );
		$this->assertNotNull( $prof );
		$this->assertSame( 'oem', $prof['rear']['4.10-18']['type'] );
		$this->assertSame( 'alt', $prof['rear']['110/90-18']['type'] );
		$this->assertSame( 'equiv', $prof['rear']['120/80-18']['type'] );
		$this->assertSame( '4.10-18', $prof['rear']['120/80-18']['from'] );
	}

	public function test_compatible_motos_for_product_intersects_both_axles_for_kits(): void {
		$this->fake_storage();
		Functions\when( '__' )->returnArg();

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			// 1st call: ProductIndexRepository::find_many for kit product #500.
			array(
				array(
					'product_id'       => 500,
					'position'         => TireSpecs::POS_KIT,
					'front_size_id'    => 10,
					'front_canonical'  => '120/70-17',
					'rear_size_id'     => 20,
					'rear_canonical'   => '180/55-17',
					'front_load'       => null,
					'rear_load'        => null,
					'front_speed_rank' => null,
					'rear_speed_rank'  => null,
					'construction'     => TireSpecs::CONST_RADIAL,
					'terms_hash'       => 'xyz',
				),
			),
			// 2nd call: direct front fitments -> version 1 and version 2.
			array(
				array(
					'version_id' => 1, 'version_slug' => 'z900', 'year_from' => 2017, 'year_to' => 0, 'variant' => '',
					'model_id' => 1, 'model_name' => 'Z900', 'aliases' => '', 'brand_id' => 1, 'brand_name' => 'Kawasaki',
					'fit_type' => 'oem', 'min_load' => null, 'min_speed_rank' => null, 'req_construction' => null, 'is_equiv' => '0', 'equiv_from' => '',
				),
				array(
					'version_id' => 2, 'version_slug' => 'mt07', 'year_from' => 2014, 'year_to' => 0, 'variant' => '',
					'model_id' => 2, 'model_name' => 'MT-07', 'aliases' => '', 'brand_id' => 2, 'brand_name' => 'Yamaha',
					'fit_type' => 'oem', 'min_load' => null, 'min_speed_rank' => null, 'req_construction' => null, 'is_equiv' => '0', 'equiv_from' => '',
				),
			),
			// 3rd call: equivalences_to_size(front 10).
			array(),
			// 4th call: direct rear fitments -> only version 1 (Z900) accepts 180/55-17; MT-07 is excluded.
			array(
				array(
					'version_id' => 1, 'version_slug' => 'z900', 'year_from' => 2017, 'year_to' => 0, 'variant' => '',
					'model_id' => 1, 'model_name' => 'Z900', 'aliases' => '', 'brand_id' => 1, 'brand_name' => 'Kawasaki',
					'fit_type' => 'oem', 'min_load' => null, 'min_speed_rank' => null, 'req_construction' => null, 'is_equiv' => '0', 'equiv_from' => '',
				),
			),
			// 5th call: equivalences_to_size(rear 20).
			array()
		);
		$GLOBALS['wpdb'] = $wpdb;

		$out = MatchEngine::compatible_motos_for_product( 500 );
		$this->assertNotNull( $out );
		$this->assertSame( TireSpecs::POS_KIT, $out['position'] );
		$this->assertCount( 1, $out['items'] );
		$this->assertSame( 'z900', $out['items'][0]['slug'] );
		$this->assertSame( array( 'front', 'rear' ), $out['items'][0]['positions'] );
	}
}
