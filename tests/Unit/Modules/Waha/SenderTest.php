<?php
namespace Motored\Core\Tests\Unit\Modules\Waha;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Integration\Waha\WahaClient;
use Motored\Core\Modules\Waha\CircuitBreaker;
use Motored\Core\Modules\Waha\Message;
use Motored\Core\Modules\Waha\RateLimiter;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Tests\Unit\TestCase;

final class SenderTest extends TestCase {

	private const NOW    = 1700000000; // 20 s into a minute.
	private const JITTER = 5;

	/** @var \Mockery\MockInterface&WahaClient */
	private $client;
	private Sender $sender;
	private int $handler_calls = 0;
	/** @var list<int> */
	private array $pauses = array();
	/** @var list<Result> */
	private array $finished = array();

	protected function setUp(): void {
		parent::setUp();
		Sender::reset();
		$this->fake_storage();
		$this->fake_logger();
		$this->options['motored_waha'] = array(
			'enabled'           => 'yes',
			'test_mode'         => 'no',
			'rate_per_minute'   => '2',
			'rate_per_hour'     => '100',
			'breaker_threshold' => '2',
			'breaker_minutes'   => '10',
		);
		$this->options['motored_waha']['typing'] = 'no';
		Functions\when( 'wp_timezone' )->alias( fn() => new \DateTimeZone( 'America/Argentina/Buenos_Aires' ) );
		Functions\when( 'wp_date' )->alias( fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( $format ) );
		$this->pauses        = array();
		$this->client        = \Mockery::mock( WahaClient::class );
		$this->client->shouldReceive( 'sessionStatus' )->andReturn( Result::success( 200, array( 'status' => 'WORKING' ) ) )->byDefault();
		$this->sender        = $this->make_sender( 0 );
		$this->handler_calls = 0;
		$this->finished      = array();
		Actions\expectDone( Sender::RESULT_HOOK )->zeroOrMoreTimes()->whenHappen(
			function ( $kind, $ref_id, $step, $result ): void {
				$this->finished[] = $result;
			}
		);
		Sender::register_handler(
			'order',
			function ( int $ref_id, ?string $step ): ?Message {
				++$this->handler_calls;
				return 0 === $ref_id ? null : new Message( '011 15 5555-1234', 'Hola' );
			}
		);
	}

	private function make_sender( int $gap ): Sender {
		return new Sender(
			$this->client,
			fn() => self::NOW,
			fn() => self::JITTER,
			fn( int $min, int $max ) => $gap,
			function ( int $ms ): void {
				$this->pauses[] = $ms;
			}
		);
	}

	protected function tearDown(): void {
		Sender::reset();
		parent::tearDown();
	}

	private function expect_exists( string $chat_id = '5491155551234@c.us' ): void {
		$this->client->shouldReceive( 'checkExists' )->with( '5491155551234' )->andReturn(
			Result::success( 200, array( 'exists' => true, 'chat_id' => $chat_id ) )
		);
	}

	private function expect_schedule( int $at, array $args ): void {
		Functions\expect( 'as_schedule_single_action' )->once()->with( $at, 'motored_waha_send', $args, 'motored-core' )->andReturn( 10 );
	}

	public function test_enqueue_schedules_ids_only_with_jitter_and_delay(): void {
		$this->expect_schedule( self::NOW + 30 + self::JITTER, array( 'order', 7, 'reminder_1', 1, 'normal' ) );
		$this->assertTrue( $this->sender->queue( 'order', 7, 'reminder_1', 30 ) );
	}

	public function test_enqueue_without_step_uses_empty_string(): void {
		$this->expect_schedule( self::NOW + self::JITTER, array( 'order', 7, '', 1, 'normal' ) );
		$this->assertTrue( $this->sender->queue( 'order', 7 ) );
	}

	public function test_enqueue_rejects_invalid_kind_and_id(): void {
		Functions\expect( 'as_schedule_single_action' )->never();
		$this->assertFalse( $this->sender->queue( 'Bad Kind!', 7 ) );
		$this->assertFalse( $this->sender->queue( 'order', 0 ) );
	}

	public function test_successful_send_resolves_chat_id_and_fires_result(): void {
		$this->expect_exists();
		$this->client->shouldReceive( 'sendText' )->once()->with( '5491155551234@c.us', 'Hola' )->andReturn( Result::success( 201 ) );
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->sender->handle( 'order', 7, '', 1 );

		$this->assertCount( 1, $this->finished );
		$this->assertTrue( $this->finished[0]->ok );
		$this->assertSame( 0, CircuitBreaker::failures() );
	}

