<?php
namespace Motored\Core\Tests\Unit\Modules\PaymentPlans;

use Brain\Monkey\Functions;
use Motored\Core\Modules\PaymentPlans\Config;
use Motored\Core\Modules\PaymentPlans\GatewayCheck;
use Motored\Core\Modules\PaymentPlans\PaymentPlansModule;
use Motored\Core\Modules\PaymentPlans\PlanDialog;
use Motored\Core\Modules\PaymentPlans\Plans;
use Motored\Core\Modules\PaymentPlans\ProductView;
use Motored\Core\Modules\PaymentPlans\Storefront;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class StorefrontTest extends TestCase {

	private int $enqueued = 0;

	protected function setUp(): void {
		parent::setUp();
		PlanDialog::reset();
		if ( ! defined( 'MOTORED_CORE_URL' ) ) {
			define( 'MOTORED_CORE_URL', 'https://example.test/wp-content/plugins/motored-core/' );
			define( 'MOTORED_CORE_VERSION', '0.0.0' );
		}
		$this->fake_storage();
		Options::register_defaults( 'payment_plans', Config::defaults() );
		$this->options['motored_payment_plans'] = array( 'enabled' => 'yes' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		Functions\when( 'wc_price' )->alias( static fn( $p ) => '<span>$&nbsp;' . number_format( (float) $p, 2, ',', '.' ) . '</span>' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '_n' )->alias( static fn( $s, $p, $n ) => 1 === $n ? $s : $p );
		$this->enqueued = 0;
		Functions\when( 'wp_enqueue_script' )->alias(
			function () {
				++$this->enqueued;
				return true;
			}
		);
	}

	private function money( string $text ): string {
		return str_replace( "\xc2\xa0", ' ', $text );
	}

	public function test_view_uses_the_list_plan_for_the_line_and_all_active_plans_for_the_rows(): void {
		$view = ProductView::build( new Plans( Plans::defaults() ), 60000.0 );
		$this->assertNotNull( $view );
		$this->assertStringContainsString( '6 cuotas sin interés', $view['l'] );
		$this->assertStringContainsString( '10.000,00', $this->money( $view['l'] ), 'list price / 6' );
		$this->assertCount( 5, $view['r'] );
	}

	public function test_discounts_show_total_and_percent_but_surcharges_never_show_the_percent(): void {
		$rows = array_column( ProductView::build( new Plans( Plans::defaults() ), 60000.0 )['r'], null, 0 );

		$cash = $rows['Transferencia, dinero en cuenta o efectivo'];
		$this->assertStringContainsString( '54.000,00', $cash[1] );
		$this->assertStringContainsString( '(10% OFF)', $cash[1] );

		$c3 = $rows['3 cuotas sin interés'];
		$this->assertStringContainsString( '(5% OFF)', $c3[1] );
		$this->assertStringContainsString( '19.000,00', $c3[2] );

		foreach ( array( '9 cuotas sin interés', '12 cuotas sin interés' ) as $name ) {
			$flat = implode( ' ', $rows[ $name ] );
			$this->assertStringNotContainsString( '%', $flat );
			$this->assertStringNotContainsString( 'OFF', $flat );
		}
		$this->assertStringContainsString( '7.333,33', $this->money( $rows['9 cuotas sin interés'][1] ) );
		$this->assertStringContainsString( '66.000,00', $rows['9 cuotas sin interés'][2] );
	}

	public function test_no_view_without_price_or_plans(): void {
		$this->assertNull( ProductView::build( new Plans( Plans::defaults() ), 0 ) );
		$this->assertNull( ProductView::build( new Plans( Plans::defaults() ), '' ) );
		$this->assertNull( ProductView::build( new Plans( array() ), 100 ) );
	}

	private function product( bool $in_stock, $price, string $type = 'simple' ): \WC_Product {
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'is_type' )->andReturnUsing(
			static fn( $t ) => is_array( $t ) ? in_array( $type, $t, true ) : $type === $t
		);
		$product->shouldReceive( 'is_in_stock' )->andReturn( $in_stock );
		$product->shouldReceive( 'get_price' )->andReturn( $price );
		Functions\when( 'wc_get_price_to_display' )->justReturn( (float) $price );
		return $product;
	}

	public function test_simple_product_renders_line_link_and_data_attribute(): void {
		$html = ( new Storefront() )->html_for( $this->product( true, '60000' ), false );
		$this->assertStringContainsString( 'class="motored-plans"', $html );
		$this->assertStringContainsString( 'motored-plans-link', $html );
		$this->assertStringContainsString( 'data-motored-plans=', $html );
		$this->assertStringNotContainsString( 'data-mv', $html );
		$this->assertStringContainsString( 'See all payment methods', $html );
		$this->assertNotFalse( has_action( 'wp_footer' ), 'dialog is scheduled for the footer' );
	}

	public function test_nothing_for_out_of_stock_no_price_or_disabled_module(): void {
		$store = new Storefront();
		$this->assertSame( '', $store->html_for( $this->product( false, '60000' ), false ) );
		$this->assertSame( '', $store->html_for( $this->product( true, '' ), false ) );
		$this->assertSame( '', $store->html_for( $this->product( true, '0' ), false ) );
		$this->assertSame( '', $store->html_for( null, false ) );
		$this->assertFalse( has_action( 'wp_footer' ), 'no link, no dialog' );

		$this->options['motored_payment_plans'] = array( 'enabled' => 'no' );
		Options::reset();
		Options::register_defaults( 'payment_plans', Config::defaults() );
		$this->assertSame( '', $store->html_for( $this->product( true, '60000' ), false ) );
	}

	public function test_variation_data_carries_the_formatted_plans(): void {
		$store = new Storefront();
		$data  = $store->filter_variation( array( 'is_in_stock' => true, 'display_price' => 12000.0 ), null, null );
		$this->assertArrayHasKey( 'motored_plans', $data );
		$this->assertArrayHasKey( 'l', $data['motored_plans'] );
		$this->assertCount( 5, $data['motored_plans']['r'] );

		$out = $store->filter_variation( array( 'is_in_stock' => false, 'display_price' => 12000.0 ), null, null );
		$this->assertArrayNotHasKey( 'motored_plans', $out );
	}

	public function test_dialog_is_requested_once(): void {
		PlanDialog::request();
		PlanDialog::request();
		$this->assertSame( 1, $this->enqueued );
	}

	public function test_gateway_warnings(): void {
		Functions\when( '__' )->returnArg();
		$none = GatewayCheck::warnings( array( 'discount' => 0.0, 'commission' => 0.0, 'installments' => 12 ), 12 );
		$this->assertSame( array(), $none );
		$this->assertSame( array(), GatewayCheck::warnings( array( 'discount' => null, 'commission' => null, 'installments' => null ), 12 ) );
		$this->assertCount( 3, GatewayCheck::warnings( array( 'discount' => 5.0, 'commission' => 2.5, 'installments' => 6 ), 12 ) );
		$this->assertCount( 1, GatewayCheck::warnings( array( 'discount' => 0.0, 'commission' => 0.0, 'installments' => 6 ), 12 ) );
	}

	public function test_module_contexts_and_registration_without_option_reads(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'add_shortcode' )->justReturn( null );
		$_SERVER['REQUEST_URI'] = '/producto/x/';
		Options::reset();
		Functions\expect( 'get_option' )->never();

		$module = new PaymentPlansModule();
		$this->assertSame( 'payment_plans', $module->id() );
		$this->assertTrue( $module->should_load( new Context() ) );
		$module->register();

		$this->assertNotFalse( has_action( 'woocommerce_single_product_summary' ) );
		$this->assertNotFalse( has_action( 'woocommerce_after_shop_loop_item_title' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_available_variation' ) );
		$this->assertNotFalse( has_action( 'woocommerce_review_order_before_payment' ) );
		$this->assertNotFalse( has_action( 'woocommerce_cart_calculate_fees' ) );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_create_order' ) );
		$this->assertNull( \Motored\Core\Admin\SectionRegistry::get( 'payment_plans' ) );
		unset( $_SERVER['REQUEST_URI'] );
	}
}
