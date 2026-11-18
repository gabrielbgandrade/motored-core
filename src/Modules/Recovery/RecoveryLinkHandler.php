<?php
/**
 * Handles ?motored_recover and ?motored_optout query parameters on template_redirect.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Validates recovery and opt-out links.
 * Public page budget: immediately exits if neither parameter is in $_GET.
 */
final class RecoveryLinkHandler {

	public const RATE_LIMIT = 30;

	/**
	 * Hooked on template_redirect.
	 */
	public static function handle(): void {
		// Strict guard: zero cost on regular page loads.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public link entrypoint.
		if ( ! isset( $_GET['motored_recover'] ) && ! isset( $_GET['motored_optout'] ) ) {
			return;
		}

		if ( ! self::rate_limit_check() ) {
			wp_die(
				esc_html__( 'Too many requests. Please try again in a few minutes.', 'motored-core' ),
				'',
				array( 'response' => 429 )
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public token verified via SHA-256 hash_equals.
		if ( isset( $_GET['motored_recover'] ) ) {
			self::handle_recover( sanitize_text_field( wp_unslash( $_GET['motored_recover'] ) ) );
		}

		if ( isset( $_GET['motored_optout'] ) ) {
			self::handle_optout( sanitize_text_field( wp_unslash( $_GET['motored_optout'] ) ) );
		}
		// phpcs:enable
	}

	/**
	 * Processes cart recovery link.
	 *
	 * @param string $param Value of ?motored_recover.
	 */
	public static function handle_recover( string $param ): never {
		$parsed = self::parse_param( $param );
		if ( null === $parsed ) {
			self::recover_failed();
		}

		list( $id, $token ) = $parsed;

		if ( ! RecoveryToken::verify( $id, $token ) ) {
			self::recover_failed();
		}

		$row = Repository::get( $id );
		if ( null === $row || Status::OPTED_OUT === $row['status'] ) {
			self::recover_failed();
		}

		if ( empty( $row['clicked_gmt'] ) ) {
			Repository::update( $id, array( 'clicked_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
			History::log( $id, 'clicked', 'none', 'auto', 0, 'ok', '', false );
		}

		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
			$items          = Snapshot::decode( (string) $row['items'] );
			$restored_count = 0;

			foreach ( $items as $item ) {
				$product_id   = (int) ( $item['product_id'] ?? 0 );
				$variation_id = (int) ( $item['variation_id'] ?? 0 );
				$qty          = max( 1, (int) ( $item['qty'] ?? 1 ) );
				$variation    = (array) ( $item['attributes'] ?? array() );

				$target_id = $variation_id > 0 ? $variation_id : $product_id;
				$product   = function_exists( 'wc_get_product' ) ? wc_get_product( $target_id ) : false;

				if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
					$name = $product instanceof \WC_Product ? $product->get_name() : '#' . $target_id;
					if ( function_exists( 'wc_add_notice' ) ) {
						wc_add_notice(
							sprintf(
								/* translators: %s: product name */
								__( 'Item "%s" is no longer available and could not be restored to your cart.', 'motored-core' ),
								$name
							),
							'notice'
						);
					}
					continue;
				}

				$added = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation );
				if ( false !== $added ) {
					++$restored_count;
				}
			}

			if ( $restored_count > 0 ) {
				History::log( $id, 'restored', 'none', 'auto', 0, 'ok', 'items=' . $restored_count );
			}

			$coupon = Config::coupon_code();
			if ( '' !== $coupon && ! WC()->cart->has_discount( $coupon ) ) {
				WC()->cart->apply_coupon( $coupon );
			}
		}

		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard WordPress cache constant.
		}

		$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
		wp_safe_redirect( $checkout_url );
		exit;
	}

	/**
	 * Processes opt-out link.
	 *
	 * @param string $param Value of ?motored_optout.
	 */
	public static function handle_optout( string $param ): never {
		$parsed = self::parse_param( $param );
		if ( null === $parsed || ! RecoveryToken::verify_optout( $parsed[0], $parsed[1] ) ) {
			wp_die( esc_html__( 'Invalid opt-out link.', 'motored-core' ), '', array( 'response' => 400 ) );
		}
		$id = $parsed[0];
		nocache_headers();

		// A GET never changes state: mail scanners and link previews prefetch URLs. Confirm with a POST.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) {
			$form = '<p>' . esc_html__( 'Do you want to stop receiving reminders about this purchase?', 'motored-core' ) . '</p>'
				. '<form method="post" action="' . esc_url( RecoveryToken::optout_url( $id ) ) . '">'
				. '<p><button type="submit" class="button">' . esc_html__( 'Yes, unsubscribe me', 'motored-core' ) . '</button></p></form>';
			wp_die( $form, esc_html__( 'Unsubscribe', 'motored-core' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		}

		$row = Repository::get( $id );
		if ( null !== $row ) {
			if ( '' !== (string) $row['phone'] ) {
				Suppression::suppress( (string) $row['phone'], 'whatsapp' );
			}
			if ( '' !== (string) $row['email'] ) {
				Suppression::suppress( (string) $row['email'], 'email' );
			}
			if ( Status::OPTED_OUT !== $row['status'] ) {
				Repository::update(
					$id,
					array(
						'status'          => Status::OPTED_OUT,
						'next_action_gmt' => null,
					)
				);
				History::log( $id, 'opted_out', 'none', 'auto', 0, 'ok', '' );
			}
		}

		wp_die(
			'<p>' . esc_html__( 'You have successfully opted out. You will not receive any more reminders about this cart.', 'motored-core' ) . '</p>',
			esc_html__( 'Opt-out confirmed', 'motored-core' ),
			array( 'response' => 200 )
		);
	}

	/**
	 * Rate limit check per IP address.
	 */
	private static function rate_limit_check(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return true;
		}
		$key   = 'rl_link:' . md5( $ip );
		$count = Cache::counter( $key, CaptureLimiter::BUCKET );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		Cache::incr( $key, 1, 60, CaptureLimiter::BUCKET );
		return true;
	}

	/**
	 * Splits {id}.{token}.
	 *
	 * @param string $param Param.
	 * @return array{0:int,1:string}|null
	 */
	private static function parse_param( string $param ): ?array {
		if ( 1 !== preg_match( '/^(\d+)\.([a-f0-9]{32,64})$/i', $param, $matches ) ) {
			return null;
		}
		return array( (int) $matches[1], (string) $matches[2] );
	}

	/**
	 * Redirects to cart with generic error notice.
	 */
	private static function recover_failed(): never {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'The recovery link is invalid or has expired.', 'motored-core' ), 'error' );
		}
		$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
		wp_safe_redirect( $cart_url );
		exit;
	}
}
