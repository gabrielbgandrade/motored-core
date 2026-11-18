<?php
/**
 * Daily cleanup of old rows.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Recurring Action Scheduler job (hook motored_recovery_cleanup), batches of 500:
 * converted/recovered rows after 7 days, the rest after the configured retention.
 * Scheduled on activation; self-repaired only in admin_init (Cache flag of 1 h).
 */
final class Retention {

	public const HOOK          = 'motored_recovery_cleanup';
	public const BATCH         = 500;
	public const TERMINAL_DAYS = 7;
	public const CHECK_TTL     = 3600;

	/**
	 * GMT cutoffs (pure).
	 *
	 * @param int $now            Unix time.
	 * @param int $retention_days Retention for non-terminal rows.
	 * @return array{terminal:string,other:string}
	 */
	public static function cutoffs( int $now, int $retention_days ): array {
		return array(
			'terminal' => gmdate( 'Y-m-d H:i:s', $now - ( self::TERMINAL_DAYS * 86400 ) ),
			'other'    => gmdate( 'Y-m-d H:i:s', $now - ( max( 1, $retention_days ) * 86400 ) ),
		);
	}

	/**
	 * Schedules the daily job when missing (activation).
	 */
	public static function schedule(): void {
		if ( ! ActionScheduler::has_scheduled( self::HOOK ) ) {
			ActionScheduler::schedule_recurring( time() + 3600, 86400, self::HOOK );
		}
	}

	/**
	 * Self-repair on admin_init, at most once per hour.
	 */
	public static function ensure_scheduled(): void {
		if ( Cache::add( 'cleanup_check', 1, self::CHECK_TTL, CaptureLimiter::BUCKET ) ) {
			self::schedule();
		}
	}

	/**
	 * Action Scheduler callback: deletes one batch; schedules a follow-up when the batch was full.
	 */
	public static function run(): void {
		$deleted = Repository::purge( self::cutoffs( time(), Config::retention_days() ), self::BATCH );
		Cache::delete( 'summary', CaptureLimiter::BUCKET );
		if ( $deleted >= self::BATCH ) {
			ActionScheduler::schedule( time() + 60, self::HOOK, array() );
		}
	}
}
