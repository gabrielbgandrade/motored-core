<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Recovery\OrderSync;
use Motored\Core\Tests\Unit\TestCase;

final class OrderSyncTest extends TestCase {

	private const NOW = 1700000000;

	/** @var list<array{0:string,1:array<string,mixed>}> */
	private array $inserts = array();
	/** @var list<array{0:string,1:array<string,mixed>,2:array<string,mixed>}> */
	private array $updates = array();
	/** @var array<string,mixed>|null */
	private ?array $lookup = null;
	/** @var list<mixed> */
	private array $lookup_args = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		$this->fake_logger();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $text ) => strip_tags( (string) $text ) );

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, $args = array() ) {
				$this->lookup_args = (array) $args;
				return 'SQL: ' . $sql;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn() => $this->lookup );
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				$this->inserts[] = array( $table, $data );
				$wpdb            = $GLOBALS['wpdb'];
				$wpdb->insert_id = 77;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;
		$this->inserts   = array();
		$this->updates   = array();
		$this->lookup    = null;
	}

	/**
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function order( string $status, array $overrides = array() ): array {
		return array_merge(
			array(
				'order_id'      => 500,
				'status'        => $status,
				'email'         => 'Ana.Perez@gmail.com',
				'user_id'       => 0,
				'phone'         => '011 15 5555-1234',
				'first_name'    => 'Ana',
				'country'       => '',
				'total'         => '12345.5',
				'currency'      => 'ARS',
				'needs_payment' => true,
				'lines'         => array(
					array( 'product_id' => 10, 'variation_id' => 0, 'quantity' => 2, 'variation' => array() ),
				),
			),
			$overrides
		);
	}

	/**
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => 12,
				'type'             => 'cart',
				'status'           => 'active',
				'step'             => '',
				'order_id'         => 0,
				'wa_sent_count'    => 0,
				'email_sent_count' => 0,
				'has_items'        => 1,
			),
			$overrides
		);
	}

	public function test_new_pending_order_creates_a_row_with_the_pending_sequence(): void {
		$this->assertNull( OrderSync::apply( $this->order( 'pending' ), array( 'processing', 'completed' ), '', self::NOW, 0 ) );

		$this->assertCount( 1, $this->inserts );
		$this->assertSame( array(), $this->updates );
		$data = $this->inserts[0][1];
		$this->assertSame( 'wp_motored_recoveries', $this->inserts[0][0] );
		$this->assertSame( 'order', $data['type'] );
		$this->assertSame( 500, $data['order_id'] );
		$this->assertSame( 'active', $data['status'] );
		$this->assertSame( 'ana.perez@gmail.com', $data['email'] );
		$this->assertSame( '+5491155551234', $data['phone'] );
		$this->assertSame( 'Ana', $data['first_name'] );
		$this->assertSame( '12345.5000', $data['total'] );
		$this->assertSame( 'ARS', $data['currency'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 30 * 60 ), $data['next_action_gmt'], 'WhatsApp at 30 minutes.' );
		$this->assertArrayNotHasKey( 'session_key', $data );
		$this->assertStringContainsString( '"product_id":10', $data['items'] );
		$this->assertStringNotContainsString( 'price', $data['items'] );
	}

	public function test_failed_uses_30_minutes_and_on_hold_24_hours(): void {
		OrderSync::apply( $this->order( 'failed' ), array( 'processing' ), '', self::NOW, 0 );
		OrderSync::apply( $this->order( 'on-hold', array( 'order_id' => 501 ) ), array( 'processing' ), '', self::NOW, 0 );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 30 * 60 ), $this->inserts[0][1]['next_action_gmt'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 1440 * 60 ), $this->inserts[1][1]['next_action_gmt'] );
	}

	public function test_cart_row_is_converted_into_the_order_row_without_a_second_sequence(): void {
		// The active cart of the same session/e-mail, which already got step 1 (WhatsApp).
		$this->lookup = array_merge( $this->row( array( 'step' => '1', 'wa_sent_count' => 1 ) ), array() );

		OrderSync::apply( $this->order( 'pending' ), array( 'processing' ), 'sess-1', self::NOW, 0 );

		$this->assertSame( array(), $this->inserts, 'Same row: nothing is inserted.' );
		$this->assertCount( 1, $this->updates );
		$this->assertSame( array( 'id' => 12 ), $this->updates[0][2] );
		$fields = $this->updates[0][1];
		$this->assertSame( 'order', $fields['type'] );
		$this->assertSame( 500, $fields['order_id'] );
		$this->assertArrayNotHasKey( 'session_key', $fields, 'The session stays on the row so no second cart is captured.' );
		$this->assertArrayNotHasKey( 'items', $fields, 'Cart items are kept.' );
		$this->assertArrayNotHasKey( 'step', $fields, 'Progress is kept: no repeated message.' );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 1440 * 60 ), $fields['next_action_gmt'], 'Continues with step 2 (the second message of the same sequence).' );
		$this->assertContains( 'sess-1', $this->lookup_args );
		$this->assertContains( 'ana.perez@gmail.com', $this->lookup_args );
	}

	public function test_a_later_status_event_updates_the_same_order_row(): void {
		$this->lookup = $this->row( array( 'type' => 'order', 'order_id' => 500 ) );
		OrderSync::apply( $this->order( 'on-hold' ), array( 'processing' ), '', self::NOW, 0 );
		OrderSync::apply( $this->order( 'failed' ), array( 'processing' ), '', self::NOW + 10, 0 );

		$this->assertSame( array(), $this->inserts );
		$this->assertCount( 2, $this->updates );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 1440 * 60 ), $this->updates[0][1]['next_action_gmt'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 10 + 30 * 60 ), $this->updates[1][1]['next_action_gmt'], 'Delay restarts from the new status event.' );
	}

	public function test_normal_path_costs_one_lookup_and_one_write(): void {
		$queries = 0;
		$mock    = \Mockery::mock( 'wpdb_counter' );
		$mock->prefix = 'wp_';
		$mock->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$mock->shouldReceive( 'get_row' )->andReturnUsing( function () use ( &$queries ) {
			++$queries;
			return null;
		} );
		$mock->shouldReceive( 'insert' )->andReturnUsing( function () use ( &$queries ) {
			++$queries;
			$GLOBALS['wpdb']->insert_id = 5;
			return 1;
		} );
		$mock->shouldReceive( 'update' )->andReturnUsing( function () use ( &$queries ) {
			++$queries;
			return 1;
		} );
		$GLOBALS['wpdb'] = $mock;

		OrderSync::apply( $this->order( 'pending' ), array( 'processing' ), '', self::NOW, 0 );
		$this->assertLessThanOrEqual( 2, $queries );
	}

	public function test_no_contact_and_no_row_means_nothing_is_stored(): void {
		OrderSync::apply( $this->order( 'pending', array( 'email' => '', 'phone' => '' ) ), array( 'processing' ), '', self::NOW, 0 );
		OrderSync::apply( $this->order( 'pending', array( 'email' => 'x', 'phone' => '12' ) ), array( 'processing' ), '', self::NOW, 0 );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_finished_rows_are_never_restarted(): void {
		foreach ( array( 'expired', 'opted_out', 'converted', 'recovered' ) as $status ) {
			$this->lookup = $this->row( array( 'type' => 'order', 'order_id' => 500, 'status' => $status ) );
			OrderSync::apply( $this->order( 'failed' ), array( 'processing' ), '', self::NOW, 0 );
		}
		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_disabled_order_recovery_creates_nothing(): void {
		$this->options['motored_recovery'] = array( 'order_enabled' => 'no' );
		OrderSync::apply( $this->order( 'pending' ), array( 'processing' ), '', self::NOW, 0 );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_paid_after_our_message_is_recovered_with_attribution(): void {
		$this->lookup = $this->row( array( 'type' => 'order', 'order_id' => 500, 'step' => '1', 'wa_sent_count' => 1 ) );

		$recovered = OrderSync::apply( $this->order( 'processing' ), array( 'processing', 'completed' ), '', self::NOW, 0 );

		$this->assertSame( 12, $recovered );
		$fields = $this->updates[0][1];
		$this->assertSame( 'recovered', $fields['status'] );
		$this->assertSame( 500, $fields['recovered_order_id'] );
		$this->assertNull( $fields['next_action_gmt'], 'Pending sends are cancelled.' );
		$this->assertCount( 1, $this->inserts, 'One history event.' );
		$this->assertSame( 'wp_motored_recovery_events', $this->inserts[0][0] );
		$this->assertSame( 'recovered', $this->inserts[0][1]['event'] );
		$this->assertSame( 'order_id=500', $this->inserts[0][1]['detail'] );
	}

	public function test_paid_without_any_delivered_message_is_only_converted(): void {
		$this->lookup = $this->row( array( 'type' => 'order', 'order_id' => 500 ) );
		$this->assertNull( OrderSync::apply( $this->order( 'completed' ), array( 'processing', 'completed' ), '', self::NOW, 0 ) );
		$this->assertSame( 'converted', $this->updates[0][1]['status'] );
		$this->assertSame( array(), $this->inserts, 'No extra query on the normal paid path.' );
	}

	public function test_paid_without_a_row_does_nothing(): void {
		$this->assertNull( OrderSync::apply( $this->order( 'processing' ), array( 'processing' ), '', self::NOW, 0 ) );
		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_cancelled_by_a_person_stops_but_automatic_cancellation_leaves_the_fallback(): void {
		$this->lookup = $this->row( array( 'type' => 'order', 'order_id' => 500 ) );

		OrderSync::apply( $this->order( 'cancelled' ), array( 'processing' ), '', self::NOW, 0 );
		$this->assertSame( array(), $this->updates, 'Timeout cancellation (no user): the send-time fallback decides.' );

		OrderSync::apply( $this->order( 'cancelled' ), array( 'processing' ), '', self::NOW, 3 );
		$this->assertSame( 'expired', $this->updates[0][1]['status'] );
		$this->assertSame( 'cancelled_manually', $this->inserts[0][1]['detail'] );
	}

	public function test_irrelevant_statuses_are_ignored(): void {
		$this->lookup = $this->row();
		OrderSync::apply( $this->order( 'refunded' ), array( 'processing' ), '', self::NOW, 0 );
		OrderSync::apply( $this->order( 'pending', array( 'order_id' => 0 ) ), array( 'processing' ), '', self::NOW, 0 );
		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), $this->inserts );
	}
}
