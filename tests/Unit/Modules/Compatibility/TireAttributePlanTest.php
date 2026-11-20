<?php
/**
 * Tests for TireAttributePlan.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Motored\Core\Modules\Compatibility\TireAttributePlan;
use Brain\Monkey\Functions;
use Motored\Core\Tests\Unit\TestCase;

/**
 * Pure planner: form values -> attribute terms.
 */
final class TireAttributePlanTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
	}

	public function test_rear_tire_sets_rear_size_and_dimensions(): void {
		$plan = TireAttributePlan::build(
			array(
				'position' => 'rear',
				'size'     => '160/60ZR17',
				'speed'    => 'W',
				'load'     => '69',
			)
		);
		$this->assertSame( array(), $plan['errors'] );
		$t = $plan['terms'];
		$this->assertSame( 'trasero', $t['posicion']['slug'] );
		$this->assertSame( '160/60-17', $t['medida-trasera']['name'] );
		$this->assertNull( $t['medida-delantera'] );
		$this->assertSame( '160', $t['ancho']['name'] );
		$this->assertSame( '60', $t['perfil']['name'] );
		$this->assertSame( '17', $t['rodado']['name'] );
		$this->assertSame( 'radial', $t['construccion']['slug'] );
		$this->assertSame( 'w', $t['indice-velocidad']['slug'] );
		$this->assertSame( '69', $t['indice-carga']['name'] );
	}

	public function test_front_tire_with_dash_has_no_construction(): void {
		$plan = TireAttributePlan::build(
			array(
				'position' => 'front',
				'size'     => '110/80-19',
			)
		);
		$t = $plan['terms'];
		$this->assertSame( '110/80-19', $t['medida-delantera']['name'] );
		$this->assertNull( $t['medida-trasera'] );
		$this->assertNull( $t['construccion'] );
	}

	public function test_kit_needs_both_sizes_and_skips_dimensions(): void {
		$bad = TireAttributePlan::build(
			array(
				'position' => 'kit',
				'size'     => '120/70ZR17',
			)
		);
		$this->assertNotEmpty( $bad['errors'] );

		$plan = TireAttributePlan::build(
			array(
				'position'  => 'kit',
				'size'      => '120/70ZR17',
				'rear_size' => '180/55ZR17',
			)
		);
		$this->assertSame( array(), $plan['errors'] );
		$this->assertSame( 'kit', $plan['terms']['posicion']['slug'] );
		$this->assertSame( '120/70-17', $plan['terms']['medida-delantera']['name'] );
		$this->assertSame( '180/55-17', $plan['terms']['medida-trasera']['name'] );
		$this->assertNull( $plan['terms']['ancho'] );
	}

	public function test_invalid_inputs_report_errors(): void {
		$this->assertNotEmpty( TireAttributePlan::build( array( 'position' => '' ) )['errors'] );
		$this->assertNotEmpty( TireAttributePlan::build( array( 'position' => 'rear', 'size' => 'abc' ) )['errors'] );
		$plan = TireAttributePlan::build(
			array(
				'position' => 'rear',
				'size'     => '160/60-17',
				'speed'    => 'X',
				'load'     => '5',
			)
		);
		$this->assertCount( 2, $plan['errors'] );
		$this->assertNull( $plan['terms']['indice-velocidad'] );
		$this->assertNull( $plan['terms']['indice-carga'] );
	}

	public function test_explicit_construction_overrides_designation(): void {
		$plan = TireAttributePlan::build(
			array(
				'position'     => 'front',
				'size'         => '150/70-17',
				'construction' => 'cinturado',
			)
		);
		$this->assertSame( 'cinturado', $plan['terms']['construccion']['slug'] );
	}
}