	public function test_handler_returning_null_skips_without_http(): void {
		$this->client->shouldNotReceive( 'checkExists' );
		$this->client->shouldNotReceive( 'sendText' );

		$this->sender->handle( 'order', 0, '', 1 );

		$this->assertSame( 1, $this->handler_calls );
		$this->assertSame( 'skipped', $this->finished[0]->code );
		$this->assertSame( 0, RateLimiter::wait_seconds( self::NOW, 1, 1 ), 'Skipping must not use a rate slot.' );
	}

	public function test_missing_handler_finishes_with_error(): void {
		$this->sender->handle( 'unknown', 7, '', 1 );
		$this->assertSame( 'no_handler', $this->finished[0]->code );
	}

	public function test_handler_exception_is_contained(): void {
		Sender::register_handler( 'boom', static function (): ?Message {
			throw new \RuntimeException( 'with phone 5491155551234' );
		} );
		$this->sender->handle( 'boom', 7, '', 1 );
		$this->assertSame( 'handler_error', $this->finished[0]->code );
		$this->assertStringNotContainsString( '5491155551234', $this->finished[0]->message );
	}

	public function test_rate_limit_reschedules_without_calling_handler(): void {
		RateLimiter::hit( self::NOW );
		RateLimiter::hit( self::NOW );
		$this->client->shouldNotReceive( 'sendText' );
		// 40 s until the next minute + jitter; same attempt number.
		$this->expect_schedule( self::NOW + 40 + self::JITTER, array( 'order', 7, '', 1, 'normal' ) );

		$this->sender->handle( 'order', 7, '', 1 );

		$this->assertSame( 0, $this->handler_calls );
		$this->assertSame( array(), $this->finished );
	}

	public function test_hourly_limit_waits_for_next_hour(): void {
		$this->options['motored_waha']['rate_per_hour'] = '1';
		RateLimiter::hit( self::NOW );
		$this->expect_schedule( self::NOW + ( 3600 - ( self::NOW % 3600 ) ) + self::JITTER, array( 'order', 7, '', 1, 'normal' ) );

		$this->sender->handle( 'order', 7, '', 1 );
		$this->assertSame( 0, $this->handler_calls );
	}

	public function test_retryable_failure_backs_off_and_gives_up_after_three_attempts(): void {
		$this->expect_exists();
		$this->client->shouldReceive( 'sendText' )->times( 3 )->andReturn( Result::failure( 'timeout', 'Request timed out.', true ) );
		Functions\expect( 'as_schedule_single_action' )->twice()->andReturnUsing(
			function ( $at, $hook, $args ) {
				static $calls = 0;
				++$calls;
				if ( 1 === $calls ) {
					$this->assertSame( array( self::NOW + 60 + self::JITTER, array( 'order', 7, '', 2, 'normal' ) ), array( $at, $args ) );
				} else {
					$this->assertSame( array( self::NOW + 300 + self::JITTER, array( 'order', 7, '', 3, 'normal' ) ), array( $at, $args ) );
				}
				return 10;
			}
		);
		$this->options['motored_waha']['breaker_threshold'] = '99';
		$this->options['motored_waha']['rate_per_minute']   = '50';

		$this->sender->handle( 'order', 7, '', 1 );
		$this->sender->handle( 'order', 7, '', 2 );
		$this->assertSame( array(), $this->finished, 'Retries are not final results.' );

		$this->sender->handle( 'order', 7, '', 3 );
		$this->assertCount( 1, $this->finished );
		$this->assertSame( 'timeout', $this->finished[0]->code );
	}

	public function test_non_retryable_failure_is_final(): void {
		$this->expect_exists();
		$this->client->shouldReceive( 'sendText' )->once()->andReturn( Result::failure( 'http_error', 'Unexpected HTTP status.', false, 422 ) );
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->sender->handle( 'order', 7, '', 1 );
		$this->assertSame( 'http_error', $this->finished[0]->code );
	}

	public function test_number_without_whatsapp_is_final_and_does_not_trip_breaker(): void {
		$this->client->shouldReceive( 'checkExists' )->andReturn( Result::success( 200, array( 'exists' => false, 'chat_id' => '' ) ) );
		$this->client->shouldNotReceive( 'sendText' );

		$this->sender->handle( 'order', 7, '', 1 );

		$this->assertSame( 'no_whatsapp', $this->finished[0]->code );
		$this->assertSame( 0, CircuitBreaker::failures() );
	}

	public function test_breaker_opens_after_consecutive_failures_and_holds_jobs(): void {
		$this->expect_exists();
		$this->client->shouldReceive( 'sendText' )->twice()->andReturn( Result::failure( 'auth', 'Authentication rejected.', false, 401 ) );

		$this->sender->handle( 'order', 7, '', 1 );
		$this->assertSame( 0, CircuitBreaker::until() );
		$this->sender->handle( 'order', 8, '', 1 );
		$this->assertSame( self::NOW + 600, CircuitBreaker::until() );

		// While open: handler and HTTP untouched, job moved after the pause.
		$calls_before = $this->handler_calls;
		$this->expect_schedule( self::NOW + 600 + self::JITTER, array( 'order', 9, '', 1, 'normal' ) );
		$this->sender->handle( 'order', 9, '', 1 );
		$this->assertSame( $calls_before, $this->handler_calls );
	}

