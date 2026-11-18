<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Motored\Core\Modules\Recovery\RecoveryMigration;
use Motored\Core\Plugin;
use PHPUnit\Framework\TestCase;

final class RecoveryMigrationTest extends TestCase {

	public function test_schema_has_required_columns_and_indexes(): void {
		list( $main, $events ) = RecoveryMigration::statements( 'wp_motored_recoveries', 'wp_motored_recovery_events', 'DEFAULT CHARSET=utf8mb4' );

		foreach ( array( 'type', 'session_key', 'user_id', 'order_id', 'email', 'phone', 'first_name', 'cart_hash', 'items', 'currency', 'total', 'status', 'step', 'last_activity_gmt', 'next_action_gmt', 'token_hash', 'token_expires_gmt', 'clicked_gmt', 'recovered_order_id', 'wa_sent_count', 'email_sent_count', 'last_event_gmt', 'created_gmt', 'updated_gmt' ) as $column ) {
			$this->assertMatchesRegularExpression( '/^  ' . $column . ' /m', $main, $column );
		}
		$this->assertStringContainsString( 'CREATE TABLE wp_motored_recoveries (', $main );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $main );
		$this->assertStringContainsString( 'UNIQUE KEY session_key (session_key)', $main );
		$this->assertStringContainsString( 'KEY status_next_action (status,next_action_gmt)', $main );
		$this->assertStringContainsString( 'KEY user_id (user_id)', $main );
		$this->assertStringContainsString( 'KEY updated_gmt (updated_gmt)', $main );
		$this->assertStringContainsString( 'KEY order_id (order_id)', $main );
		$this->assertMatchesRegularExpression( '/session_key varchar\(64\) DEFAULT NULL/', $main, 'NULL for orders.' );

		foreach ( array( 'recovery_id', 'event', 'channel', 'source', 'user_id', 'result', 'detail', 'created_gmt' ) as $column ) {
			$this->assertMatchesRegularExpression( '/^  ' . $column . ' /m', $events, $column );
		}
		$this->assertStringContainsString( 'KEY recovery_id (recovery_id)', $events );
	}

	public function test_migration_is_registered_with_a_valid_id(): void {
		$migrations = Plugin::migrations();
		$this->assertArrayHasKey( '2026111801', $migrations );
		$this->assertIsCallable( $migrations['2026111801'] );
	}
}
