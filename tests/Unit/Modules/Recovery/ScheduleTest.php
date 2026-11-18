<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Motored\Core\Modules\Recovery\Schedule;
use PHPUnit\Framework\TestCase;

final class ScheduleTest extends TestCase {

	public function test_inside_window_daytime(): void {
		// 14:30 is between 09:00 and 21:00
		$ts = strtotime( '2026-11-18 14:30:00' );
		$this->assertTrue( Schedule::is_inside_window( $ts, '09:00', '21:00' ) );
	}

	public function test_outside_window_early_morning(): void {
		// 06:15 is before 09:00
		$ts = strtotime( '2026-11-18 06:15:00' );
		$this->assertFalse( Schedule::is_inside_window( $ts, '09:00', '21:00' ) );
	}

	public function test_outside_window_late_night(): void {
		// 22:30 is after 21:00
		$ts = strtotime( '2026-11-18 22:30:00' );
		$this->assertFalse( Schedule::is_inside_window( $ts, '09:00', '21:00' ) );
	}

	public function test_next_window_start_when_before_window(): void {
		// 06:00 -> same day 09:00
		$ts         = strtotime( '2026-11-18 06:00:00' );
		$next_start = Schedule::next_window_start( $ts, '09:00', '21:00' );
		$this->assertSame( '2026-11-18 09:00', date( 'Y-m-d H:i', $next_start ) );
	}

	public function test_next_window_start_when_after_window(): void {
		// 22:00 -> next day 09:00
		$ts         = strtotime( '2026-11-18 22:00:00' );
		$next_start = Schedule::next_window_start( $ts, '09:00', '21:00' );
		$this->assertSame( '2026-11-19 09:00', date( 'Y-m-d H:i', $next_start ) );
	}

}
