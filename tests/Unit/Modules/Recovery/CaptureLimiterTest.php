<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Motored\Core\Modules\Recovery\CaptureLimiter;
use Motored\Core\Tests\Unit\TestCase;

final class CaptureLimiterTest extends TestCase {

	private const NOW = 1700000000;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
	}

	public function test_session_limit_per_minute(): void {
		for ( $i = 1; $i <= CaptureLimiter::SESSION_LIMIT; $i++ ) {
			$this->assertTrue( CaptureLimiter::allow( 'sess-a', '1.1.1.' . $i, self::NOW ), "hit $i" );
		}
		$this->assertFalse( CaptureLimiter::allow( 'sess-a', '9.9.9.9', self::NOW ) );
		$this->assertTrue( CaptureLimiter::allow( 'sess-b', '9.9.9.9', self::NOW ), 'Another session is independent.' );
	}

	public function test_window_resets_next_minute(): void {
		for ( $i = 0; $i <= CaptureLimiter::SESSION_LIMIT; $i++ ) {
			CaptureLimiter::allow( 'sess-a', '2.2.2.' . $i, self::NOW );
		}
		$this->assertFalse( CaptureLimiter::allow( 'sess-a', '3.3.3.3', self::NOW ) );
		$this->assertTrue( CaptureLimiter::allow( 'sess-a', '3.3.3.3', self::NOW + 60 ) );
	}

	public function test_ip_limit_across_sessions(): void {
		for ( $i = 1; $i <= CaptureLimiter::IP_LIMIT; $i++ ) {
			$this->assertTrue( CaptureLimiter::allow( 'sess-' . $i, '5.5.5.5', self::NOW ) );
		}
		$this->assertFalse( CaptureLimiter::allow( 'sess-new', '5.5.5.5', self::NOW ) );
	}

	public function test_keys_do_not_contain_clear_text_identifiers(): void {
		CaptureLimiter::allow( 'secret-session', '8.8.4.4', self::NOW );
		foreach ( array_keys( $this->transients ) as $name ) {
			$this->assertStringNotContainsString( 'secret-session', (string) $name );
			$this->assertStringNotContainsString( '8.8.4.4', (string) $name );
		}
	}
}
