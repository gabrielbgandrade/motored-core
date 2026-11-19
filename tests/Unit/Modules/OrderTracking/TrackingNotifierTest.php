<?php
namespace Motored\Core\Tests\Unit\Modules\OrderTracking;

use Brain\Monkey\Functions;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Modules\OrderNotifications\Config as NotificationsConfig;
use Motored\Core\Modules\OrderTracking\Config;
use Motored\Core\Modules\OrderTracking\Presenter;
use Motored\Core\Modules\OrderTracking\Tracking;
use Motored\Core\Modules\OrderTracking\TrackingNotifier;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class TrackingNotifierTest extends TestCase {

	/** @var array<string,mixed> */
	private array $meta = array();
	/** @var list<string> */
	private array $notes = array();
	/** @var list<array{string,int,?string}> */
	private array $queued = array();
	private int $emails = 0;
	private bool $email_ok = true;
	private string $created_via = 'checkout';
	private string $phone = '011 15 5555-1234';
	private string $billing_email = 'ana@example.com';

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		Options::register_defaults( NotificationsConfig::OPTION_KEY, NotificationsConfig::DEFAULTS );
		$this->options['motored_order_tracking'] = array(
			'carriers' => array( array( 'id' => 'andreani', 'name' => 'Andreani', 'url' => 'https://a.com/t/{code}', 'active' => 'yes' ) ),
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->alias( static fn( $u ) => $u );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn( $e ) => str_contains( (string) $e, '@' ) );
		$this->meta          = array( Tracking::META => array( 'carrier_id' => 'andreani', 'code' => 'AB123', 'url_override' => '', 'updated_gmt' => 1 ) );
		$this->notes         = array();
		$this->queued        = array();
		$this->emails        = 0;
		$this->email_ok      = true;
		$this->created_via   = 'checkout';
		$this->phone         = '011 15 5555-1234';
		$this->billing_email = 'ana@example.com';
	}

	private function order(): \WC_Order {
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_order_number' )->andReturn( '1042' );
		$order->shouldReceive( 'get_created_via' )->andReturnUsing( fn() => $this->created_via );
		$order->shouldReceive( 'get_billing_phone' )->andReturnUsing( fn() => $this->phone );
		$order->shouldReceive( 'get_billing_email' )->andReturnUsing( fn() => $this->billing_email );
		$order->shouldReceive( 'get_billing_country' )->andReturn( 'AR' );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( 'Ana' );
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://s.com/order/42' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( string $k ) => $this->meta[ $k ] ?? '' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $k, $v ): void {
				$this->meta[ $k ] = $v;
			}
		);
		$order->shouldReceive( 'save_meta_data' );
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( string $n ): int {
				$this->notes[] = $n;
				return 1;
			}
		);
		return $order;
	}

	private function notifier( \WC_Order $order ): TrackingNotifier {
		return new TrackingNotifier(
			fn( int $id ) => 42 === $id ? $order : false,
			function ( string $kind, int $id, ?string $step ): bool {
				$this->queued[] = array( $kind, $id, $step );
				return true;
			},
			function (): bool {
				++$this->emails;
				return $this->email_ok;
			},
			fn( string $iso ): string => 'AR' === $iso ? '54' : '',
			fn(): string => 'Motored'
		);
	}

	public function test_new_code_queues_one_whatsapp_and_one_email_then_is_idempotent(): void {
		$order = $this->order();
		$n     = $this->notifier( $order );

		$n->on_saved( $order, '' );
		$n->on_saved( $order, '' );

		$this->assertSame( array( array( 'order_tracking', 42, 'added' ) ), $this->queued );
		$this->assertSame( 1, $this->emails );
		$this->assertSame( md5( 'AB123' ), $this->meta[ Tracking::SENT_WA ] );
		$this->assertSame( md5( 'AB123' ), $this->meta[ Tracking::SENT_EMAIL ] );
	}

	public function test_changed_code_sends_again_as_updated_and_unchanged_code_does_not(): void {
		$order = $this->order();
		$n     = $this->notifier( $order );
		$n->on_saved( $order, '' );

		$this->meta[ Tracking::META ]['code'] = 'ZZ999';
		$n->on_saved( $order, 'AB123' );
		$n->on_saved( $order, 'ZZ999' );

		$this->assertSame( array( 'added', 'updated' ), array_column( $this->queued, 2 ) );
		$this->assertSame( 2, $this->emails );
	}

	public function test_completed_message_or_email_that_carried_the_code_suppresses_the_dedicated_ones(): void {
		$order                                 = $this->order();
		$this->meta[ Tracking::CARRIED_WA ]    = md5( 'AB123' );
		$this->meta[ Tracking::CARRIED_EMAIL ] = md5( 'AB123' );
		$this->notifier( $order )->on_saved( $order, '' );
		$this->assertSame( array(), $this->queued );
		$this->assertSame( 0, $this->emails );

		// Job queued earlier but the completed message went out first: nothing is built.
		$this->assertNull( $this->notifier( $order )->build_message( 42, 'added' ) );
	}

	public function test_switches_are_respected_per_channel(): void {
		$order = $this->order();
		$this->options['motored_order_tracking']['send_whatsapp'] = 'no';
		$this->notifier( $order )->on_saved( $order, '' );
		$this->assertSame( array(), $this->queued );
		$this->assertSame( 1, $this->emails );
	}

	public function test_manual_orders_and_missing_contacts_are_skipped_with_a_note_without_pii(): void {
		$order             = $this->order();
		$this->created_via = 'admin';
		$this->notifier( $order )->on_saved( $order, '' );
		$this->assertSame( array(), $this->queued );
		$this->assertSame( 0, $this->emails );
		$this->assertCount( 1, $this->notes );

		$this->created_via   = 'checkout';
		$this->notes         = array();
		$this->phone         = '';
		$this->billing_email = 'nope';
		$this->notifier( $order )->on_saved( $order, '' );
		$this->assertSame( array(), $this->queued );
		$this->assertSame( 0, $this->emails );
		$this->assertCount( 2, $this->notes );
		foreach ( $this->notes as $note ) {
			$this->assertStringNotContainsString( 'nope', $note );
			$this->assertStringNotContainsString( '5555', $note );
		}
		$this->assertArrayNotHasKey( Tracking::SENT_WA, $this->meta );
	}

	public function test_failed_email_is_not_marked_as_sent(): void {
		$order          = $this->order();
		$this->email_ok = false;
		$this->notifier( $order )->on_saved( $order, '' );
		$this->assertArrayNotHasKey( Tracking::SENT_EMAIL, $this->meta );
	}

	public function test_build_message_renders_at_send_time_with_tracking_placeholders(): void {
		$order   = $this->order();
		$message = $this->notifier( $order )->build_message( 42, 'added' );

		$this->assertNotNull( $message );
		$text = $message->text;
		$this->assertStringContainsString( 'Track your shipment with Andreani: code AB123 https://a.com/t/AB123', $text );
		$this->assertStringContainsString( '#1042', $text );
		$this->assertStringContainsString( 'https://s.com/order/42', $text );
		$this->assertNull( $this->notifier( $order )->build_message( 42, 'bogus' ) );
		$this->assertNull( $this->notifier( $order )->build_message( 7, 'added' ) );
	}

	public function test_result_note_has_neither_phone_nor_text(): void {
		$order = $this->order();
		$this->notifier( $order )->on_result( 'order_tracking', 42, 'added', Result::success( 200 ) );
		$this->notifier( $order )->on_result( 'order_tracking', 42, 'added', Result::failure( 'timeout', 'x' ) );
		$this->notifier( $order )->on_result( 'order_event', 42, 'added', Result::success( 200 ) );
		$this->assertCount( 2, $this->notes );
		foreach ( $this->notes as $note ) {
			$this->assertStringNotContainsString( 'AB123', $note );
		}
	}

	public function test_presenter_placeholders_with_and_without_tracking(): void {
		$order = $this->order();
		$vars  = Presenter::vars( $order );
		$this->assertSame( 'Andreani', $vars['tracking_carrier'] );
		$this->assertSame( 'AB123', $vars['tracking_code'] );
		$this->assertSame( 'https://a.com/t/AB123', $vars['tracking_url'] );

		$this->meta[ Tracking::META ] = array( 'carrier_id' => 'other', 'code' => 'Q1', 'url_override' => 'https://m.com/q' );
		$this->assertSame( 'Track your shipment: code Q1 https://m.com/q', Presenter::vars( $order )['tracking_block'] );

		$this->meta = array();
		$this->assertSame( array( 'tracking_carrier' => '', 'tracking_code' => '', 'tracking_url' => '', 'tracking_block' => '' ), Presenter::vars( $order ) );
	}
}
