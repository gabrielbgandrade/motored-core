<?php
namespace Motored\Core\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Motored\Core\Support\Migrations;
use Motored\Core\Tests\Unit\TestCase;

final class MigrationsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		$this->fake_logger();
		$wpdb = \Mockery::mock( 'wpdb_fake' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function () {
				if ( isset( $this->options[ Migrations::LOCK ] ) ) {
					return 0;
				}
				$this->options[ Migrations::LOCK ] = 'locked';
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_runs_in_id_order_and_stores_version_without_autoload(): void {
		$order      = array();
		$migrations = new Migrations(
			array(
				'2026010102' => function () use ( &$order ) {
					$order[] = 'b';
				},
				'2026010101' => function () use ( &$order ) {
					$order[] = 'a';
					return true;
				},
			)
		);

		$this->assertSame( 2, $migrations->run() );
		$this->assertSame( array( 'a', 'b' ), $order );
		$this->assertSame( '2026010102', $this->options[ Migrations::OPTION ] );
		$this->assertFalse( $this->autoload[ Migrations::OPTION ] );
	}

	public function test_is_idempotent(): void {
		$count      = 0;
		$migrations = new Migrations(
			array(
				'2026010101' => function () use ( &$count ) {
					++$count;
				},
			)
		);
		$this->assertSame( 1, $migrations->run() );
		$this->assertSame( 0, $migrations->run() );
		$this->assertSame( 1, $count );
	}

	public function test_skips_already_applied(): void {
		$this->options[ Migrations::OPTION ] = '2026010101';
		$ran                                 = array();
		$migrations                          = new Migrations(
			array(
				'2026010101' => function () use ( &$ran ) {
					$ran[] = 1;
				},
				'2026010102' => function () use ( &$ran ) {
					$ran[] = 2;
				},
			)
		);
		$this->assertSame( array( '2026010102' ), $migrations->pending( '2026010101' ) );
		$migrations->run();
		$this->assertSame( array( 2 ), $ran );
	}

	public function test_stops_at_first_failure_and_keeps_version(): void {
		$ran        = array();
		$migrations = new Migrations(
			array(
				'2026010101' => function () use ( &$ran ) {
					$ran[] = 1;
					return false;
				},
				'2026010102' => function () use ( &$ran ) {
					$ran[] = 2;
				},
			)
		);
		$this->assertSame( 0, $migrations->run() );
		$this->assertSame( array( 1 ), $ran );
		$this->assertArrayNotHasKey( Migrations::OPTION, $this->options );
	}

	public function test_exception_is_contained(): void {
		$migrations = new Migrations(
			array(
				'2026010101' => function () {
					throw new \RuntimeException( 'x' );
				},
			)
		);
		$this->assertSame( 0, $migrations->run() );
		$this->assertArrayNotHasKey( Migrations::OPTION, $this->options );
	}

	public function test_lock_is_released_after_run(): void {
		$migrations = new Migrations( array( '2026010101' => fn() => true ) );
		$this->assertSame( 1, $migrations->run() );
		$this->assertArrayNotHasKey( Migrations::LOCK, $this->options );
	}

	public function test_existing_lock_prevents_a_second_writer(): void {
		$this->options[ Migrations::LOCK ] = 'locked';
		$migrations = new Migrations( array( '2026010101' => fn() => $this->fail( 'A second writer acquired the lock.' ) ) );
		$this->assertSame( 0, $migrations->run() );
		$this->assertSame( 'locked', $this->options[ Migrations::LOCK ] );
	}

	public function test_failed_version_write_stops_remaining_migrations(): void {
		Functions\when( 'update_option' )->justReturn( false );
		$migrations = new Migrations(
			array(
				'2026010101' => fn() => true,
				'2026010102' => fn() => $this->fail( 'The next migration ran after a failed write.' ),
			)
		);
		$this->assertSame( 0, $migrations->run() );
	}

	public function test_empty_registry_performs_no_io(): void {
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'update_option' )->never();
		$this->assertSame( 0, ( new Migrations() )->run() );
	}

	public function test_default_registry_only_has_valid_ids(): void {
		$ids = array_keys( \Motored\Core\Plugin::migrations() );
		$this->assertNotEmpty( $ids );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^\d{10}$/', (string) $id );
		}
		$sorted = $ids;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $ids, 'Ids must be listed in ascending order.' );
	}

	public function test_rejects_malformed_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Migrations( array( 'abc' => fn() => true ) );
	}
}
