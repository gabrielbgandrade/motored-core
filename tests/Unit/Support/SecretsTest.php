<?php
namespace Motored\Core\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Motored\Core\Support\Secrets;
use Motored\Core\Tests\Unit\TestCase;

final class SecretsTest extends TestCase {

	private string $salt = 'salt-one';

	protected function setUp(): void {
		parent::setUp();
		if ( ! Secrets::available() ) {
			$this->markTestSkipped( 'libsodium is not available.' );
		}
		$this->fake_storage();
		Secrets::reset();
		$this->salt = 'salt-one';
		Functions\when( 'wp_salt' )->alias( fn() => $this->salt );
	}

	public function test_round_trip_with_versioned_format_and_random_nonce(): void {
		$a = Secrets::encrypt( 'my-waha-key' );
		$b = Secrets::encrypt( 'my-waha-key' );
		$this->assertStringStartsWith( 'v1:', (string) $a );
		$this->assertNotSame( $a, $b, 'random nonce' );
		$this->assertStringNotContainsString( 'my-waha-key', (string) $a );
		$this->assertSame( 'my-waha-key', Secrets::decrypt( (string) $a ) );
	}

	public function test_tampering_and_bad_formats_return_null_without_throwing(): void {
		$cipher = (string) Secrets::encrypt( 'my-waha-key' );
		$raw    = base64_decode( substr( $cipher, 3 ), true );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		$this->assertNull( Secrets::decrypt( 'v1:' . base64_encode( $raw ) ) );
		$this->assertNull( Secrets::decrypt( 'v2:' . substr( $cipher, 3 ) ) );
		$this->assertNull( Secrets::decrypt( 'v1:***not base64***' ) );
		$this->assertNull( Secrets::decrypt( 'v1:' . base64_encode( 'short' ) ) );
		$this->assertNull( Secrets::decrypt( '' ) );
	}

	public function test_different_salt_cannot_decrypt(): void {
		$cipher     = (string) Secrets::encrypt( 'my-waha-key' );
		$this->salt = 'salt-two';
		$this->assertNull( Secrets::decrypt( $cipher ) );
	}

	public function test_store_read_state_has_and_forget(): void {
		$this->assertSame( 'none', Secrets::state( 'motored_waha_secret' ) );
		$this->assertFalse( Secrets::has( 'motored_waha_secret' ) );

		$this->assertTrue( Secrets::store( 'motored_waha_secret', 'my-waha-key' ) );
		$this->assertFalse( $this->autoload['motored_waha_secret'], 'autoload=false' );
		$this->assertSame( 'ok', Secrets::state( 'motored_waha_secret' ) );
		$this->assertSame( 'my-waha-key', Secrets::read( 'motored_waha_secret' ) );
		$this->assertTrue( Secrets::has( 'motored_waha_secret' ) );

		Secrets::forget( 'motored_waha_secret' );
		$this->assertNull( Secrets::read( 'motored_waha_secret' ) );
		$this->assertSame( 'none', Secrets::state( 'motored_waha_secret' ) );
	}

	public function test_changed_salts_make_the_stored_value_unreadable_not_an_error(): void {
		Secrets::store( 'motored_waha_secret', 'my-waha-key' );
		$this->salt = 'salt-two';
		Secrets::reset();
		$this->assertSame( 'unreadable', Secrets::state( 'motored_waha_secret' ) );
		$this->assertNull( Secrets::read( 'motored_waha_secret' ) );
		$this->assertTrue( Secrets::has( 'motored_waha_secret' ) );
	}

	public function test_empty_values_are_not_stored_and_foreign_option_names_are_refused(): void {
		$this->assertFalse( Secrets::store( 'motored_waha_secret', '' ) );
		$this->expectException( \InvalidArgumentException::class );
		Secrets::read( 'blogname' );
	}
}
