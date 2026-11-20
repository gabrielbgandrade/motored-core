<?php
/**
 * Single-attachment optimization routine (ERP-safe in-place compression or optional WebP sub-sizes).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ImageOptimizer;

use Motored\Core\Support\ImageProcessor;
use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Optimizes one existing Media Library attachment without changing original file names or public URLs by default.
 */
final class AttachmentOptimizer {

	/**
	 * Optimizes one attachment (or measures eligible bytes in dry-run mode).
	 *
	 * @param int  $attachment_id Attachment post ID.
	 * @param bool $dry_run       Measure only without modifying files or metadata.
	 * @param bool $webp_subsizes Also regenerate sub-sizes in WebP when supported.
	 * @return array{status:string,bytes_before:int,bytes_after:int,bytes_saved:int,error:string}
	 */
	public static function optimize( int $attachment_id, bool $dry_run = false, bool $webp_subsizes = false ): array {
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return self::result( 'skipped', 0, 0, 'not_attachment' );
		}
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! OptimizerRules::is_optimizable_mime( $mime ) ) {
			return self::result( 'skipped', 0, 0, '' );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) ) {
			return self::result( 'error', 0, 0, 'missing_file' );
		}

		$meta         = wp_get_attachment_metadata( $attachment_id );
		$meta         = is_array( $meta ) ? $meta : array();
		$bytes_before = self::measure_bytes( $file, $meta );

		if ( $dry_run ) {
			return self::result( 'would_optimize', $bytes_before, $bytes_before, '' );
		}

		// 0 = recompress only (default): existing banners/theme images keep their resolution.
		$max_side   = Config::bulk_resize() ? Config::max_side() : 0;
		$quality    = Config::quality();
		$strip_exif = Config::strip_exif();

		try {
			$main = ImageProcessor::optimize_in_place(
				$file,
				array(
					'max_side'   => $max_side,
					'quality'    => $quality,
					'strip_exif' => $strip_exif,
					'mime'       => $mime,
				)
			);
			if ( ! $main['ok'] ) {
				Logger::warning(
					'Image optimization failed.',
					array(
						'attachment' => $attachment_id,
						'code'       => $main['error'],
					)
				);
				return self::result( 'error', $bytes_before, $bytes_before, $main['error'] );
			}

			if ( $main['changed'] ) {
				$meta['width']    = $main['width'];
				$meta['height']   = $main['height'];
				$meta['filesize'] = $main['bytes_after'];
			}

			if ( $webp_subsizes && ImageProcessor::supports_webp() ) {
				wp_update_attachment_metadata( $attachment_id, $meta );
				$regen = self::regenerate_webp_subsizes( $attachment_id, $file, $quality, $strip_exif );
				if ( array() !== $regen ) {
					$meta = $regen;
				}
			} else {
				$meta = self::optimize_existing_subsizes( $file, $meta, $mime, $max_side, $quality, $strip_exif );
			}

			wp_update_attachment_metadata( $attachment_id, $meta );
			$bytes_after = self::measure_bytes( $file, $meta );
			$saved       = max( 0, $bytes_before - $bytes_after );

			update_post_meta(
				$attachment_id,
				UploadHooks::META_KEY,
				array(
					'v'      => 1,
					'mode'   => 'bulk',
					'at'     => time(),
					'before' => $bytes_before,
					'after'  => $bytes_after,
					'saved'  => $saved,
				)
			);

			return self::result( 'optimized', $bytes_before, $bytes_after, '' );
		} catch ( \Throwable $e ) {
			Logger::warning(
				'Image optimization exception.',
				array(
					'attachment' => $attachment_id,
					'exception'  => get_class( $e ),
				)
			);
			return self::result( 'error', $bytes_before, $bytes_before, get_class( $e ) );
		}
	}

	/**
	 * Compresses all existing generated sub-sizes in place (0 broken URLs, 100% ERP safe).
	 *
	 * @param string              $main_file     Full path to main attachment file.
	 * @param array<string,mixed> $meta          Attachment metadata.
	 * @param string              $fallback_mime Main attachment mime type.
	 * @param int                 $max_side      Maximum dimension.
	 * @param int                 $quality       Target quality.
	 * @param bool                $strip         Strip EXIF metadata.
	 * @return array<string,mixed>
	 */
	private static function optimize_existing_subsizes( string $main_file, array $meta, string $fallback_mime, int $max_side, int $quality, bool $strip ): array {
		if ( ! isset( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return $meta;
		}
		$dir = dirname( $main_file );
		foreach ( $meta['sizes'] as $slug => $row ) {
			if ( ! is_array( $row ) || empty( $row['file'] ) || ! is_string( $row['file'] ) ) {
				continue;
			}
			$sub_path = trailingslashit( $dir ) . wp_basename( $row['file'] );
			$sub_mime = isset( $row['mime-type'] ) && is_string( $row['mime-type'] ) && '' !== $row['mime-type']
				? $row['mime-type']
				: $fallback_mime;
			$res      = ImageProcessor::optimize_in_place(
				$sub_path,
				array(
					'max_side'   => $max_side,
					'quality'    => $quality,
					'strip_exif' => $strip,
					'mime'       => $sub_mime,
				)
			);
			if ( $res['ok'] && $res['changed'] ) {
				$meta['sizes'][ $slug ]['width']    = $res['width'];
				$meta['sizes'][ $slug ]['height']   = $res['height'];
				$meta['sizes'][ $slug ]['filesize'] = $res['bytes_after'];
			}
		}
		return $meta;
	}

	/**
	 * Regenerates intermediate sub-sizes using scoped WebP conversion filters without altering the main file path.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Full path to main file.
	 * @param int    $quality       Compression quality.
	 * @param bool   $strip         Strip EXIF metadata.
	 * @return array<string,mixed>
	 */
	private static function regenerate_webp_subsizes( int $attachment_id, string $file, int $quality, bool $strip ): array {
		$no_big = static fn(): bool => false;
		$fmt    = static fn( $formats ): array => OptimizerRules::output_formats(
			is_array( $formats ) ? $formats : array(),
			Config::convert_webp(),
			Config::convert_png_webp(),
			true
		);
		$qual   = static fn( $q, $mime = '' ) => in_array( strtolower( (string) $mime ), array( 'image/jpeg', 'image/webp' ), true ) ? $quality : $q;
		$ex     = static fn(): bool => $strip;
		$sz     = static fn( $sizes ): array => is_array( $sizes ) ? OptimizerRules::filter_sizes( $sizes, Config::skip_unused_sizes() ) : array();

		add_filter( 'big_image_size_threshold', $no_big, 99 );
		add_filter( 'image_editor_output_format', $fmt, 99 );
		add_filter( 'wp_editor_set_quality', $qual, 99, 2 );
		add_filter( 'image_strip_meta', $ex, 99 );
		add_filter( 'intermediate_image_sizes_advanced', $sz, 99 );
		try {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$generated = wp_generate_attachment_metadata( $attachment_id, $file );
			return is_array( $generated ) ? $generated : array();
		} finally {
			remove_filter( 'big_image_size_threshold', $no_big, 99 );
			remove_filter( 'image_editor_output_format', $fmt, 99 );
			remove_filter( 'wp_editor_set_quality', $qual, 99 );
			remove_filter( 'image_strip_meta', $ex, 99 );
			remove_filter( 'intermediate_image_sizes_advanced', $sz, 99 );
		}
	}

	/**
	 * Sums existing disk bytes of main image plus sub-sizes.
	 *
	 * @param string              $main_file Full path to main image.
	 * @param array<string,mixed> $meta      Attachment metadata.
	 */
	private static function measure_bytes( string $main_file, array $meta ): int {
		$seen  = array();
		$total = 0;
		$main  = wp_normalize_path( $main_file );
		$size  = file_exists( $main_file ) ? filesize( $main_file ) : false;
		if ( false !== $size ) {
			$seen[ $main ] = true;
			$total        += (int) $size;
		}
		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $main_file );
			foreach ( $meta['sizes'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['file'] ) || ! is_string( $row['file'] ) ) {
					continue;
				}
				$path = trailingslashit( $dir ) . wp_basename( $row['file'] );
				$norm = wp_normalize_path( $path );
				if ( isset( $seen[ $norm ] ) || ! file_exists( $path ) ) {
					continue;
				}
				$sub_size = filesize( $path );
				if ( false !== $sub_size ) {
					$seen[ $norm ] = true;
					$total        += (int) $sub_size;
				}
			}
		}
		return $total;
	}

	/**
	 * Builds normalized optimize() result.
	 *
	 * @param string $status Status slug.
	 * @param int    $before Bytes before.
	 * @param int    $after  Bytes after.
	 * @param string $error  Error code.
	 * @return array{status:string,bytes_before:int,bytes_after:int,bytes_saved:int,error:string}
	 */
	private static function result( string $status, int $before, int $after, string $error ): array {
		return array(
			'status'       => $status,
			'bytes_before' => max( 0, $before ),
			'bytes_after'  => max( 0, $after ),
			'bytes_saved'  => max( 0, $before - $after ),
			'error'        => $error,
		);
	}
}
