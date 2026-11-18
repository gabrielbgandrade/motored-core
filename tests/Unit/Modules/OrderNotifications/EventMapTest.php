<?php
namespace Motored\Core\Tests\Unit\Modules\OrderNotifications;

use Motored\Core\Modules\OrderNotifications\EventMap;
use Motored\Core\Tests\Unit\TestCase;

final class EventMapTest extends TestCase {

	public function test_from_status_maps_supported_statuses(): void {
		$this->assertSame( EventMap::ON_HOLD, EventMap::from_status( 'on-hold' ) );
		$this->assertSame( EventMap::ON_HOLD, EventMap::from_status( 'wc-on-hold' ) );
		$this->assertSame( EventMap::PROCESSING, EventMap::from_status( 'PROCESSING' ) );
		$this->assertSame( EventMap::COMPLETED, EventMap::from_status( 'completed' ) );
		$this->assertSame( EventMap::REFUNDED, EventMap::from_status( 'wc-refunded' ) );
		$this->assertNull( EventMap::from_status( 'pending' ) );
		$this->assertNull( EventMap::from_status( 'cancelled' ) );
		$this->assertNull( EventMap::from_status( 'failed' ) );
	}

	public function test_matches_status_validates_current_order_status(): void {
		$this->assertTrue( EventMap::matches_status( EventMap::ON_HOLD, 'on-hold' ) );
		$this->assertFalse( EventMap::matches_status( EventMap::ON_HOLD, 'processing' ) );
		$this->assertTrue( EventMap::matches_status( EventMap::PROCESSING, 'wc-processing' ) );
		$this->assertTrue( EventMap::matches_status( EventMap::ADMIN_NEW_ORDER, 'on-hold' ) );
		$this->assertTrue( EventMap::matches_status( EventMap::ADMIN_NEW_ORDER, 'processing' ) );
		$this->assertTrue( EventMap::matches_status( EventMap::ADMIN_NEW_ORDER, 'completed' ) );
		$this->assertFalse( EventMap::matches_status( EventMap::ADMIN_NEW_ORDER, 'cancelled' ) );
		$this->assertTrue( EventMap::matches_status( EventMap::CUSTOMER_NOTE, 'processing' ) );
		$this->assertFalse( EventMap::matches_status( EventMap::CUSTOMER_NOTE, 'trash' ) );
		$this->assertFalse( EventMap::matches_status( 'unknown', 'processing' ) );
	}

	public function test_triggers_admin_new_order_only_on_initial_active_transitions(): void {
		$this->assertTrue( EventMap::triggers_admin_new_order( 'pending', 'on-hold' ) );
		$this->assertTrue( EventMap::triggers_admin_new_order( 'pending', 'processing' ) );
		$this->assertTrue( EventMap::triggers_admin_new_order( 'checkout-draft', 'completed' ) );
		$this->assertTrue( EventMap::triggers_admin_new_order( 'failed', 'processing' ) );
		$this->assertFalse( EventMap::triggers_admin_new_order( 'on-hold', 'processing' ) );
		$this->assertFalse( EventMap::triggers_admin_new_order( 'processing', 'completed' ) );
		$this->assertFalse( EventMap::triggers_admin_new_order( 'pending', 'cancelled' ) );
	}

	public function test_meta_key_uses_prefix(): void {
		$this->assertSame( '_motored_wa_processing', EventMap::meta_key( EventMap::PROCESSING ) );
		$this->assertSame( '_motored_wa_admin_new_order', EventMap::meta_key( EventMap::ADMIN_NEW_ORDER ) );
	}
}
