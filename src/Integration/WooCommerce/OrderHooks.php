<?php
/**
 * WooCommerce order hooks and helper adapters.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Isolates WooCommerce hooks and internals whose exact runtime behavior is tracked in docs/VERIFY.md.
 */
final class OrderHooks {

	/**
	 * Registers a listener for order status changes.
	 *
	 * @param callable(int,string,string,mixed):void $listener Listener receiving ($order_id, $from, $to, $order).
	 */
	public static function register_status_changed( callable $listener ): void {
		add_action( 'woocommerce_order_status_changed', $listener, 10, 4 );
	}

	/**
	 * Registers a listener for newly added customer order notes.
	 *
	 * @param callable(mixed):void $listener Listener receiving array{order_id?:int,customer_note?:string}.
	 */
	public static function register_customer_note( callable $listener ): void {
		add_action( 'woocommerce_new_customer_note', $listener, 10, 1 );
	}

	/**
	 * Returns the newest customer-facing note text for an order, or '' if none is found.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function latest_customer_note( int $order_id ): string {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order_notes' ) ) {
			return '';
		}
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order_id,
				'type'     => 'customer',
				'limit'    => 1,
				'orderby'  => 'id',
				'order'    => 'DESC',
			)
		);
		if ( ! is_array( $notes ) || array() === $notes ) {
			return '';
		}
		$first = reset( $notes );
		if ( ! is_object( $first ) || ! isset( $first->content ) || ! is_string( $first->content ) ) {
			return '';
		}
		return trim( $first->content );
	}

	/**
	 * Resolves an ISO 3166-1 alpha-2 country code (e.g. "AR") to calling code digits (e.g. "54").
	 *
	 * @param string $country_iso Billing country code.
	 */
	public static function country_calling_code( string $country_iso ): string {
		$country_iso = strtoupper( trim( $country_iso ) );
		if ( '' === $country_iso || ! function_exists( 'WC' ) ) {
			return '';
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! ( $wc->countries instanceof \WC_Countries ) ) {
			return '';
		}
		$code = $wc->countries->get_country_calling_code( $country_iso );
		if ( is_array( $code ) ) {
			$code = reset( $code );
		}
		if ( ! is_scalar( $code ) ) {
			return '';
		}
		$digits = preg_replace( '/\D+/', '', (string) $code );
		return is_string( $digits ) ? $digits : '';
	}

