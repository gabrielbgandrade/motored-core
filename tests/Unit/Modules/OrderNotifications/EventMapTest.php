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
		$this->assertTrue( EventMap::matches_status( EventMap::CUSTOMER_NOTE, 'processing' ) );
		$this->assertFalse( EventMap::matches_status( EventMap::CUSTOMER_NOTE, 'trash' ) );
		$this->assertFalse( EventMap::matches_status( 'unknown', 'processing' ) );
	}

	public function test_meta_key_uses_prefix(): void {
		$this->assertSame( '_motored_wa_processing', EventMap::meta_key( EventMap::PROCESSING ) );
		$this->assertFalse( EventMap::is_valid( 'admin_new_order' ), 'the admin alert no longer exists' );
		$this->assertNotContains( 'admin_new_order', EventMap::all() );
	}
}
