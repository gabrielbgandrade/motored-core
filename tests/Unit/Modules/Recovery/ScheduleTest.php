<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Recovery\Schedule;
use Motored\Core\Tests\Unit\TestCase;

/**
 * Independent of the machine timezone: the store timezone (America/Argentina/Buenos_Aires, UTC-3, no DST)
 * is mocked through wp_timezone() and expected instants are computed from fixed UTC timestamps.
 */
final class ScheduleTest extends TestCase {

	private \DateTimeZone $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new \DateTimeZone( 'America/Argentina/Buenos_Aires' );
		Functions\when( 'wp_timezone' )->alias( fn() => $this->store );
	}

	/**
	 * Unix time of a store-local wall clock time ("Y-m-d H:i"), computed by hand from UTC-3.
	 */
	private function utc( string $y_m_d, int $hour, int $min = 0 ): int {
		return (int) gmmktime( $hour + 3, $min, 0, (int) substr( $y_m_d, 5, 2 ), (int) substr( $y_m_d, 8, 2 ), (int) substr( $y_m_d, 0, 4 ) );
	}

	private function local( int $ts, ?string $tz = null ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( $tz ?? 'America/Argentina/Buenos_Aires' ) )->format( 'Y-m-d H:i' );
	}

	public function test_inside_window_daytime(): void {
		$this->assertTrue( Schedule::is_inside_window( $this->utc( '2026-11-18', 14, 30 ) ) );
		$this->assertTrue( Schedule::is_inside_window( $this->utc( '2026-11-18', 9, 0 ) ), 'start is inclusive' );
	}

	public function test_outside_window_early_morning(): void {
		$this->assertFalse( Schedule::is_inside_window( $this->utc( '2026-11-18', 6, 15 ) ) );
	}

	public function test_outside_window_late_night(): void {
		$this->assertFalse( Schedule::is_inside_window( $this->utc( '2026-11-18', 22, 30 ) ) );
		$this->assertFalse( Schedule::is_inside_window( $this->utc( '2026-11-18', 21, 0 ) ), 'end is exclusive' );
	}

	public function test_window_uses_the_store_timezone_not_utc(): void {
		// 23:00 UTC is 20:00 in Buenos Aires (inside); 12:00 UTC is 09:00 there (inside); 03:00 UTC is 00:00 (outside).
		$this->assertTrue( Schedule::is_inside_window( gmmktime( 23, 0, 0, 11, 18, 2026 ) ) );
		$this->assertTrue( Schedule::is_inside_window( gmmktime( 12, 0, 0, 11, 18, 2026 ) ) );
		$this->assertFalse( Schedule::is_inside_window( gmmktime( 3, 0, 0, 11, 18, 2026 ) ) );
	}

	public function test_next_window_start_when_before_window(): void {
		$next = Schedule::next_window_start( $this->utc( '2026-11-18', 6, 0 ) );
		$this->assertSame( gmmktime( 12, 0, 0, 11, 18, 2026 ), $next, '09:00 BA = 12:00 UTC the same day' );
		$this->assertSame( '2026-11-18 09:00', $this->local( $next ) );
	}

	public function test_next_window_start_when_after_window(): void {
		$next = Schedule::next_window_start( $this->utc( '2026-11-18', 22, 0 ) );
		$this->assertSame( gmmktime( 12, 0, 0, 11, 19, 2026 ), $next );
		$this->assertSame( '2026-11-19 09:00', $this->local( $next ) );
	}

	public function test_next_window_start_at_exactly_the_start_moves_to_tomorrow(): void {
		$next = Schedule::next_window_start( $this->utc( '2026-11-18', 9, 0 ) );
		$this->assertSame( '2026-11-19 09:00', $this->local( $next ) );
	}

	public function test_next_window_start_keeps_the_wall_clock_across_daylight_saving(): void {
		// New York springs forward on 2026-03-08: 22:00 EST on the 7th -> 09:00 EDT on the 8th (13:00 UTC).
		$ny   = new \DateTimeZone( 'America/New_York' );
		$from = gmmktime( 3, 0, 0, 3, 8, 2026 ); // 22:00 EST of Mar 7.
		$next = Schedule::next_window_start( $from, '09:00', '21:00', $ny );
		$this->assertSame( '2026-03-08 09:00', $this->local( $next, 'America/New_York' ) );
		$this->assertSame( gmmktime( 13, 0, 0, 3, 8, 2026 ), $next );
		$this->assertFalse( Schedule::is_inside_window( $from, '09:00', '21:00', $ny ) );
	}

	public function test_cross_midnight_window(): void {
		$this->assertTrue( Schedule::is_inside_window( $this->utc( '2026-11-18', 23, 0 ), '22:00', '06:00' ) );
		$this->assertTrue( Schedule::is_inside_window( $this->utc( '2026-11-18', 5, 0 ), '22:00', '06:00' ) );
		$this->assertFalse( Schedule::is_inside_window( $this->utc( '2026-11-18', 12, 0 ), '22:00', '06:00' ) );
	}
}
