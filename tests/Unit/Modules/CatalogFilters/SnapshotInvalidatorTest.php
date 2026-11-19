<?php
namespace Motored\Core\Tests\Unit\Modules\CatalogFilters;

use Brain\Monkey\Functions;
use Motored\Core\Modules\CatalogFilters\FacetEngine;
use Motored\Core\Modules\CatalogFilters\SnapshotInvalidator;
use Motored\Core\Support\Cache;
use Motored\Core\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/stubs/object-cache.php';

final class SnapshotInvalidatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		SnapshotInvalidator::reset();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
	}

	protected function tearDown(): void {
		SnapshotInvalidator::reset();
		parent::tearDown();
	}

	public function test_erp_unrelated_or_unchanged_terms_do_not_invalidate_while_stock_status_and_cooldown_work(): void {
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		$inv = new SnapshotInvalidator();

		// Unrelated taxonomy (product_tag) and unchanged pa_ancho tt_ids do NOT mark dirty.
		$inv->on_set_object_terms( 10, array(), array( 1 ), 'product_tag', false, array() );
		$inv->on_set_object_terms( 10, array(), array( 5, 6 ), 'pa_ancho', false, array( 6, 5 ) );
		$this->assertFalse( SnapshotInvalidator::should_rebuild_now() );

		// Stock status transition DOES mark dirty and allows rebuild when no prior build exists.
		$inv->on_stock_status_change( 10, 'outofstock' );
		$this->assertTrue( SnapshotInvalidator::should_rebuild_now() );

		// Acquire lock + finish rebuild -> clears dirty and starts 60-second cooldown.
		$this->assertTrue( SnapshotInvalidator::acquire_lock() );
		$this->assertFalse( SnapshotInvalidator::acquire_lock() ); // Lock prevents concurrent rebuilds.
		SnapshotInvalidator::finish_rebuild( true );
		$this->assertFalse( SnapshotInvalidator::should_rebuild_now() );

		// Immediately marking dirty again inside 60s cooldown must NOT trigger rebuild yet.
		SnapshotInvalidator::reset();
		$inv->on_stock_status_change( 11, 'instock' );
		$this->assertFalse( SnapshotInvalidator::should_rebuild_now() );
	}

	public function test_facet_engine_serves_previous_snapshot_during_cooldown_and_lock_without_blocking(): void {
		$prev = array(
			'products' => array(
				array( 'id' => 99, 'in_stock' => true, 'is_kit' => false, 'cats' => array(), 'brands' => array( 'pirelli' ), 'attrs' => array() ),
			),
			'labels'   => array( 'product_brand' => array( 'pirelli' => 'Pirelli' ) ),
		);
		Cache::set( FacetEngine::SNAPSHOT_KEY, $prev, FacetEngine::SNAPSHOT_TTL, SnapshotInvalidator::BUCKET );
		Cache::set( SnapshotInvalidator::LAST_BUILD_KEY, time(), 86400, SnapshotInvalidator::BUCKET );
		Cache::set( SnapshotInvalidator::DIRTY_KEY, time(), 86400, SnapshotInvalidator::BUCKET );

		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->never();
		$GLOBALS['wpdb'] = $wpdb;

		// 1. Inside 60s cooldown: returns previous cached snapshot with 0 DB queries.
		FacetEngine::reset();
		$this->assertSame( $prev, FacetEngine::snapshot() );

		// 2. Cooldown elapsed, but another process holds the rebuild lock: still returns previous snapshot with 0 DB queries.
		Cache::set( SnapshotInvalidator::LAST_BUILD_KEY, time() - SnapshotInvalidator::REBUILD_COOLDOWN - 5, 86400, SnapshotInvalidator::BUCKET );
		$this->assertTrue( SnapshotInvalidator::acquire_lock() );
		FacetEngine::reset();
		$this->assertSame( $prev, FacetEngine::snapshot() );
		unset( $GLOBALS['wpdb'] );
	}
}
