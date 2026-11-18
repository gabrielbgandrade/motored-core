<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Motored\Core\Modules\Recovery\CaptureValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CaptureValidatorTest extends TestCase {

	public function test_valid_payload_is_normalized(): void {
		$result = CaptureValidator::validate(
			array(
				'email' => '  Juan.Perez@Gmail.com ',
				'phone' => '011 15 5555-1234',
				'name'  => 'Juan',
			),
			'54'
		);
		$this->assertSame(
			array(
				'email'      => 'juan.perez@gmail.com',
				'phone'      => '+5491155551234',
				'first_name' => 'Juan',
			),
			$result
		);
	}

	public function test_either_email_or_phone_is_enough(): void {
		$only_phone = CaptureValidator::validate( array( 'phone' => '11 5555 1234' ), '54' );
		$this->assertSame( '', $only_phone['email'] );
		$only_email = CaptureValidator::validate( array( 'email' => 'ana@gmail.com' ), '54' );
		$this->assertSame( '', $only_email['phone'] );
	}

	public function test_nothing_usable_returns_null(): void {
		$this->assertNull( CaptureValidator::validate( array(), '54' ) );
		$this->assertNull( CaptureValidator::validate( array( 'email' => 'nope', 'phone' => '123' ), '54' ) );
		$this->assertNull( CaptureValidator::validate( array( 'email' => array( 'a@b.com' ), 'phone' => str_repeat( '1', 300 ) ), '54' ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function junk_emails(): array {
		return array(
			'example domain' => array( 'juan@example.com' ),
			'test local'     => array( 'test@gmail.com' ),
			'repeated'       => array( 'aaaaaa@gmail.com' ),
			'no tld'         => array( 'juan@localhost' ),
			'spaces'         => array( 'ju an@gmail.com' ),
			'too long'       => array( str_repeat( 'a', 95 ) . '@x.com' ),
		);
	}

	#[DataProvider( 'junk_emails' )]
	public function test_junk_emails_are_discarded( string $email ): void {
		$this->assertSame( '', CaptureValidator::email( $email ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function junk_phones(): array {
		return array(
			'repeated digits' => array( '11 1111 1111' ),
			'sequence'        => array( '11 1234 5678' ),
			'too short'       => array( '555' ),
			'letters'         => array( 'llamame' ),
		);
	}

	#[DataProvider( 'junk_phones' )]
	public function test_junk_phones_are_discarded( string $phone ): void {
		$this->assertSame( '', CaptureValidator::phone( $phone, '54' ) );
	}

	public function test_name_rules(): void {
		$this->assertSame( 'María José', CaptureValidator::name( '  María   José ' ) );
		$this->assertSame( "D'Angelo", CaptureValidator::name( "D'Angelo" ) );
		$this->assertSame( '', CaptureValidator::name( '<script>alert(1)</script>' ) );
		$this->assertSame( '', CaptureValidator::name( '12345' ) );
		$this->assertSame( 60, mb_strlen( CaptureValidator::name( str_repeat( 'a', 100 ) ) ) );
	}
}
