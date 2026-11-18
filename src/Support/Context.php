<?php
/**
 * Request context detection.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Lazy, memoized request-type detection.
 *
 * Detection runs at plugins_loaded, so it must not rely on constants WordPress
 * defines later (REST_REQUEST is defined in rest_api_loaded(), after parse_request).
 */
final class Context {

	/**
	 * Memoized results.
	 *
	 * @var array<string,bool>
	 */
	private array $memo = array();

	/**
	 * True for any wp-admin request. Note: WordPress also returns true for admin-ajax.php.
	 */
	public function is_admin(): bool {
		return $this->memo( 'admin', static fn(): bool => (bool) is_admin() );
	}

	/**
	 * True for admin-ajax.php and WooCommerce's wc-ajax endpoint.
	 */
	public function is_ajax(): bool {
		return $this->memo(
			'ajax',
			static function (): bool {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only.
				return (bool) wp_doing_ajax() || isset( $_GET['wc-ajax'] );
			}
		);
	}

	/**
	 * True for REST API requests, detected early from the URL.
	 */
	public function is_rest(): bool {
		return $this->memo(
			'rest',
			static function (): bool {
				if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					return true;
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only.
				if ( isset( $_GET['rest_route'] ) ) {
					return true;
				}
				if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
					return false;
				}
				$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
				return false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' );
			}
		);
	}

	/**
	 * True for WP-Cron requests.
	 */
	public function is_cron(): bool {
		return $this->memo( 'cron', static fn(): bool => (bool) wp_doing_cron() );
	}

	/**
	 * True for WP-CLI.
	 */
	public function is_cli(): bool {
		return $this->memo( 'cli', static fn(): bool => defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * True for a regular public page view (none of the other contexts).
	 */
	public function is_frontend(): bool {
		return $this->memo(
			'frontend',
			fn(): bool => ! $this->is_admin() && ! $this->is_ajax() && ! $this->is_rest() && ! $this->is_cron() && ! $this->is_cli()
		);
	}

	/**
	 * Evaluates and caches a boolean signal.
	 *
	 * @param string          $key      Memo key.
	 * @param callable():bool $resolver Resolver.
	 */
	private function memo( string $key, callable $resolver ): bool {
		if ( ! array_key_exists( $key, $this->memo ) ) {
			$this->memo[ $key ] = (bool) $resolver();
		}
		return $this->memo[ $key ];
	}
}
