<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Recovery\RecoveryToken;
use Motored\Core\Tests\Unit\TestCase;

final class RecoveryTokenTest extends TestCase {

	/** @var array<string,mixed> */
	private array $row = array();
	/** @var list<array<string,mixed>> */
	private array $updates = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'home_url' )->alias( fn( $path ) => 'https://example.com' . $path );
		Functions\when( 'add_query_arg' )->alias(
			function ( $key, $val, $url ) {
				$sep = str_contains( $url, '?' ) ? '&' : '?';
				return $url . $sep . $key . '=' . $val;
			}
		);
		$this->row     = array( 'id' => 10 );
		$this->updates = array();
		$wpdb          = \Mockery::mock( 'wpdb_mock' );
		$wpdb->prefix  = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn() => $this->row );
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data ) {
				$this->updates[] = $data;
				$this->row       = array_merge( $this->row, $data );
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_generate_stores_only_the_hash_and_the_expiry(): void {
		$token = RecoveryToken::generate( 10, 7 );

		$this->assertSame( RecoveryToken::LENGTH, strlen( $token ) );
		$this->assertSame( array( 'token_hash', 'token_expires_gmt' ), array_keys( $this->updates[0] ) );
		$this->assertSame( hash( 'sha256', $token ), $this->updates[0]['token_hash'] );
		$this->assertStringNotContainsString( $token, (string) json_encode( $this->updates ) );
		$this->assertSame( array(), $this->transients, 'The raw token is never cached.' );
	}

	public function test_valid_token_is_reused_without_any_cache(): void {
		$first  = RecoveryToken::get_or_generate( 10, 7 );
		$second = RecoveryToken::get_or_generate( 10, 7 );

		$this->assertSame( $first, $second, 'Links already sent keep working (no rotation).' );
		$this->assertCount( 1, $this->updates );
		$this->assertTrue( RecoveryToken::verify( 10, $first ) );
		$this->assertTrue( RecoveryToken::verify( 10, strtoupper( $first ) ) );
	}

	public function test_expired_token_is_rejected_and_replaced(): void {
		$old                            = RecoveryToken::generate( 10, 1 );
		$this->row['token_expires_gmt'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$this->row['token_hash']        = hash( 'sha256', $old );

		$this->assertFalse( RecoveryToken::verify( 10, $old ) );
		$new = RecoveryToken::get_or_generate( 10, 7 );
		$this->assertNotSame( $old, $new );
		$this->assertTrue( RecoveryToken::verify( 10, $new ) );
	}

	public function test_wrong_or_missing_token_is_rejected(): void {
		RecoveryToken::generate( 10, 7 );
		$this->assertFalse( RecoveryToken::verify( 10, 'wrong_token_123' ) );
		$this->assertFalse( RecoveryToken::verify( 10, '' ) );
		$this->assertFalse( RecoveryToken::verify( 0, 'x' ) );
		$this->row = array( 'id' => 11 );
		$this->assertFalse( RecoveryToken::verify( 11, 'abc' ), 'No token issued yet.' );
	}

	public function test_optout_token_is_stateless_and_bound_to_the_row(): void {
		$token = RecoveryToken::optout_token( 10 );
		$this->assertTrue( RecoveryToken::verify_optout( 10, $token ) );
		$this->assertFalse( RecoveryToken::verify_optout( 11, $token ) );
		$this->assertFalse( RecoveryToken::verify_optout( 10, RecoveryToken::get_or_generate( 10, 7 ) ) );
		$this->assertNotSame( $token, RecoveryToken::optout_token( 11 ) );
	}

	public function test_urls_format(): void {
		$this->assertSame( 'https://example.com/?motored_recover=10.abc123', RecoveryToken::recovery_url( 10, 'abc123' ) );
		$this->assertSame( 'https://example.com/?motored_optout=10.' . RecoveryToken::optout_token( 10 ), RecoveryToken::optout_url( 10 ) );
	}
}
