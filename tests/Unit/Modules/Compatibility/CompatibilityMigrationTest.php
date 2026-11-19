<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\CompatibilityMigration;
use Motored\Core\Tests\Unit\TestCase;

final class CompatibilityMigrationTest extends TestCase {

	public function test_defines_all_seven_tables_without_variation_id_or_nullable_year_to(): void {
		$statements = CompatibilityMigration::statements( 'wp_motored_', 'DEFAULT CHARSET=utf8mb4' );
		$this->assertCount( 7, $statements );
		$joined = implode( "\n", $statements );

		foreach ( array(
			'wp_motored_compat_brands',
			'wp_motored_compat_models',
			'wp_motored_compat_versions',
			'wp_motored_compat_sizes',
			'wp_motored_compat_fitments',
			'wp_motored_compat_equivalences',
			'wp_motored_compat_product_index',
		) as $table ) {
			$this->assertStringContainsString( "CREATE TABLE {$table}", $joined );
		}

		// Simple products only: no variation_id in product_index.
		$this->assertStringNotContainsString( 'variation_id', $statements[6] );
		$this->assertStringContainsString( 'PRIMARY KEY  (product_id)', $statements[6] );
		// year_to uses 0 sentinel instead of NULL so UNIQUE KEY natural_key deduplicates reliably.
		$this->assertStringContainsString( 'year_to smallint(5) unsigned NOT NULL DEFAULT 0', $statements[2] );
	}

	public function test_migration_up_runs_dbdelta_for_every_table(): void {
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET=utf8mb4' );
		$GLOBALS['wpdb'] = $wpdb;

		$calls = 0;
		Functions\when( 'dbDelta' )->alias(
			function () use ( &$calls ) {
				++$calls;
				return array();
			}
		);
		$this->assertTrue( CompatibilityMigration::up() );
		$this->assertSame( 7, $calls );
	}

	public function test_migration_2026112101_backfills_in_stock_from_postmeta(): void {
		$wpdb           = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix   = 'wp_';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET=utf8mb4' );
		$wpdb->shouldReceive( 'query' )->once()->with( \Mockery::pattern( '/UPDATE wp_motored_compat_product_index i.*_stock_status/s' ) )->andReturn( 5 );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'dbDelta' )->justReturn( array() );
		$this->assertTrue( CompatibilityMigration::up_2026112101() );
	}
}
