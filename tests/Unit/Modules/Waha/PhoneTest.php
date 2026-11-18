<?php
namespace Motored\Core\Tests\Unit\Modules\Waha;

use Motored\Core\Modules\Waha\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:?string}>
	 */
	public static function argentine_inputs(): array {
		return array(
			'area 11 with 0 and 15' => array( '011 15 5555-1234', '+5491155551234' ),
			'international mobile'  => array( '+54 9 11 5555 1234', '+5491155551234' ),
			'area 351 with 0 + 15'  => array( '0351 15 555 1234', '+5493515551234' ),
			'plain national'        => array( '11 5555 1234', '+5491155551234' ),
			'country code no plus'  => array( '5491155551234', '+5491155551234' ),
			'00 prefix'             => array( '0054 9 11 5555-1234', '+5491155551234' ),
			'no mobile 9'           => array( '+54 11 5555 1234', '+5491155551234' ),
			'old 15 with 54'        => array( '+54 11 15 5555 1234', '+5491155551234' ),
			'parentheses'           => array( '(011) 15-5555-1234', '+5491155551234' ),
			'empty'                 => array( '', null ),
			'letters'               => array( 'abc', null ),
			'mixed letters'         => array( '11 5555 12ab', null ),
			'too short'             => array( '123', null ),
			'too long'              => array( '+54 9 11 5555 1234 99', null ),
			'bad area digit'        => array( '711 5555 1234', null ),
		);
	}

	#[DataProvider( 'argentine_inputs' )]
	public function test_argentina( string $input, ?string $expected ): void {
		$this->assertSame( $expected, Phone::normalize( $input, '54' ) );
	}

	public function test_other_country_uses_generic_rule(): void {
		$this->assertSame( '+5989123456', Phone::normalize( '09 123 456', '598' ) );
		$this->assertSame( '+34612345678', Phone::normalize( '+34 612 345 678', '54' ) );
		$this->assertNull( Phone::normalize( '12', '598' ) );
	}

	public function test_invalid_country_code(): void {
		$this->assertNull( Phone::normalize( '11 5555 1234', '' ) );
	}

	public function test_chat_id_and_digits(): void {
		$this->assertSame( '5491155551234@c.us', Phone::chat_id( '+5491155551234' ) );
		$this->assertSame( '5491155551234', Phone::digits( '+54 9 11 5555 1234' ) );
	}
}
