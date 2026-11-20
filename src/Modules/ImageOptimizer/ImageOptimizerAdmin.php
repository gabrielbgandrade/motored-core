<?php
/**
 * Admin interface for ImageOptimizer: WooCommerce > Settings > Motored > Images + Status row.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ImageOptimizer;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Support\Cache;
use Motored\Core\Support\ImageProcessor;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the shared motored-admin.css classes only (no custom CSS or inline styles).
 */
final class ImageOptimizerAdmin {

	public const SECTION       = 'images';
	public const ACTION_DRY    = 'motored_img_opt_dry_run';
	public const ACTION_START  = 'motored_img_opt_start';
	public const ACTION_RESUME = 'motored_img_opt_resume';
	public const ACTION_CANCEL = 'motored_img_opt_cancel';
	public const NONCE_ACTION  = 'motored_img_opt_tools';
	public const NONCE_FIELD   = 'motored_img_opt_nonce';
	private const BUCKET       = 'img_opt_admin';

	/**
	 * Registers settings section, bulk action endpoints and status row.
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'Images', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			65
		);
		add_action( 'woocommerce_admin_field_motored_image_optimizer_tools', array( $this, 'render_tools' ) );
		add_action( 'admin_post_' . self::ACTION_DRY, array( $this, 'handle_dry_run' ) );
		add_action( 'admin_post_' . self::ACTION_START, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::ACTION_RESUME, array( $this, 'handle_resume' ) );
		add_action( 'admin_post_' . self::ACTION_CANCEL, array( $this, 'handle_cancel' ) );
		add_filter( 'motored_status_rows', array( $this, 'status_rows' ) );
	}

	/**
	 * Settings fields for the "Images" section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		$editor = ImageProcessor::active_editor_name();
		$webp   = ImageProcessor::supports_webp() ? __( 'Yes', 'motored-core' ) : __( 'No', 'motored-core' );
		$desc   = sprintf(
			/* translators: 1: active image editor (GD/Imagick/None), 2: WebP support (Yes/No). */
			__( 'Optimizes newly uploaded and existing Media Library images with WordPress native tools. Server editor: %1$s · WebP supported: %2$s.', 'motored-core' ),
			'' !== $editor ? $editor : __( 'None', 'motored-core' ),
			$webp
		);

		return array(
			array(
				'title' => __( 'Image optimization', 'motored-core' ),
				'type'  => 'title',
				'desc'  => $desc,
				'id'    => 'motored_image_optimizer_title',
			),
			array(
				'title'    => __( 'Enable upload optimization', 'motored-core' ),
				'desc'     => __( 'Automatically compress, resize and convert new Media Library uploads.', 'motored-core' ),
				'id'       => 'motored_image_optimizer[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Maximum longest side (px)', 'motored-core' ),
				'desc_tip'          => __( 'Images larger than this are scaled down proportionally (800-3000 px).', 'motored-core' ),
				'id'                => 'motored_image_optimizer[max_side]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['max_side'],
				'custom_attributes' => array(
					'min'  => '800',
					'max'  => '3000',
					'step' => '10',
				),
				'autoload'          => false,
			),
			array(
				'title'             => __( 'JPEG / WebP quality', 'motored-core' ),
				'desc_tip'          => __( 'Balanced web quality is 80-84 (60-95).', 'motored-core' ),
				'id'                => 'motored_image_optimizer[quality]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['quality'],
				'custom_attributes' => array(
					'min'  => '60',
					'max'  => '95',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'    => __( 'Convert new JPEG sub-sizes to WebP', 'motored-core' ),
				'desc'     => __( 'Generate WebP thumbnails and catalog sizes on new JPEG uploads when the server supports WebP.', 'motored-core' ),
				'id'       => 'motored_image_optimizer[convert_webp]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['convert_webp'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Convert new PNG sub-sizes to WebP', 'motored-core' ),
				'desc'     => __( 'Also generate WebP sub-sizes for new PNG uploads (optional).', 'motored-core' ),
				'id'       => 'motored_image_optimizer[convert_png_webp]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['convert_png_webp'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Strip EXIF metadata', 'motored-core' ),
				'desc'     => __( 'Remove camera EXIF/GPS data during optimization.', 'motored-core' ),
				'id'       => 'motored_image_optimizer[strip_exif]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['strip_exif'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Skip 1536px and 2048px sizes on upload', 'motored-core' ),
				'desc'     => __( 'Prevent WordPress from generating extra 1536x1536 and 2048x2048 files to save disk space.', 'motored-core' ),
				'id'       => 'motored_image_optimizer[skip_unused_sizes]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['skip_unused_sizes'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Bulk batch size', 'motored-core' ),
				'desc_tip'          => __( 'Number of attachments processed per background Action Scheduler batch (5-25). Keep low on shared or busy servers.', 'motored-core' ),
				'id'                => 'motored_image_optimizer[bulk_batch_size]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['bulk_batch_size'],
				'custom_attributes' => array(
					'min'  => '5',
					'max'  => '25',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'    => __( 'Convert sub-sizes to WebP during bulk run', 'motored-core' ),
				'desc'     => __( 'Warning: regenerates sub-size URLs to .webp, which may differ from cached or external ERP links. Leave unchecked to keep every file name and URL unchanged.', 'motored-core' ),
				'id'       => 'motored_image_optimizer[bulk_webp_subsizes]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['bulk_webp_subsizes'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Scale down existing originals during bulk run', 'motored-core' ),
				'desc'     => __( 'Also shrink existing images larger than the maximum side. Leave unchecked to only recompress (wide banners and theme images keep their resolution).', 'motored-core' ),
				'id'       => 'motored_image_optimizer[bulk_resize]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['bulk_resize'],
				'autoload' => false,
			),
			// No id on purpose: WooCommerce skips id-less fields when saving.
			array( 'type' => 'motored_image_optimizer_tools' ),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_image_optimizer_title',
			),
		);
	}

	/**
	 * Renders the Media Library stats + Action Scheduler bulk controls inside `.motored-box`.
	 */
	public function render_tools(): void {
		$stats  = BulkRunner::library_stats();
		$job    = BulkRunner::status();
		$notice = $this->pull_notice();

		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Bulk optimization & library status', 'motored-core' ) . '</th><td class="forminp"><div class="motored-box">';
		if ( null !== $notice ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				esc_attr( $notice['ok'] ? 'success' : 'error' ),
				esc_html( $notice['text'] )
			);
		}

		echo '<div class="motored-box__stats">';
		echo '<span class="motored-box__stat"><strong>' . esc_html__( 'Images:', 'motored-core' ) . '</strong> ' . (int) $stats['total'] . '</span>';
		echo '<span class="motored-box__stat"><strong>' . esc_html__( 'Optimized:', 'motored-core' ) . '</strong> ' . (int) $stats['optimized'] . '</span>';
		echo '<span class="motored-box__stat"><strong>' . esc_html__( 'Pending:', 'motored-core' ) . '</strong> ' . (int) $stats['pending'] . '</span>';
		echo '<span class="motored-box__stat"><strong>' . esc_html__( 'Space saved:', 'motored-core' ) . '</strong> ' . esc_html( OptimizerRules::format_bytes( $stats['bytes_saved'] ) ) . '</span>';
		echo '</div>';

		if ( 'idle' !== $job['status'] ) {
			$saved = max( 0, $job['bytes_before'] - $job['bytes_after'] );
			$mode  = $job['dry_run'] ? __( 'Test scan (dry run)', 'motored-core' ) : __( 'Live compression', 'motored-core' );
			echo '<p class="motored-box__preview"><strong>' . esc_html( $mode ) . ':</strong> ';
			echo esc_html(
				sprintf(
					/* translators: 1: status, 2: processed count, 3: total count, 4: optimized/eligible count, 5: error count, 6: saved bytes formatted. */
					__( 'Status %1$s — %2$d / %3$d processed (%4$d OK, %5$d errors, %6$s saved).', 'motored-core' ),
					self::status_label( $job['status'] ),
					$job['processed'],
					$job['total'],
					$job['optimized'],
					$job['errors'],
					OptimizerRules::format_bytes( $saved )
				)
			);
			echo '</p>';
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );
		$post_url = esc_url( admin_url( 'admin-post.php' ) );
		echo '<p class="motored-actions">';
		printf(
			'<button type="submit" class="button" name="action" value="%1$s" formmethod="post" formaction="%2$s">%3$s</button> ',
			esc_attr( self::ACTION_DRY ),
			$post_url, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_html__( 'Run test scan (dry run)', 'motored-core' )
		);
		printf(
			'<button type="submit" class="button button-primary" name="action" value="%1$s" formmethod="post" formaction="%2$s">%3$s</button> ',
			esc_attr( self::ACTION_START ),
			$post_url, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_html__( 'Optimize pending images', 'motored-core' )
		);
		if ( 'running' === $job['status'] && ( time() - $job['updated_ts'] ) >= BulkRunner::STALE_SECONDS ) {
			printf(
				'<button type="submit" class="button" name="action" value="%1$s" formmethod="post" formaction="%2$s">%3$s</button> ',
				esc_attr( self::ACTION_RESUME ),
				$post_url, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				esc_html__( 'Resume job', 'motored-core' )
			);
		}
		if ( 'running' === $job['status'] ) {
			printf(
				'<button type="submit" class="button" name="action" value="%1$s" formmethod="post" formaction="%2$s">%3$s</button>',
				esc_attr( self::ACTION_CANCEL ),
				$post_url, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				esc_html__( 'Cancel job', 'motored-core' )
			);
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Default bulk mode compresses images in place, keeping every filename and URL intact so ERP integrations and cached links stay valid. Runs asynchronously in small batches.', 'motored-core' ) . '</p>';
		echo '</div></td></tr>';
	}

	/**
	 * Starts a dry-run scan across unoptimized attachments.
	 */
	public function handle_dry_run(): void {
		$this->guard();
		$res = BulkRunner::start( true, true );
		$this->finish( $res['ok'], $res['message'] );
	}

	/**
	 * Starts live bulk optimization across unoptimized attachments.
	 */
	public function handle_start(): void {
		$this->guard();
		$res = BulkRunner::start( false, true );
		$this->finish( $res['ok'], $res['message'] );
	}

	/**
	 * Resumes a paused/stale optimization job.
	 */
	public function handle_resume(): void {
		$this->guard();
		$res = BulkRunner::resume();
		$this->finish( $res['ok'], $res['message'] );
	}

	/**
	 * Cancels an active optimization job.
	 */
	public function handle_cancel(): void {
		$this->guard();
		$res = BulkRunner::cancel();
		$this->finish( $res['ok'], $res['message'] );
	}

	/**
	 * Adds one summary row to WooCommerce > Settings > Motored > Status.
	 *
	 * @param mixed $rows Existing status rows.
	 * @return array<string,string>
	 */
	public function status_rows( $rows ): array {
		$rows = is_array( $rows ) ? $rows : array();
		if ( ! Config::enabled() ) {
			$rows[ __( 'Image optimization', 'motored-core' ) ] = __( 'Off', 'motored-core' );
			return $rows;
		}
		$editor = ImageProcessor::active_editor_name();
		$webp   = ImageProcessor::supports_webp() && Config::convert_webp() ? 'WebP' : __( 'Original format', 'motored-core' );

		$rows[ __( 'Image optimization', 'motored-core' ) ] = sprintf(
			/* translators: 1: output mode (WebP/Original format), 2: editor name (GD/Imagick). */
			__( 'Active (%1$s / %2$s)', 'motored-core' ),
			$webp,
			'' !== $editor ? $editor : __( 'None', 'motored-core' )
		);
		return $rows;
	}

	/**
	 * Translated job status label.
	 *
	 * @param string $status Job status key.
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			'idle'      => __( 'idle', 'motored-core' ),
			'running'   => __( 'running', 'motored-core' ),
			'completed' => __( 'completed', 'motored-core' ),
			'cancelled' => __( 'cancelled', 'motored-core' ),
			'error'     => __( 'error', 'motored-core' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Capability + nonce guard for the tool handlers (dies on failure).
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'motored-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
	}

	/**
	 * Stores one-time flash notice and redirects back to the Images settings screen.
	 *
	 * @param bool   $ok   Success flag.
	 * @param string $text Notice message.
	 */
	private function finish( bool $ok, string $text ): never {
		Cache::set(
			'notice:' . get_current_user_id(),
			array(
				'ok'   => $ok,
				'text' => $text,
			),
			120,
			self::BUCKET
		);
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=motored&section=' . self::SECTION ) );
		exit;
	}

	/**
	 * Reads and clears the pending flash notice for the current user.
	 *
	 * @return array{ok:bool,text:string}|null
	 */
	private function pull_notice(): ?array {
		$key    = 'notice:' . get_current_user_id();
		$found  = false;
		$notice = Cache::get( $key, self::BUCKET, $found );
		if ( ! $found || ! is_array( $notice ) ) {
			return null;
		}
		Cache::delete( $key, self::BUCKET );
		return array(
			'ok'   => ! empty( $notice['ok'] ),
			'text' => isset( $notice['text'] ) && is_string( $notice['text'] ) ? $notice['text'] : '',
		);
	}
}
