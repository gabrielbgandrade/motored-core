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
	/** @var list<Result> */
	private array $finished = array();

	protected function setUp(): void {
		parent::setUp();
		Sender::reset();
		$this->fake_storage();
		$this->fake_logger();
		$this->options['motored_waha'] = array(
			'rate_per_minute'   => '2',
			'rate_per_hour'     => '100',
			'breaker_threshold' => '2',
			'breaker_minutes'   => '10',
		);
		$this->client        = \Mockery::mock( WahaClient::class );
		$this->sender        = new Sender( $this->client, fn() => self::NOW, fn() => self::JITTER );
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
		$this->expect_schedule( self::NOW + 30 + self::JITTER, array( 'order', 7, 'reminder_1', 1 ) );
		$this->assertTrue( $this->sender->queue( 'order', 7, 'reminder_1', 30 ) );
	}

	public function test_enqueue_without_step_uses_empty_string(): void {
		$this->expect_schedule( self::NOW + self::JITTER, array( 'order', 7, '', 1 ) );
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
		$this->expect_schedule( self::NOW + 40 + self::JITTER, array( 'order', 7, '', 1 ) );

		$this->sender->handle( 'order', 7, '', 1 );

		$this->assertSame( 0, $this->handler_calls );
		$this->assertSame( array(), $this->finished );
	}

	public function test_hourly_limit_waits_for_next_hour(): void {
		$this->options['motored_waha']['rate_per_hour'] = '1';
		RateLimiter::hit( self::NOW );
		$this->expect_schedule( self::NOW + ( 3600 - ( self::NOW % 3600 ) ) + self::JITTER, array( 'order', 7, '', 1 ) );

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
					$this->assertSame( array( self::NOW + 60 + self::JITTER, array( 'order', 7, '', 2 ) ), array( $at, $args ) );
				} else {
					$this->assertSame( array( self::NOW + 300 + self::JITTER, array( 'order', 7, '', 3 ) ), array( $at, $args ) );
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
		$this->expect_schedule( self::NOW + 600 + self::JITTER, array( 'order', 9, '', 1 ) );
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
}
