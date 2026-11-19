<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\AttributeInstaller;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Tests\Unit\TestCase;

final class TireSpecsTest extends TestCase {

	public function test_all_attribute_slugs_respect_28_char_woocommerce_limit(): void {
		$orders = TireSpecs::attribute_order_map();
		$this->assertCount( 12, $orders );
		foreach ( array_keys( $orders ) as $slug ) {
			$this->assertLessThanOrEqual( 28, strlen( $slug ), $slug );
		}
		$this->assertSame( 'name', $orders[ TireSpecs::ATTR_FRONT_SIZE ] );
		$this->assertSame( 'name', $orders[ TireSpecs::ATTR_REAR_SIZE ] );
		$this->assertSame( 'name_num', $orders[ TireSpecs::ATTR_WIDTH ] );
		$this->assertSame( 'name_num', $orders[ TireSpecs::ATTR_PROFILE ] );
		$this->assertSame( 'name_num', $orders[ TireSpecs::ATTR_RIM ] );
		$this->assertSame( 'name_num', $orders[ TireSpecs::ATTR_LOAD_INDEX ] );
		$this->assertSame( 'menu_order', $orders[ TireSpecs::ATTR_SPEED_RATING ] );
		$this->assertSame( 'menu_order', $orders[ TireSpecs::ATTR_POSITION ] );
	}

	public function test_etrto_speed_ranks_follow_non_alphabetical_order(): void {
		$this->assertLessThan( TireSpecs::speed_rank( 'T' ), TireSpecs::speed_rank( 'S' ) );
		$this->assertLessThan( TireSpecs::speed_rank( 'U' ), TireSpecs::speed_rank( 'T' ) );
		$this->assertLessThan( TireSpecs::speed_rank( 'H' ), TireSpecs::speed_rank( 'U' ) );
		$this->assertLessThan( TireSpecs::speed_rank( 'V' ), TireSpecs::speed_rank( 'H' ) );
		$this->assertLessThan( TireSpecs::speed_rank( 'ZR' ), TireSpecs::speed_rank( 'V' ) );
		$this->assertLessThan( TireSpecs::speed_rank( 'W' ), TireSpecs::speed_rank( 'ZR' ) );
		$this->assertLessThan( TireSpecs::speed_rank( '(W)' ), TireSpecs::speed_rank( 'W' ) );
		$this->assertLessThan( TireSpecs::speed_rank( 'Y' ), TireSpecs::speed_rank( '(W)' ) );
		$this->assertSame( TireSpecs::speed_rank( '(W)' ), TireSpecs::speed_rank( 'w-par' ) );
	}

	public function test_fixed_term_sets_include_four_positions_and_omit_line(): void {
		Functions\when( '__' )->returnArg();
		$sets = AttributeInstaller::fixed_term_sets();
		$this->assertArrayHasKey( 'delantero', $sets[ TireSpecs::ATTR_POSITION ] );
		$this->assertArrayHasKey( 'trasero', $sets[ TireSpecs::ATTR_POSITION ] );
		$this->assertArrayHasKey( 'delantero-trasero', $sets[ TireSpecs::ATTR_POSITION ] );
		$this->assertArrayHasKey( 'kit', $sets[ TireSpecs::ATTR_POSITION ] );
		$this->assertArrayNotHasKey( TireSpecs::ATTR_LINE, $sets );
	}

	public function test_normalizes_positions_constructions_and_load_index(): void {
		$this->assertSame( TireSpecs::POS_FRONT, TireSpecs::normalize_position( 'Delantero' ) );
		$this->assertSame( TireSpecs::POS_REAR, TireSpecs::normalize_position( 'Trasero' ) );
		$this->assertSame( TireSpecs::POS_BOTH, TireSpecs::normalize_position( 'Delantero / Trasero' ) );
		$this->assertSame( TireSpecs::POS_KIT, TireSpecs::normalize_position( 'Kit' ) );
		$this->assertSame( TireSpecs::CONST_RADIAL, TireSpecs::normalize_construction( 'Radial' ) );
		$this->assertSame( TireSpecs::CONST_BELTED, TireSpecs::normalize_construction( 'Cinturado (B)' ) );
		$this->assertSame( 58, TireSpecs::parse_load_index( '58' ) );
		$this->assertNull( TireSpecs::parse_load_index( '5' ) );
	}
}
