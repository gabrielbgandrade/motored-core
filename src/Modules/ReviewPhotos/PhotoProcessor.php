<?php
/**
 * Validates and re-encodes one uploaded photo into a Media Library attachment.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

use Motored\Core\Support\ImageProcessor;
use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress-bound half of the upload pipeline (untestable without WordPress, see docs/VERIFY.md).
 */
final class PhotoProcessor {

	/**
	 * Real type check: WordPress' filetype check plus getimagesize. Returns jpg|png|webp or null.
	 *
	 * @param string $tmp_name Temporary file.
	 * @param string $name     Original name (only used for the extension check).
	 */
	public function inspect( string $tmp_name, string $name ): ?string {
		$check = wp_check_filetype_and_ext( $tmp_name, $name, FileRules::MIMES );
		$info  = @getimagesize( $tmp_name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid files only return false.
		return FileRules::extension( $check, $info );
	}

	/**
	 * Re-encodes the photo (rotation fixed, max 1600 px, quality 82, prefers WebP when supported; drops EXIF/GPS)
	 * under a random name and creates the attachment. Returns 0 on any failure.
	 *
	 * @param string $tmp_name   Temporary file.
	 * @param string $ext        jpg|png|webp.
	 * @param int    $product_id Parent product.
	 */
	public function process( string $tmp_name, string $ext, int $product_id ): int {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$result = ImageProcessor::process_upload(
			$tmp_name,
			(string) $upload['path'],
			'review-' . bin2hex( random_bytes( 10 ) ),
			$ext,
			array(
				'max_side'    => FileRules::MAX_SIDE,
				'quality'     => FileRules::QUALITY,
				'prefer_webp' => true,
				'prefer_gd'   => true,
			)
		);
		if ( ! $result['ok'] ) {
			Logger::warning( 'Review photo: image processing failed.', array( 'code' => $result['error'] ) );
			return 0;
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $result['mime'],
				'post_title'     => 'review-photo',
				'post_status'    => 'inherit',
				'post_parent'    => $product_id,
			),
			$result['path'],
			$product_id,
			true
		);
		if ( is_wp_error( $id ) || (int) $id < 1 ) {
			wp_delete_file( $result['path'] );
			return 0;
		}
		update_post_meta( (int) $id, PhotoMeta::FLAG, '1' );

		// The comment_post request is public: the admin image helpers are not loaded there.
		// A failure while building thumbnails must never lose the attachment that already exists.
		// Only the sizes the gallery uses are generated: less CPU in this request and far less disk.
		$keep = array( FileRules::class, 'keep_sizes' );
		add_filter( 'intermediate_image_sizes_advanced', $keep );
		try {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $result['path'] ) );
		} catch ( \Throwable $e ) {
			Logger::warning( 'Review photo thumbnails failed.', array( 'exception' => get_class( $e ) ) );
		} finally {
			remove_filter( 'intermediate_image_sizes_advanced', $keep );
		}
		return (int) $id;
	}
}
