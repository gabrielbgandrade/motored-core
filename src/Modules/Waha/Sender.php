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
 * Safety: per-minute / per-hour rate limit (reschedules), 3-15 s jitter on every schedule, retry
 * with backoff (max 3 attempts), circuit breaker. Never sleeps.
 */
final class Sender {

	public const HOOK         = 'motored_waha_send';
	public const RESULT_HOOK  = 'motored_waha_result';
	public const MAX_ATTEMPTS = 3;
	public const JITTER_MIN   = 3;
	public const JITTER_MAX   = 15;

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
	 * Constructor.
	 *
	 * @param WahaClient    $client Transport.
	 * @param \Closure|null $clock  Returns unix time (tests).
	 * @param \Closure|null $jitter Returns jitter seconds (tests).
	 */
	public function __construct( private WahaClient $client, ?\Closure $clock = null, ?\Closure $jitter = null ) {
		$this->clock  = $clock ?? static fn(): int => time();
		$this->jitter = $jitter ?? static fn(): int => random_int( self::JITTER_MIN, self::JITTER_MAX );
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
	 * @param int         $delay  Extra seconds before sending.
	 * @return bool False when it could not be scheduled.
	 */
	public static function enqueue( string $kind, int $ref_id, ?string $step = null, int $delay = 0 ): bool {
		return self::instance()->queue( $kind, $ref_id, $step, $delay );
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param string $kind    Kind.
	 * @param int    $ref_id  Entity id.
	 * @param string $step    Step ('' for none).
	 * @param int    $attempt Attempt number (1-based).
	 */
	public static function run( string $kind, int $ref_id, string $step = '', int $attempt = 1 ): void {
		self::instance()->handle( $kind, $ref_id, $step, $attempt );
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
	 * @param int         $delay  Extra seconds.
	 */
	public function queue( string $kind, int $ref_id, ?string $step = null, int $delay = 0 ): bool {
		if ( ! self::valid_kind( $kind ) || $ref_id <= 0 ) {
			return false;
		}
		return $this->schedule( $kind, $ref_id, $step ?? '', 1, max( 0, $delay ) );
	}

	/**
	 * Processes one job.
	 *
	 * @param string $kind    Kind.
	 * @param int    $ref_id  Entity id.
	 * @param string $step    Step ('' for none).
	 * @param int    $attempt Attempt number (1-based).
	 */
	public function handle( string $kind, int $ref_id, string $step = '', int $attempt = 1 ): void {
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
			$this->reschedule( $kind, $ref_id, $step, $attempt, $paused_until - $now );
			return;
		}

		$wait = RateLimiter::wait_seconds( $now, Config::rate_per_minute(), Config::rate_per_hour() );
		if ( $wait > 0 ) {
			$this->reschedule( $kind, $ref_id, $step, $attempt, $wait );
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

		RateLimiter::hit( $now );
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
			if ( CircuitBreaker::record_failure( $now, Config::breaker_threshold(), Config::breaker_minutes() ) ) {
				Logger::warning( 'WAHA circuit breaker opened; sending paused.', array( 'minutes' => Config::breaker_minutes() ) );
			}
		}

		if ( $result->retryable && $attempt < self::MAX_ATTEMPTS ) {
			if ( $this->schedule( $kind, $ref_id, $step, $attempt + 1, self::BACKOFF[ min( $attempt, count( self::BACKOFF ) ) - 1 ] ) ) {
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
		return $this->client->sendText( (string) ( $resolved->data['chat_id'] ?? '' ), $message->text );
	}

	/**
	 * Re-queues the same attempt (rate limit / breaker hold), without consuming an attempt.
	 *
	 * @param string $kind    Kind.
	 * @param int    $ref_id  Entity id.
	 * @param string $step    Step.
	 * @param int    $attempt Attempt.
	 * @param int    $delay   Seconds.
	 */
	private function reschedule( string $kind, int $ref_id, string $step, int $attempt, int $delay ): void {
		if ( ! $this->schedule( $kind, $ref_id, $step, $attempt, $delay ) ) {
			$this->finish( $kind, $ref_id, '' === $step ? null : $step, Result::failure( 'schedule_failed', 'Could not reschedule the job.' ) );
		}
	}

	/**
	 * Schedules a job at now + delay + jitter. Failure to schedule ends the job.
	 *
	 * @param string $kind    Kind.
	 * @param int    $ref_id  Entity id.
	 * @param string $step    Step.
	 * @param int    $attempt Attempt.
	 * @param int    $delay   Seconds.
	 */
	private function schedule( string $kind, int $ref_id, string $step, int $attempt, int $delay ): bool {
		$when = ( $this->clock )() + max( 0, $delay ) + max( 0, (int) ( $this->jitter )() );
		if ( ActionScheduler::schedule( $when, self::HOOK, array( $kind, $ref_id, $step, $attempt ) ) ) {
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
