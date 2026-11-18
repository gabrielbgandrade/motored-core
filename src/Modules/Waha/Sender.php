<?php
/**
 * Send queue API for other modules.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Waha;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Integration\Waha\WahaClient;
use Motored\Core\Support\Cache;
use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Public API of the Waha module.
 *
 *  - Sender::register_handler( $kind, $callable ) — callable( int $ref_id, ?string $step ): ?Message,
 *    invoked AT SEND TIME (returns the phone + text, or null to skip). Register it in every
 *    context that runs jobs (cron, ajax, cli, admin).
 *  - Sender::enqueue( $kind, $ref_id, $step, $delay ) — schedules an Action Scheduler job whose
 *    args are IDs only (never phone numbers or text).
 *  - Action `motored_waha_result` ( string $kind, int $ref_id, ?string $step, Result $result ) fires
 *    when a job is finished (sent, skipped or given up); modules store their own history there.
 *
 * Safety: priority (high = order notices, normal, low = recovery/reviews), per-minute / per-hour rate
 * limit, global random spacing between messages (20-60 s, soft: kept in Cache), daily cap (low priority
 * only uses a share of it), session health check (60 s cache), typing simulation (max 3 s, background
 * only), 3-15 s jitter on every schedule, retry with backoff (max 3 attempts), circuit breaker.
 * Waiting is always a reschedule, never a long sleep.
 */
final class Sender {

	public const HOOK         = 'motored_waha_send';
	public const RESULT_HOOK  = 'motored_waha_result';
	public const MAX_ATTEMPTS = 3;

	/**
	 * Arguments of every scheduled job: kind, ref_id, step, attempt, priority (the hook must accept all of them).
	 */
	public const HOOK_ARGS = 5;

	public const JITTER_MIN = 3;
	public const JITTER_MAX = 15;

	public const HIGH   = 'high';
	public const NORMAL = 'normal';
	public const LOW    = 'low';

	public const TYPING_MAX_MS     = 3000;
	public const HEALTH_TTL        = 60;
	public const HEALTH_RETRY_WAIT = 60;

	/**
	 * Seconds before retry number 2 and 3.
	 *
	 * @var array<int,int>
	 */
	private const BACKOFF = array( 60, 300 );

	/**
	 * Handlers by kind.
	 *
	 * @var array<string,callable>
	 */
	private static array $handlers = array();

	/**
	 * Shared instance.
	 *
	 * @var Sender|null
	 */
	private static ?Sender $instance = null;

	/**
	 * Clock (unix time).
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Jitter source (seconds).
	 *
	 * @var \Closure
	 */
	private \Closure $jitter;

	/**
	 * Gap between two messages: fn( int $min, int $max ): int seconds.
	 *
	 * @var \Closure
	 */
	private \Closure $gap;

	/**
	 * Pause: fn( int $milliseconds ): void.
	 *
	 * @var \Closure
	 */
	private \Closure $pause;

	/**
	 * Constructor.
	 *
	 * @param WahaClient    $client Transport.
	 * @param \Closure|null $clock  Returns unix time (tests).
	 * @param \Closure|null $jitter Returns jitter seconds (tests).
	 * @param \Closure|null $gap    Returns the spacing in seconds for ( min, max ) (tests).
	 * @param \Closure|null $pause  Waits N milliseconds (tests).
	 */
	public function __construct( private WahaClient $client, ?\Closure $clock = null, ?\Closure $jitter = null, ?\Closure $gap = null, ?\Closure $pause = null ) {
		$this->clock  = $clock ?? static fn(): int => time();
		$this->jitter = $jitter ?? static fn(): int => random_int( self::JITTER_MIN, self::JITTER_MAX );
		$this->gap    = $gap ?? static fn( int $min, int $max ): int => random_int( $min, max( $min, $max ) );
		$this->pause  = $pause ?? static function ( int $ms ): void {
			usleep( $ms * 1000 );
		};
	}

