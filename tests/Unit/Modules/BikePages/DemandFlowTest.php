<?php
namespace Motored\Core\Tests\Unit\Modules\BikePages;

use Brain\Monkey\Functions;
use Motored\Core\Modules\BikePages\DemandCancelHandler;
use Motored\Core\Modules\BikePages\DemandStore;
use Motored\Core\Tests\Unit\TestCase;

/**
 * Avisame review fixes: deliverable phones only, scanner-safe cancellation.
 */
final class DemandFlowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Functions\when( 'sanitize_email' )->alias( static fn( $e ) => filter_var( trim( (string) $e ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $e ) : '' );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_undeliverable_phone_is_rejected_and_formats_dedupe_to_one_number(): void {
		$this->assertSame( '', DemandStore::parse_contacts( '1234567' )['phone'] );
		$this->assertSame( '', DemandStore::parse_contacts( 'abc 11 5555 4444' )['hash'] );
		$a = DemandStore::parse_contacts( '+54 9 11 5555-4444' );
		$b = DemandStore::parse_contacts( '011 15 5555-4444' );
		$this->assertSame( '+5491155554444', $a['phone'] );
		$this->assertSame( $a['hash'], $b['hash'] );
	}

	public function test_cancel_needs_valid_token_and_a_post_to_delete(): void {
		$row  = array(
			'id'          => 9,
			'consent_gmt' => '2026-11-25 10:00:00',
			'status'      => 'pending',
		);
		$wpdb = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'get_row' )->andReturn( $row );
		$wpdb->shouldReceive( 'delete' )->once()->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;

		$token = DemandStore::token( $row );
		$this->assertSame( 'invalid', DemandCancelHandler::process( 9, 'forged', true ) );
		// A GET (mail scanner, link preview) only shows the confirmation.
		$this->assertSame( 'confirm', DemandCancelHandler::process( 9, $token, false ) );
		$this->assertSame( 'canceled', DemandCancelHandler::process( 9, $token, true ) );
	}

	public function test_missing_subscription_reads_as_already_canceled(): void {
		$wpdb = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldNotReceive( 'delete' );
		$GLOBALS['wpdb'] = $wpdb;
		$this->assertSame( 'canceled', DemandCancelHandler::process( 9, 'x', true ) );
	}
}
