<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Motored\Core\Modules\Recovery\Repository;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase {

	private const CUTOFF = '2026-11-18 10:00:00';

	public function test_no_filters_means_no_where(): void {
		$this->assertSame( array( '', array() ), Repository::where( array(), self::CUTOFF ) );
	}

	public function test_abandoned_is_derived_from_active_and_cutoff(): void {
		list( $sql, $args ) = Repository::where( array( 'status' => 'abandoned' ), self::CUTOFF );
		$this->assertSame( ' WHERE status = %s AND type = %s AND last_activity_gmt < %s', $sql );
		$this->assertSame( array( 'active', 'cart', self::CUTOFF ), $args, 'Only carts are ever abandoned.' );

		list( $sql, $args ) = Repository::where( array( 'status' => 'active' ), self::CUTOFF );
		$this->assertSame( ' WHERE status = %s AND (type <> %s OR last_activity_gmt >= %s)', $sql );
		$this->assertSame( array( 'active', 'cart', self::CUTOFF ), $args, 'Unpaid orders stay active however old.' );
	}

	public function test_stored_status_type_and_dates(): void {
		list( $sql, $args ) = Repository::where(
			array(
				'status'    => 'recovered',
				'type'      => 'order',
				'date_from' => '2026-11-01',
				'date_to'   => '2026-11-30',
			),
			self::CUTOFF
		);
		$this->assertSame( ' WHERE status = %s AND type = %s AND created_gmt >= %s AND created_gmt <= %s', $sql );
		$this->assertSame( array( 'recovered', 'order', '2026-11-01 00:00:00', '2026-11-30 23:59:59' ), $args );
	}

	public function test_invalid_filter_values_are_ignored(): void {
		$this->assertSame(
			array( '', array() ),
			Repository::where( array( 'status' => 'x; DROP TABLE', 'type' => 'other', 'date_from' => '2026/11/01', 'date_to' => 'tomorrow' ), self::CUTOFF )
		);
	}

	public function test_search_is_escaped_for_like_and_never_inlined(): void {
		list( $sql, $args ) = Repository::where( array( 'search' => '50%_off' ), self::CUTOFF );
		$this->assertSame( ' WHERE (email LIKE %s OR phone LIKE %s)', $sql );
		$this->assertSame( array( '%50\\%\\_off%', '%50\\%\\_off%' ), $args );
		$this->assertStringNotContainsString( '50', $sql );
	}

	public function test_columns_never_select_everything_or_the_token(): void {
		$this->assertStringNotContainsString( '*', Repository::COLUMNS );
		$this->assertStringNotContainsString( 'token_hash', Repository::COLUMNS );
	}
}
