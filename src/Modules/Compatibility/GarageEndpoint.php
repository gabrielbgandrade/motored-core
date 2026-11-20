<?php
/**
 * My Account -> Mi Garage endpoint and logout cleanup signal.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Modules\CatalogFilters\StorefrontAssets;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `mi-garage` WooCommerce account endpoint using core hooks only (theme-agnostic).
 */
final class GarageEndpoint {

	public const ENDPOINT        = 'mi-garage';
	public const REWRITE_VERSION = '1';
	public const REWRITE_OPT     = 'motored_compat_rewrite_ver';
	public const LOGOUT_COOKIE   = 'motored_garage_logout';

	/**
	 * Registers endpoint and account menu hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_page' ) );
		add_action( 'wp_logout', array( $this, 'set_logout_cookie' ) );
	}

	/**
	 * Registers the rewrite endpoint on init.
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Adds "My Garage" to the My Account navigation before Logout when enabled.
	 *
	 * @param mixed $items Existing menu items.
	 * @return array<string,string>
	 */
	public function menu_item( $items ): array {
		$items = is_array( $items ) ? $items : array();
		if ( ! Config::enabled() ) {
			return $items;
		}
		$out   = array();
		$added = false;
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key && ! $added ) {
				$out[ self::ENDPOINT ] = __( 'My Garage', 'motored-core' );
				$added                 = true;
			}
			$out[ (string) $key ] = (string) $label;
		}
		if ( ! $added ) {
			$out[ self::ENDPOINT ] = __( 'My Garage', 'motored-core' );
		}
		return $out;
	}

	/**
	 * Renders the My Account -> My Garage page (empty shell hydrated/controlled by compat-garage.js + selector shortcode).
	 */
	public function render_page(): void {
		if ( ! Config::enabled() ) {
			echo '<p>' . esc_html__( 'Vehicle compatibility is currently disabled.', 'motored-core' ) . '</p>';
			return;
		}
		StorefrontAssets::enqueue_compat_css();
		Storefront::enqueue_garage_script();
		wp_enqueue_script(
			'motored-compat-garage-page',
			MOTORED_CORE_URL . 'assets/js/compat-garage-page.js',
			array( Storefront::HANDLE_GARAGE ),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		$shop_url = Config::shop_url();
		echo '<div class="motored-garage-page" data-shop-url="' . esc_url( $shop_url ) . '">';
		echo '<div class="motored-garage-page__head">';
		echo '<h2>' . esc_html__( 'My Garage', 'motored-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Save your motorcycles to filter tires and verify compatibility automatically across the store.', 'motored-core' ) . '</p>';
		echo '</div>';
		echo '<p class="motored-garage-notice" role="status" aria-live="polite" hidden></p>';
		echo '<div class="motored-garage-list" aria-live="polite"></div>';
		echo '<div class="motored-garage-add">';
		echo '<h3>' . esc_html__( 'Add a motorcycle', 'motored-core' ) . '</h3>';
		echo do_shortcode( '[' . Storefront::SELECTOR_SHORTCODE . ' mode="garage"]' );
		echo '</div>';
		echo '<p class="motored-compat-disclaimer"><small>' . esc_html( Config::disclaimer() ) . '</small></p>';
		echo '</div>';
	}

	/**
	 * Signals the browser on logout so shared-computer guest localStorage is cleared cleanly.
	 */
	public function set_logout_cookie(): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::LOGOUT_COOKIE,
			'1',
			array(
				'expires'  => time() + 300,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}
}
