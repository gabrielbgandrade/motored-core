<?php
/**
 * Pure image optimization rules (no WordPress calls).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ImageOptimizer;

use Motored\Core\Support\ImageProcessor;

defined( 'ABSPATH' ) || exit;

/**
 * Pure MIME, sub-size, output-format and job-state normalization helpers.
 */
final class OptimizerRules {

	public const OPTIMIZABLE_MIMES = array(
		'image/jpeg',
		'image/png',
		'image/webp',
	);

	public const UNUSED_CORE_SIZES = array(
		'1536x1536',
		'2048x2048',
	);

	private const JOB_STATUSES = array(
		'idle',
		'running',
		'completed',
		'cancelled',
		'error',
	);

	/**
	 * Whether a MIME type can be optimized safely.
	 *
	 * @param string $mime Mime type.
	 */
	public static function is_optimizable_mime( string $mime ): bool {
		return in_array( strtolower( trim( $mime ) ), self::OPTIMIZABLE_MIMES, true );
	}

	/**
	 * Applies WebP output-format mapping for JPEG/PNG sub-sizes when supported.
	 *
	 * @param array<string,string> $formats              Existing mime => target mime map.
	 * @param bool                 $webp_jpeg            Convert JPEG to WebP.
	 * @param bool                 $webp_png             Convert PNG to WebP.
	 * @param bool                 $server_supports_webp Server WebP capability.
	 * @return array<string,string>
	 */
	public static function output_formats( array $formats, bool $webp_jpeg, bool $webp_png, bool $server_supports_webp ): array {
		if ( ! $server_supports_webp ) {
			return $formats;
		}
		if ( $webp_jpeg ) {
			$formats['image/jpeg'] = 'image/webp';
		}
		if ( $webp_png ) {
			$formats['image/png'] = 'image/webp';
		}
		return $formats;
	}

	/**
	 * Drops large default WordPress core intermediate sizes when configured.
	 *
	 * @param array<string,mixed> $sizes       Registered sizes keyed by name.
	 * @param bool                $skip_unused Whether to remove UNUSED_CORE_SIZES.
	 * @return array<string,mixed>
	 */
	public static function filter_sizes( array $sizes, bool $skip_unused ): array {
		if ( ! $skip_unused ) {
			return $sizes;
		}
		return array_diff_key( $sizes, array_flip( self::UNUSED_CORE_SIZES ) );
	}

	/**
	 * Whether an in-place re-encoded file should replace the original on disk (single rule: ImageProcessor).
	 *
	 * @param int  $before  Original size in bytes.
	 * @param int  $after   Re-encoded size in bytes.
	 * @param bool $resized Whether pixel dimensions were reduced.
	 */
	public static function should_keep_reencoded( int $before, int $after, bool $resized ): bool {
		return ImageProcessor::worth_keeping( $before, $after, $resized );
	}

	/**
	 * Formats byte counts cleanly for admin reporting.
	 *
	 * @param int $bytes Byte count.
	 */
	public static function format_bytes( int $bytes ): string {
		$bytes = max( 0, $bytes );
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		if ( $bytes < 1048576 ) {
			return number_format( $bytes / 1024, 1, '.', '' ) . ' KB';
		}
		return number_format( $bytes / 1048576, 2, '.', '' ) . ' MB';
	}

	/**
	 * Normalizes a persisted bulk job state array.
	 *
	 * @param mixed $raw Raw option value.
	 * @return array{status:string,run_id:string,dry_run:bool,only_unoptimized:bool,total:int,processed:int,optimized:int,skipped:int,errors:int,bytes_before:int,bytes_after:int,cursor:int,updated_ts:int,finished_gmt:string}
	 */
	public static function sanitize_job_state( $raw ): array {
		$row    = is_array( $raw ) ? $raw : array();
		$status = isset( $row['status'] ) && is_string( $row['status'] ) && in_array( $row['status'], self::JOB_STATUSES, true )
			? $row['status']
			: 'idle';

		return array(
			'status'           => $status,
			'run_id'           => isset( $row['run_id'] ) && is_string( $row['run_id'] ) ? $row['run_id'] : '',
			'dry_run'          => ! empty( $row['dry_run'] ),
			'only_unoptimized' => ! array_key_exists( 'only_unoptimized', $row ) || ! empty( $row['only_unoptimized'] ),
			'total'            => self::uint( $row['total'] ?? 0 ),
			'processed'        => self::uint( $row['processed'] ?? 0 ),
			'optimized'        => self::uint( $row['optimized'] ?? 0 ),
			'skipped'          => self::uint( $row['skipped'] ?? 0 ),
			'errors'           => self::uint( $row['errors'] ?? 0 ),
			'bytes_before'     => self::uint( $row['bytes_before'] ?? 0 ),
			'bytes_after'      => self::uint( $row['bytes_after'] ?? 0 ),
			'cursor'           => self::uint( $row['cursor'] ?? 0 ),
			'updated_ts'       => self::uint( $row['updated_ts'] ?? 0 ),
			'finished_gmt'     => isset( $row['finished_gmt'] ) && is_string( $row['finished_gmt'] ) ? $row['finished_gmt'] : '',
		);
	}

	/**
	 * Non-negative integer cast.
	 *
	 * @param mixed $val Raw scalar.
	 */
	private static function uint( $val ): int {
		return is_scalar( $val ) ? max( 0, (int) $val ) : 0;
	}
}
