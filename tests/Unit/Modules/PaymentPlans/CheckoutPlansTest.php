<?php
namespace Motored\Core\Tests\Unit\Modules\PaymentPlans;

use Brain\Monkey\Functions;
use Motored\Core\Modules\PaymentPlans\CheckoutPlans;
use Motored\Core\Modules\PaymentPlans\Plans;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CheckoutPlansTest extends TestCase {

	/** @var array<string,mixed> */
	private array $session = array();

	/** @var list<array<string,mixed>> */
	private array $fees = array();

	protected function setUp(): void {
		parent::setUp();
		$this->session = array();
		$this->fees    = array();
		$this->fake_storage();
		Options::register_defaults( 'payment_plans', \Motored\Core\Modules\PaymentPlans\Config::defaults() );
		$this->options['motored_payment_plans'] = array( 'enabled' => 'yes' );

		$session = \Mockery::mock( 'WC_Session' );
		$session->shouldReceive( 'get' )->andReturnUsing( fn( $key ) => $this->session[ $key ] ?? null );
		$session->shouldReceive( 'set' )->andReturnUsing(
			function ( $key, $value ) {
				$this->session[ $key ] = $value;
			}
		);
		$this->wc = (object) array( 'session' => $session, 'cart' => null );
		Functions\when( 'WC' )->justReturn( $this->wc );
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		Functions\when( 'wc_price' )->alias( static fn( $p ) => '$ ' . number_format( (float) $p, 2, ',', '.' ) );
		Functions\when( 'wc_format_decimal' )->alias( static fn( $n, $dp = 2 ) => number_format( (float) $n, (int) $dp, '.', '' ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $s, $p, $n ) => 1 === $n ? $s : $p );
	}

	/** @var object */
	private $wc;

	private function cart( float $contents, float $tax = 0.0 ): \WC_Cart {
		$fees = \Mockery::mock( 'WC_Cart_Fees' );
		$fees->shouldReceive( 'add_fee' )->andReturnUsing(
			function ( $args ) {
				$this->fees[] = $args;
				return (object) $args;
			}
		);
		$cart = \Mockery::mock( 'WC_Cart' );
		$cart->shouldReceive( 'get_cart_contents_total' )->andReturn( $contents );
		$cart->shouldReceive( 'get_cart_contents_tax' )->andReturn( $tax );
		$cart->shouldReceive( 'get_cart' )->andReturn( array() );
		$cart->shouldReceive( 'fees_api' )->andReturn( $fees );
		$this->wc->cart = $cart;
		return $cart;
	}

	public function test_update_order_review_stores_a_valid_plan_for_mercado_pago(): void {
		( new CheckoutPlans() )->on_update_order_review( 'payment_method=woo-mercado-pago-basic&motored_plan=c9&billing_email=a%40b.com' );
		$this->assertSame( 'c9', $this->session['motored_plan'] );
	}

	public function test_update_order_review_falls_back_to_default_for_unknown_or_inactive_plan(): void {
		$checkout = new CheckoutPlans();
		$checkout->on_update_order_review( 'payment_method=woo-mercado-pago-basic&motored_plan=hack' );
		$this->assertSame( 'c6', $this->session['motored_plan'] );

		$raw              = Plans::defaults();
		$raw[3]['active'] = 'no'; // c9.
		$this->options['motored_payment_plans']['plans'] = $raw;
		Options::reset();
		Options::register_defaults( 'payment_plans', \Motored\Core\Modules\PaymentPlans\Config::defaults() );
		$checkout->on_update_order_review( 'payment_method=woo-mercado-pago-basic&motored_plan=c9' );
		$this->assertSame( 'c6', $this->session['motored_plan'] );
	}

	public function test_update_order_review_clears_the_plan_for_other_gateways(): void {
		$this->session['motored_plan'] = 'c3';
		( new CheckoutPlans() )->on_update_order_review( 'payment_method=bacs&motored_plan=c3' );
		$this->assertNull( $this->session['motored_plan'] );
	}

	public function test_disabled_module_does_not_touch_the_session(): void {
		$this->options['motored_payment_plans'] = array( 'enabled' => 'no' );
		( new CheckoutPlans() )->on_update_order_review( 'payment_method=woo-mercado-pago-basic&motored_plan=c9' );
		$this->assertArrayNotHasKey( 'motored_plan', $this->session );
	}

	public function test_posted_data_uses_the_submitted_plan_or_keeps_the_session_one(): void {
		$checkout = new CheckoutPlans();
		$data     = array( 'payment_method' => 'woo-mercado-pago-basic' );

		$_POST['motored_plan'] = 'c3';
		$this->assertSame( $data, $checkout->on_posted_data( $data ) );
		$this->assertSame( 'c3', $this->session['motored_plan'] );

		unset( $_POST['motored_plan'] );
		$this->session['motored_plan'] = 'c12';
		$checkout->on_posted_data( $data );
		$this->assertSame( 'c12', $this->session['motored_plan'] );

		$checkout->on_posted_data( array( 'payment_method' => 'cod' ) );
		$this->assertNull( $this->session['motored_plan'] );
	}

	#[DataProvider( 'fee_cases' )]
	public function test_fee_per_plan( string $plan, float $amount, string $label ): void {
		$cart                              = $this->cart( 1000.0, 210.0 ); // Base 1210.00 (taxes included, no shipping).
		$this->session['motored_plan']     = $plan;
		$this->session['chosen_payment_method'] = 'woo-mercado-pago-basic';

		( new CheckoutPlans() )->add_fee( $cart );

		$this->assertCount( 1, $this->fees );
		$this->assertEqualsWithDelta( $amount, $this->fees[0]['amount'], 0.001 );
		$this->assertFalse( $this->fees[0]['taxable'] );
		$this->assertSame( $label, $this->fees[0]['name'] );
	}

	/**
	 * @return array<string,array{string,float,string}>
	 */
	public static function fee_cases(): array {
		return array(
			'cash discount'   => array( 'cash', -121.0, 'Discount for paying by transfer, account money or cash' ),
			'3 installments'  => array( 'c3', -60.5, 'Discount for paying in 3 installments' ),
			'9 surcharge'     => array( 'c9', 121.0, 'Financing in 9 installments' ),
			'12 surcharge'    => array( 'c12', 242.0, 'Financing in 12 installments' ),
		);
	}

	public function test_no_fee_for_list_plan_other_gateway_empty_session_or_disabled(): void {
		$cart = $this->cart( 1000.0 );
		$checkout = new CheckoutPlans();

		$this->session = array( 'motored_plan' => 'c6', 'chosen_payment_method' => 'woo-mercado-pago-basic' );
		$checkout->add_fee( $cart );
		$this->assertSame( array(), $this->fees, '0% plan adds nothing' );

		$this->session = array( 'motored_plan' => 'c3', 'chosen_payment_method' => 'bacs' );
		$checkout->add_fee( $cart );
		$this->assertSame( array(), $this->fees, 'other gateway' );

		$this->session = array( 'chosen_payment_method' => 'woo-mercado-pago-basic' );
		$checkout->add_fee( $cart );
		$this->assertSame( array(), $this->fees, 'no plan in session' );

		$this->session = array( 'motored_plan' => 'c3', 'chosen_payment_method' => 'woo-mercado-pago-basic' );
		$this->options['motored_payment_plans'] = array( 'enabled' => 'no' );
		Options::reset();
		Options::register_defaults( 'payment_plans', \Motored\Core\Modules\PaymentPlans\Config::defaults() );
		$checkout->add_fee( $cart );
		$this->assertSame( array(), $this->fees, 'module disabled' );
	}

	public function test_stale_session_plan_is_revalidated_at_fee_time(): void {
		$cart                                   = $this->cart( 1000.0 );
		$this->session                          = array( 'motored_plan' => 'deleted', 'chosen_payment_method' => 'woo-mercado-pago-basic' );
		( new CheckoutPlans() )->add_fee( $cart );
		$this->assertSame( array(), $this->fees, 'unknown id resolves to the 0% default plan' );
	}

	public function test_save_order_writes_meta_through_crud(): void {
		$this->cart( 1000.0, 210.0 );
		$this->session = array( 'motored_plan' => 'c9', 'chosen_payment_method' => 'woo-mercado-pago-basic' );
		$meta          = array();
		$order         = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( $key, $value ) use ( &$meta ) {
				$meta[ $key ] = $value;
			}
		);
		$order->shouldNotReceive( 'delete_meta_data' );

		( new CheckoutPlans() )->save_order( $order );

		$this->assertSame(
			array(
				'_motored_plan_id'               => 'c9',
				'_motored_plan_type'             => 'card',
				'_motored_plan_excl_credit'      => '1',
				'_motored_plan_max_installments' => '9',
				'_motored_plan_pct'              => '10',
				'_motored_plan_base'             => '1210.00',
				'_motored_plan_fee'              => '121.00',
			),
			$meta
		);
	}

	public function test_save_order_clears_stale_meta_without_a_plan(): void {
		$this->cart( 1000.0 );
		$this->session = array( 'chosen_payment_method' => 'bacs' );
		$deleted       = array();
		$order         = \Mockery::mock( 'WC_Order' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldReceive( 'delete_meta_data' )->andReturnUsing(
			function ( $key ) use ( &$deleted ) {
				$deleted[] = $key;
			}
		);
		( new CheckoutPlans() )->save_order( $order );
		$this->assertCount( 7, $deleted );
	}

	public function test_selector_lists_active_plans_and_hides_for_other_gateways(): void {
		$this->cart( 1000.0 );
		$checkout = new CheckoutPlans();

		$this->session = array( 'chosen_payment_method' => 'bacs' );
		$this->assertSame( '<div class="motored-plan-selector update_totals_on_change"></div>', $checkout->selector_html() );

		$this->session = array( 'chosen_payment_method' => 'woo-mercado-pago-basic', 'motored_plan' => 'c9' );
		$html          = $checkout->selector_html();
		$this->assertSame( 5, substr_count( $html, 'type="radio"' ) );
		$this->assertMatchesRegularExpression( '/value="c9" checked/', $html );
		$this->assertStringContainsString( '(10% OFF)', $html, 'cash plan shows its percentage' );
		$this->assertStringNotContainsString( '10%', substr( $html, (int) strpos( $html, 'c9' ) ), 'surcharges never show a percentage' );
		$this->assertStringNotContainsString( '20%', $html );
	}
}
