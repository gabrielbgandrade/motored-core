<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Modules\Recovery\Config;
use Motored\Core\Modules\Recovery\RecoveryResultHandler;
use Motored\Core\Modules\Recovery\Repository;
use Motored\Core\Modules\Recovery\Status;
use Motored\Core\Tests\Unit\TestCase;

final class RecoveryStepSequenceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $text ) => strip_tags( (string) $text ) );
	}

	public function test_next_step_num_finds_next_active_step(): void {
		// Default: step 1 = whatsapp, step 2 = email, step 3 = off
		$this->assertSame( 1, Config::next_step_num( 0 ) );
		$this->assertSame( 1, Config::next_step_num( '' ) );
		$this->assertSame( 2, Config::next_step_num( 1 ) );
		$this->assertNull( Config::next_step_num( 2 ) ); // Step 3 is 'off' by default
		$this->assertNull( Config::next_step_num( 3 ) );
	}

	public function test_advance_step_updates_counters_and_schedules_next_step(): void {
		$GLOBALS['wpdb'] = \Mockery::mock( 'wpdb_mock' );
		$GLOBALS['wpdb']->prefix = 'wp_';
		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );

		// 1. Initial row at step 0 (empty step)
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->once()->andReturn(
			array(
				'id'                => 15,
				'status'            => Status::ACTIVE,
				'step'              => '',
				'wa_sent_count'     => 0,
				'email_sent_count'  => 0,
				'last_activity_gmt' => '2026-11-18 10:00:00',
			)
		);

		$updated_data = array();
		$GLOBALS['wpdb']->shouldReceive( 'update' )->once()->andReturnUsing(
			function( $table, $data ) use ( &$updated_data ) {
				$updated_data = $data;
				return 1;
			}
		);

		// Complete step 1 via WhatsApp
		RecoveryResultHandler::advance_step( 15, 1, 'whatsapp', true );

		$this->assertSame( '1', $updated_data['step'] );
		$this->assertSame( 1, $updated_data['wa_sent_count'] );
		$this->assertNotEmpty( $updated_data['next_action_gmt'] );
		$this->assertArrayNotHasKey( 'status', $updated_data );
	}

	public function test_advance_step_expires_when_sequence_finishes(): void {
		$GLOBALS['wpdb'] = \Mockery::mock( 'wpdb_mock' );
		$GLOBALS['wpdb']->prefix = 'wp_';
		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );

		// Row at step 2 (next step 3 is off)
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->once()->andReturn(
			array(
				'id'                => 15,
				'status'            => Status::ACTIVE,
				'step'              => '2',
				'wa_sent_count'     => 1,
				'email_sent_count'  => 0,
				'last_activity_gmt' => '2026-11-18 10:00:00',
			)
		);

		$updated_data = array();
		$GLOBALS['wpdb']->shouldReceive( 'update' )->once()->andReturnUsing(
			function( $table, $data ) use ( &$updated_data ) {
				$updated_data = $data;
				return 1;
			}
		);
		$GLOBALS['wpdb']->shouldReceive( 'insert' )->andReturn( 1 ); // History::log
		$GLOBALS['wpdb']->shouldReceive( 'query' )->andReturn( 1 ); // History touch

		// Complete step 2 via email
		RecoveryResultHandler::advance_step( 15, 2, 'email', true );

		$this->assertSame( '2', $updated_data['step'] );
		$this->assertSame( 1, $updated_data['email_sent_count'] );
		$this->assertSame( Status::EXPIRED, $updated_data['status'] );
		$this->assertNull( $updated_data['next_action_gmt'] );
	}

	public function test_on_result_ignores_other_kinds(): void {
		$GLOBALS['wpdb'] = \Mockery::mock( 'wpdb_mock' );
		$GLOBALS['wpdb']->shouldNotReceive( 'get_row' );
		$GLOBALS['wpdb']->shouldNotReceive( 'update' );

		RecoveryResultHandler::on_result( 'other_kind', 15, '1', Result::success( 200 ) );
		$this->assertTrue( true );
	}

	public function test_summary_attribution_separates_link_from_message(): void {
		$GLOBALS['wpdb'] = \Mockery::mock( 'wpdb_mock' );
		$GLOBALS['wpdb']->prefix = 'wp_';
		$GLOBALS['wpdb']->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$GLOBALS['wpdb']->shouldReceive( 'get_row' )->once()->andReturn(
			array(
				'abandoned'         => 12,
				'recovered'         => 8,
				'recovered_link'    => 5,
				'recovered_message' => 3,
				'recovered_value'   => 450000.50,
			)
		);

		$summary = Repository::summary( '2026-11-18 10:00:00' );

		$this->assertSame( 12, $summary['abandoned'] );
		$this->assertSame( 8, $summary['recovered'] );
		$this->assertSame( 5, $summary['recovered_link'] );
		$this->assertSame( 3, $summary['recovered_message'] );
		$this->assertSame( 450000.50, $summary['recovered_value'] );
	}
}
