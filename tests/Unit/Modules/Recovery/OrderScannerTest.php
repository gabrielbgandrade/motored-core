<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Recovery\Repository;
use Motored\Core\Modules\Recovery\Scanner;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Tests\Unit\TestCase;

final class OrderScannerTest extends TestCase {

	/** @var list<array<string,mixed>> */
	private array $updates = array();

	protected function setUp(): void {
		parent::setUp();
		Sender::reset();
		$this->fake_storage();
		$this->fake_logger();
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $text ) => strip_tags( (string) $text ) );

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data ) {
				$this->updates[] = $data;
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
		$this->updates   = array();
	}

	protected function tearDown(): void {
		Sender::reset();
		parent::tearDown();
	}

	/**
	 * @param list<array<string,mixed>> $rows Due rows.
	 */
	private function due( array $rows ): void {
		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->andReturn( $rows );
	}

	public function test_due_order_enqueues_the_next_step_number_without_http(): void {
		$this->due( array( array( 'id' => 7, 'type' => 'order', 'step' => '', 'order_id' => 500, 'last_activity_gmt' => '2026-11-18 10:00:00', 'next_action_gmt' => '2026-11-18 10:30:00' ) ) );
		$args = array();
		Functions\expect( 'as_schedule_single_action' )->once()->andReturnUsing(
			function ( $ts, $hook, $a, $group ) use ( &$args ) {
				$args = array( $hook, $a, $group );
				return 1;
			}
		);

		Scanner::run();

		$this->assertSame( 'motored_waha_send', $args[0] );
		$this->assertSame( array( 'recovery_step', 7, '1', 1 ), $args[1], 'IDs and step number only.' );
		$this->assertSame( array( 'next_action_gmt' => null ), $this->updates[0], 'In-flight rows are not enqueued twice.' );
	}

	public function test_second_order_step_and_exhausted_sequence(): void {
		$this->due(
			array(
				array( 'id' => 8, 'type' => 'order', 'step' => '1', 'order_id' => 501, 'last_activity_gmt' => '2026-11-18 10:00:00', 'next_action_gmt' => '2026-11-19 10:00:00' ),
				array( 'id' => 9, 'type' => 'order', 'step' => '2', 'order_id' => 502, 'last_activity_gmt' => '2026-11-18 10:00:00', 'next_action_gmt' => '2026-11-19 10:00:00' ),
			)
		);
		$steps = array();
		Functions\expect( 'as_schedule_single_action' )->once()->andReturnUsing(
			function ( $ts, $hook, $a ) use ( &$steps ) {
				$steps[] = $a;
				return 1;
			}
		);

		Scanner::run();

		$this->assertSame( array( array( 'recovery_step', 8, '2', 1 ) ), $steps );
		$this->assertContains( 'expired', array_column( $this->updates, 'status' ), 'Row 9 finished its two steps.' );
	}

	public function test_find_for_order_builds_one_prepared_query(): void {
		$sql  = '';
		$args = array();
		$wpdb = \Mockery::mock( 'wpdb_q' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $query, $params ) use ( &$sql, &$args ) {
				$sql  = $query;
				$args = $params;
				return 'SQL';
			}
		);
		$wpdb->shouldReceive( 'get_row' )->twice()->andReturn( null );
		$GLOBALS['wpdb'] = $wpdb;

		Repository::find_for_order( 500, 'sess', 'ANA@x.com', '2026-11-17 00:00:00' );
		$this->assertStringContainsString( 'order_id = %d', $sql );
		$this->assertStringContainsString( 'session_key = %s', $sql );
		$this->assertStringContainsString( 'email = %s', $sql );
		$this->assertStringContainsString( 'LIMIT 1', $sql );
		$this->assertStringNotContainsString( '*', $sql );
		$this->assertSame( array( 500, 'sess', 'ana@x.com', '2026-11-17 00:00:00', 500 ), $args );

		Repository::find_for_order( 501, '', '', '2026-11-17 00:00:00' );
		$this->assertStringNotContainsString( 'session_key', $sql );
		$this->assertSame( array( 501, 501 ), $args );
	}
}
