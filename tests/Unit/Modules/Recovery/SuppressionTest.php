<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Recovery\Suppression;
use Motored\Core\Tests\Unit\TestCase;

final class SuppressionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_salt' )->justReturn( 'test_salt_recovery' );
	}

	public function test_normalize_email(): void {
		$this->assertSame( 'user@example.com', Suppression::normalize( '  USER@Example.COM  ' ) );
	}

	public function test_normalize_phone(): void {
		$this->assertSame( '5491155551234', Suppression::normalize( '+54 (9) 11 5555-1234' ) );
	}

	public function test_hash_is_deterministic_and_salted(): void {
		$hash1 = Suppression::hash( 'user@example.com' );
		$hash2 = Suppression::hash( 'USER@EXAMPLE.COM' );
		$this->assertSame( $hash1, $hash2 );
		$this->assertSame( 64, strlen( $hash1 ) );
		$this->assertSame( '', Suppression::hash( '' ) );
	}

	public function test_suppress_and_is_suppressed_with_mocked_wpdb(): void {
		$GLOBALS['wpdb'] = \Mockery::mock( 'wpdb_mock' );
		$GLOBALS['wpdb']->prefix = 'wp_';

		// Prepare returns prepared query string.
		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturnUsing(
			function( $query, ...$args ) {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}
		);

		// Not found -> insert called.
		$GLOBALS['wpdb']->shouldReceive( 'get_var' )->once()->andReturn( null );
		$GLOBALS['wpdb']->shouldReceive( 'insert' )->once()->andReturn( 1 );

		$this->assertTrue( Suppression::suppress( '1155551234', 'whatsapp' ) );

		// Found -> is_suppressed returns true.
		$GLOBALS['wpdb']->shouldReceive( 'get_var' )->once()->andReturn( '42' );
		$this->assertTrue( Suppression::is_suppressed( '1155551234', 'whatsapp' ) );

		// Not found -> is_suppressed returns false.
		$GLOBALS['wpdb']->shouldReceive( 'get_var' )->once()->andReturn( null );
		$this->assertFalse( Suppression::is_suppressed( '1155551234', 'email' ) );
	}
}
