<?php
namespace Motored\Core\Tests\Unit\Modules\PaymentPlans;

use Brain\Monkey\Functions;
use Motored\Core\Integration\MercadoPago\CheckoutPro;
use Motored\Core\Modules\PaymentPlans\Reconciler;
use Motored\Core\Tests\Unit\TestCase;

final class ReconcilerTest extends TestCase {

	/** @var array<string,mixed> */
	private array $meta = array();

	/** @var list<string> */
	private array $notes = array();

	/** @var list<string> */
	private array $status_changes = array();

	/** @var list<array{int,string,array<int,mixed>}> */
	private array $scheduled = array();

	/** @var list<array<string,string>> */
	private array $mails = array();

	protected function setUp(): void {
		parent::setUp();
		$this->meta           = array();
		$this->notes          = array();
		$this->status_changes = array();
		$this->scheduled      = array();
		$this->mails          = array();
		$this->fake_storage();
		$this->fake_logger();
		$this->options['admin_email'] = 'owner@example.test';
		Functions\when( '__' )->returnArg();
		Functions\when( 'wc_price' )->alias( static fn( $p ) => '$' . number_format( (float) $p, 2, ',', '.' ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $ts, $hook, $args ) {
				$this->scheduled[] = array( $ts, $hook, $args );
				return 5;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mails[] = compact( 'to', 'subject', 'message' );
				return true;
			}
		);
	}

	private function plan( int $max, string $type = 'card' ): void {
		$this->meta['_motored_plan_id']               = 'p';
		$this->meta['_motored_plan_max_installments'] = (string) $max;
		$this->meta['_motored_plan_type']             = $type;
	}

	private function payment( string $id, string $type, float $amount, float $paid, ?int $installments ): void {
		$this->meta[ 'Mercado Pago - Payment ' . $id ] = "[Date 2026-11-18 10:00:00]/[Amount $amount]/[Payment Type $type]/[Payment Method visa]/[Paid $paid]/[Coupon 0]/[Refund 0]";
		if ( null !== $installments ) {
			$this->meta[ "Mercado Pago - $id - installments" ] = $installments;
		}
	}

