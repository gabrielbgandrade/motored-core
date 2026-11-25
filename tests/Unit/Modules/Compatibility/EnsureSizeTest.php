<?php
/**
 * Regression test: CatalogRepository::ensure_size() must return the new size row ID, not the ID of the attribute
 * terms it creates afterwards.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\CatalogRepository;
use Motored\Core\Tests\Unit\TestCase;

/**
 * New sizes are created while saving a product.
 */
final class EnsureSizeTest extends TestCase {

	public function test_new_size_keeps_its_own_insert_id_after_term_creation(): void {
		$wpdb            = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->once()->andReturnUsing(
			static function () use ( $wpdb ) {
				$wpdb->insert_id = 36;
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'wc_attribute_taxonomy_name' )->alias( static fn( $s ) => 'pa_' . $s );
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( false );
		// Creating the size terms runs other inserts, which move $wpdb->insert_id.
		Functions\when( 'wp_insert_term' )->alias(
			static function () use ( $wpdb ) {
				$wpdb->insert_id = 103;
				return array( 'term_id' => 103 );
			}
		);

		$row = CatalogRepository::ensure_size( '100/80-17' );
		$this->assertNotNull( $row );
		$this->assertSame( 36, $row['id'] );
		$this->assertSame( '100/80-17', $row['canonical'] );
	}
}
