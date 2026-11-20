<?php
/**
 * Action-Scheduler batched runner for Media Library image optimization.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ImageOptimizer;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Support\Cache;
use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Cursor-based batch processor with dry-run scan, resume/cancel and non-autoloaded job state.
 */
final class BulkRunner {

	public const HOOK          = 'motored_image_opt_batch';
	public const JOB_OPTION    = 'motored_image_opt_job';
	public const SAVED_OPTION  = 'motored_image_opt_saved';
	public const LOCK_BUCKET   = 'img_opt';
	public const STALE_SECONDS = 900;
	public const LOCK_TTL      = 300;
	public const TIME_BUDGET   = 20.0;

	/**
	 * Optional test scheduler override: fn( int $ts, string $hook, list<string> $args ): bool.
	 * Avoids defining global Action Scheduler stubs across PHPUnit processes.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $test_scheduler = null;

	/**
	 * Registers the Action Scheduler batch listener.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ), 10, 1 );
	}

	/**
	 * Returns the normalized current or last bulk optimization job state.
	 *
	 * @return array{status:string,run_id:string,dry_run:bool,only_unoptimized:bool,total:int,processed:int,optimized:int,skipped:int,errors:int,bytes_before:int,bytes_after:int,cursor:int,updated_ts:int,finished_gmt:string}
	 */
	public static function status(): array {
		return OptimizerRules::sanitize_job_state( get_option( self::JOB_OPTION, array() ) );
	}

