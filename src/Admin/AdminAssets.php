<?php
/**
 * Loader of the shared Motored admin stylesheet.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Admin;

use Motored\Core\Integration\WooCommerce\OrderHooks;

defined( 'ABSPATH' ) || exit;

/**
 * The ONE shared admin stylesheet (CSS only, no JS, no dependencies). Enqueued only on WooCommerce > Settings > Motored,
 * the order edit screen (meta boxes) and the Recovery screen; never on other admin screens.
 */
final class AdminAssets {

	public const HANDLE = 'motored-admin';

	/**
	 * Hooked on admin_enqueue_scripts.
	 */
	public static function enqueue(): void {
		if ( ! self::is_motored_screen() ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, MOTORED_CORE_URL . 'assets/admin/motored-admin.css', array(), MOTORED_CORE_VERSION );
	}

	/**
	 * Whether the current admin screen is one of ours.
	 */
	private static function is_motored_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'wc-settings' === $page && 'motored' === $tab ) {
			return true;
		}
		if ( 'motored-recovery' === $page ) {
			return true;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! is_object( $screen ) || OrderHooks::order_screen_id() !== $screen->id ) {
			return false;
		}
		// With HPOS the list and the edit page share the screen id: only the edit/new page has our meta boxes.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$action = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		return 'wc-orders' !== $page || in_array( $action, array( 'edit', 'new' ), true );
	}
}
