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

	protected function tearDown(): void {
		Logger::reset_secrets();
		parent::tearDown();
	}

	public function test_redact_removes_api_key_headers_keys_and_registered_secrets(): void {
		Logger::protect( 'sup3r-secret-key' );
		$clean = Logger::redact(
			array(
				'headers' => array(
					'X-Api-Key' => 'sup3r-secret-key',
					'Accept'    => 'application/json',
				),
				'api_key' => 'other',
				'note'    => 'sent X-Api-Key: abc123 and sup3r-secret-key twice sup3r-secret-key',
				'error'   => new \RuntimeException( 'sup3r-secret-key' ),
				'code'    => 401,
			)
		);
		$json = (string) json_encode( $clean );
		$this->assertStringNotContainsString( 'sup3r-secret-key', $json );
		$this->assertStringNotContainsString( 'abc123', $json );
		$this->assertStringNotContainsString( 'other', $json );
		$this->assertSame( 'application/json', $clean['headers']['Accept'] );
		$this->assertSame( 401, $clean['code'] );
	}

	public function test_log_redacts_message_and_context_before_writing(): void {
		$this->fake_storage();
		$this->options['motored_general'] = array( 'log_level' => 'debug' );
		Logger::protect( 'sup3r-secret-key' );
		$logger = \Mockery::mock( 'WC_Logger_Fake' );
		$logger->shouldReceive( 'log' )->once()->with(
			'error',
			'failed with [redacted]',
			array(
				'X-Api-Key' => '[redacted]',
				'source'    => 'motored-core',
			)
		);
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		Logger::error( 'failed with sup3r-secret-key', array( 'X-Api-Key' => 'sup3r-secret-key' ) );
	}
}
