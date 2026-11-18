<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Recovery\RecoveryToken;
use Motored\Core\Modules\Recovery\Repository;
use Motored\Core\Tests\Unit\TestCase;

final class RecoveryTokenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Functions\when( 'home_url' )->alias( fn( $path ) => 'https://example.com' . $path );
		Functions\when( 'add_query_arg' )->alias(
			function( $key, $val, $url ) {
				$sep = str_contains( $url, '?' ) ? '&' : '?';
				return $url . $sep . $key . '=' . $val;
			}
		);
	}

	public function test_generate_stores_hash_and_returns_raw_token(): void {
		$GLOBALS['wpdb'] = \Mockery::mock( 'wpdb_mock' );
		$GLOBALS['wpdb']->prefix = 'wp_';

		$updated_data = array();
		$GLOBALS['wpdb']->shouldReceive( 'update' )->once()->andReturnUsing(
			function( $table, $data ) use ( &$updated_data ) {
				$updated_data = $data;
				return 1;
			}
		);

		$token = RecoveryToken::generate( 10, 7 );

		$this->assertSame( 32, strlen( $token ) ); // 16 bytes = 32 hex chars
		$this->assertNotEmpty( $updated_data['token_hash'] );
		$this->assertSame( hash( 'sha256', $token ), $updated_data['token_hash'] );
		$this->assertNotEmpty( $updated_data['token_expires_gmt'] );
	}

	public function test_verify_token_matching_hash_and_unexpired(): void {
		$raw_token   = '0123456789abcdef0123456789abcdef';
		$hash        = hash( 'sha256', $raw_token );
		$expires_gmt = gmdate( 'Y-m-d H:i:s', time() + 86400 );

		$GLOBALS['wpdb'] = \Mockery::mock( 'wpdb_mock' );
		$GLOBALS['wpdb']->prefix = 'wp_';
		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn(
			array(
				'id'                => 10,
				'token_hash'        => $hash,
				'token_expires_gmt' => $expires_gmt,
			)
		);

		// Matching token returns true
		$this->assertTrue( RecoveryToken::verify( 10, $raw_token, true ) );

		// Wrong token returns false
		$this->assertFalse( RecoveryToken::verify( 10, 'wrong_token_123', true ) );
	}

	public function test_verify_expired_token(): void {
		$raw_token   = '0123456789abcdef0123456789abcdef';
		$hash        = hash( 'sha256', $raw_token );
		$expired_gmt = gmdate( 'Y-m-d H:i:s', time() - 3600 ); // 1 hour ago

		$GLOBALS['wpdb'] = \Mockery::mock( 'wpdb_mock' );
		$GLOBALS['wpdb']->prefix = 'wp_';
		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->andReturn(
			array(
				'id'                => 10,
				'token_hash'        => $hash,
				'token_expires_gmt' => $expired_gmt,
			)
		);

		// With expiry enforcement: false
		$this->assertFalse( RecoveryToken::verify( 10, $raw_token, true ) );

		// Without expiry enforcement (opt-out link allows expired): true
		$this->assertTrue( RecoveryToken::verify( 10, $raw_token, false ) );
	}

	public function test_urls_format(): void {
		$this->assertSame(
			'https://example.com/?motored_recover=10.abc123',
			RecoveryToken::recovery_url( 10, 'abc123' )
		);
		$this->assertSame(
			'https://example.com/?motored_optout=10.abc123',
			RecoveryToken::optout_url( 10, 'abc123' )
		);
	}
}
