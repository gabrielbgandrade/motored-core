<?php
namespace Motored\Core\Tests\Unit\Modules\OrderNotifications;

use Brain\Monkey\Functions;
use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Modules\OrderNotifications\EventMap;
use Motored\Core\Modules\OrderNotifications\OrderNotificationsModule;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class OrderNotificationsModuleTest extends TestCase {

	private function context( bool $admin, bool $ajax = false, bool $cron = false, bool $rest = false, string $method = 'GET', string $uri = '/' ): Context {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
		Functions\when( 'wp_doing_cron' )->justReturn( $cron );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_SERVER['REQUEST_URI']    = $rest ? '/wp-json/wc/store/v1/checkout' : $uri;
		$_SERVER['REQUEST_METHOD'] = $method;
		unset( $_GET['wc-api'] );
		return new Context();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_GET['wc-api'] );
		Sender::reset();
		parent::tearDown();
	}

	public function test_id(): void {
		$this->assertSame( 'order_notifications', ( new OrderNotificationsModule() )->id() );
	}

	public function test_should_load_skips_public_get_and_loads_in_order_and_worker_contexts(): void {
		$module = new OrderNotificationsModule();

		$this->assertFalse( $module->should_load( $this->context( false ) ), 'public GET page' );
		$this->assertTrue( $module->should_load( $this->context( true ) ), 'admin' );
		$this->assertTrue( $module->should_load( $this->context( false, true ) ), 'ajax (wc-ajax / AS)' );
		$this->assertTrue( $module->should_load( $this->context( false, false, true ) ), 'cron (AS)' );
		$this->assertTrue( $module->should_load( $this->context( false, false, false, true ) ), 'REST (Store API / webhooks)' );
		$this->assertTrue( $module->should_load( $this->context( false, false, false, false, 'GET', '/wc-api/WC_Gateway/' ) ), 'wc-api IPN' );
		$this->assertTrue( $module->should_load( $this->context( false, false, false, false, 'POST', '/checkout/' ) ), 'POST checkout' );
	}

	public function test_register_in_rest_only_hooks_order_listeners(): void {
		$this->context( false, false, false, true );
		( new OrderNotificationsModule() )->register();

		$this->assertNotFalse( has_action( 'woocommerce_order_status_changed' ) );
		$this->assertNotFalse( has_action( 'woocommerce_new_customer_note' ) );
		$this->assertFalse( has_action( Sender::RESULT_HOOK ) );
		$this->assertNull( SectionRegistry::get( 'messages' ) );
	}

	public function test_register_in_admin_adds_messages_section_and_worker_hooks(): void {
		$this->context( true );
		( new OrderNotificationsModule() )->register();

		$this->assertNotNull( SectionRegistry::get( 'messages' ) );
		$this->assertNotFalse( has_action( Sender::RESULT_HOOK ) );
		$this->assertNotFalse( has_action( 'woocommerce_admin_field_motored_order_event' ) );
		$this->assertNotFalse( has_action( 'woocommerce_update_options_motored_messages' ) );
		$this->assertSame( EventMap::KIND, 'order_event' );
	}
}
