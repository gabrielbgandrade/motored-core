<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Recovery\MessageLimiter;
use Motored\Core\Tests\Unit\TestCase;

final class MessageLimiterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		// Suppression::hash() calls wp_salt() once any earlier test has defined it (run-order independent).
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	public function test_can_send_initially_and_blocks_when_24h_limit_reached(): void {
		$phone = '+54 9 11 5555-1234';

		// Limit 1 per 24h
		$this->assertTrue( MessageLimiter::can_send( $phone, 1, 3 ) );
		MessageLimiter::hit( $phone );

		// Now reached 24h limit
		$this->assertFalse( MessageLimiter::can_send( $phone, 1, 3 ) );
	}

	public function test_blocks_when_weekly_limit_reached(): void {
		$phone = '+54 9 11 5555-9999';

		// Allow 5 per 24h, but max 2 per week
		$this->assertTrue( MessageLimiter::can_send( $phone, 5, 2 ) );
		MessageLimiter::hit( $phone );
		$this->assertTrue( MessageLimiter::can_send( $phone, 5, 2 ) );
		MessageLimiter::hit( $phone );

		// Reached weekly limit of 2
		$this->assertFalse( MessageLimiter::can_send( $phone, 5, 2 ) );
	}

	public function test_empty_phone_always_allowed(): void {
		$this->assertTrue( MessageLimiter::can_send( '', 1, 1 ) );
	}
}
