<?php
/**
 * WordPress upload-time optimization filters (zero work when disabled).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ImageOptimizer;

use Motored\Core\Support\ImageProcessor;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks WordPress' native image pipeline (`big_image_size_threshold`, `wp_editor_set_quality`,
 * `image_editor_output_format`, `intermediate_image_sizes_advanced`, `image_strip_meta`,
 * and `wp_generate_attachment_metadata`). No option is read during register().
 */
final class UploadHooks {

	public const META_KEY = '_motored_img_opt';

	/**
	 * Registers upload filters (deferred option evaluation inside each callback).
	 */
	public function register(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'filter_threshold' ), 20 );
		add_filter( 'wp_editor_set_quality', array( $this, 'filter_quality' ), 20, 2 );
		add_filter( 'image_editor_output_format', array( $this, 'filter_output_format' ), 20 );
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_sizes' ), 20 );
		add_filter( 'image_strip_meta', array( $this, 'filter_strip_meta' ), 20 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'record_upload_meta' ), 99, 2 );
	}

	/**
	 * Limits max width/height of newly uploaded images via WordPress scaling.
	 *
	 * @param mixed $threshold Existing threshold in pixels (or false).
	 * @return mixed
	 */
	public function filter_threshold( $threshold ) {
		if ( ! Config::enabled() ) {
			return $threshold;
		}
		return Config::max_side();
	}

	/**
	 * Applies configured compression quality to JPEG and WebP images.
	 *
	 * @param mixed  $quality   Current quality.
	 * @param string $mime_type Target mime type.
	 * @return mixed
	 */
	public function filter_quality( $quality, string $mime_type = '' ) {
		if ( ! Config::enabled() ) {
			return $quality;
		}
		if ( in_array( strtolower( $mime_type ), array( 'image/jpeg', 'image/webp' ), true ) ) {
			return Config::quality();
		}
		return $quality;
	}

	/**
	 * Maps JPEG/PNG sub-sizes to WebP when configured and supported by the server.
	 *
	 * @param mixed $formats Existing output mime map.
	 * @return array<string,string>
	 */
	public function filter_output_format( $formats ): array {
		$map = is_array( $formats ) ? $formats : array();
		if ( ! Config::enabled() ) {
			return $map;
		}
		return OptimizerRules::output_formats(
			$map,
			Config::convert_webp(),
			Config::convert_png_webp(),
			ImageProcessor::supports_webp()
		);
	}

	/**
	 * Optionally drops 1536x1536 and 2048x2048 generated sub-sizes on new uploads.
	 *
	 * @param mixed $sizes Registered sizes array.
	 * @return array<string,mixed>
	 */
	public function filter_sizes( $sizes ): array {
		if ( ! is_array( $sizes ) ) {
			return array();
		}
		if ( ! Config::enabled() ) {
			return $sizes;
		}
		return OptimizerRules::filter_sizes( $sizes, Config::skip_unused_sizes() );
	}

	/**
	 * Strips EXIF/IPTC metadata when enabled.
	 *
	 * @param mixed $strip Current strip policy.
	 * @return mixed
	 */
	public function filter_strip_meta( $strip ) {
		if ( ! Config::enabled() ) {
			return $strip;
		}
		return Config::strip_exif() ? true : $strip;
	}

	/**
	 * Marks newly uploaded optimizable attachments as processed so bulk scans skip them later.
	 *
	 * @param mixed $metadata      Attachment metadata.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function record_upload_meta( $metadata, $attachment_id ) {
		$id = is_scalar( $attachment_id ) ? (int) $attachment_id : 0;
		if ( ! is_array( $metadata ) || $id < 1 || ! Config::enabled() ) {
			return $metadata;
		}
		$mime = (string) get_post_mime_type( $id );
		if ( ! OptimizerRules::is_optimizable_mime( $mime ) ) {
			return $metadata;
		}
		update_post_meta(
			$id,
			self::META_KEY,
			array(
				'v'     => 1,
				'mode'  => 'upload',
				'at'    => time(),
				'saved' => 0,
			)
		);
		return $metadata;
	}
}
