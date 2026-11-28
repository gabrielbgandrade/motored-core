<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Motored\Core\Modules\Compatibility\TireCard;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Tests\Unit\TestCase;

final class TireCardTest extends TestCase {

	public function test_load_speed_code_keeps_only_valid_parts(): void {
		$this->assertSame( '69H', TireCard::load_speed_code( '69', 'H' ) );
		$this->assertSame( '58W', TireCard::load_speed_code( '58', 'w' ) );
		$this->assertSame( '73(W)', TireCard::load_speed_code( '73', 'w-par' ) );
		$this->assertSame( 'H', TireCard::load_speed_code( '', 'H' ) );
		$this->assertSame( '69', TireCard::load_speed_code( '69', 'nonsense' ) );
		$this->assertSame( '', TireCard::load_speed_code( '999', '' ) );
	}

	public function test_tube_code_reads_the_attribute_label(): void {
		$this->assertSame( 'TL', TireCard::tube_code( 'Sin cámara (TL)' ) );
		$this->assertSame( 'TT', TireCard::tube_code( 'Con cámara (TT)' ) );
		$this->assertSame( 'TL/TT', TireCard::tube_code( 'TL / TT' ) );
		$this->assertSame( '', TireCard::tube_code( 'Sin cámara' ) );
	}

	public function test_indexes_give_kg_and_kmh_and_skip_unknown_values(): void {
		$this->assertSame( array( 'load' => array( 'code' => '69', 'kg' => TireSpecs::load_kg( 69 ) ), 'speed' => array( 'code' => 'H', 'kmh' => 210 ) ), TireCard::indexes( '69', 'h' ) );
		$this->assertSame( array( 'load' => array(), 'speed' => array() ), TireCard::indexes( '999', 'nonsense' ) );
	}
}
