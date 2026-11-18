<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Modules\Recovery\RecoveryModule;
use Motored\Core\Modules\Recovery\Retention;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class RecoveryModuleTest extends TestCase {

	private function context( bool $admin = false, bool $ajax = false, bool $cron = false, bool $rest = false ): Context {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
		Functions\when( 'wp_doing_cron' )->justReturn( $cron );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_SERVER['REQUEST_URI'] = $rest ? '/wp-json/wc/store/v1/cart' : '/checkout/';
		return new Context();
	}

	public function test_id(): void {
		$this->assertSame( 'recovery', ( new RecoveryModule() )->id() );
	}

	public function test_loads_in_every_context(): void {
		$module = new RecoveryModule();
		$this->assertTrue( $module->should_load( $this->context() ), 'storefront' );
		$this->assertTrue( $module->should_load( $this->context( true ) ) );
		$this->assertTrue( $module->should_load( $this->context( false, true ) ) );
		$this->assertTrue( $module->should_load( $this->context( false, false, true ) ) );
		$this->assertTrue( $module->should_load( $this->context( false, false, false, true ) ) );
	}

	public function test_storefront_registers_script_and_cart_hooks_only(): void {
		$this->context();
		( new RecoveryModule() )->register();

		$this->assertNotFalse( has_action( 'wp_enqueue_scripts' ) );
		$this->assertNotFalse( has_action( 'woocommerce_add_to_cart' ) );
		$this->assertNotFalse( has_action( 'woocommerce_cart_item_removed' ) );
		$this->assertNotFalse( has_action( 'woocommerce_after_cart_item_quantity_update' ) );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_order_processed' ) );
		$this->assertNotFalse( has_action( 'woocommerce_store_api_checkout_order_processed' ) );
		$this->assertFalse( has_action( 'wc_ajax_motored_capture' ) );
		$this->assertFalse( has_action( 'admin_menu' ) );
		$this->assertFalse( has_action( Retention::HOOK ) );
		$this->assertFalse( has_filter( 'wp_privacy_personal_data_exporters' ) );
		$this->assertNull( SectionRegistry::get( 'recovery' ) );
	}

	public function test_ajax_registers_the_endpoint_not_the_script(): void {
		$this->context( false, true );
		( new RecoveryModule() )->register();
		$this->assertNotFalse( has_action( 'wc_ajax_motored_capture' ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts' ) );
		$this->assertNotFalse( has_action( Retention::HOOK ) );
	}

	public function test_cron_only_registers_cleanup(): void {
		$this->context( false, false, true );
		( new RecoveryModule() )->register();
		$this->assertNotFalse( has_action( Retention::HOOK ) );
		$this->assertFalse( has_action( 'woocommerce_add_to_cart' ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts' ) );
	}

	public function test_admin_registers_screen_settings_privacy_and_self_repair(): void {
		$this->context( true );
		( new RecoveryModule() )->register();
		$this->assertNotNull( SectionRegistry::get( 'recovery' ) );
		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'admin_init', array( Retention::class, 'ensure_scheduled' ) ) );
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_exporters' ) );
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_erasers' ) );
	}
}