	/**
	 * Shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self( new WahaClient() );
		}
		return self::$instance;
	}

	/**
	 * Registers the handler that builds the message for a kind at send time.
	 *
	 * @param string   $kind    Lowercase slug [a-z0-9_]+.
	 * @param callable $handler fn( int $ref_id, ?string $step ): ?Message.
	 */
	public static function register_handler( string $kind, callable $handler ): void {
		if ( self::valid_kind( $kind ) ) {
			self::$handlers[ $kind ] = $handler;
		}
	}

	/**
	 * Schedules a message. Safe to call from any request (no HTTP, one AS insert).
	 *
	 * @param string      $kind   Kind with a registered handler.
	 * @param int         $ref_id Entity id (order, cart, ...).
	 * @param string|null $step   Optional step name (e.g. "reminder_1").
	 * @param int         $delay    Extra seconds before sending.
	 * @param string      $priority self::HIGH, self::NORMAL or self::LOW.
	 * @return bool False when it could not be scheduled.
	 */
	public static function enqueue( string $kind, int $ref_id, ?string $step = null, int $delay = 0, string $priority = self::NORMAL ): bool {
		return self::instance()->queue( $kind, $ref_id, $step, $delay, $priority );
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param string $kind    Kind.
	 * @param int    $ref_id  Entity id.
	 * @param string $step    Step ('' for none).
	 * @param int    $attempt  Attempt number (1-based).
	 * @param string $priority Priority (jobs scheduled before it existed have none: normal).
	 */
	public static function run( string $kind, int $ref_id, string $step = '', int $attempt = 1, string $priority = self::NORMAL ): void {
		self::instance()->handle( $kind, $ref_id, $step, $attempt, $priority );
	}

	/**
	 * Clears registered handlers and the shared instance (tests).
	 */
	public static function reset(): void {
		self::$handlers = array();
		self::$instance = null;
	}

	/**
	 * Schedules the first attempt.
	 *
	 * @param string      $kind   Kind.
	 * @param int         $ref_id Entity id.
	 * @param string|null $step   Step.
	 * @param int         $delay    Extra seconds.
	 * @param string      $priority Priority.
	 */
	public function queue( string $kind, int $ref_id, ?string $step = null, int $delay = 0, string $priority = self::NORMAL ): bool {
		if ( ! self::valid_kind( $kind ) || $ref_id <= 0 ) {
			return false;
		}
		return $this->schedule( $kind, $ref_id, $step ?? '', 1, max( 0, $delay ), self::priority( $priority ) );
	}

	/**
	 * Processes one job.
	 *
	 * @param string $kind    Kind.
	 * @param int    $ref_id  Entity id.
	 * @param string $step    Step ('' for none).
	 * @param int    $attempt  Attempt number (1-based).
	 * @param string $priority Priority.
	 */
	public function handle( string $kind, int $ref_id, string $step = '', int $attempt = 1, string $priority = self::NORMAL ): void {
		$priority = self::priority( $priority );
		$now      = ( $this->clock )();
		$step_arg = '' === $step ? null : $step;
		$attempt  = max( 1, $attempt );
		$handler  = self::$handlers[ $kind ] ?? null;

		if ( null === $handler ) {
			Logger::error( 'No handler registered for kind.', array( 'kind' => $kind ) );
			$this->finish( $kind, $ref_id, $step_arg, Result::failure( 'no_handler', 'No handler registered for this kind.' ) );
			return;
		}

		$paused_until = CircuitBreaker::until();
		if ( $paused_until > $now ) {
			$this->reschedule( $kind, $ref_id, $step, $attempt, $paused_until - $now, $priority );
			return;
		}

		$wait = RateLimiter::wait_seconds( $now, Config::rate_per_minute(), Config::rate_per_hour() );
		if ( $wait > 0 ) {
			$this->reschedule( $kind, $ref_id, $step, $attempt, $wait, $priority );
			return;
		}

		$wait = RateLimiter::spacing_wait( $now );
		if ( $wait > 0 ) {
			$this->reschedule( $kind, $ref_id, $step, $attempt, $wait, $priority );
			return;
		}

		$wait = RateLimiter::daily_wait( $now, Config::daily_cap(), $priority, Config::low_percent() );
		if ( $wait > 0 ) {
			$this->reschedule( $kind, $ref_id, $step, $attempt, $wait, $priority );
			return;
		}

		try {
			$message = $handler( $ref_id, $step_arg );
		} catch ( \Throwable $e ) {
			Logger::error(
				'Message handler threw.',
				array(
					'kind'      => $kind,
					'exception' => get_class( $e ),
				)
			);
			$this->finish( $kind, $ref_id, $step_arg, Result::failure( 'handler_error', 'Message handler failed.' ) );
			return;
		}
		if ( ! $message instanceof Message ) {
			$this->finish( $kind, $ref_id, $step_arg, Result::failure( 'skipped', 'Handler returned no message.' ) );
			return;
		}

		$health = $this->session_health();
		if ( ! $health->ok ) {
			if ( 'not_configured' === $health->code ) {
				$this->finish( $kind, $ref_id, $step_arg, $health );
				return;
			}
			$this->record_failure( $now );
			Logger::warning( 'WAHA session is not working; message rescheduled.', array( 'kind' => $kind ) );
			$this->reschedule( $kind, $ref_id, $step, $attempt, self::HEALTH_RETRY_WAIT, $priority );
			return;
		}

		RateLimiter::hit( $now );
		RateLimiter::daily_hit( $now );
		RateLimiter::reserve_slot( $now, ( $this->gap )( Config::interval_min(), Config::interval_max() ) );
		$result = $this->deliver( $message );

		if ( $result->ok ) {
			CircuitBreaker::record_success();
			Logger::info(
				'WhatsApp message sent.',
				array(
					'kind'   => $kind,
					'ref_id' => $ref_id,
					'to'     => Logger::mask_phone( $message->phone ),
				)
			);
			$this->finish( $kind, $ref_id, $step_arg, $result );
			return;
		}

		if ( $result->retryable || 'auth' === $result->code ) {
			$this->record_failure( $now );
		}

		if ( $result->retryable && $attempt < self::MAX_ATTEMPTS ) {
			if ( $this->schedule( $kind, $ref_id, $step, $attempt + 1, self::BACKOFF[ min( $attempt, count( self::BACKOFF ) ) - 1 ], $priority ) ) {
				return;
			}
		}

		Logger::warning(
			'WhatsApp message failed.',
			array(
				'kind'   => $kind,
				'ref_id' => $ref_id,
				'code'   => $result->code,
				'to'     => Logger::mask_phone( $message->phone ),
			)
		);
		$this->finish( $kind, $ref_id, $step_arg, $result );
	}

	/**
	 * Resolves the chat id and sends.
	 *
	 * @param Message $message Message.
	 */
	private function deliver( Message $message ): Result {
		$resolved = ( new ChatResolver( $this->client, Config::country_code() ) )->resolve( $message->phone );
		if ( ! $resolved->ok ) {
			return $resolved;
		}
		$chat_id = (string) ( $resolved->data['chat_id'] ?? '' );
		if ( Config::typing() ) {
			// Best effort: a typing failure never blocks the message. Runs in the job worker only.
			$this->client->startTyping( $chat_id );
			( $this->pause )( self::typing_ms( $message->text ) );
			$this->client->stopTyping( $chat_id );
		}
		return $this->client->sendText( $chat_id, $message->text );
	}

	/**
	 * Typing time proportional to the text (about 40 ms per character, 0.5-3 s).
	 *
	 * @param string $text Message text.
	 */
	public static function typing_ms( string $text ): int {
		return (int) min( self::TYPING_MAX_MS, max( 500, mb_strlen( $text ) * 40 ) );
	}

	/**
	 * Session state, cached 60 s (a "down" answer is cached too, so a stopped session is not hammered).
	 */
	private function session_health(): Result {
		$found  = false;
		$cached = Cache::get( 'session:health', ChatResolver::BUCKET, $found );
		if ( $found && 'up' === $cached ) {
			return Result::success( 200 );
		}
		if ( $found && 'down' === $cached ) {
			return Result::failure( 'session_down', 'WAHA session is not working.', true );
		}

		$status = $this->client->sessionStatus();
		if ( ! $status->ok && 'not_configured' === $status->code ) {
			return $status;
		}
		$up = $status->ok && 'WORKING' === ( $status->data['status'] ?? '' );
		Cache::set( 'session:health', $up ? 'up' : 'down', self::HEALTH_TTL, ChatResolver::BUCKET );
		return $up ? Result::success( 200 ) : Result::failure( 'session_down', 'WAHA session is not working.', true );
	}

	/**
	 * Counts a service failure for the circuit breaker.
	 *
	 * @param int $now Unix time.
	 */
	private function record_failure( int $now ): void {
		if ( CircuitBreaker::record_failure( $now, Config::breaker_threshold(), Config::breaker_minutes() ) ) {
			Logger::warning( 'WAHA circuit breaker opened; sending paused.', array( 'minutes' => Config::breaker_minutes() ) );
		}
	}

	/**
	 * Valid priority or normal.
	 *
	 * @param string $priority Priority.
	 */
	private static function priority( string $priority ): string {
		return in_array( $priority, array( self::HIGH, self::LOW ), true ) ? $priority : self::NORMAL;
	}

	/**
	 * Re-queues the same attempt (rate limit / breaker hold), without consuming an attempt.
	 *
	 * @param string $kind    Kind.
	 * @param int    $ref_id  Entity id.
	 * @param string $step    Step.
	 * @param int    $attempt  Attempt.
	 * @param int    $delay    Seconds.
	 * @param string $priority Priority.
	 */
	private function reschedule( string $kind, int $ref_id, string $step, int $attempt, int $delay, string $priority ): void {
		if ( ! $this->schedule( $kind, $ref_id, $step, $attempt, $delay, $priority ) ) {
			$this->finish( $kind, $ref_id, '' === $step ? null : $step, Result::failure( 'schedule_failed', 'Could not reschedule the job.' ) );
		}
	}

	/**
	 * Schedules a job at now + delay + jitter. Failure to schedule ends the job.
	 *
	 * @param string $kind    Kind.
	 * @param int    $ref_id  Entity id.
	 * @param string $step    Step.
	 * @param int    $attempt  Attempt.
	 * @param int    $delay    Seconds.
	 * @param string $priority Priority.
	 */
	private function schedule( string $kind, int $ref_id, string $step, int $attempt, int $delay, string $priority ): bool {
		$when = ( $this->clock )() + max( 0, $delay ) + max( 0, (int) ( $this->jitter )() );
		if ( ActionScheduler::schedule( $when, self::HOOK, array( $kind, $ref_id, $step, $attempt, $priority ) ) ) {
			return true;
		}
		Logger::error( 'Could not schedule WhatsApp job (Action Scheduler unavailable?).', array( 'kind' => $kind ) );
		return false;
	}

	/**
	 * Announces the final outcome of a job.
	 *
	 * @param string      $kind   Kind.
	 * @param int         $ref_id Entity id.
	 * @param string|null $step   Step.
	 * @param Result      $result Outcome.
	 */
	private function finish( string $kind, int $ref_id, ?string $step, Result $result ): void {
		do_action( self::RESULT_HOOK, $kind, $ref_id, $step, $result ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Constant value starts with motored_.
	}

	/**
	 * Whether a kind slug is valid.
	 *
	 * @param string $kind Kind.
	 */
	private static function valid_kind( string $kind ): bool {
		return 1 === preg_match( '/^[a-z0-9_]{1,40}$/', $kind );
	}
}
