<?php
/**
 * Synchronous / Action-Scheduler batched runner for JSON compatibility imports.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Integration\ActionScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Executes small imports immediately and stages large payloads (> 50 versions) in non-autoloaded options for AS batches.
 */
final class ImportRunner {

	public const HOOK            = 'motored_compat_import';
	public const BATCH_SIZE      = 50;
	public const STALE_SECONDS   = 900;
	public const JOB_OPTION_PREF = 'motored_compat_import_job_';
	public const LAST_STATUS_OPT = 'motored_compat_last_import';

	/**
	 * Registers the Action Scheduler batch hook.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ), 10, 2 );
	}

	/**
	 * Starts a JSON import immediately (<= 50 versions or sync=true) or via Action Scheduler batches (> 50 versions).
	 *
	 * @param string                                                                                                   $raw_json Raw JSON string.
	 * @param array{default_status?:string,import_constraints?:bool,allow_overlaps?:bool,force_draft?:bool,sync?:bool} $options Import options.
	 * @return array{ok:bool,mode:string,message:string,stats:array<string,int>}
	 */
	public static function start( string $raw_json, array $options = array() ): array {
		if ( null !== self::active_job() ) {
			return array(
				'ok'      => false,
				'mode'    => 'busy',
				'message' => __( 'An import is already in progress. Wait for it to finish or cancel it first.', 'motored-core' ),
				'stats'   => self::empty_stats(),
			);
		}

		$parsed = JsonImporter::parse_payload( $raw_json );
		if ( ! $parsed['ok'] ) {
			return array(
				'ok'      => false,
				'mode'    => 'error',
				'message' => $parsed['error'],
				'stats'   => array(),
			);
		}

		$flat = JsonImporter::flatten_items(
			array(
				'brands'       => $parsed['brands'],
				'equivalences' => $parsed['equivalences'],
			)
		);

		$total_versions = count( $flat['versions'] );
		if ( empty( $options['sync'] ) && $total_versions > self::BATCH_SIZE && ActionScheduler::available() ) {
			$job_id = substr( md5( wp_json_encode( $flat ) . microtime() ), 0, 12 );
			$job    = array(
				'versions'     => $flat['versions'],
				'equivalences' => $flat['equivalences'],
				'options'      => $options,
				'stats'        => self::empty_stats(),
			);
			update_option( self::JOB_OPTION_PREF . $job_id, $job, false );
			if ( ActionScheduler::schedule( time(), self::HOOK, array( $job_id, 0 ) ) ) {
				update_option(
					self::LAST_STATUS_OPT,
					array_merge(
						self::empty_stats(),
						array(
							'status'         => 'queued',
							'mode'           => 'async',
							'job_id'         => $job_id,
							'offset'         => 0,
							'total_versions' => $total_versions,
							'updated_ts'     => time(),
							'finished_gmt'   => '',
						)
					),
					false
				);
				return array(
					'ok'      => true,
					'mode'    => 'queued',
					'message' => sprintf(
						/* translators: %d: number of motorcycle versions queued for background import. */
						__( 'Import queued in background batches (%d versions).', 'motored-core' ),
						$total_versions
					),
					'stats'   => self::empty_stats(),
				);
			}
			delete_option( self::JOB_OPTION_PREF . $job_id );
		}

		$stats        = JsonImporter::import_chunk( $flat['versions'], $flat['equivalences'], $options );
		$final_status = (int) ( $stats['rejected'] ?? 0 ) > 0 ? 'partial' : 'completed';
		update_option(
			self::LAST_STATUS_OPT,
			array_merge(
				$stats,
				array(
					'status'         => $final_status,
					'mode'           => 'sync',
					'job_id'         => '',
					'offset'         => $total_versions,
					'total_versions' => $total_versions,
					'updated_ts'     => time(),
					'finished_gmt'   => gmdate( 'Y-m-d H:i:s' ),
				)
			),
			false
		);

		return array(
			'ok'      => true,
			'mode'    => 'sync',
			'message' => self::format_completion_message( $stats ),
			'stats'   => $stats,
		);
	}

	/**
	 * Action Scheduler callback processing a 50-version slice of a staged job.
	 *
	 * @param mixed $job_id Job identifier.
	 * @param mixed $offset Zero-based version offset.
	 */
	public function run_batch( $job_id, $offset = 0 ): void {
		$jid = sanitize_key( (string) $job_id );
		if ( '' === $jid ) {
			return;
		}
		$opt_name = self::JOB_OPTION_PREF . $jid;
		$job      = get_option( $opt_name, null );
		if ( ! is_array( $job ) || ! isset( $job['versions'] ) || ! is_array( $job['versions'] ) ) {
			return;
		}

		$start = max( 0, (int) $offset );
		$total = count( $job['versions'] );

		while ( true ) {
			$slice  = array_slice( $job['versions'], $start, self::BATCH_SIZE );
			$equivs = 0 === $start && isset( $job['equivalences'] ) && is_array( $job['equivalences'] ) ? $job['equivalences'] : array();
			$opts   = isset( $job['options'] ) && is_array( $job['options'] ) ? $job['options'] : array();

			$chunk_stats = JsonImporter::import_chunk( $slice, $equivs, $opts );
			foreach ( $chunk_stats as $k => $v ) {
				$job['stats'][ $k ] = (int) ( $job['stats'][ $k ] ?? 0 ) + $v;
			}

			$next = $start + self::BATCH_SIZE;
			if ( $next >= $total ) {
				delete_option( $opt_name );
				$final_status = (int) ( $job['stats']['rejected'] ?? 0 ) > 0 ? 'partial' : 'completed';
				update_option(
					self::LAST_STATUS_OPT,
					array_merge(
						$job['stats'],
						array(
							'status'         => $final_status,
							'mode'           => 'async',
							'job_id'         => $jid,
							'offset'         => $total,
							'total_versions' => $total,
							'updated_ts'     => time(),
							'finished_gmt'   => gmdate( 'Y-m-d H:i:s' ),
						)
					),
					false
				);
				return;
			}

			update_option( $opt_name, $job, false );
			if ( ActionScheduler::schedule( time() + 1, self::HOOK, array( $jid, $next ) ) ) {
				update_option(
					self::LAST_STATUS_OPT,
					array_merge(
						$job['stats'],
						array(
							'status'         => 'running',
							'mode'           => 'async',
							'job_id'         => $jid,
							'offset'         => $next,
							'total_versions' => $total,
							'updated_ts'     => time(),
							'finished_gmt'   => '',
						)
					),
					false
				);
				return;
			}
			$start = $next;
		}
	}

