<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Recovery\History;
use Motored\Core\Tests\Unit\TestCase;

final class HistoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $text ) => strip_tags( (string) $text ) );
	}

	public function test_clean_detail_strips_pii_and_cuts(): void {
		$clean = History::clean_detail( 'Llamó juan@gmail.com al +54 9 11 5555-1234 por <b>pedido</b> 123' );
		$this->assertStringNotContainsString( 'juan@', $clean );
		$this->assertStringNotContainsString( '5555', $clean );
		$this->assertStringContainsString( 'pedido 123', $clean );
		$this->assertSame( History::DETAIL_MAX, mb_strlen( History::clean_detail( str_repeat( 'x', 500 ) ) ) );
	}

	public function test_format_builds_label_meta_detail_and_date(): void {
		$line = History::format(
			array(
				'event'       => 'sent',
				'channel'     => 'whatsapp',
				'source'      => 'manual',
				'result'      => 'failed',
				'detail'      => 'timeout',
				'created_gmt' => '2026-11-18 10:00:00',
			)
		);
		$this->assertSame( 'Message sent', $line['label'] );
		$this->assertSame( 'manual · WhatsApp · failed', $line['meta'] );
		$this->assertSame( 'timeout', $line['detail'] );
		$this->assertSame( '2026-11-18 10:00:00', $line['date'] );
	}

	public function test_format_system_event_has_no_meta_and_unknown_event_falls_back(): void {
		$this->assertSame( '', History::format( array( 'event' => 'captured', 'source' => 'system', 'channel' => 'none', 'result' => 'ok' ) )['meta'] );
		$this->assertSame( 'weird', History::format( array( 'event' => 'weird' ) )['label'] );
	}

	public function test_every_event_has_a_label(): void {
		foreach ( History::EVENTS as $event ) {
			$this->assertNotSame( $event, History::event_label( $event ), $event );
		}
	}

	public function test_log_rejects_invalid_values_without_touching_the_database(): void {
		$GLOBALS['wpdb'] = \Mockery::mock( 'wpdb_fake' );
		$GLOBALS['wpdb']->shouldNotReceive( 'insert' );
		$this->assertFalse( History::log( 0, 'captured' ) );
		$this->assertFalse( History::log( 5, 'bogus' ) );
		$this->assertFalse( History::log( 5, 'note', 'sms' ) );
		$this->assertFalse( History::log( 5, 'note', 'none', 'robot' ) );
		$this->assertFalse( History::log( 5, 'note', 'none', 'system', 0, 'maybe' ) );
	}

	public function test_log_inserts_with_cleaned_detail_and_touches_last_event(): void {
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'insert' )->once()->withArgs(
			function ( $table, $data ) {
				return 'wp_motored_recovery_events' === $table
					&& 7 === $data['recovery_id'] && 'note' === $data['event'] && '[email]' === $data['detail'];
			}
		)->andReturn( 1 );
		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'UPDATE ...' );
		$wpdb->shouldReceive( 'query' )->once()->with( 'UPDATE ...' )->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;

		$this->assertTrue( History::log( 7, 'note', 'none', 'manual', 3, 'ok', 'a@b.com' ) );
	}
}