	public function test_success_resets_failure_streak(): void {
		$this->expect_exists();
		$this->client->shouldReceive( 'sendText' )->andReturn(
			Result::failure( 'auth', 'Authentication rejected.', false, 401 ),
			Result::success( 201 ),
			Result::failure( 'auth', 'Authentication rejected.', false, 401 )
		);
		$this->options['motored_waha']['rate_per_minute'] = '50';

		$this->sender->handle( 'order', 1, '', 1 );
		$this->sender->handle( 'order', 2, '', 1 );
		$this->sender->handle( 'order', 3, '', 1 );

		$this->assertSame( 0, CircuitBreaker::until() );
		$this->assertSame( 1, CircuitBreaker::failures() );
	}

	public function test_enqueue_stores_priority_in_job_args(): void {
		$this->expect_schedule( self::NOW + self::JITTER, array( 'order', 7, '', 1, 'high' ) );
		$this->assertTrue( $this->sender->queue( 'order', 7, null, 0, Sender::HIGH ) );
		$this->expect_schedule( self::NOW + self::JITTER, array( 'order', 8, '', 1, 'normal' ) );
		$this->assertTrue( $this->sender->queue( 'order', 8, null, 0, 'bogus' ) );
	}

	public function test_gap_is_reserved_after_send_and_next_job_is_rescheduled(): void {
		$this->options['motored_waha']['rate_per_minute'] = '50';
		$sender = $this->make_sender( 40 );
		$this->expect_exists();
		$this->client->shouldReceive( 'sendText' )->once()->andReturn( Result::success( 201 ) );

		$sender->handle( 'order', 7, '', 1 );
		$this->assertSame( 40, RateLimiter::spacing_wait( self::NOW ) );

		$this->expect_schedule( self::NOW + 40 + self::JITTER, array( 'order', 8, '', 1, 'normal' ) );
		$sender->handle( 'order', 8, '', 1 );
		$this->assertSame( 1, $this->handler_calls, 'The second job must not reach the handler or WAHA.' );
	}

	public function test_empty_cache_means_no_extra_spacing(): void {
		$this->assertSame( 0, RateLimiter::spacing_wait( self::NOW ) );
		RateLimiter::reserve_slot( self::NOW, 30 );
		$this->assertSame( 0, RateLimiter::spacing_wait( self::NOW + 30 ) );
		$GLOBALS['motored_fake_cache'] = array();
		$this->transients              = array();
		$this->assertSame( 0, RateLimiter::spacing_wait( self::NOW ) );
	}

	public function test_default_gap_is_random_within_configured_range(): void {
		$this->options['motored_waha']['interval_min'] = '20';
		$this->options['motored_waha']['interval_max'] = '60';
		$this->options['motored_waha']['rate_per_minute'] = '50';
		$this->expect_exists();
		$this->client->shouldReceive( 'sendText' )->once()->andReturn( Result::success( 201 ) );
		( new Sender( $this->client, fn() => self::NOW, fn() => self::JITTER ) )->handle( 'order', 7, '', 1 );
		$wait = RateLimiter::spacing_wait( self::NOW );
		$this->assertGreaterThanOrEqual( 20, $wait );
		$this->assertLessThanOrEqual( 60, $wait );
	}

	public function test_daily_cap_reschedules_to_next_local_midnight_with_priority_kept(): void {
		$this->options['motored_waha']['daily_cap'] = '2';
		RateLimiter::daily_hit( self::NOW );
		RateLimiter::daily_hit( self::NOW );
		$this->client->shouldNotReceive( 'sendText' );
		// 19:13:20 in Buenos Aires -> midnight is 17200 s away.
		$this->expect_schedule( self::NOW + 17200 + self::JITTER, array( 'order', 7, '', 1, 'high' ) );

		$this->sender->handle( 'order', 7, '', 1, Sender::HIGH );
		$this->assertSame( 0, $this->handler_calls );
	}

