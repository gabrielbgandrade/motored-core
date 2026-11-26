<?php
/**
 * My Account -> Mi Garage endpoint and logout cleanup signal.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\MiGarage;

use Motored\Core\Support\StorefrontAssets;

use Motored\Core\Modules\Compatibility\Config;
use Motored\Core\Modules\Compatibility\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `mi-garage` WooCommerce account endpoint using core hooks only (theme-agnostic).
 */
final class GarageEndpoint {

	public const ENDPOINT          = 'mi-garage';
	public const SHORTCODE         = 'motored_mi_garage';
	public const REWRITE_VERSION   = '2';
	public const REWRITE_OPT       = 'motored_compat_rewrite_ver';
	public const REWRITE_HEAL_LOCK = 'motored_garage_rewrite_heal';
	public const LOGOUT_COOKIE     = 'motored_garage_logout';

	/**
	 * Avoids calling add_rewrite_endpoint() twice in the same request.
	 *
	 * @var bool
	 */
	private bool $endpoint_added  = false;
	private bool $drawer_rendered = false;

	/**
	 * Registers only the zero-cost rewrite and query-var hooks so flushes from CLI, cron,
	 * REST or AJAX never strip the `mi-garage` endpoint from saved rewrite rules.
	 */
	public function register_rewrite_hooks(): void {
		add_action( 'init', array( $this, 'add_endpoint' ), 0 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ), 0 );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_wc_query_vars' ) );
	}

	/**
	 * Registers endpoint and account menu hooks.
	 */
	public function register(): void {
		$this->register_rewrite_hooks();
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'motored_garage_trigger', array( $this, 'mount_drawer' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_page' ) );
		add_filter( 'wp_robots', array( $this, 'robots_noindex' ) );
		add_action( 'wp_logout', array( $this, 'set_logout_cookie' ) );
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ) );
	}

	/**
	 * Adds `noindex, follow` to the Mi Garage account endpoint (logged-out visitors redirect to login).
	 *
	 * @param mixed $robots Robots directives.
	 * @return array<string,mixed>
	 */
	public function robots_noindex( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();
		global $post;
		$public_garage = $post instanceof \WP_Post && has_shortcode( $post->post_content, self::SHORTCODE );
		if ( $public_garage || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT ) ) ) {
			unset( $robots['index'] );
			$robots['noindex']  = true;
			$robots['nofollow'] = false;
			$robots['follow']   = true;
		}
		return $robots;
	}

	/**
	 * Flushes rewrite rules when the endpoint version changes or when pretty rewrite rules
	 * are missing our endpoint (admin_init only, never on frontend or AJAX).
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}
		$version_mismatch = self::REWRITE_VERSION !== (string) get_option( self::REWRITE_OPT, '' );
		$missing_rule     = ! $version_mismatch && $this->is_missing_from_rewrite_rules();
		if ( ! $version_mismatch && ! $missing_rule ) {
			return;
		}
		if ( $missing_rule && false !== get_transient( self::REWRITE_HEAL_LOCK ) ) {
			return;
		}
		$this->add_endpoint();
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		update_option( self::REWRITE_OPT, self::REWRITE_VERSION, false );
		if ( $missing_rule ) {
			set_transient( self::REWRITE_HEAL_LOCK, '1', 300 );
		}
	}

	/**
	 * Returns true when pretty permalinks are active (rewrite_rules is a non-empty array)
	 * and none of the compiled rules mention our endpoint.
	 */
	public function is_missing_from_rewrite_rules(): bool {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || array() === $rules ) {
			return false;
		}
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( false !== strpos( (string) $pattern, self::ENDPOINT ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Registers the rewrite endpoint on init.
	 */
	public function add_endpoint(): void {
		if ( $this->endpoint_added ) {
			return;
		}
		$this->endpoint_added = true;
		if ( function_exists( 'add_rewrite_endpoint' ) ) {
			add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		}
	}

	/**
	 * Registers the endpoint as a public WordPress query var (also covers Plain permalinks).
	 *
	 * @param mixed $vars Existing query vars.
	 * @return array<int,string>
	 */
	public function add_query_vars( $vars ): array {
		$out = array();
		if ( is_array( $vars ) ) {
			foreach ( $vars as $var ) {
				$out[] = (string) $var;
			}
		}
		if ( ! in_array( self::ENDPOINT, $out, true ) ) {
			$out[] = self::ENDPOINT;
		}
		return $out;
	}

	/**
	 * Registers the endpoint in WooCommerce WC_Query so My Account routing, titles and
	 * active-item checks recognize `mi-garage` natively.
	 *
	 * @param mixed $vars Existing WooCommerce endpoint query vars.
	 * @return array<string,string>
	 */
	public function add_wc_query_vars( $vars ): array {
		$out = array();
		if ( is_array( $vars ) ) {
			foreach ( $vars as $key => $val ) {
				$out[ (string) $key ] = (string) $val;
			}
		}
		$out[ self::ENDPOINT ] = self::ENDPOINT;
		return $out;
	}

	/**
	 * Endpoint page title inside My Account.
	 *
	 * @param mixed $title Default page title.
	 */
	public function endpoint_title( $title ): string {
		return Config::enabled() ? __( 'My Garage', 'motored-core' ) : (string) $title;
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
	 * Shortcode [motored_mi_garage] so guests can also list, activate and remove saved bikes (B5).
	 */
	public function shortcode(): string {
		ob_start();
		$this->render_page();
		return (string) ob_get_clean();
	}

	/**
	 * Renders the My Account -> My Garage page (empty shell hydrated/controlled by compat-garage.js + selector shortcode).
	 */
	public function render_page(): void {
		if ( ! Config::enabled() ) {
			echo '<p>' . esc_html__( 'Vehicle compatibility is currently disabled.', 'motored-core' ) . '</p>';
			return;
		}
		$this->render_garage( false );
	}

	/**
	 * Opts in from a rendered storefront trigger, outside checkout.
	 */
	public function mount_drawer(): void {
		if ( ( function_exists( 'is_checkout' ) && is_checkout() ) || ! Config::enabled() ) {
			return;
		}
		add_action( 'wp_footer', array( $this, 'render_drawer' ), 15 );
	}

	/**
	 * Outputs the shared garage inside one native modal.
	 */
	public function render_drawer(): void {
		if ( $this->drawer_rendered ) {
			return;
		}
		$this->drawer_rendered = true;
		echo '<dialog id="motored-garage-drawer" class="motored-garage-drawer" aria-labelledby="motored-garage-drawer-title">';
		echo '<div class="motored-garage-drawer__header">';
		echo '<h2 id="motored-garage-drawer-title">' . esc_html__( 'My Garage', 'motored-core' ) . '</h2>';
		echo '<button type="button" class="motored-garage-drawer__close" aria-label="' . esc_attr__( 'Close', 'motored-core' ) . '"><span aria-hidden="true">&times;</span></button>';
		echo '</div>';
		$this->render_garage( true );
		echo '</dialog>';
	}

	/**
	 * Renders the list, picker and account hint for either garage surface.
	 *
	 * @param bool $drawer Whether this is the modal surface.
	 */
	private function render_garage( bool $drawer ): void {
		StorefrontAssets::enqueue_compat_css();
		GarageAssets::enqueue();
		if ( ! wp_script_is( 'motored-compat-garage-page', 'enqueued' ) ) {
			wp_enqueue_script(
				'motored-compat-garage-page',
				MOTORED_CORE_URL . 'assets/js/compat-garage-page.js',
				array( GarageAssets::HANDLE ),
				MOTORED_CORE_VERSION . '-garage11',
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
			wp_add_inline_script( 'motored-compat-garage-page', 'window.motoredGaragePageI18n=' . wp_json_encode( GarageAssets::page_i18n() ) . ';', 'before' );
		}
		$shop_url    = Config::shop_url();
		$account_url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
		echo '<div class="motored-garage-page' . ( $drawer ? ' motored-garage-page--drawer' : '' ) . '" data-shop-url="' . esc_url( $shop_url ) . '"' . ( $drawer ? '' : ' data-garage-full-page' ) . '>';

		if ( $drawer ) {
			echo '<div class="motored-garage-drawer__body">';
			if ( ! is_user_logged_in() && '' !== $account_url ) {
				echo '<div class="motored-garage-guest">';
				echo '<p class="motored-garage-guest__title">' . esc_html__( 'Guardá tus motos en tu cuenta', 'motored-core' ) . '</p>';
				echo '<p class="motored-garage-guest__cta"><a href="' . esc_url( $account_url ) . '">' . esc_html__( 'Iniciá sesión o creá tu cuenta', 'motored-core' ) . '</a> </p>';
				echo '<ul class="motored-garage-guest__perks">';
				echo '</ul>';
				echo '</div>';
			}
		} else {
			echo '<div class="motored-garage-page__head">';
			echo '<h2>' . esc_html__( 'My Garage', 'motored-core' ) . '</h2>';
			echo '<p>' . esc_html__( 'Save your motorcycles to filter tires and verify compatibility automatically across the store.', 'motored-core' ) . '</p>';
			echo '</div>';
		}

		echo '<p class="motored-garage-notice" role="status" aria-live="polite" hidden></p>';
		echo '<div class="motored-garage-list" aria-live="polite"></div>';

		if ( $drawer ) {
			echo '<p class="motored-compat-disclaimer"><small>' . esc_html( Config::disclaimer() ) . '</small></p>';
			echo '</div>';
			echo '<div class="motored-garage-drawer__footer">';
			echo '<details class="motored-garage-add"><summary><span>' . esc_html__( 'Add a motorcycle', 'motored-core' ) . '</span><span class="motored-garage-add__chev" aria-hidden="true"></span></summary>';
			echo '<div class="motored-garage-add__body">';
			echo do_shortcode( '[' . Storefront::SELECTOR_SHORTCODE . ' mode="garage" lazy="1"]' );
			echo '</div>';
			echo '</details>';
			echo '</div>';
		} else {
			echo '<div class="motored-garage-add">';
			echo '<h3>' . esc_html__( 'Add a motorcycle', 'motored-core' ) . '</h3>';
			echo do_shortcode( '[' . Storefront::SELECTOR_SHORTCODE . ' mode="garage"]' );
			echo '</div>';
			if ( ! is_user_logged_in() && '' !== $account_url ) {
				echo '<p class="motored-garage-page__login"><a href="' . esc_url( $account_url ) . '">' . esc_html__( 'Log in to save your garage permanently across devices', 'motored-core' ) . '</a></p>';
			}
			echo '<p class="motored-compat-disclaimer"><small>' . esc_html( Config::disclaimer() ) . '</small></p>';
		}
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
