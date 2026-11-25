<?php
/**
 * Shared storefront CSS and browser helpers.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the shared assets when a storefront feature needs them.
 */
final class StorefrontAssets {

	public const HANDLE_COMPAT_CSS = 'motored-compat';
	public const HANDLE_UI         = 'motored-ui';

	/**
	 * Enqueues the shared compat stylesheet (design tokens + Compatibility/Mi Garage components).
	 */
	public static function enqueue_compat_css(): void {
		if ( wp_style_is( self::HANDLE_COMPAT_CSS, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style(
			self::HANDLE_COMPAT_CSS,
			MOTORED_CORE_URL . 'assets/css/compat.css',
			array(),
			MOTORED_CORE_VERSION . '-compat4'
		);
		$accent = (string) sanitize_hex_color( (string) apply_filters( 'motored_storefront_accent', '' ) );
		if ( '' !== $accent ) {
			// Components read --motored-accent from --motored-accent-color, so a :root value is not shadowed.
			wp_add_inline_style( self::HANDLE_COMPAT_CSS, ':root{--motored-accent-color:' . $accent . ';}' );
		}
	}

	/**
	 * Enqueues the shared vanilla helpers (cascade, text normalization, optional analytics events).
	 */
	public static function enqueue_ui_js(): void {
		if ( wp_script_is( self::HANDLE_UI, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE_UI,
			MOTORED_CORE_URL . 'assets/js/motored-ui.js',
			array(),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}
}