	public function test_low_priority_only_uses_its_share_of_the_cap(): void {
		$this->options['motored_waha']['daily_cap']   = '10';
		$this->options['motored_waha']['low_percent'] = '60';
		for ( $i = 0; $i < 6; $i++ ) {
			RateLimiter::daily_hit( self::NOW );
		}
		$this->assertGreaterThan( 0, RateLimiter::daily_wait( self::NOW, 10, Sender::LOW, 60 ) );
		$this->assertSame( 0, RateLimiter::daily_wait( self::NOW, 10, Sender::HIGH, 60 ) );
		$this->assertSame( 0, RateLimiter::daily_wait( self::NOW, 10, Sender::NORMAL, 60 ) );

		$this->expect_schedule( self::NOW + 17200 + self::JITTER, array( 'order', 7, '', 1, 'low' ) );
		$this->sender->handle( 'order', 7, '', 1, Sender::LOW );
		$this->assertSame( 0, $this->handler_calls );

		// A high message still goes out.
		$this->options['motored_waha']['rate_per_minute'] = '50';
		$this->expect_exists();
		$this->client->shouldReceive( 'sendText' )->once()->andReturn( Result::success( 201 ) );
		$this->sender->handle( 'order', 8, '', 1, Sender::HIGH );
		$this->assertSame( 7, RateLimiter::daily_count( self::NOW ) );
	}

	public function test_daily_counter_resets_on_the_next_local_day(): void {
		RateLimiter::daily_hit( self::NOW );
		$this->assertSame( 1, RateLimiter::daily_count( self::NOW ) );
		$this->assertSame( 0, RateLimiter::daily_count( self::NOW + 17200 ) );
	}

	public function test_typing_wraps_send_and_failure_does_not_block_it(): void {
		$this->options['motored_waha']['typing'] = 'yes';
		$this->expect_exists();
		$this->client->shouldReceive( 'startTyping' )->once()->with( '5491155551234@c.us' )->andReturn( Result::failure( 'timeout', 'x', true ) );
		$this->client->shouldReceive( 'stopTyping' )->once()->with( '5491155551234@c.us' )->andReturn( Result::success( 201 ) );
		$this->client->shouldReceive( 'sendText' )->once()->andReturn( Result::success( 201 ) );

		$this->sender->handle( 'order', 7, '', 1 );

		$this->assertTrue( $this->finished[0]->ok );
		$this->assertSame( array( 500 ), $this->pauses, '"Hola" is short: minimum wait.' );
		$this->assertSame( 0, CircuitBreaker::failures(), 'A typing failure is not a service failure.' );
	}

	public function test_typing_time_is_proportional_and_capped(): void {
		$this->assertSame( 500, Sender::typing_ms( 'Hola' ) );
		$this->assertSame( 2000, Sender::typing_ms( str_repeat( 'a', 50 ) ) );
		$this->assertSame( 3000, Sender::typing_ms( str_repeat( 'a', 1000 ) ) );
	}

	public function test_typing_can_be_disabled(): void {
		$this->expect_exists();
		$this->client->shouldNotReceive( 'startTyping' );
		$this->client->shouldNotReceive( 'stopTyping' );
		$this->client->shouldReceive( 'sendText' )->once()->andReturn( Result::success( 201 ) );
		$this->sender->handle( 'order', 7, '', 1 );
		$this->assertSame( array(), $this->pauses );
	}

	public function test_session_down_reschedules_and_counts_as_breaker_failure(): void {
		$this->client->shouldReceive( 'sessionStatus' )->once()->andReturn( Result::success( 200, array( 'status' => 'SCAN_QR_CODE' ) ) );
		$this->client->shouldNotReceive( 'sendText' );
		$this->expect_schedule( self::NOW + 60 + self::JITTER, array( 'order', 7, '', 1, 'normal' ) );

		$this->sender->handle( 'order', 7, '', 1 );

		$this->assertSame( array(), $this->finished );
		$this->assertSame( 1, CircuitBreaker::failures() );
	}

	public function test_session_health_is_cached_for_60_seconds(): void {
		$this->options['motored_waha']['rate_per_minute'] = '50';
		$this->client->shouldReceive( 'sessionStatus' )->once()->andReturn( Result::success( 200, array( 'status' => 'WORKING' ) ) );
		$this->expect_exists();
		$this->client->shouldReceive( 'sendText' )->twice()->andReturn( Result::success( 201 ) );

		$this->sender->handle( 'order', 1, '', 1 );
		$this->sender->handle( 'order', 2, '', 1 );
	}

	public function test_session_status_http_failure_counts_as_down(): void {
		$this->client->shouldReceive( 'sessionStatus' )->once()->andReturn( Result::failure( 'timeout', 'x', true ) );
		$this->expect_schedule( self::NOW + 60 + self::JITTER, array( 'order', 7, '', 1, 'normal' ) );
		$this->sender->handle( 'order', 7, '', 1 );
		$this->assertSame( 1, CircuitBreaker::failures() );
	}

	public function test_skipped_handler_does_not_check_session(): void {
		$this->client->shouldNotReceive( 'sessionStatus' );
		$this->sender->handle( 'order', 0, '', 1 );
		$this->assertSame( 'skipped', $this->finished[0]->code );
	}
}
