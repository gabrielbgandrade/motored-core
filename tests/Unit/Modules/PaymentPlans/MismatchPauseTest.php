<?php
namespace Motored\Core\Tests\Unit\Modules\PaymentPlans;

use Brain\Monkey\Functions;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\OrderNotifications\Config as NotifConfig;
use Motored\Core\Modules\OrderNotifications\Notifier;
use Motored\Core\Modules\PaymentPlans\Reconciler;
use Motored\Core\Modules\Recovery\OrderStepHandler;
use Motored\Core\Modules\Recovery\OrderSync;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

/**
 * Regression: an order flagged `_motored_plan_mismatch` gets NO customer notice and NO recovery step.
 */
final class MismatchPauseTest extends TestCase {

	/** @var array<string,string> */
	private array $meta = array();

	/** @var list<string> */
	private array $notes = array();

	/** @var list<array{string,int,?string}> */
	private array $enqueued = array();

	/** @var list<array<string,mixed>> */
	private array $updates = array();

	/** @var array<string,mixed>|null */
	private ?array $row = null;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		$this->fake_logger();
		Options::register_defaults( NotifConfig::OPTION_KEY, NotifConfig::DEFAULTS );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $t ) => strip_tags( (string) $t ) );
		$this->meta     = array();
		$this->notes    = array();
		$this->enqueued = array();
		$this->updates  = array();
		$this->row      = null;
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn() => $this->row );
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data ) {
				$this->updates[] = $data;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;
	}

	private function order(): \WC_Order {
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order->shouldReceive( 'get_created_via' )->andReturn( 'checkout' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( 'cliente@example.test' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( $key ) => $this->meta[ $key ] ?? '' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( $key, $value ) {
				$this->meta[ $key ] = $value;
			}
		);
		$order->shouldReceive( 'save_meta_data' )->andReturnNull();
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( $note ) {
				$this->notes[] = $note;
				return 1;
			}
		);
		return $order;
	}

	private function notifier( \WC_Order $order ): Notifier {
		return new Notifier(
			fn( int $id ) => $order,
			function ( string $kind, int $ref, ?string $step ): bool {
				$this->enqueued[] = array( $kind, $ref, $step );
				return true;
			},
			fn() => 'nota',
			fn() => '54',
			fn( $total, $currency ) => $total,
			fn() => 'Motored'
		);
	}

	public function test_reconciler_sets_the_flag_before_changing_the_status(): void {
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_total' )->andReturn( 1000.0 );
		$order->shouldReceive( 'get_order_number' )->andReturn( '42' );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.test/edit' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( $key ) => $this->meta[ $key ] ?? '' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( $key, $value ) {
				$this->meta[ $key ] = $value;
			}
		);
		$order->shouldReceive( 'save_meta_data' )->andReturnNull();
		$seen = null;
		$order->shouldReceive( 'update_status' )->andReturnUsing(
			function () use ( &$seen ) {
				$seen = $this->meta[ OrderHooks::PLAN_MISMATCH_META ] ?? null;
				return true;
			}
		);
		$order->shouldReceive( 'get_meta_data' )->andReturn(
			array(
				new class() {
					public function get_data(): array {
						return array( 'key' => 'Mercado Pago - Payment 5', 'value' => '[Amount 1000]/[Payment Type credit_card]/[Payment Method visa]/[Paid 1000]' );
					}
				},
				new class() {
					public function get_data(): array {
						return array( 'key' => 'Mercado Pago - 5 - installments', 'value' => 9 );
					}
				},
			)
		);
		$this->meta = array( '_motored_plan_id' => 'c3', '_motored_plan_max_installments' => '3', '_motored_plan_type' => 'card' );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'wc_price' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		( new Reconciler() )->on_status_changed( 42, 'pending', 'processing', $order );

		$this->assertSame( '1', $seen, 'the flag is already stored when the on-hold status event fires' );
		$this->assertSame( '1', $this->meta['_motored_plan_mismatch'] );
	}

	public function test_customer_notices_are_not_enqueued_for_a_flagged_order(): void {
		$order = $this->order();
		$this->notifier( $order )->on_status_changed( 42, 'processing', 'on-hold', $order );
		$this->assertNotEmpty( $this->enqueued, 'control: an unflagged on-hold order is notified' );

		$this->enqueued = array();
		$this->meta     = array( '_motored_plan_mismatch' => '1' );
		$this->notifier( $order )->on_status_changed( 42, 'processing', 'on-hold', $order );
		$this->notifier( $order )->on_customer_note( array( 'order_id' => 42, 'customer_note' => 'hola' ) );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_already_queued_notices_stop_at_send_time(): void {
		$order = $this->order();
		$this->assertNotNull( $this->notifier( $order )->build_message( 42, 'on-hold' ) ?? new \stdClass(), 'control (phone may be missing: still not an error)' );
		$this->meta = array( '_motored_plan_mismatch' => '1' );
		$this->assertNull( $this->notifier( $order )->build_message( 42, 'on-hold' ) );
	}

	public function test_recovery_stops_the_active_sequence_with_a_note(): void {
		$order            = $this->order();
		$this->meta       = array( '_motored_plan_mismatch' => '1' );
		$this->row        = array( 'id' => 9, 'type' => 'order', 'status' => 'active', 'step' => 0, 'order_id' => 42, 'wa_sent_count' => 0, 'email_sent_count' => 0, 'has_items' => 1 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		OrderSync::sync_order( $order );

		$this->assertSame( 'expired', $this->updates[0]['status'] ?? null );
		$this->assertSame( array( 'Recovery paused for payment plan review.' ), $this->notes );
	}

	public function test_recovery_never_starts_a_sequence_for_a_flagged_order(): void {
		$order      = $this->order();
		$this->meta = array( '_motored_plan_mismatch' => '1' );
		OrderSync::sync_order( $order );
		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), $this->notes );
	}

	public function test_recovery_email_step_is_not_sent_for_a_flagged_order(): void {
		$order      = $this->order();
		$this->meta = array( '_motored_plan_mismatch' => '1' );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$row = array( 'id' => 9, 'order_id' => 42, 'type' => 'order', 'status' => 'active', 'step' => 0 );
		$this->assertFalse( OrderStepHandler::send_email( $row, 2, true ) );
	}
}
