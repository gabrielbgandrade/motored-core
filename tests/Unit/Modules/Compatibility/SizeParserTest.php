<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Motored\Core\Modules\Compatibility\SizeParser;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Tests\Unit\TestCase;

final class SizeParserTest extends TestCase {

	public function test_parses_metric_variants_to_single_canonical_key(): void {
		$cases = array(
			'120/70-17',
			'120/70R17',
			'120/70ZR17',
			'120/70 ZR 17 (58W) M/C TL FRONT',
			'120/70 R17 TL',
		);
		foreach ( $cases as $raw ) {
			$parsed = SizeParser::parse( $raw );
			$this->assertIsArray( $parsed, $raw );
			$this->assertSame( '120/70-17', $parsed['canonical'], $raw );
			$this->assertSame( SizeParser::NOTATION_METRIC, $parsed['notation'] );
			$this->assertSame( '120', $parsed['width'] );
			$this->assertSame( 70, $parsed['aspect'] );
			$this->assertSame( 17, $parsed['rim'] );
		}

		$belted = SizeParser::parse( '150/80B16 71H TL/TT' );
		$this->assertNotNull( $belted );
		$this->assertSame( '150/80-16', $belted['canonical'] );

		$enduro = SizeParser::parse( '80/100-21' );
		$this->assertNotNull( $enduro );
		$this->assertSame( '80/100-21', $enduro['canonical'] );
		$this->assertSame( 100, $enduro['aspect'] );

		$vb = SizeParser::parse( '120/70 VB 17' );
		$this->assertNotNull( $vb );
		$this->assertSame( '120/70-17', $vb['canonical'] );
	}

	public function test_parses_imperial_widths_as_strings_without_dropping_trailing_zeros(): void {
		$p1 = SizeParser::parse( '4.10-18' );
		$this->assertNotNull( $p1 );
		$this->assertSame( '4.10-18', $p1['canonical'] );
		$this->assertSame( SizeParser::NOTATION_IMPERIAL, $p1['notation'] );
		$this->assertSame( '4.10', $p1['width'] );
		$this->assertNull( $p1['aspect'] );
		$this->assertSame( 18, $p1['rim'] );

		$p2 = SizeParser::parse( '4,10-18 TT' );
		$this->assertNotNull( $p2 );
		$this->assertSame( '4.10-18', $p2['canonical'] );

		$p3 = SizeParser::parse( '3.00-18' );
		$this->assertNotNull( $p3 );
		$this->assertSame( '3.00-18', $p3['canonical'] );
		$this->assertSame( '3.00', $p3['width'] );

		$p4 = SizeParser::parse( '3.5-10' );
		$this->assertNotNull( $p4 );
		$this->assertSame( '3.50-10', $p4['canonical'] );
	}

	public function test_parses_alpha_cruiser_sizes(): void {
		$mt = SizeParser::parse( 'MT90-16' );
		$this->assertNotNull( $mt );
		$this->assertSame( 'MT90-16', $mt['canonical'] );
		$this->assertSame( SizeParser::NOTATION_ALPHA, $mt['notation'] );
		$this->assertSame( 'MT90', $mt['width'] );
		$this->assertNull( $mt['aspect'] );
		$this->assertSame( 16, $mt['rim'] );

		$mu = SizeParser::parse( 'MU85B16 77H' );
		$this->assertNotNull( $mu );
		$this->assertSame( 'MU85-16', $mu['canonical'] );

		$mh = SizeParser::parse( 'MH90-21' );
		$this->assertNotNull( $mh );
		$this->assertSame( 'MH90-21', $mh['canonical'] );
	}

	public function test_rejects_invalid_inputs_and_flags_suspicious_rims(): void {
		$this->assertNull( SizeParser::parse( '' ) );
		$this->assertNull( SizeParser::parse( '   ' ) );
		$this->assertNull( SizeParser::parse( 'Metzeler Sportec' ) );
		$this->assertNull( SizeParser::parse( '999/70-17' ) );
		$this->assertNull( SizeParser::parse( '120/10-17' ) );

		$this->assertTrue( SizeParser::is_suspicious_rim( 7 ) );
		$this->assertTrue( SizeParser::is_suspicious_rim( 26 ) );
		$this->assertFalse( SizeParser::is_suspicious_rim( 8 ) );
		$this->assertFalse( SizeParser::is_suspicious_rim( 17 ) );
		$this->assertFalse( SizeParser::is_suspicious_rim( 23 ) );
	}

	public function test_formats_full_size_display_labels(): void {
		$metric = SizeParser::parse( '120/70-17' );
		$this->assertNotNull( $metric );
		$this->assertSame( '120/70ZR17', SizeParser::format_full_size( $metric, TireSpecs::CONST_RADIAL, 'W' ) );
		$this->assertSame( '120/70R17', SizeParser::format_full_size( $metric, TireSpecs::CONST_RADIAL, 'H' ) );
		$this->assertSame( '120/70B17', SizeParser::format_full_size( $metric, TireSpecs::CONST_BELTED, 'S' ) );
		$this->assertSame( '120/70-17', SizeParser::format_full_size( $metric, TireSpecs::CONST_DIAGONAL, 'S' ) );

		$imp = SizeParser::parse( '4.10-18' );
		$this->assertNotNull( $imp );
		$this->assertSame( '4.10-18', SizeParser::format_full_size( $imp, TireSpecs::CONST_DIAGONAL, 'S' ) );
	}
}
