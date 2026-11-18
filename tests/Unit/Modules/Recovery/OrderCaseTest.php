<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Motored\Core\Modules\Recovery\OrderCase;
use Motored\Core\Modules\Recovery\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderCaseTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:?string}>
	 */
	public static function statuses(): array {
		return array(
			'pending'             => array( 'pending', OrderCase::PENDING ),
			'failed'              => array( 'failed', OrderCase::FAILED ),
			'on-hold'             => array( 'on-hold', OrderCase::ON_HOLD ),
			'prefixed'            => array( 'wc-on-hold', OrderCase::ON_HOLD ),
			'upper case'          => array( 'Failed', OrderCase::FAILED ),
			'processing is paid'  => array( 'processing', OrderCase::PAID ),
			'completed is paid'   => array( 'completed', OrderCase::PAID ),
			'cancelled'           => array( 'cancelled', OrderCase::CANCELLED ),
			'trash'               => array( 'trash', OrderCase::CANCELLED ),
			'refunded ignored'    => array( 'refunded', null ),
			'draft ignored'       => array( 'checkout-draft', null ),
			'unknown ignored'     => array( 'wc-custom', null ),
		);
	}

	#[DataProvider( 'statuses' )]
	public function test_status_to_case( string $status, ?string $expected ): void {
		$this->assertSame( $expected, OrderCase::of_status( $status ) );
	}

	public function test_custom_paid_statuses_are_honored(): void {
		$this->assertSame( OrderCase::PAID, OrderCase::of_status( 'wc-shipped', array( 'processing', 'shipped' ) ) );
		$this->assertNull( OrderCase::of_status( 'shipped' ) );
	}

	public function test_open_cases_are_exactly_the_ones_waiting_for_payment(): void {
		$this->assertTrue( OrderCase::is_open( OrderCase::PENDING ) );
		$this->assertTrue( OrderCase::is_open( OrderCase::FAILED ) );
		$this->assertTrue( OrderCase::is_open( OrderCase::ON_HOLD ) );
		$this->assertFalse( OrderCase::is_open( OrderCase::PAID ) );
		$this->assertFalse( OrderCase::is_open( OrderCase::CANCELLED ) );
		$this->assertFalse( OrderCase::is_open( null ) );
	}

	public function test_orders_are_never_derived_as_abandoned(): void {
		$old = array(
			'status'            => 'active',
			'type'              => 'order',
			'last_activity_gmt' => '2020-01-01 00:00:00',
		);
		$this->assertSame( Status::ACTIVE, Status::derive_row( $old, 1700000000, 60 ) );
		$this->assertSame( Status::ABANDONED, Status::derive_row( array_merge( $old, array( 'type' => 'cart' ) ), 1700000000, 60 ) );
	}
}
