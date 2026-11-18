<?php
namespace Motored\Core\Tests\Unit\Modules\ReviewRequests;

use Brain\Monkey\Functions;
use Motored\Core\Modules\ReviewRequests\RequestHandler;
use Motored\Core\Modules\Recovery\MessageLimiter;
use Motored\Core\Tests\Unit\TestCase;

final class RequestHandlerTest extends TestCase {

	private const INSIDE  = 1700000000; // 19:13 Buenos Aires.
	private const OUTSIDE = 1700028000; // 03:00 Buenos Aires.

	/** @var array<string,mixed> */
	private array $facts;
	/** @var list<array{int,int}> */
	private array $requeued = array();
	/** @var list<string> */
	private array $suppressed_list = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Functions\when( 'wp_timezone' )->alias( fn() => new \DateTimeZone( 'America/Argentina/Buenos_Aires' ) );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Motored' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.test/' );
		Functions\when( 'add_query_arg' )->alias( fn( $k, $v, $u ) => $u . '?' . $k . '=' . $v );
		$this->options['motored_review_requests'] = array( 'enabled' => 'yes', 'max_products' => '3' );
		$this->facts                              = array(
			'order_id'     => 7,
			'order_number' => '1007',
			'status'       => 'completed',
			'refunded'     => false,
			'phone'        => '011 15 5555-1234',
			'calling_code' => '',
			'first_name'   => 'Ana',
			'email'        => 'ana@example.com',
			'user_id'      => 0,
			'lines'        => array(
				array( 'id' => 1, 'name' => 'Cubierta A' ),
				array( 'id' => 2, 'name' => 'Cubierta B' ),
				array( 'id' => 3, 'name' => 'Cubierta C' ),
			),
		);
		$this->requeued        = array();
		$this->suppressed_list = array();
	}

	/**
	 * @param list<int> $reviewed Reviewed product ids.
	 */
	private function handler( array $reviewed = array(), int $now = self::INSIDE ): RequestHandler {
		return new RequestHandler(
			fn(): array => $this->facts,
			fn(): array => $reviewed,
			fn( string $phone ): bool => in_array( $phone, $this->suppressed_list, true ),
			function ( int $id, int $delay ): void {
				$this->requeued[] = array( $id, $delay );
			},
			fn(): int => $now,
			fn( int $id ): string => 'https://shop.test/p' . $id . '/'
		);
	}

	public function test_builds_a_message_with_unreviewed_products_links_and_optout(): void {
		$message = $this->handler( array( 2 ) )->handle( 7, null );

		$this->assertNotNull( $message );
		$this->assertSame( '+5491155551234', $message->phone );
		$this->assertStringContainsString( 'Ana', $message->text );
		$this->assertStringContainsString( '- Cubierta A: https://shop.test/p1/#reviews', $message->text );
		$this->assertStringContainsString( '- Cubierta C: https://shop.test/p3/#reviews', $message->text );
		$this->assertStringNotContainsString( 'Cubierta B', $message->text, 'Already reviewed.' );
		$this->assertStringContainsString( 'motored_review_optout=7.', $message->text );
		$this->assertStringNotContainsString( 'calificar la tienda', $message->text, 'No store URL configured: its line is dropped.' );
		$this->assertStringNotContainsString( '{', $message->text, 'Variations and placeholders are resolved.' );
	}

	public function test_store_review_url_line_is_kept_when_configured(): void {
		$this->options['motored_review_requests']['store_review_url'] = 'https://g.page/r/abc';
		$message = $this->handler()->handle( 7, null );
		$this->assertStringContainsString( 'https://g.page/r/abc', $message->text );
	}

	public function test_skips_when_all_products_were_reviewed(): void {
		$this->assertNull( $this->handler( array( 1, 2, 3 ) )->handle( 7, null ) );
	}

	public function test_skips_when_status_changed_or_refunded(): void {
		$this->facts['status'] = 'processing';
		$this->assertNull( $this->handler()->handle( 7, null ) );
		$this->facts['status'] = 'refunded';
		$this->assertNull( $this->handler()->handle( 7, null ) );
		$this->facts['status']   = 'completed';
		$this->facts['refunded'] = true;
		$this->assertNull( $this->handler()->handle( 7, null ) );
	}

	public function test_skips_trashed_orders_and_disabled_module(): void {
		$this->facts['status'] = 'trash';
		$this->assertNull( $this->handler()->handle( 7, null ) );
		$this->facts['status']                              = 'completed';
		\Motored\Core\Support\Options::reset();
		$this->options['motored_review_requests']['enabled'] = 'no';
		$this->assertNull( $this->handler()->handle( 7, null ) );
	}

	public function test_skips_missing_or_invalid_phone(): void {
		$this->facts['phone'] = '';
		$this->assertNull( $this->handler()->handle( 7, null ) );
		$this->facts['phone'] = '12';
		$this->assertNull( $this->handler()->handle( 7, null ) );
	}

	public function test_skips_suppressed_numbers_in_either_format(): void {
		$this->suppressed_list = array( '011 15 5555-1234' );
		$this->assertNull( $this->handler()->handle( 7, null ) );
		$this->suppressed_list = array( '+5491155551234' );
		$this->assertNull( $this->handler()->handle( 7, null ) );
	}

	public function test_outside_the_window_it_requeues_until_the_window_opens(): void {
		$this->assertNull( $this->handler( array(), self::OUTSIDE )->handle( 7, null ) );
		$this->assertSame( array( array( 7, 21600 ) ), $this->requeued );
	}

	public function test_per_number_limit_requeues_one_day_later(): void {
		MessageLimiter::hit( '5491155551234' );
		$this->assertNull( $this->handler()->handle( 7, null ) );
		$this->assertSame( array( array( 7, 86400 ) ), $this->requeued );
	}

	public function test_a_sent_message_counts_for_the_per_number_limit(): void {
		$this->assertNotNull( $this->handler()->handle( 7, null ) );
		$this->assertNull( $this->handler()->handle( 7, null ) );
	}

	public function test_manual_ignores_trigger_status_and_window_but_not_refunds_or_suppression(): void {
		$this->facts['status'] = 'processing';
		$this->assertNotNull( $this->handler( array(), self::OUTSIDE )->handle( 7, 'manual' ) );
		$this->assertSame( array(), $this->requeued );

		$this->facts['status'] = 'cancelled';
		$this->assertNull( $this->handler()->handle( 7, 'manual' ) );
		$this->facts['status'] = 'completed';
		$this->suppressed_list = array( '+5491155551234' );
		$this->assertNull( $this->handler()->handle( 7, 'manual' ) );
	}
}
