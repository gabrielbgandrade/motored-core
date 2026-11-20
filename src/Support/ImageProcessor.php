<?php
/**
 * Shared image processing helpers (EXIF strip, resize, re-encode, WebP check).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Used by ReviewPhotos, InstallGallery and ImageOptimizer so every image pipeline shares
 * one tested path over WordPress' native WP_Image_Editor.
 */
final class ImageProcessor {

	public const SUPPORTED_MIMES  = array( 'image/jpeg', 'image/png', 'image/webp' );
	public const DEFAULT_QUALITY  = 82;
	public const DEFAULT_MAX_SIDE = 1600;

	/**
	 * Minimum relative saving (3%) to replace a file that was not resized.
	 */
	public const MIN_GAIN_RATIO = 0.03;

	/**
	 * Mime map from canonical extension.
	 *
	 * @var array<string,string>
	 */
	private const EXT_TO_MIME = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);

	/**
	 * Canonical extension from mime type.
	 *
	 * @var array<string,string>
	 */
	private const MIME_TO_EXT = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	/**
	 * Whether the active WordPress image editor stack supports saving WebP.
	 */
	public static function supports_webp(): bool {
		if ( function_exists( 'wp_image_editor_supports' ) ) {
			return (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		}
		return function_exists( 'imagewebp' );
	}

	/**
	 * Active image editor library label (`GD`, `Imagick`, or empty string when unavailable).
	 */
	public static function active_editor_name(): string {
		if ( function_exists( 'wp_image_editor_supports' ) ) {
			wp_image_editor_supports( array() );
		}
		if ( class_exists( \WP_Image_Editor_Imagick::class ) && \WP_Image_Editor_Imagick::test() ) {
			return 'Imagick';
		}
		if ( class_exists( \WP_Image_Editor_GD::class ) && \WP_Image_Editor_GD::test() ) {
			return 'GD';
		}
		if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
			return 'Imagick';
		}
		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' ) ) {
			return 'GD';
		}
		return '';
	}

	/**
	 * Proportional target dimensions when the longest side exceeds $max_side, null otherwise.
	 *
	 * @param int $width    Current width.
	 * @param int $height   Current height.
	 * @param int $max_side Longest allowed side in pixels.
	 * @return array{0:int,1:int}|null
	 */
	public static function target_size( int $width, int $height, int $max_side = self::DEFAULT_MAX_SIDE ): ?array {
		$longest = max( $width, $height );
		if ( $max_side < 1 || $longest <= $max_side || $width < 1 || $height < 1 ) {
			return null;
		}
		$ratio = $max_side / $longest;
		return array(
			max( 1, (int) round( $width * $ratio ) ),
			max( 1, (int) round( $height * $ratio ) ),
		);
	}

	/**
	 * Keeps only whitelisted registered image sizes (for `intermediate_image_sizes_advanced`).
	 *
	 * @param mixed             $sizes   Registered sizes keyed by slug.
	 * @param array<int,string> $allowed Allowed size slugs.
	 * @return array<string,mixed>
	 */
	public static function keep_sizes( $sizes, array $allowed ): array {
		return is_array( $sizes ) ? array_intersect_key( $sizes, array_flip( $allowed ) ) : array();
	}

	/**
	 * Opens an image in WP_Image_Editor. When $prefer_gd is true, puts GD first while keeping
	 * Imagick/others as fallback so Imagick-only servers still work.
	 *
	 * @param string $path      Path to image file.
	 * @param bool   $prefer_gd Put WP_Image_Editor_GD first.
	 * @return \WP_Image_Editor|\WP_Error
	 */
	public static function load_editor( string $path, bool $prefer_gd = true ) {
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			return new \WP_Error( 'missing_wp_editor', 'wp_get_image_editor is unavailable.' );
		}
		if ( ! $prefer_gd ) {
			return wp_get_image_editor( $path );
		}

		$prefer = static function ( $editors ): array {
			$editors = is_array( $editors ) ? $editors : array();
			return array_values( array_unique( array_merge( array( 'WP_Image_Editor_GD' ), $editors ) ) );
		};
		add_filter( 'wp_image_editors', $prefer, 99 );
		try {
			return wp_get_image_editor( $path );
		} finally {
			remove_filter( 'wp_image_editors', $prefer, 99 );
		}
	}

	/**
	 * Re-encodes one uploaded image (EXIF rotate, resize, quality, optional WebP) into $dest_dir.
	 *
	 * @param string              $tmp_path   Uploaded temp file.
	 * @param string              $dest_dir   Destination directory.
	 * @param string              $base_name  Filename stem (without extension).
	 * @param string              $source_ext Canonical extension (jpg|jpeg|png|webp).
	 * @param array<string,mixed> $opts       Options: max_side, quality, prefer_webp, prefer_gd.
	 * @return array{ok:bool,path:string,mime:string,ext:string,error:string}
	 */
	public static function process_upload( string $tmp_path, string $dest_dir, string $base_name, string $source_ext, array $opts = array() ): array {
		$max_side    = isset( $opts['max_side'] ) && is_numeric( $opts['max_side'] ) ? max( 1, (int) $opts['max_side'] ) : self::DEFAULT_MAX_SIDE;
		$quality     = isset( $opts['quality'] ) && is_numeric( $opts['quality'] ) ? max( 1, min( 100, (int) $opts['quality'] ) ) : self::DEFAULT_QUALITY;
		$prefer_webp = ! empty( $opts['prefer_webp'] );
		$prefer_gd   = ! array_key_exists( 'prefer_gd', $opts ) || (bool) $opts['prefer_gd'];

		$source_ext = strtolower( $source_ext );
		if ( 'jpeg' === $source_ext ) {
			$source_ext = 'jpg';
		}
		$source_mime = self::EXT_TO_MIME[ $source_ext ] ?? '';
		if ( '' === $source_mime ) {
			return self::upload_error( 'unsupported_extension' );
		}

		$editor = self::load_editor( $tmp_path, $prefer_gd );
		if ( is_wp_error( $editor ) ) {
			return self::upload_error( (string) $editor->get_error_code() );
		}

		if ( is_callable( array( $editor, 'maybe_exif_rotate' ) ) ) {
			$editor->maybe_exif_rotate();
		}
		$size   = $editor->get_size();
		$target = is_array( $size ) ? self::target_size( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ), $max_side ) : null;
		if ( null !== $target && is_wp_error( $editor->resize( $target[0], $target[1], false ) ) ) {
			return self::upload_error( 'resize_failed' );
		}
		$editor->set_quality( $quality );

		$target_ext  = $source_ext;
		$target_mime = $source_mime;
		if ( $prefer_webp && 'webp' !== $source_ext && self::supports_webp() ) {
			$target_ext  = 'webp';
			$target_mime = 'image/webp';
		}

		$filename = wp_unique_filename( $dest_dir, $base_name . '.' . $target_ext );
		$dest     = trailingslashit( $dest_dir ) . $filename;
		$saved    = self::save_with_exif_policy( $editor, $dest, $target_mime, true );

		// Fallback to original format if WebP save failed cleanly.
		if ( ( is_wp_error( $saved ) || empty( $saved['path'] ) ) && $target_mime !== $source_mime ) {
			$target_ext  = $source_ext;
			$target_mime = $source_mime;
			$filename    = wp_unique_filename( $dest_dir, $base_name . '.' . $target_ext );
			$dest        = trailingslashit( $dest_dir ) . $filename;
			$saved       = self::save_with_exif_policy( $editor, $dest, $target_mime, true );
		}

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return self::upload_error( is_wp_error( $saved ) ? (string) $saved->get_error_code() : 'save_empty' );
		}

		$actual_mime = isset( $saved['mime-type'] ) && is_string( $saved['mime-type'] ) && '' !== $saved['mime-type']
			? $saved['mime-type']
			: $target_mime;
		$actual_ext  = self::MIME_TO_EXT[ $actual_mime ] ?? $target_ext;

		return array(
			'ok'    => true,
			'path'  => (string) $saved['path'],
			'mime'  => $actual_mime,
			'ext'   => $actual_ext,
			'error' => '',
		);
	}

	/**
	 * Optimizes a file in place WITHOUT changing its path, filename, extension or public URL (ERP safe).
	 * Replaces the file only when the new file is strictly smaller (or same size after downscale).
	 *
	 * @param string              $file_path Full filesystem path to image.
	 * @param array<string,mixed> $opts      Options: max_side, quality, strip_exif, mime.
	 * @return array{ok:bool,changed:bool,resized:bool,width:int,height:int,bytes_before:int,bytes_after:int,error:string}
	 */
	public static function optimize_in_place( string $file_path, array $opts = array() ): array {
		if ( '' === $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return self::inplace_result( false, false, false, 0, 0, 0, 0, 'missing_file' );
		}
		$before = filesize( $file_path );
		if ( false === $before || $before < 1 ) {
			return self::inplace_result( false, false, false, 0, 0, 0, 0, 'unreadable_size' );
		}

		// 0 = never resize (recompress only); see target_size().
		$max_side   = isset( $opts['max_side'] ) && is_numeric( $opts['max_side'] ) ? max( 0, (int) $opts['max_side'] ) : self::DEFAULT_MAX_SIDE;
		$quality    = isset( $opts['quality'] ) && is_numeric( $opts['quality'] ) ? max( 1, min( 100, (int) $opts['quality'] ) ) : self::DEFAULT_QUALITY;
		$strip_exif = ! array_key_exists( 'strip_exif', $opts ) || (bool) $opts['strip_exif'];
		$mime       = isset( $opts['mime'] ) && is_string( $opts['mime'] ) ? $opts['mime'] : '';

		if ( '' === $mime ) {
			$check = wp_check_filetype( $file_path );
			$mime  = is_array( $check ) && isset( $check['type'] ) && is_string( $check['type'] ) ? $check['type'] : '';
		}
		if ( ! in_array( $mime, self::SUPPORTED_MIMES, true ) ) {
			return self::inplace_result( true, false, false, 0, 0, (int) $before, (int) $before, '' );
		}

		$editor = self::load_editor( $file_path, false );
		if ( is_wp_error( $editor ) ) {
			return self::inplace_result( false, false, false, 0, 0, (int) $before, (int) $before, (string) $editor->get_error_code() );
		}

		if ( is_callable( array( $editor, 'maybe_exif_rotate' ) ) ) {
			$editor->maybe_exif_rotate();
		}
		$size    = $editor->get_size();
		$orig_w  = is_array( $size ) ? (int) ( $size['width'] ?? 0 ) : 0;
		$orig_h  = is_array( $size ) ? (int) ( $size['height'] ?? 0 ) : 0;
		$target  = self::target_size( $orig_w, $orig_h, $max_side );
		$resized = false;
		if ( null !== $target ) {
			$res = $editor->resize( $target[0], $target[1], false );
			if ( is_wp_error( $res ) ) {
				return self::inplace_result( false, false, false, $orig_w, $orig_h, (int) $before, (int) $before, (string) $res->get_error_code() );
			}
			$resized = true;
		}
		$editor->set_quality( $quality );

		$dir      = dirname( $file_path );
		$ext      = self::MIME_TO_EXT[ $mime ];
		$tmp_name = wp_unique_filename( $dir, 'motored-opt-' . bin2hex( random_bytes( 6 ) ) . '.' . $ext );
		$tmp_path = trailingslashit( $dir ) . $tmp_name;

		$saved = self::save_with_exif_policy( $editor, $tmp_path, $mime, $strip_exif );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
			return self::inplace_result(
				false,
				false,
				false,
				$orig_w,
				$orig_h,
				(int) $before,
				(int) $before,
				is_wp_error( $saved ) ? (string) $saved->get_error_code() : 'save_empty'
			);
		}

		$saved_path = (string) $saved['path'];
		$saved_mime = isset( $saved['mime-type'] ) && is_string( $saved['mime-type'] ) ? $saved['mime-type'] : $mime;
		if ( $saved_mime !== $mime || str_replace( '\\', '/', $saved_path ) !== str_replace( '\\', '/', $tmp_path ) ) {
			// Never swap a different format (or an unexpected file) behind the original URL.
			if ( file_exists( $saved_path ) ) {
				wp_delete_file( $saved_path );
			}
			return self::inplace_result( false, false, false, $orig_w, $orig_h, (int) $before, (int) $before, 'format_changed' );
		}
		$after = file_exists( $saved_path ) ? filesize( $saved_path ) : false;
		if ( false === $after || $after < 1 ) {
			if ( file_exists( $saved_path ) ) {
				wp_delete_file( $saved_path );
			}
			return self::inplace_result( false, false, false, $orig_w, $orig_h, (int) $before, (int) $before, 'empty_output' );
		}

		if ( ! self::worth_keeping( (int) $before, (int) $after, $resized ) ) {
			wp_delete_file( $saved_path );
			return self::inplace_result( true, false, false, $orig_w, $orig_h, (int) $before, (int) $before, '' );
		}

		// Atomic same-directory rename keeps the exact path & URL intact.
		if ( ! @rename( $saved_path, $file_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Local temp file atomic swap.
			wp_delete_file( $saved_path );
			return self::inplace_result( false, false, false, $orig_w, $orig_h, (int) $before, (int) $before, 'rename_failed' );
		}

		$final_size = $editor->get_size();
		$final_w    = is_array( $final_size ) ? (int) ( $final_size['width'] ?? $orig_w ) : $orig_w;
		$final_h    = is_array( $final_size ) ? (int) ( $final_size['height'] ?? $orig_h ) : $orig_h;

		return self::inplace_result( true, true, $resized, $final_w, $final_h, (int) $before, (int) $after, '' );
	}

	/**
	 * Calls `$editor->save()` under a scoped `image_strip_meta` filter.
	 *
	 * @param \WP_Image_Editor $editor     Editor instance.
	 * @param string           $dest       Destination path.
	 * @param string           $mime       Target mime type.
	 * @param bool             $strip_exif Strip EXIF/IPTC/ICC metadata.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function save_with_exif_policy( \WP_Image_Editor $editor, string $dest, string $mime, bool $strip_exif ) {
		$filter = static fn(): bool => $strip_exif;
		// The caller already chose the output MIME. Without this, an active `image_editor_output_format` mapping
		// (e.g. ImageOptimizer "JPEG -> WebP") would re-route the save and an in-place swap could put WebP bytes
		// behind a .jpg URL.
		$no_map = static fn(): array => array();
		add_filter( 'image_strip_meta', $filter, 99 );
		add_filter( 'image_editor_output_format', $no_map, PHP_INT_MAX );
		try {
			return $editor->save( $dest, $mime );
		} finally {
			remove_filter( 'image_strip_meta', $filter, 99 );
			remove_filter( 'image_editor_output_format', $no_map, PHP_INT_MAX );
		}
	}

	/**
	 * Whether a re-encoded file is worth keeping: resized down (never larger), or at least MIN_GAIN_RATIO smaller.
	 * Avoids generation loss for negligible savings on already-compressed images.
	 *
	 * @param int  $before  Original bytes.
	 * @param int  $after   Re-encoded bytes.
	 * @param bool $resized Whether pixels were scaled down.
	 */
	public static function worth_keeping( int $before, int $after, bool $resized ): bool {
		if ( $before < 1 || $after < 1 ) {
			return false;
		}
		if ( $resized ) {
			return $after <= $before;
		}
		return $after <= (int) floor( $before * ( 1 - self::MIN_GAIN_RATIO ) );
	}

	/**
	 * Builds a failed process_upload() result.
	 *
	 * @param string $code Error code.
	 * @return array{ok:false,path:string,mime:string,ext:string,error:string}
	 */
	private static function upload_error( string $code ): array {
		return array(
			'ok'    => false,
			'path'  => '',
			'mime'  => '',
			'ext'   => '',
			'error' => $code,
		);
	}

	/**
	 * Builds an optimize_in_place() result.
	 *
	 * @param bool   $ok           Operation succeeded.
	 * @param bool   $changed      File was replaced on disk.
	 * @param bool   $resized      Dimensions changed.
	 * @param int    $width        Result width.
	 * @param int    $height       Result height.
	 * @param int    $bytes_before Bytes before.
	 * @param int    $bytes_after  Bytes after.
	 * @param string $error        Error code if failed.
	 * @return array{ok:bool,changed:bool,resized:bool,width:int,height:int,bytes_before:int,bytes_after:int,error:string}
	 */
	private static function inplace_result( bool $ok, bool $changed, bool $resized, int $width, int $height, int $bytes_before, int $bytes_after, string $error ): array {
		return array(
			'ok'           => $ok,
			'changed'      => $changed,
			'resized'      => $resized,
			'width'        => $width,
			'height'       => $height,
			'bytes_before' => $bytes_before,
			'bytes_after'  => $bytes_after,
			'error'        => $error,
		);
	}
}