	private function order( float $total = 1000.0 ): \WC_Order {
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 77 );
		$order->shouldReceive( 'get_total' )->andReturn( $total );
		$order->shouldReceive( 'get_order_number' )->andReturn( '77' );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.test/wp-admin/post.php?post=77' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( $key ) => $this->meta[ $key ] ?? '' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( $key, $value ) {
				$this->meta[ $key ] = $value;
			}
		);
		$order->shouldReceive( 'save_meta_data' )->andReturnNull();
		$order->shouldReceive( 'get_meta_data' )->andReturnUsing(
			function () {
				$list = array();
				foreach ( $this->meta as $key => $value ) {
					$list[] = new class( $key, $value ) {
						public function __construct( private string $key, private mixed $value ) {}
						public function get_data(): array {
							return array( 'key' => $this->key, 'value' => $this->value );
						}
					};
				}
				return $list;
			}
		);
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( $note ) {
				$this->notes[] = $note;
				return 1;
			}
		);
		$order->shouldReceive( 'update_status' )->andReturnUsing(
			function ( $status, $note = '' ) {
				$this->status_changes[] = $status;
				$this->notes[]          = $note;
				return true;
			}
		);
		$order->shouldNotReceive( 'delete' );
		return $order;
	}

	public function test_payments_are_read_from_plain_meta(): void {
		$this->payment( '555', 'credit_card', 1000.0, 1000.0, 3 );
		$this->payment( '556', 'account_money', 100.5, 100.5, null );
		$payments = CheckoutPro::payments( $this->order() );
		$this->assertSame(
			array(
				array( 'id' => '555', 'type' => 'credit_card', 'method' => 'visa', 'amount' => 1000.0, 'paid' => 1000.0, 'installments' => 3 ),
				array( 'id' => '556', 'type' => 'account_money', 'method' => 'visa', 'amount' => 100.5, 'paid' => 100.5, 'installments' => null ),
			),
			$payments
		);
	}

	public function test_evaluate_rules(): void {
		$card = static fn( int $n, float $amount = 10.0, float $paid = 10.0 ) => array( array( 'id' => '1', 'type' => 'credit_card', 'method' => 'visa', 'amount' => $amount, 'paid' => $paid, 'installments' => $n ) );

		$this->assertSame( array(), Reconciler::evaluate( 3, 'card', 1000, $card( 3 ) ), 'within the limit' );
		$this->assertCount( 1, Reconciler::evaluate( 3, 'card', 1000, $card( 6 ) ), 'too many installments' );
		$this->assertCount( 1, Reconciler::evaluate( 1, 'cash', 1000, $card( 1 ) ), 'card on a cash plan' );
		$this->assertSame( array(), Reconciler::evaluate( 1, 'cash', 1000, array( array( 'id' => '1', 'type' => 'account_money', 'method' => 'account_money', 'amount' => 10.0, 'paid' => 10.0, 'installments' => null ) ) ) );
		$this->assertSame( array(), Reconciler::evaluate( 9, 'card', 1000, $card( 9, 10.005, 10.0 ) ), 'one cent of tolerance' );
		$this->assertCount( 1, Reconciler::evaluate( 3, 'card', 1000, $card( 3, 12.0, 10.0 ) ), 'amount differs' );
		$this->assertCount( 1, Reconciler::evaluate( 3, 'card', 1000, $card( 3, 10.0, 11.5 ) ), 'customer paid interest' );
		$this->assertCount( 4, Reconciler::evaluate( 1, 'cash', 1000, $card( 6, 12.0, 14.0 ) ) );
	}

	public function test_mp_credit_counts_as_divergence_below_12_installments(): void {
		$credit = array( array( 'id' => '9', 'type' => 'digital_credit', 'method' => 'consumer_credits', 'amount' => 10.0, 'paid' => 10.0, 'installments' => null ) );
		$this->assertCount( 1, Reconciler::evaluate( 3, 'card', 1000, $credit ) );
		$this->assertCount( 1, Reconciler::evaluate( 9, 'card', 1000, $credit ) );
		$this->assertSame( array(), Reconciler::evaluate( 12, 'card', 1000, $credit ), 'the 12-installment plan allows it' );
		$by_type = array( array( 'id' => '9', 'type' => 'consumer_credits', 'method' => '', 'amount' => 10.0, 'paid' => 10.0, 'installments' => null ) );
		$this->assertCount( 1, Reconciler::evaluate( 6, 'card', 1000, $by_type ) );
		$this->assertSame( array(), Reconciler::evaluate( 3, 'card', 1000, $credit, false ), 'setting off: not a divergence' );
		$this->assertTrue( Reconciler::is_mp_credit( '', 'consumer_credits' ) );
		$this->assertFalse( Reconciler::is_mp_credit( 'credit_card', 'visa' ) );
		$this->assertFalse( Reconciler::is_mp_credit( 'account_money', 'account_money' ) );
	}

	public function test_consistent_card_payment_is_verified_once_and_not_rescheduled(): void {
		$this->plan( 3 );
		$this->payment( '555', 'credit_card', 1000.0, 1000.0, 3 );
		( new Reconciler() )->on_status_changed( 77, 'pending', 'processing', $this->order() );
		$this->assertSame( 'ok', $this->meta['_motored_plan_recon'] );
		$this->assertSame( array(), $this->scheduled );
		$this->assertSame( array(), $this->status_changes );
		$this->assertSame( array(), $this->mails );
	}

	public function test_divergence_puts_the_order_on_hold_notes_and_mails_but_never_cancels_or_refunds(): void {
		$this->plan( 3 );
		$this->payment( '555', 'credit_card', 1000.0, 1000.0, 6 );
		$order = $this->order();
		( new Reconciler() )->on_status_changed( 77, 'pending', 'completed', $order );

		$this->assertSame( array( 'on-hold' ), $this->status_changes );
		$this->assertSame( 'diverged', $this->meta['_motored_plan_recon'] );
		$this->assertStringContainsString( 'mismatch', $this->notes[0] );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'owner@example.test', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Nothing was cancelled or refunded', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'example.test/customer', $this->mails[0]['message'] );

		// A second look (e.g. the delayed job) does not repeat the actions.
		Functions\when( 'wc_get_order' )->justReturn( $order );
		( new Reconciler() )->run( 77 );
		$this->assertSame( array( 'on-hold' ), $this->status_changes );
		$this->assertCount( 1, $this->mails );
	}

	public function test_missing_payment_data_schedules_the_delayed_check_and_reports_at_the_end(): void {
		$this->plan( 3 );
		$order = $this->order();
		( new Reconciler() )->on_status_changed( 77, 'pending', 'processing', $order );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( Reconciler::HOOK, $this->scheduled[0][1] );
		$this->assertSame( array( 77 ), $this->scheduled[0][2] );
		$this->assertEqualsWithDelta( time() + 120, $this->scheduled[0][0], 5 );
		$this->assertSame( array(), $this->notes, 'no note on the first look' );
		$this->assertSame( array(), $this->status_changes );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		( new Reconciler() )->run( 77 );
		$this->assertSame( 'unverified', $this->meta['_motored_plan_recon'] );
		$this->assertCount( 1, $this->notes );
		$this->assertSame( array(), $this->status_changes, 'no data is not a divergence' );
	}

	public function test_payment_meta_arriving_late_is_checked_by_the_delayed_job(): void {
		$this->plan( 3 );
		$order = $this->order();
		( new Reconciler() )->on_status_changed( 77, 'pending', 'processing', $order );
		$this->payment( '555', 'credit_card', 1000.0, 1000.0, 9 );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		( new Reconciler() )->run( 77 );
		$this->assertSame( array( 'on-hold' ), $this->status_changes );
	}

	public function test_orders_without_plan_and_other_statuses_are_ignored(): void {
		$reconciler = new Reconciler();
		$reconciler->on_status_changed( 77, 'pending', 'processing', $this->order() );
		$this->plan( 3 );
		$reconciler->on_status_changed( 77, 'pending', 'on-hold', $this->order() );
		$reconciler->on_status_changed( 77, 'pending', 'cancelled', $this->order() );
		$this->assertSame( array(), $this->scheduled );
		$this->assertArrayNotHasKey( '_motored_plan_recon', $this->meta );
	}
}
