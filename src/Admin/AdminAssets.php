<?php
/**
 * Stylesheet of the Motored settings screens.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One CSS file (no JS, no dependencies), enqueued only on WooCommerce > Settings > Motored.
 */
final class AdminAssets {

	public const HANDLE = 'motored-admin';

	/**
	 * Hooked on admin_enqueue_scripts.
	 */
	public static function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'wc-settings' !== $page || 'motored' !== $tab ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, MOTORED_CORE_URL . 'assets/css/motored-admin.css', array(), MOTORED_CORE_VERSION );
	}
}
