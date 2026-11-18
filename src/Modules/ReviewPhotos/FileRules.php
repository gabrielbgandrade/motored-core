<?php
/**
 * Pure upload rules (no WordPress calls).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes $_FILES, applies count/size limits and decides the image type from detected data.
 */
final class FileRules {

	public const MIMES      = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);
	public const MAX_PIXELS = 40000000;
	public const MAX_SIDE   = 1600;
	public const QUALITY    = 82;

	/**
	 * Detected image type => canonical extension and mime.
	 */
	private const TYPES = array(
		2  => array( 'jpg', 'image/jpeg' ),
		3  => array( 'png', 'image/png' ),
		18 => array( 'webp', 'image/webp' ),
	);

	/**
	 * Turns the $_FILES entry of a multiple input into a list of files (empty slots dropped).
	 *
	 * @param mixed $field $_FILES['name'].
	 * @return list<array{name:string,tmp_name:string,size:int,error:int}>
	 */
	public static function normalize( $field ): array {
		if ( ! is_array( $field ) || ! isset( $field['name'], $field['tmp_name'], $field['size'], $field['error'] ) ) {
			return array();
		}
		$names = (array) $field['name'];
		$files = array();
		foreach ( array_keys( $names ) as $i ) {
			$error = (int) ( (array) $field['error'] )[ $i ];
			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}
			$files[] = array(
				'name'     => (string) $names[ $i ],
				'tmp_name' => (string) ( (array) $field['tmp_name'] )[ $i ],
				'size'     => (int) ( (array) $field['size'] )[ $i ],
				'error'    => $error,
			);
		}
		return $files;
	}

	/**
	 * Keeps the files that may be processed: at most min( $max, $allowance ), no upload errors,
	 * not empty, not above $max_bytes. Order is kept; the rest counts as rejected.
	 *
	 * @param list<array{name:string,tmp_name:string,size:int,error:int}> $files     Files.
	 * @param int                                                         $max       Photos per review.
	 * @param int                                                         $max_bytes Bytes per photo.
	 * @param int                                                         $allowance Photos still allowed for this visitor.
	 * @return array{accepted:list<array{name:string,tmp_name:string,size:int,error:int}>,rejected:int}
	 */
	public static function select( array $files, int $max, int $max_bytes, int $allowance ): array {
		$limit    = max( 0, min( $max, $allowance ) );
		$accepted = array();
		foreach ( $files as $file ) {
			if ( count( $accepted ) >= $limit ) {
				break;
			}
			if ( UPLOAD_ERR_OK === $file['error'] && $file['size'] > 0 && $file['size'] <= $max_bytes && '' !== $file['tmp_name'] ) {
				$accepted[] = $file;
			}
		}
		return array(
			'accepted' => $accepted,
			'rejected' => count( $files ) - count( $accepted ),
		);
	}

	/**
	 * Canonical extension when WordPress' file check AND the real image data agree on JPEG/PNG/WebP
	 * (anything else, such as SVG or GIF, returns null).
	 *
	 * @param mixed $check wp_check_filetype_and_ext() result.
	 * @param mixed $info  getimagesize() result.
	 */
	public static function extension( $check, $info ): ?string {
		if ( ! is_array( $check ) || ! is_array( $info ) || ! isset( $info[0], $info[1], $info[2] ) ) {
			return null;
		}
		$type = self::TYPES[ (int) $info[2] ] ?? null;
		if ( null === $type || ( $check['type'] ?? false ) !== $type[1] || ! in_array( $check['ext'] ?? false, array( 'jpg', 'jpeg', 'jpe', 'png', 'webp' ), true ) ) {
			return null;
		}
		$width  = (int) $info[0];
		$height = (int) $info[1];
		if ( $width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS ) {
			return null;
		}
		return $type[0];
	}

	/**
	 * Mime type of a canonical extension.
	 *
	 * @param string $ext jpg|png|webp.
	 */
	public static function mime( string $ext ): string {
		foreach ( self::TYPES as $type ) {
			if ( $type[0] === $ext ) {
				return $type[1];
			}
		}
		return '';
	}

	/**
	 * New size when the image exceeds MAX_SIDE (keeps the ratio), null when it can stay as is.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return array{0:int,1:int}|null
	 */
	public static function target_size( int $width, int $height ): ?array {
		$longest = max( $width, $height );
		if ( $longest <= self::MAX_SIDE || $width < 1 || $height < 1 ) {
			return null;
		}
		$ratio = self::MAX_SIDE / $longest;
		return array( max( 1, (int) round( $width * $ratio ) ), max( 1, (int) round( $height * $ratio ) ) );
	}
}
