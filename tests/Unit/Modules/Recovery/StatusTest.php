<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Motored\Core\Modules\Recovery\Status;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase {

	private const NOW = 1700000000; // 2023-11-14 22:13:20 UTC.

	public function test_active_older_than_limit_is_abandoned(): void {
		$last = gmdate( 'Y-m-d H:i:s', self::NOW - 61 * 60 );
		$this->assertSame( Status::ABANDONED, Status::derive( 'active', $last, self::NOW, 60 ) );
	}

	public function test_active_within_limit_stays_active(): void {
		$last = gmdate( 'Y-m-d H:i:s', self::NOW - 59 * 60 );
		$this->assertSame( Status::ACTIVE, Status::derive( 'active', $last, self::NOW, 60 ) );
		$this->assertSame( Status::ACTIVE, Status::derive( 'active', gmdate( 'Y-m-d H:i:s', self::NOW - 3600 ), self::NOW, 60 ), 'Exactly at the limit is not abandoned yet.' );
	}

	public function test_other_statuses_are_never_derived(): void {
		$old = gmdate( 'Y-m-d H:i:s', self::NOW - 999999 );
		foreach ( array( 'converted', 'recovered', 'expired', 'opted_out' ) as $status ) {
			$this->assertSame( $status, Status::derive( $status, $old, self::NOW, 60 ) );
		}
	}

	public function test_invalid_date_is_not_abandoned(): void {
		$this->assertSame( Status::ACTIVE, Status::derive( 'active', 'garbage', self::NOW, 60 ) );
	}

	public function test_cutoff_matches_the_derivation(): void {
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 7200 ), Status::abandon_cutoff( self::NOW, 120 ) );
	}
}
