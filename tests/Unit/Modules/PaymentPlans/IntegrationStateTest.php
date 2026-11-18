<?php
namespace Motored\Core\Tests\Unit\Modules\PaymentPlans;

use Brain\Monkey\Functions;
use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Integration\MercadoPago\CheckoutPro;
use Motored\Core\Modules\PaymentPlans\PaymentPlansAdmin;
use Motored\Core\Modules\PaymentPlans\PaymentPlansModule;
use Motored\Core\Modules\PaymentPlans\Reconciler;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class IntegrationStateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', '/tmp/plugins' );
		}
	}

	private function installed( ?string $version ): void {
		$this->options['active_plugins'] = null === $version ? array( 'akismet/akismet.php' ) : array( 'woocommerce-mercadopago/woocommerce-mercadopago.php' );
		Functions\when( 'get_plugin_data' )->justReturn( array( 'Version' => $version ) );
	}

	public function test_validated_version_is_8_10_2(): void {
		$this->assertSame( '8.10.2', CheckoutPro::VALIDATED_VERSION );
		$this->assertSame( '/v1/asgard/preferences', CheckoutPro::PREFERENCE_PATH );
		$this->assertSame( 'woo-mercado-pago-basic', CheckoutPro::GATEWAY_ID );
	}

	public function test_state_ok_review_or_inactive(): void {
		$this->installed( '8.10.2' );
		$this->assertTrue( CheckoutPro::version_matches() );
		$this->assertStringStartsWith( 'OK', PaymentPlansAdmin::integration_state() );

		$this->installed( '8.11.0' );
		$this->assertFalse( CheckoutPro::version_matches() );
		$this->assertStringStartsWith( 'Review integration', PaymentPlansAdmin::integration_state() );

		$this->installed( null );
		$this->assertNull( CheckoutPro::version_matches() );
		$this->assertSame( 'Mercado Pago plugin not active', PaymentPlansAdmin::integration_state() );
	}

	public function test_status_row_and_section_warning_for_another_version(): void {
		$this->installed( '9.0.0' );
		$admin = new PaymentPlansAdmin();
		$rows  = $admin->status_rows( array( 'A' => 'b' ) );
		$this->assertArrayHasKey( 'Mercado Pago integration', $rows );
		Functions\when( 'esc_html' )->returnArg();
		$fields = $admin->settings();
		$this->assertStringContainsString( 'validated with 8.10.2', $fields[0]['desc'] );
		$this->assertSame( 'x', $admin->status_rows( 'x' ) );
	}

	public function test_module_loads_in_cron_and_registers_only_the_reconciliation_there(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_SERVER['REQUEST_URI'] = '/wp-cron.php';

		$module = new PaymentPlansModule();
		$this->assertTrue( $module->should_load( new Context() ) );
		$module->register();

		$this->assertNotFalse( has_action( Reconciler::HOOK ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_changed' ) );
		$this->assertFalse( has_filter( 'http_request_args' ) );
		$this->assertFalse( has_action( 'woocommerce_single_product_summary' ) );
		$this->assertNull( SectionRegistry::get( 'payment_plans' ) );
		unset( $_SERVER['REQUEST_URI'] );
	}

	public function test_frontend_registers_the_preference_filter(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_shortcode' )->justReturn( null );
		$_SERVER['REQUEST_URI'] = '/checkout/';
		( new PaymentPlansModule() )->register();
		$this->assertNotFalse( has_filter( 'http_request_args' ) );
		$this->assertNotFalse( has_filter( 'pre_http_request' ) );
		unset( $_SERVER['REQUEST_URI'] );
	}
}