	/**
	 * Reads the last recorded import state and cleans up stale queued/running jobs older than 15 minutes.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_last_status(): array {
		$st = get_option( self::LAST_STATUS_OPT, array() );
		if ( ! is_array( $st ) || empty( $st ) ) {
			return array();
		}
		$status = (string) ( $st['status'] ?? '' );
		if ( in_array( $status, array( 'queued', 'running' ), true ) ) {
			$jid     = sanitize_key( (string) ( $st['job_id'] ?? '' ) );
			$updated = (int) ( $st['updated_ts'] ?? 0 );
			$stale   = ( time() - $updated ) > self::STALE_SECONDS;
			$has_opt = '' !== $jid && is_array( get_option( self::JOB_OPTION_PREF . $jid, null ) );
			if ( $stale || ! $has_opt ) {
				if ( '' !== $jid ) {
					delete_option( self::JOB_OPTION_PREF . $jid );
				}
				$st['status']       = 'failed';
				$st['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
				update_option( self::LAST_STATUS_OPT, $st, false );
			}
		}
		return $st;
	}

	/**
	 * Returns the currently active async import job if valid and not expired.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function active_job(): ?array {
		$st     = self::get_last_status();
		$status = (string) ( $st['status'] ?? '' );
		if ( in_array( $status, array( 'queued', 'running' ), true ) && ! empty( $st['job_id'] ) ) {
			return $st;
		}
		return null;
	}

	/**
	 * Executes the next batch of an active queued/running import immediately from admin tools.
	 */
	public static function resume_active(): bool {
		$active = self::active_job();
		if ( null === $active ) {
			return false;
		}
		$jid    = sanitize_key( (string) ( $active['job_id'] ?? '' ) );
		$offset = max( 0, (int) ( $active['offset'] ?? 0 ) );
		if ( '' === $jid ) {
			return false;
		}
		( new self() )->run_batch( $jid, $offset );
		return true;
	}

	/**
	 * Cancels an active queued/running import and removes its staged payload option.
	 */
	public static function cancel_active(): bool {
		$st = get_option( self::LAST_STATUS_OPT, array() );
		if ( ! is_array( $st ) || empty( $st['job_id'] ) ) {
			return false;
		}
		$jid = sanitize_key( (string) $st['job_id'] );
		if ( '' !== $jid ) {
			delete_option( self::JOB_OPTION_PREF . $jid );
		}
		ActionScheduler::unschedule_all( self::HOOK );
		$st['status']       = 'cancelled';
		$st['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
		$st['updated_ts']   = time();
		update_option( self::LAST_STATUS_OPT, $st, false );
		return true;
	}

	/**
	 * Builds the user-facing completion summary distinguishing full vs partial imports.
	 *
	 * @param array<string,int> $stats Import counters.
	 */
	public static function format_completion_message( array $stats ): string {
		$versions = (int) ( $stats['versions'] ?? 0 );
		$fits     = (int) ( $stats['fitments'] ?? 0 );
		$equivs   = (int) ( $stats['equivalences'] ?? 0 );
		$rejected = (int) ( $stats['rejected'] ?? 0 );
		if ( $rejected > 0 ) {
			return sprintf(
				/* translators: 1: versions count, 2: fitments count, 3: equivalences count, 4: rejected items count. */
				__( 'Partial import completed: %1$d versions, %2$d fitments, %3$d equivalences (%4$d rejected items).', 'motored-core' ),
				$versions,
				$fits,
				$equivs,
				$rejected
			);
		}
		return sprintf(
			/* translators: 1: versions count, 2: fitments count, 3: equivalences count. */
			__( 'Import completed: %1$d versions, %2$d fitments, %3$d equivalences.', 'motored-core' ),
			$versions,
			$fits,
			$equivs
		);
	}

	/**
	 * Empty import counters array.
	 *
	 * @return array{versions:int,fitments:int,equivalences:int,rejected:int}
	 */
	private static function empty_stats(): array {
		return array(
			'versions'     => 0,
			'fitments'     => 0,
			'equivalences' => 0,
			'rejected'     => 0,
		);
	}
}
