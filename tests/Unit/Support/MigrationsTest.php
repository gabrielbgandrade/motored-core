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
		$migrations->run();
		$this->assertTrue( \Motored\Core\Support\Cache::add( 'migrations_lock', 1, 60, 'migrations' ) );
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
