<?php
/**
 * Tests for FitmentRow (motorcycle tire size rows) and the open-ended year label.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Motored\Core\Modules\Compatibility\FitmentRow;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Tests\Unit\TestCase;

/**
 * Sizes typed the way the manual prints them.
 */
final class FitmentRowTest extends TestCase {

	public function test_printed_size_gives_key_designation_construction_and_minimum_rating(): void {
		$row = FitmentRow::parse(
			array(
				'size'     => '120/70ZR17 58W',
				'position' => 'front',
			)
		);
		$this->assertTrue( $row['ok'] );
		$this->assertSame( '120/70-17', $row['canonical'] );
		$this->assertSame( '120/70ZR17', $row['designation'] );
		$this->assertSame( TireSpecs::FIT_OEM, $row['fit_type'] );
		$this->assertSame( 58, $row['min_load'] );
		$this->assertSame( TireSpecs::speed_rank( 'W' ), $row['min_speed_rank'] );
		$this->assertSame( TireSpecs::CONST_RADIAL, $row['req_construction'] );
	}

	public function test_explicit_fields_win_and_dash_sizes_have_no_construction_rule(): void {
		$row = FitmentRow::parse(
			array(
				'size'      => '110/80-19 59H',
				'position'  => 'front',
				'fit_type'  => 'alt',
				'min_load'  => '57',
				'min_speed' => 'S',
				'note'      => ' rally ',
			)
		);
		$this->assertTrue( $row['ok'] );
		$this->assertSame( TireSpecs::FIT_ALT, $row['fit_type'] );
		$this->assertSame( 57, $row['min_load'] );
		$this->assertSame( TireSpecs::speed_rank( 'S' ), $row['min_speed_rank'] );
		$this->assertNull( $row['req_construction'] );
		$this->assertSame( 'rally', $row['note'] );

		$plain = FitmentRow::parse(
			array(
				'size'     => '4.10-18',
				'position' => 'rear',
			)
		);
		$this->assertTrue( $plain['ok'] );
		$this->assertNull( $plain['min_load'] );
		$this->assertNull( $plain['min_speed_rank'] );
	}

	public function test_invalid_rows_say_what_is_wrong(): void {
		$this->assertSame( 'size', FitmentRow::parse( array( 'size' => 'abc', 'position' => 'front' ) )['error'] );
		$this->assertSame( 'position', FitmentRow::parse( array( 'size' => '120/70-17', 'position' => 'kit' ) )['error'] );
		$this->assertSame( 'load', FitmentRow::parse( array( 'size' => '120/70-17', 'position' => 'front', 'min_load' => '300' ) )['error'] );
		$this->assertSame( 'speed', FitmentRow::parse( array( 'size' => '120/70-17', 'position' => 'front', 'min_speed' => 'X' ) )['error'] );
	}

	public function test_open_ended_year_label(): void {
		$this->assertSame( '2017+', MatchEngine::format_years( 2017, 0 ) );
		$this->assertSame( '2008–2016', MatchEngine::format_years( 2008, 2016 ) );
	}
}
