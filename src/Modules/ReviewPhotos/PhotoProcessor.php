<?php
/**
 * Validates and re-encodes one uploaded photo into a Media Library attachment.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

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
	 * Re-encodes the photo (rotation fixed, max 1600 px, quality 82; GD re-encoding drops EXIF/GPS)
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
		$mime = FileRules::mime( $ext );

		// GD always rewrites the pixels and never copies metadata; Imagick might keep EXIF.
		$only_gd = static fn(): array => array( 'WP_Image_Editor_GD' );
		add_filter( 'wp_image_editors', $only_gd );
		$editor  = wp_get_image_editor( $tmp_name );
		remove_filter( 'wp_image_editors', $only_gd );
		if ( is_wp_error( $editor ) ) {
			return 0;
		}

		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		$size   = $editor->get_size();
		$target = is_array( $size ) ? FileRules::target_size( (int) $size['width'], (int) $size['height'] ) : null;
		if ( null !== $target && is_wp_error( $editor->resize( $target[0], $target[1], false ) ) ) {
			return 0;
		}
		$editor->set_quality( FileRules::QUALITY );

		$filename = wp_unique_filename( $upload['path'], 'review-' . bin2hex( random_bytes( 10 ) ) . '.' . $ext );
		$saved    = $editor->save( trailingslashit( $upload['path'] ) . $filename, $mime );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return 0;
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'review-photo',
				'post_status'    => 'inherit',
				'post_parent'    => $product_id,
			),
			$saved['path'],
			$product_id,
			true
		);
		if ( is_wp_error( $id ) || (int) $id < 1 ) {
			wp_delete_file( $saved['path'] );
			return 0;
		}
		update_post_meta( (int) $id, PhotoMeta::FLAG, '1' );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $saved['path'] ) );
		return (int) $id;
	}
}