	/**
	 * Returns Media Library attachment optimization counts (admin settings screen only).
	 *
	 * @return array{total:int,optimized:int,pending:int,bytes_saved:int}
	 */
	public static function library_stats(): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
			return array(
				'total'       => 0,
				'optimized'   => 0,
				'pending'     => 0,
				'bytes_saved' => max( 0, (int) get_option( self::SAVED_OPTION, 0 ) ),
			);
		}

		$mimes = OptimizerRules::OPTIMIZABLE_MIMES;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin settings summary only.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (%s, %s, %s)",
				$mimes[0],
				$mimes[1],
				$mimes[2]
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin settings summary only.
		$optimized = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_mime_type IN (%s, %s, %s)",
				UploadHooks::META_KEY,
				$mimes[0],
				$mimes[1],
				$mimes[2]
			)
		);

		$saved = get_option( self::SAVED_OPTION, 0 );

		return array(
			'total'       => max( 0, $total ),
			'optimized'   => max( 0, min( $total, $optimized ) ),
			'pending'     => max( 0, $total - $optimized ),
			'bytes_saved' => is_scalar( $saved ) ? max( 0, (int) $saved ) : 0,
		);
	}

	/**
	 * Starts a bulk dry-run scan or live optimization job via Action Scheduler (or 1 sync batch fallback).
	 *
	 * @param bool $dry_run          Measure eligible images without writing files or meta.
	 * @param bool $only_unoptimized Skip attachments already marked with `_motored_img_opt`.
	 * @return array{ok:bool,message:string}
	 */
	public static function start( bool $dry_run = true, bool $only_unoptimized = true ): array {
		$current = self::status();
		if ( 'running' === $current['status'] && ( time() - $current['updated_ts'] ) < self::STALE_SECONDS ) {
			return array(
				'ok'      => false,
				'message' => __( 'An image optimization job is already running. Wait for it to finish or cancel it first.', 'motored-core' ),
			);
		}

		$total = self::count_matching( 0, $only_unoptimized );
		if ( $total < 1 ) {
			return array(
				'ok'      => true,
				'message' => __( 'No pending images to optimize.', 'motored-core' ),
			);
		}

		$run_id = bin2hex( random_bytes( 6 ) );
		$job    = OptimizerRules::sanitize_job_state(
			array(
				'status'           => 'running',
				'run_id'           => $run_id,
				'dry_run'          => $dry_run,
				'only_unoptimized' => $only_unoptimized,
				'total'            => $total,
				'updated_ts'       => time(),
			)
		);
		update_option( self::JOB_OPTION, $job, false );

		if ( self::can_schedule() ) {
			ActionScheduler::unschedule_all( self::HOOK );
			if ( self::schedule_batch( time(), array( $run_id ) ) ) {
				return array(
					'ok'      => true,
					'message' => $dry_run
						/* translators: %d: number of images queued for dry-run scan. */
						? sprintf( __( 'Test scan queued in background batches (%d images).', 'motored-core' ), $total )
						/* translators: %d: number of images queued for optimization. */
						: sprintf( __( 'Bulk optimization queued in background batches (%d images).', 'motored-core' ), $total ),
				);
			}
		}

		( new self() )->run_batch( $run_id );
		$after = self::status();
		if ( 'completed' === $after['status'] ) {
			return array(
				'ok'      => true,
				'message' => $dry_run
					/* translators: %d: number of images scanned. */
					? sprintf( __( 'Test scan finished synchronously (%d images checked).', 'motored-core' ), $after['processed'] )
					/* translators: %d: number of images optimized. */
					: sprintf( __( 'Bulk optimization finished synchronously (%d images processed).', 'motored-core' ), $after['processed'] ),
			);
		}

		return array(
			'ok'      => true,
			/* translators: 1: processed count, 2: total count. */
			'message' => sprintf( __( 'Action Scheduler is unavailable: processed the first batch (%1$d of %2$d images). Click Resume to continue.', 'motored-core' ), $after['processed'], $after['total'] ),
		);
	}

	/**
	 * Resumes a running/stale job from its persisted cursor.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function resume(): array {
		$job = self::status();
		if ( 'running' !== $job['status'] || '' === $job['run_id'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'There is no active optimization job to resume.', 'motored-core' ),
			);
		}

		$job['updated_ts'] = time();
		update_option( self::JOB_OPTION, $job, false );

		if ( self::can_schedule() ) {
			ActionScheduler::unschedule_all( self::HOOK );
			if ( self::schedule_batch( time(), array( $job['run_id'] ) ) ) {
				return array(
					'ok'      => true,
					'message' => __( 'Optimization job resumed in background batches.', 'motored-core' ),
				);
			}
		}

		( new self() )->run_batch( $job['run_id'] );
		return array(
			'ok'      => true,
			'message' => __( 'Processed the next optimization batch synchronously.', 'motored-core' ),
		);
	}

	/**
	 * Cancels an active optimization job and clears pending Action Scheduler batches.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function cancel(): array {
		ActionScheduler::unschedule_all( self::HOOK );
		$job = self::status();
		if ( 'running' !== $job['status'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'There is no active optimization job to cancel.', 'motored-core' ),
			);
		}
		$job['status']       = 'cancelled';
		$job['updated_ts']   = time();
		$job['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
		update_option( self::JOB_OPTION, $job, false );
		return array(
			'ok'      => true,
			'message' => __( 'Optimization job cancelled.', 'motored-core' ),
		);
	}

	/**
	 * Action Scheduler batch callback: processes one slice and queues the next.
	 *
	 * @param mixed $run_id Job identifier passed positionally by Action Scheduler.
	 */
	public function run_batch( $run_id = '' ): void {
		$id  = is_string( $run_id ) ? $run_id : '';
		$job = self::status();
		if ( 'running' !== $job['status'] || '' === $id || $id !== $job['run_id'] ) {
			return;
		}

		$lock_key = 'batch_' . $id;
		if ( ! Cache::add( $lock_key, '1', self::LOCK_TTL, self::LOCK_BUCKET ) ) {
			return;
		}

		try {
			$limit   = Config::bulk_batch_size();
			$webp    = Config::bulk_webp_subsizes();
			$ids     = self::next_ids( $job['cursor'], $limit, $job['only_unoptimized'] );
			$started = microtime( true );
			$partial = false;

			$batch_saved = 0;
			$last_id     = $job['cursor'];
			foreach ( $ids as $n => $att_id ) {
				// Time budget: big originals with many sub-sizes can take seconds each; stop early and continue in
				// the next scheduled batch instead of hitting max_execution_time (cursor keeps the position).
				if ( $n > 0 && ( microtime( true ) - $started ) > self::TIME_BUDGET ) {
					$partial = true;
					break;
				}
				$last_id = max( $last_id, $att_id );
				$res     = AttachmentOptimizer::optimize( $att_id, $job['dry_run'], $webp );
				++$job['processed'];
				$job['bytes_before'] += $res['bytes_before'];
				$job['bytes_after']  += $res['bytes_after'];
				if ( 'optimized' === $res['status'] || 'would_optimize' === $res['status'] ) {
					++$job['optimized'];
					$batch_saved += $res['bytes_saved'];
				} elseif ( 'error' === $res['status'] ) {
					++$job['errors'];
				} else {
					++$job['skipped'];
				}
			}

			$job['cursor']     = $last_id;
			$job['updated_ts'] = time();

			if ( ! $job['dry_run'] && $batch_saved > 0 ) {
				$prev_saved = get_option( self::SAVED_OPTION, 0 );
				$total_save = ( is_scalar( $prev_saved ) ? max( 0, (int) $prev_saved ) : 0 ) + $batch_saved;
				update_option( self::SAVED_OPTION, $total_save, false );
			}

			// Cancel (or a new run) may have happened while this batch was working: never resurrect it.
			$fresh = OptimizerRules::sanitize_job_state( self::fresh_option( self::JOB_OPTION ) );
			if ( 'running' !== $fresh['status'] || $fresh['run_id'] !== $id ) {
				return;
			}

			$more = $partial || count( $ids ) >= $limit;
			if ( $more && self::can_schedule() ) {
				update_option( self::JOB_OPTION, $job, false );
				self::schedule_batch( time() + 2, array( $id ) );
				return;
			}

			if ( ! $more ) {
				$job['status']       = 'completed';
				$job['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
				Logger::info(
					'Image optimization batch job finished.',
					array(
						'dry_run'   => $job['dry_run'] ? '1' : '0',
						'processed' => $job['processed'],
						'optimized' => $job['optimized'],
						'errors'    => $job['errors'],
						'saved'     => max( 0, $job['bytes_before'] - $job['bytes_after'] ),
					)
				);
			}
			update_option( self::JOB_OPTION, $job, false );
		} finally {
			Cache::delete( $lock_key, self::LOCK_BUCKET );
		}
	}

	/**
	 * Counts matching attachments after cursor.
	 *
	 * @param int  $cursor           Minimum attachment ID (exclusive).
	 * @param bool $only_unoptimized Exclude attachments carrying `_motored_img_opt`.
	 */
	private static function count_matching( int $cursor, bool $only_unoptimized ): int {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
			return 0;
		}
		$mimes = OptimizerRules::OPTIMIZABLE_MIMES;
		if ( $only_unoptimized ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin job count only.
			return max(
				0,
				(int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} p
						WHERE p.ID > %d
							AND p.post_type = 'attachment'
							AND p.post_mime_type IN (%s, %s, %s)
							AND NOT EXISTS (
								SELECT 1 FROM {$wpdb->postmeta} pm
								WHERE pm.post_id = p.ID AND pm.meta_key = %s
							)",
						$cursor,
						$mimes[0],
						$mimes[1],
						$mimes[2],
						UploadHooks::META_KEY
					)
				)
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin job count only.
		return max(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					WHERE p.ID > %d
						AND p.post_type = 'attachment'
						AND p.post_mime_type IN (%s, %s, %s)",
					$cursor,
					$mimes[0],
					$mimes[1],
					$mimes[2]
				)
			)
		);
	}

	/**
	 * Fetches next ascending batch of attachment IDs after cursor.
	 *
	 * @param int  $cursor           Last processed attachment ID.
	 * @param int  $limit            Batch size.
	 * @param bool $only_unoptimized Exclude attachments carrying `_motored_img_opt`.
	 * @return list<int>
	 */
	private static function next_ids( int $cursor, int $limit, bool $only_unoptimized ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
			return array();
		}
		$mimes = OptimizerRules::OPTIMIZABLE_MIMES;
		if ( $only_unoptimized ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched job cursor query.
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					WHERE p.ID > %d
						AND p.post_type = 'attachment'
						AND p.post_mime_type IN (%s, %s, %s)
						AND NOT EXISTS (
							SELECT 1 FROM {$wpdb->postmeta} pm
							WHERE pm.post_id = p.ID AND pm.meta_key = %s
						)
					ORDER BY p.ID ASC
					LIMIT %d",
					$cursor,
					$mimes[0],
					$mimes[1],
					$mimes[2],
					UploadHooks::META_KEY,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched job cursor query.
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					WHERE p.ID > %d
						AND p.post_type = 'attachment'
						AND p.post_mime_type IN (%s, %s, %s)
					ORDER BY p.ID ASC
					LIMIT %d",
					$cursor,
					$mimes[0],
					$mimes[1],
					$mimes[2],
					$limit
				)
			);
		}

		$ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = is_scalar( $row ) ? (int) $row : 0;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Reads an option value bypassing every cache layer.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private static function fresh_option( string $name ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! is_callable( array( $wpdb, 'get_var' ) ) ) {
			return get_option( $name, array() );
		}
		// Straight from the DB: the in-request option cache (and Redis copy) may predate a Cancel sent meanwhile.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately uncached state check, once per batch.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		return is_string( $raw ) ? maybe_unserialize( $raw ) : array();
	}

	/**
	 * Whether background scheduling is possible (Action Scheduler or the unit-test scheduler).
	 */
	private static function can_schedule(): bool {
		return null !== self::$test_scheduler || ActionScheduler::available();
	}

	/**
	 * Queues one batch run through the test seam or Action Scheduler.
	 *
	 * @param int               $timestamp Execution timestamp.
	 * @param array<int,string> $args      Positional hook args.
	 */
	private static function schedule_batch( int $timestamp, array $args ): bool {
		if ( null !== self::$test_scheduler ) {
			return (bool) ( self::$test_scheduler )( $timestamp, self::HOOK, $args );
		}
		return ActionScheduler::schedule( $timestamp, self::HOOK, $args );
	}
}
