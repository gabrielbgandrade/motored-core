<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\CompatibilityModule;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class CompatibilityModuleTest extends TestCase {

	private function make_context( string $mode ): Context {
		Functions\when( 'is_admin' )->justReturn( 'admin' === $mode );
		Functions\when( 'wp_doing_ajax' )->justReturn( 'ajax' === $mode );
		Functions\when( 'wp_doing_cron' )->justReturn( 'cron' === $mode );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_SERVER['REQUEST_URI'] = 'rest' === $mode ? '/wp-json/motored/v1/compat/brands' : '/shop/';
		return new Context();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	public function test_id_and_should_load_across_contexts(): void {
		$mod = new CompatibilityModule();
		$this->assertSame( 'compatibility', $mod->id() );
		foreach ( array( 'frontend', 'admin', 'rest', 'ajax', 'cron' ) as $ctx ) {
			$this->assertTrue( $mod->should_load( $this->make_context( $ctx ) ) );
		}
	}

	public function test_register_on_frontend_performs_zero_option_reads_or_asset_enqueues(): void {
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'add_shortcode' )->once()->with( 'motored_compat_selector', \Mockery::type( 'array' ) );

		$this->make_context( 'frontend' );
		( new CompatibilityModule() )->register();

		$this->assertNotFalse( has_action( 'woocommerce_product_query' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_product_tabs' ) );
		$this->assertNotFalse( has_action( 'rest_api_init' ), 'REST controllers are initialized by the REST hook.' );
	}
}
