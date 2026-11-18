<?php
namespace Motored\Core\Tests\Unit\Modules\ReviewRequests;

use Brain\Monkey\Functions;
use Motored\Core\Modules\ReviewRequests\ReviewRules;
use Motored\Core\Tests\Unit\TestCase;

final class ReviewRulesTest extends TestCase {

	private const NOW = 1700000000; // 19:13 in Buenos Aires.

	private function urls(): \Closure {
		return fn( int $id ): string => 99 === $id ? '' : 'https://shop.test/p' . $id . '/';
	}

	public function test_pick_products_dedupes_excludes_reviewed_and_limits(): void {
		$lines = array(
			array( 'id' => 1, 'name' => 'Cubierta A' ),
			array( 'id' => 1, 'name' => 'Cubierta A (otra variación)' ),
			array( 'id' => 2, 'name' => 'Cubierta B' ),
			array( 'id' => 3, 'name' => 'Cubierta C' ),
			array( 'id' => 4, 'name' => 'Cubierta D' ),
		);
		$picked = ReviewRules::pick_products( $lines, array( 2 ), 2, $this->urls() );
		$this->assertSame(
			array(
				array( 'name' => 'Cubierta A', 'url' => 'https://shop.test/p1/#reviews' ),
				array( 'name' => 'Cubierta C', 'url' => 'https://shop.test/p3/#reviews' ),
			),
			$picked
		);
	}

	public function test_pick_products_skips_unavailable_and_all_reviewed(): void {
		$lines = array( array( 'id' => 99, 'name' => 'Borrado' ), array( 'id' => 5, 'name' => 'E' ) );
		$this->assertSame( array(), ReviewRules::pick_products( $lines, array( 5 ), 3, $this->urls() ) );
	}

	public function test_anchor_replaces_an_existing_fragment(): void {
		$picked = ReviewRules::pick_products( array( array( 'id' => 1, 'name' => 'A' ) ), array(), 3, fn() => 'https://shop.test/p/#x' );
		$this->assertSame( 'https://shop.test/p/#reviews', $picked[0]['url'] );
	}

	public function test_review_links_text(): void {
		$text = ReviewRules::review_links( array( array( 'name' => 'A', 'url' => 'U1' ), array( 'name' => 'B', 'url' => 'U2' ) ) );
		$this->assertSame( "- A: U1\n- B: U2", $text );
	}

	public function test_drop_lines_removes_lines_with_the_placeholder(): void {
		$tpl = "Hola\nCalificá:\n{store_review_url}\n\nChau {x}";
		$this->assertSame( "Hola\nCalificá:\n\nChau {x}", ReviewRules::drop_lines( $tpl, '{store_review_url}' ) );
	}

	public function test_status_rules(): void {
		$this->assertTrue( ReviewRules::status_allows( 'completed', 'completed', false ) );
		$this->assertFalse( ReviewRules::status_allows( 'processing', 'completed', false ) );
		$this->assertFalse( ReviewRules::status_allows( 'refunded', 'completed', false ) );
		$this->assertTrue( ReviewRules::status_allows( 'processing', 'completed', true ), 'manual ignores the trigger' );
		foreach ( array( 'cancelled', 'refunded', 'trash', 'failed', 'pending' ) as $status ) {
			$this->assertFalse( ReviewRules::status_allows( $status, 'completed', true ), $status );
		}
	}

	public function test_delay_is_days_when_inside_the_window(): void {
		$this->fake_storage();
		Functions\when( 'wp_timezone' )->alias( fn() => new \DateTimeZone( 'America/Argentina/Buenos_Aires' ) );
		$this->assertSame( 7 * 86400, ReviewRules::delay_seconds( self::NOW, 7, '09:00', '21:00' ) );
		$this->assertSame( 86400, ReviewRules::delay_seconds( self::NOW, 0, '09:00', '21:00' ), 'at least one day' );
	}

	public function test_delay_moves_to_the_next_window_start(): void {
		$this->fake_storage();
		Functions\when( 'wp_timezone' )->alias( fn() => new \DateTimeZone( 'America/Argentina/Buenos_Aires' ) );
		// 19:13 + 3 h window end at 21:00: use a narrow window so the target (19:13 + 7 d) is outside.
		$delay = ReviewRules::delay_seconds( self::NOW, 7, '09:00', '12:00' );
		$at    = self::NOW + $delay;
		$this->assertGreaterThan( 7 * 86400, $delay );
		$this->assertSame( '09:00', ( new \DateTimeImmutable( '@' . $at ) )->setTimezone( new \DateTimeZone( 'America/Argentina/Buenos_Aires' ) )->format( 'H:i' ) );
	}
}
