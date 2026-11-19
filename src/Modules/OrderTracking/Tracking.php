<?php
/**
 * Tracking entry stored in the order meta (one per order).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderTracking;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object plus the idempotency flag helpers (meta via WC CRUD, HPOS-safe).
 */
final class Tracking {

	public const META          = '_motored_tracking';
	public const SENT_WA       = '_motored_tracking_sent_wa';
	public const SENT_EMAIL    = '_motored_tracking_sent_email';
	public const CARRIED_WA    = '_motored_tracking_carried_wa';
	public const CARRIED_EMAIL = '_motored_tracking_carried_email';

	/**
	 * Constructor.
	 *
	 * @param string $carrier_id   Carrier id ('' or Config::OTHER for manual).
	 * @param string $code         Sanitized code.
	 * @param string $url_override Sanitized https URL or ''.
	 * @param int    $updated_gmt  Unix time of the last change.
	 */
	public function __construct(
		public readonly string $carrier_id,
		public readonly string $code,
		public readonly string $url_override,
		public readonly int $updated_gmt = 0
	) {
	}

	/**
	 * Builds a sanitized entry from raw data (meta or form).
	 *
	 * @param mixed $raw Array with carrier_id, code, url_override, updated_gmt.
	 */
	public static function from_array( $raw ): self {
		$raw = is_array( $raw ) ? $raw : array();
		$str = static fn( string $key ): string => isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
		$id  = strtolower( $str( 'carrier_id' ) );
		return new self(
			1 === preg_match( '/^[a-z0-9_-]{1,30}$/', $id ) ? $id : '',
			TrackingLink::sanitize_code( $str( 'code' ) ),
			TrackingLink::sanitize_url( $str( 'url_override' ) ),
			max( 0, (int) $str( 'updated_gmt' ) )
		);
	}

	/**
	 * Reads the entry of an order (null when there is no code and no link).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function from_order( \WC_Order $order ): ?self {
		$tracking = self::from_array( $order->get_meta( self::META, true ) );
		return $tracking->is_empty() ? null : $tracking;
	}

	/**
	 * Whether neither a code nor a manual link is set.
	 */
	public function is_empty(): bool {
		return '' === $this->code && '' === $this->url_override;
	}

	/**
	 * Hash used by the per-channel idempotency flags ('' without code).
	 */
	public function hash(): string {
		return '' === $this->code ? '' : md5( $this->code );
	}

	/**
	 * Meta payload.
	 *
	 * @return array{carrier_id:string,code:string,url_override:string,updated_gmt:int}
	 */
	public function to_array(): array {
		return array(
			'carrier_id'   => $this->carrier_id,
			'code'         => $this->code,
			'url_override' => $this->url_override,
			'updated_gmt'  => $this->updated_gmt,
		);
	}

	/**
	 * Reads a flag (hash) from the order.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   One of the flag meta keys.
	 */
	public static function flag( \WC_Order $order, string $key ): string {
		$value = $order->get_meta( $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Stores a flag (hash) on the order.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Flag meta key.
	 * @param string    $hash  Code hash.
	 */
	public static function set_flag( \WC_Order $order, string $key, string $hash ): void {
		$order->update_meta_data( $key, $hash );
		$order->save_meta_data();
	}

	/**
	 * Marks that a "completed"/"processing" message or e-mail already carried the current code,
	 * so the dedicated message is not sent for the same code.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $channel 'wa' or 'email'.
	 */
	public static function mark_carried( \WC_Order $order, string $channel ): void {
		if ( $order->get_id() <= 0 ) {
			return;
		}
		$tracking = self::from_order( $order );
		if ( null === $tracking || '' === $tracking->hash() ) {
			return;
		}
		$key = 'wa' === $channel ? self::CARRIED_WA : self::CARRIED_EMAIL;
		if ( self::flag( $order, $key ) !== $tracking->hash() ) {
			self::set_flag( $order, $key, $tracking->hash() );
		}
	}
}
