<?php
namespace Motored\Core\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Motored\Core\Support\Logger;
use Motored\Core\Tests\Unit\TestCase;

final class LoggerTest extends TestCase {

	public function test_mask_phone_keeps_only_last_three_digits(): void {
		$this->assertSame( '***234', Logger::mask_phone( '+54 9 11 5555-1234' ) );
		$this->assertSame( '***', Logger::mask_phone( '12' ) );
		$this->assertSame( '***', Logger::mask_phone( '' ) );
		$this->assertStringNotContainsString( '5555', Logger::mask_phone( '+5491155551234' ) );
	}

	public function test_mask_email(): void {
		$this->assertSame( 'j***@e***.com', Logger::mask_email( 'juan@example.com' ) );
		$this->assertSame( 'j***@e***.com.ar', Logger::mask_email( 'juan@example.com.ar' ) );
		$this->assertSame( '***', Logger::mask_email( 'not-an-email' ) );
		$this->assertSame( '***', Logger::mask_email( '@example.com' ) );
	}

	public function test_threshold(): void {
		$this->assertTrue( Logger::should_log( 'error', 'warning' ) );
		$this->assertTrue( Logger::should_log( 'warning', 'warning' ) );
		$this->assertFalse( Logger::should_log( 'info', 'warning' ) );
		$this->assertTrue( Logger::should_log( 'debug', 'debug' ) );
		$this->assertFalse( Logger::should_log( 'bogus', 'debug' ) );
		$this->assertFalse( Logger::should_log( 'info', 'bogus' ), 'Unknown threshold falls back to warning.' );
	}

	public function test_log_writes_with_source_when_allowed(): void {
		$this->fake_storage();
		$this->options['motored_general'] = array( 'log_level' => 'error' );
		$logger = \Mockery::mock( 'WC_Logger_Fake' );
		$logger->shouldReceive( 'log' )->once()->with( 'error', 'boom', array( 'source' => 'motored-core' ) );
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		Logger::error( 'boom' );
		Logger::warning( 'suppressed by threshold' );
	}
}
