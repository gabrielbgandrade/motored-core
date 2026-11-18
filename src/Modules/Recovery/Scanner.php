<?php
/**
 * Recurring Action Scheduler scanner for due recovery actions.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Runs every 15 minutes: finds up to 50 active rows whose next_action_gmt <= now
 * and enqueues Sender::enqueue('recovery_step', id, step). No HTTP in the scanner.
 */
final class Scanner {

	public const HOOK      = 'motored_recovery_scan';
	public const INTERVAL  = 900; // 15 minutes.
	public const CHECK_TTL = 3600;
	public const LIMIT     = 50;

	/**
	 * Schedules the recurring scanner if missing (activation).
	 */
	public static function schedule(): void {
		if ( ! ActionScheduler::has_scheduled( self::HOOK ) ) {
			ActionScheduler::schedule_recurring( time() + self::INTERVAL, self::INTERVAL, self::HOOK );
		}
	}

	/**
	 * Self-repair on admin_init, at most once per hour.
	 */
	public static function ensure_scheduled(): void {
		if ( Cache::add( 'scanner_check', 1, self::CHECK_TTL, CaptureLimiter::BUCKET ) ) {
			self::schedule();
		}
	}

	/**
	 * Unschedules the scanner (uninstall / tooling).
	 */
	public static function unschedule(): void {
		ActionScheduler::unschedule_all( self::HOOK );
	}

	/**
	 * Action Scheduler callback.
	 */
	public static function run(): void {
		if ( ! Config::enabled() ) {
			return;
		}

		$now_gmt = gmdate( 'Y-m-d H:i:s' );
		$due     = Repository::find_due_for_action( $now_gmt, self::LIMIT );

		foreach ( $due as $row ) {
			$id       = (int) $row['id'];
			$is_order = 'order' === (string) $row['type'];
			// Orders: the step number is just the next one; the handler picks the case-specific config at send time.
			$next_step = $is_order
				? ( (int) $row['step'] < OrderCase::STEPS ? (int) $row['step'] + 1 : null )
				: Config::next_step_num( (string) $row['step'] );

			if ( null === $next_step ) {
				Repository::update(
					$id,
					array(
						'status'          => Status::EXPIRED,
						'next_action_gmt' => null,
					)
				);
				History::log( $id, 'expired', 'none', 'system', 0, 'ok', '' );
				continue;
			}

			// Clear next_action_gmt while job is in-flight so subsequent scans do not double-enqueue.
			Repository::update( $id, array( 'next_action_gmt' => null ) );
			Sender::enqueue( 'recovery_step', $id, (string) $next_step );
		}
	}
}
