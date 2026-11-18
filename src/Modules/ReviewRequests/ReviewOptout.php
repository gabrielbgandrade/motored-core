<?php
/**
 * Opt-out link of review requests (no tables: stateless token per order).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewRequests;

use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\Recovery\Suppression;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Modules\Waha\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * `?motored_review_optout={order_id}.{hmac}`. A GET only shows a confirmation form (link previews
 * prefetch URLs); the POST suppresses the billing phone in the shared Recovery suppression list.
 */
final class ReviewOptout {

	public const PARAM = 'motored_review_optout';

	/**
	 * Token of an order (HMAC, 32 hex chars).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function token( int $order_id ): string {
		$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'motored_review_requests' ) : 'motored-core-test-key';
		return substr( hash_hmac( 'sha256', $order_id . '|review_optout', $key ), 0, 32 );
	}

	/**
	 * Whether a token is valid for an order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $token    Token from the URL.
	 */
	public static function verify( int $order_id, string $token ): bool {
		return $order_id > 0 && hash_equals( self::token( $order_id ), strtolower( trim( $token ) ) );
	}

	/**
	 * Opt-out URL of an order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function url( int $order_id ): string {
		return add_query_arg( self::PARAM, $order_id . '.' . self::token( $order_id ), home_url( '/' ) );
	}

	/**
	 * Splits {id}.{token}.
	 *
	 * @param string $param Query value.
	 * @return array{0:int,1:string}|null
	 */
	public static function parse( string $param ): ?array {
		return 1 === preg_match( '/^(\d+)\.([a-f0-9]{32})$/i', $param, $m ) ? array( (int) $m[1], (string) $m[2] ) : null;
	}

	/**
	 * Hooked on template_redirect: exits at once (no queries) unless the parameter is present.
	 */
	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed link, see verify().
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$parsed = self::parse( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) );
		if ( null === $parsed || ! self::verify( $parsed[0], $parsed[1] ) ) {
			wp_die( esc_html__( 'Invalid opt-out link.', 'motored-core' ), '', array( 'response' => 400 ) );
		}
		nocache_headers();

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) {
			$form = '<p>' . esc_html__( 'Do you want to stop receiving WhatsApp messages from this store?', 'motored-core' ) . '</p>'
				. '<form method="post" action="' . esc_url( self::url( $parsed[0] ) ) . '">'
				. '<p><button type="submit" class="button">' . esc_html__( 'Yes, unsubscribe me', 'motored-core' ) . '</button></p></form>';
			wp_die( $form, esc_html__( 'Unsubscribe', 'motored-core' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		}

		$order = OrderHooks::load_order( $parsed[0] );
		if ( null !== $order ) {
			$raw = trim( (string) $order->get_billing_phone() );
			if ( '' !== $raw ) {
				Suppression::suppress( $raw, 'whatsapp' );
				$code = OrderHooks::country_calling_code( (string) $order->get_billing_country() );
				$norm = Phone::normalize( $raw, '' !== $code ? $code : WahaConfig::country_code() );
				if ( null !== $norm ) {
					Suppression::suppress( $norm, 'whatsapp' );
				}
			}
			OrderHooks::add_private_note( $parsed[0], __( 'Motored: the customer unsubscribed from WhatsApp messages.', 'motored-core' ) );
		}

		wp_die(
			'<p>' . esc_html__( 'You have successfully opted out. You will not receive more WhatsApp messages from this store.', 'motored-core' ) . '</p>',
			esc_html__( 'Opt-out confirmed', 'motored-core' ),
			array( 'response' => 200 )
		);
	}
}
