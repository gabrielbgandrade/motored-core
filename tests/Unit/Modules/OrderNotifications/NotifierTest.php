<?php
namespace Motored\Core\Tests\Unit\Modules\OrderNotifications;

use Brain\Monkey\Functions;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Modules\OrderNotifications\Config;
use Motored\Core\Modules\OrderNotifications\EventMap;
use Motored\Core\Modules\OrderNotifications\Notifier;
use Motored\Core\Modules\Waha\Message;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class NotifierTest extends TestCase {

	/** @var array<string,string> */
	private array $meta = array();
	/** @var list<array{note:string,customer:int}> */
	private array $notes = array();
	/** @var list<array{kind:string,ref_id:int,step:?string}> */
	private array $enqueued = array();
	private int $meta_saves = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		$this->fake_logger();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$this->meta       = array();
		$this->notes      = array();
		$this->enqueued   = array();
		$this->meta_saves = 0;
		Functions\when( '__' )->returnArg();
	}

	private function make_order( array $overrides = array() ): \Mockery\MockInterface {
		$data = array_merge(
			array(
				'id'                 => 42,
				'order_number'       => '1042',
				'status'             => 'processing',
				'created_via'        => 'checkout',
				'billing_first_name' => 'Martín',
				'billing_phone'      => '011 15 5555-1234',
				'billing_country'    => 'AR',
				'total'              => '25000',
				'currency'           => 'ARS',
				'order_url'          => 'https://motored.com.ar/checkout/order-received/42/?key=wc_order_xyz',
				'items'              => array(
					array( 'name' => 'Cubierta Pirelli', 'qty' => 2 ),
				),
			),
			$overrides
		);

		$order_items = array();
		foreach ( $data['items'] as $item_row ) {
			$item = \Mockery::mock( 'WC_Order_Item' );
			$item->shouldReceive( 'get_name' )->andReturn( $item_row['name'] );
			$item->shouldReceive( 'get_quantity' )->andReturn( $item_row['qty'] );
			$order_items[] = $item;
		}

		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $data['id'] );
		$order->shouldReceive( 'get_order_number' )->andReturn( $data['order_number'] );
		$order->shouldReceive( 'get_status' )->andReturnUsing( fn() => $data['status'] );
		$order->shouldReceive( 'get_created_via' )->andReturn( $data['created_via'] );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( $data['billing_first_name'] );
		$order->shouldReceive( 'get_billing_phone' )->andReturn( $data['billing_phone'] );
		$order->shouldReceive( 'get_billing_country' )->andReturn( $data['billing_country'] );
		$order->shouldReceive( 'get_total' )->andReturn( $data['total'] );
		$order->shouldReceive( 'get_currency' )->andReturn( $data['currency'] );
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( $data['order_url'] );
		$order->shouldReceive( 'get_items' )->andReturn( $order_items );

		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			fn( string $key ) => $this->meta[ $key ] ?? ''
		);
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $key, string $value ): void {
				$this->meta[ $key ] = $value;
			}
		);
		$order->shouldReceive( 'save_meta_data' )->andReturnUsing(
			function (): void {
				++$this->meta_saves;
			}
		);
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( string $note, int $is_customer_note = 0 ): int {
				$this->notes[] = array(
					'note'     => $note,
					'customer' => $is_customer_note,
				);
				return count( $this->notes );
			}
		);

		return $order;
	}

	private function make_notifier( ?\WC_Order $order = null, string $customer_note = 'Despachado hoy' ): Notifier {
		return new Notifier(
			fn( int $id ) => ( null !== $order && $id === $order->get_id() ) ? $order : false,
			function ( string $kind, int $ref_id, ?string $step ): bool {
				$this->enqueued[] = array(
					'kind'   => $kind,
					'ref_id' => $ref_id,
					'step'   => $step,
				);
				return true;
			},
			fn() => $customer_note,
			fn( string $cc ): string => match ( $cc ) {
				'AR'    => '54',
				'UY'    => '598',
				default => '',
			},
			fn( string $total, string $currency ): string => '<span class="amount">&#36;&nbsp;' . $total . '</span>',
			fn(): string => 'Motored &amp; Neumáticos'
		);
	}

	public function test_status_change_enqueues_once_and_is_idempotent(): void {
		$order    = $this->make_order();
		$notifier = $this->make_notifier( $order );

		$notifier->on_status_changed( 42, 'pending', 'processing', $order );
		$notifier->on_status_changed( 42, 'on-hold', 'processing', $order );

		$this->assertCount( 1, $this->enqueued );
		$this->assertSame( array( 'kind' => 'order_event', 'ref_id' => 42, 'step' => 'processing' ), $this->enqueued[0] );
		$this->assertSame( '1', $this->meta['_motored_wa_processing'] );
		$this->assertSame( 1, $this->meta_saves );
	}

	public function test_exits_early_when_event_is_disabled_without_reading_order(): void {
		$this->options['motored_order_notifications'] = array(
			EventMap::PROCESSING => array(
				'enabled' => 'no',
				'text'    => 'Hola',
			),
		);

		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldNotReceive( 'get_meta' );
		$order->shouldNotReceive( 'get_created_via' );

		$notifier = $this->make_notifier();
		$notifier->on_status_changed( 42, 'pending', 'processing', $order );

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_skips_manual_admin_orders_unless_enabled(): void {
		$manual   = $this->make_order( array( 'created_via' => 'admin' ) );
		$notifier = $this->make_notifier( $manual );

		$notifier->on_status_changed( 42, 'pending', 'processing', $manual );
		$this->assertSame( array(), $this->enqueued );

		Options::reset();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$this->options['motored_order_notifications'] = array( 'include_manual_orders' => 'yes' );

		$notifier->on_status_changed( 42, 'pending', 'processing', $manual );
		$this->assertCount( 1, $this->enqueued );
	}

	public function test_stored_admin_alert_options_are_ignored(): void {
		$order = $this->make_order();
		$this->options['motored_order_notifications'] = array(
			'admin_phone'     => '011 5555-9999',
			'admin_new_order' => array( 'enabled' => 'yes', 'text' => 'Nuevo #{order_number}' ),
		);
		$this->make_notifier( $order )->on_status_changed( 42, 'pending', 'processing', $order );
		$this->assertCount( 1, $this->enqueued );
		$this->assertSame( 'processing', $this->enqueued[0]['step'] );
		$this->assertArrayNotHasKey( 'admin_phone', Config::DEFAULTS );
		$this->assertArrayNotHasKey( 'admin_new_order', Config::DEFAULTS );
		$this->assertNull( $this->make_notifier( $order )->build_message( 42, 'admin_new_order' ) );
	}

	public function test_customer_note_idempotency_deduplicates_same_note_and_allows_new_one(): void {
		$order    = $this->make_order();
		$notifier = $this->make_notifier( $order );

		$notifier->on_customer_note( array( 'order_id' => 42, 'customer_note' => 'Despachado hoy' ) );
		$notifier->on_customer_note( array( 'order_id' => 42, 'customer_note' => 'Despachado hoy' ) );
		$this->assertCount( 1, $this->enqueued );

		$notifier->on_customer_note( array( 'order_id' => 42, 'customer_note' => 'Código: 9988' ) );
		$this->assertCount( 2, $this->enqueued );
	}

	public function test_build_message_skips_when_order_status_changed_before_send(): void {
		$order    = $this->make_order( array( 'status' => 'cancelled' ) );
		$notifier = $this->make_notifier( $order );

		$this->assertNull( $notifier->build_message( 42, EventMap::PROCESSING ) );
		$this->assertSame( array(), $this->notes );
	}

	public function test_build_message_records_single_private_note_on_invalid_phone(): void {
		$order    = $this->make_order( array( 'billing_phone' => '123' ) );
		$notifier = $this->make_notifier( $order );

		$this->assertNull( $notifier->build_message( 42, EventMap::PROCESSING ) );
		$this->assertNull( $notifier->build_message( 42, EventMap::PROCESSING ) );

		$this->assertCount( 1, $this->notes );
		$this->assertSame( 0, $this->notes[0]['customer'] );
		$this->assertSame( '1', $this->meta[ EventMap::NO_PHONE_META ] );
	}

	public function test_build_message_uses_billing_country_calling_code_and_renders_plain_text(): void {
		$order = $this->make_order(
			array(
				'billing_country' => 'UY',
				'billing_phone'   => '099 123 456',
			)
		);
		$notifier = $this->make_notifier( $order );

		$message = $notifier->build_message( 42, EventMap::PROCESSING );

		$this->assertInstanceOf( Message::class, $message );
		$this->assertSame( '+59899123456', $message->phone );
		$this->assertStringContainsString( '*#1042*', $message->text );
		$this->assertStringContainsString( 'Motored & Neumáticos', $message->text );
		$this->assertStringContainsString( '$ 25000', $message->text );
		$this->assertStringContainsString( '- 2x Cubierta Pirelli', $message->text );
		$this->assertStringContainsString( '?key=wc_order_xyz', $message->text );
		$this->assertStringNotContainsString( '<span', $message->text );
	}

	public function test_on_result_writes_private_notes_without_phone_or_text(): void {
		$order    = $this->make_order();
		$notifier = $this->make_notifier( $order );

		$notifier->on_result( EventMap::KIND, 42, EventMap::PROCESSING, Result::success( 201 ) );
		$notifier->on_result( EventMap::KIND, 42, EventMap::PROCESSING, Result::failure( 'no_whatsapp', '5491155551234 not found' ) );
		$notifier->on_result( EventMap::KIND, 42, EventMap::PROCESSING, Result::failure( 'skipped', 'Handler returned no message.' ) );
		$notifier->on_result( 'other_kind', 42, EventMap::PROCESSING, Result::success( 201 ) );

		$this->assertCount( 2, $this->notes );
		$this->assertSame( 0, $this->notes[0]['customer'] );
		$this->assertSame( 'WhatsApp sent: processing', $this->notes[0]['note'] );
		$this->assertSame( 0, $this->notes[1]['customer'] );
		$this->assertSame( 'WhatsApp failed: number is not on WhatsApp.', $this->notes[1]['note'] );
		$this->assertStringNotContainsString( '5491155551234', $this->notes[1]['note'] );
	}
}
