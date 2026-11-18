<?php
/**
 * WooCommerce cart/checkout hooks and cart reading (unverified names, see docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Single place that knows the WooCommerce hook names and cart internals used by Recovery.
 */
final class CartHooks {

	public const CAPTURE_ACTION = 'motored_capture';

	/**
	 * Listener for add to cart, item removed and quantity changed (args vary by hook; ignore them).
	 *
	 * @param callable $listener Listener.
	 */
	public static function register_cart_changes( callable $listener ): void {
		add_action( 'woocommerce_add_to_cart', $listener, 10, 0 );
		add_action( 'woocommerce_cart_item_removed', $listener, 10, 0 );
		add_action( 'woocommerce_after_cart_item_quantity_update', $listener, 10, 0 );
	}

	/**
	 * Listeners for order creation: classic shortcode checkout and Store API checkout.
	 *
	 * @param callable(int,mixed,mixed):void $classic   Receives ($order_id, $posted_data, $order).
	 * @param callable(mixed):void           $store_api Receives ($order).
	 */
	public static function register_order_created( callable $classic, callable $store_api ): void {
		add_action( 'woocommerce_checkout_order_processed', $classic, 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', $store_api, 10, 1 );
	}

	/**
	 * Registers the wc-ajax endpoint ("?wc-ajax=motored_capture").
	 *
	 * @param callable $handler Handler.
	 */
	public static function register_capture_endpoint( callable $handler ): void {
		add_action( 'wc_ajax_' . self::CAPTURE_ACTION, $handler );
	}

	/**
	 * URL of the wc-ajax capture endpoint.
	 */
	public static function capture_url(): string {
		return class_exists( \WC_AJAX::class )
			? (string) \WC_AJAX::get_endpoint( self::CAPTURE_ACTION )
			: add_query_arg( 'wc-ajax', self::CAPTURE_ACTION, home_url( '/' ) );
	}

	/**
	 * True on the checkout page, except the order-received (thank you) endpoint.
	 */
	public static function is_capture_page(): bool {
		return function_exists( 'is_checkout' ) && is_checkout()
			&& ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() );
	}

	/**
	 * Identifier of the current shopper session ('' when there is none).
	 */
	public static function session_key(): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! ( $wc->session instanceof \WC_Session ) ) {
			return '';
		}
		$id = $wc->session->get_customer_id();
		return is_scalar( $id ) ? substr( (string) $id, 0, 64 ) : '';
	}

	/**
	 * Raw cart lines read on the server: product_id, variation_id, quantity, variation attributes.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function cart_lines(): array {
		$cart = self::cart();
		if ( null === $cart ) {
			return array();
		}
		$lines = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$lines[] = array(
				'product_id'   => $item['product_id'] ?? 0,
				'variation_id' => $item['variation_id'] ?? 0,
				'quantity'     => $item['quantity'] ?? 0,
				'variation'    => is_array( $item['variation'] ?? null ) ? $item['variation'] : array(),
			);
		}
		return $lines;
	}

	/**
	 * Cart total at capture time.
	 */
	public static function cart_total(): float {
		$cart = self::cart();
		return null === $cart ? 0.0 : (float) $cart->get_total( 'edit' );
	}

	/**
	 * Store currency.
	 */
	public static function currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	}

	/**
	 * Checks if an order was placed by the given email or user_id after the specified GMT datetime.
	 *
	 * @param string $email     Customer billing email.
	 * @param int    $user_id   Customer user ID.
	 * @param string $after_gmt GMT datetime.
	 * @return int|null Order ID if found, null otherwise.
	 */
	public static function find_order_after( string $email, int $user_id, string $after_gmt ): ?int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$timestamp = strtotime( $after_gmt . ' UTC' );
		if ( false === $timestamp || $timestamp <= 0 ) {
			return null;
		}

		$args = array(
			'limit'        => 1,
			'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending' ),
			'date_created' => '>' . $timestamp,
			'return'       => 'ids',
		);

		if ( $user_id > 0 ) {
			$args['customer_id'] = $user_id;
			$orders              = wc_get_orders( $args );
			if ( is_array( $orders ) && array() !== $orders ) {
				$first = reset( $orders );
				return $first instanceof \WC_Order ? (int) $first->get_id() : ( is_numeric( $first ) ? (int) $first : null );
			}
		}

		if ( '' !== trim( $email ) ) {
			unset( $args['customer_id'] );
			$args['billing_email'] = trim( $email );
			$orders                = wc_get_orders( $args );
			if ( is_array( $orders ) && array() !== $orders ) {
				$first = reset( $orders );
				return $first instanceof \WC_Order ? (int) $first->get_id() : ( is_numeric( $first ) ? (int) $first : null );
			}
		}

		return null;
	}

	/**
	 * The current cart, or null when WooCommerce did not load one (admin, some REST/cron requests).
	 */
	private static function cart(): ?\WC_Cart {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		return ( is_object( $wc ) && $wc->cart instanceof \WC_Cart ) ? $wc->cart : null;
	}
}