	/**
	 * Formats an order total using WooCommerce's wc_price() (returns HTML to be stripped by Renderer).
	 *
	 * @param string|float $amount   Order total.
	 * @param string       $currency Currency code.
	 */
	public static function format_price_html( string|float $amount, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			return (string) wc_price( (float) $amount, array( 'currency' => $currency ) );
		}
		return trim( $currency . ' ' . (string) $amount );
	}
	/**
	 * Loads an order by ID (null when missing).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function load_order( int $order_id ): ?\WC_Order {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Statuses WooCommerce considers paid (without the "wc-" prefix).
	 *
	 * @return list<string>
	 */
	public static function paid_statuses(): array {
		$statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		return array_values( array_map( 'strval', is_array( $statuses ) ? $statuses : array() ) );
	}

	/**
	 * Plain facts about an order for recovery (no further queries beyond the order object).
	 *
	 * @param \WC_Order $order Order.
	 * @return array{order_id:int,status:string,email:string,user_id:int,phone:string,first_name:string,country:string,total:string,currency:string,needs_payment:bool,lines:list<array<string,mixed>>}
	 */
	public static function facts( \WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$lines[] = array(
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => $item->get_quantity(),
				'variation'    => array(),
			);
		}
		return array(
			'order_id'      => (int) $order->get_id(),
			'status'        => (string) $order->get_status(),
			'email'         => (string) $order->get_billing_email(),
			'user_id'       => (int) $order->get_customer_id(),
			'phone'         => (string) $order->get_billing_phone(),
			'first_name'    => (string) $order->get_billing_first_name(),
			'country'       => (string) $order->get_billing_country(),
			'total'         => (string) $order->get_total(),
			'currency'      => (string) $order->get_currency(),
			'needs_payment' => (bool) $order->needs_payment(),
			'lines'         => $lines,
		);
	}

	/**
	 * Names and quantities of the order lines (for message text).
	 *
	 * @param \WC_Order $order Order.
	 * @return list<array{name:string,qty:int}>
	 */
	public static function item_names( \WC_Order $order ): array {
		$names = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$names[] = array(
					'name' => (string) $item->get_name(),
					'qty'  => (int) $item->get_quantity(),
				);
			}
		}
		return $names;
	}

	/**
	 * URL the customer should open: the payment page for pending/failed orders, the order-received
	 * page (payment instructions) for on-hold ones, which WooCommerce does not let anyone "pay".
	 *
	 * @param \WC_Order $order   Order.
	 * @param bool      $on_hold Whether the order waits for an offline/pending payment.
	 */
	public static function pay_url( \WC_Order $order, bool $on_hold ): string {
		return $on_hold ? (string) $order->get_checkout_order_received_url() : (string) $order->get_checkout_payment_url();
	}

	/**
	 * Facts needed to decide and build a review request (order object only, no extra queries).
	 *
	 * @param \WC_Order $order Order.
	 * @return array{order_id:int,order_number:string,status:string,refunded:bool,phone:string,calling_code:string,first_name:string,email:string,user_id:int,lines:list<array{id:int,name:string}>}
	 */
	public static function review_facts( \WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product_id() > 0 ) {
				$lines[] = array(
					'id'   => (int) $item->get_product_id(),
					'name' => (string) $item->get_name(),
				);
			}
		}
		$total = (float) $order->get_total();
		return array(
			'order_id'     => (int) $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
			'status'       => (string) $order->get_status(),
			'refunded'     => $total > 0 && (float) $order->get_total_refunded() >= $total,
			'phone'        => trim( (string) $order->get_billing_phone() ),
			'calling_code' => self::country_calling_code( (string) $order->get_billing_country() ),
			'first_name'   => (string) $order->get_billing_first_name(),
			'email'        => (string) $order->get_billing_email(),
			'user_id'      => (int) $order->get_customer_id(),
			'lines'        => $lines,
		);
	}

	/**
	 * Screen id of the order edit page (HPOS or legacy) for meta boxes.
	 */
	public static function order_screen_id(): string {
		return function_exists( 'wc_get_page_screen_id' ) ? (string) wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	/**
	 * Writes one order meta value (HPOS-safe CRUD).
	 *
	 * @param int    $order_id Order ID.
	 * @param string $key      Meta key.
	 * @param string $value    Value.
	 */
	public static function set_meta( int $order_id, string $key, string $value ): void {
		$order = self::load_order( $order_id );
		if ( null !== $order ) {
			$order->update_meta_data( $key, $value );
			$order->save_meta_data();
		}
	}

	/**
	 * Adds a PRIVATE order note (never visible to the customer).
	 *
	 * @param int    $order_id Order ID.
	 * @param string $note     Note text (no phone numbers or message text).
	 */
	public static function add_private_note( int $order_id, string $note ): void {
		$order = self::load_order( $order_id );
		if ( null !== $order ) {
			$order->add_order_note( $note, 0, false );
		}
	}

	/**
	 * Details shown in the Recovery detail screen (native order data and notes only).
	 *
	 * @param int $order_id Order ID.
	 * @param int $notes    Number of latest notes.
	 * @return array{edit_url:string,status_label:string,payment_method:string,total:string,currency:string,notes:list<string>}|null
	 */
	public static function admin_details( int $order_id, int $notes = 5 ): ?array {
		$order = self::load_order( $order_id );
		if ( null === $order ) {
			return null;
		}
		$texts = array();
		if ( function_exists( 'wc_get_order_notes' ) ) {
			$found = wc_get_order_notes(
				array(
					'order_id' => $order_id,
					'limit'    => $notes,
					'orderby'  => 'id',
					'order'    => 'DESC',
				)
			);
			foreach ( is_array( $found ) ? $found : array() as $note ) {
				if ( is_object( $note ) && isset( $note->content ) && is_string( $note->content ) ) {
					$texts[] = wp_strip_all_tags( $note->content );
				}
			}
		}
		return array(
			'edit_url'       => (string) $order->get_edit_order_url(),
			'status_label'   => function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $order->get_status() ) : (string) $order->get_status(),
			'payment_method' => (string) $order->get_payment_method_title(),
			'total'          => (string) $order->get_total(),
			'currency'       => (string) $order->get_currency(),
			'notes'          => $texts,
		);
	}
}
