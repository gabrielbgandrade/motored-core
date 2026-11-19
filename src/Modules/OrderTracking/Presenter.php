<?php
/**
 * Resolves what the customer sees (carrier name, code, final link) and the message placeholders.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderTracking;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the order meta (already loaded with the order: 0 extra queries) and the carrier config only
 * when the order has tracking.
 */
final class Presenter {

	/**
	 * Carrier, code and link of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{carrier:string,code:string,url:string}|null
	 */
	public static function for_order( \WC_Order $order ): ?array {
		$tracking = Tracking::from_order( $order );
		return null === $tracking ? null : self::resolve( $tracking );
	}

	/**
	 * Resolves a tracking entry through the carrier list.
	 *
	 * @param Tracking $tracking Entry.
	 * @return array{carrier:string,code:string,url:string}
	 */
	public static function resolve( Tracking $tracking ): array {
		$carrier = '' !== $tracking->carrier_id && Config::OTHER !== $tracking->carrier_id ? Config::carrier( $tracking->carrier_id ) : null;
		return array(
			'carrier' => null === $carrier ? '' : $carrier['name'],
			'code'    => $tracking->code,
			'url'     => TrackingLink::build( $tracking->url_override, null === $carrier ? '' : $carrier['url'], $tracking->code ),
		);
	}

	/**
	 * Placeholder values {tracking_carrier} {tracking_code} {tracking_url} {tracking_block}; all '' without tracking.
	 * Called at send time (after init), so the translated block is safe.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{tracking_carrier:string,tracking_code:string,tracking_url:string,tracking_block:string}
	 */
	public static function vars( \WC_Order $order ): array {
		$empty = array(
			'tracking_carrier' => '',
			'tracking_code'    => '',
			'tracking_url'     => '',
			'tracking_block'   => '',
		);
		$data  = self::for_order( $order );
		if ( null === $data || ( '' === $data['code'] && '' === $data['url'] ) ) {
			return $empty;
		}
		return array(
			'tracking_carrier' => $data['carrier'],
			'tracking_code'    => $data['code'],
			'tracking_url'     => $data['url'],
			'tracking_block'   => self::block( $data ),
		);
	}

	/**
	 * "Seguí tu envío con {carrier}: código {code} {url}" (parts that do not exist are left out).
	 *
	 * @param array{carrier:string,code:string,url:string} $data Resolved data.
	 */
	public static function block( array $data ): string {
		$text = '' !== $data['carrier']
			/* translators: %s: carrier name. */
			? sprintf( __( 'Track your shipment with %s', 'motored-core' ), $data['carrier'] )
			: __( 'Track your shipment', 'motored-core' );
		if ( '' !== $data['code'] ) {
			/* translators: %s: tracking code. */
			$text .= ': ' . sprintf( __( 'code %s', 'motored-core' ), $data['code'] );
		}
		return trim( $text . ( '' !== $data['url'] ? ' ' . $data['url'] : '' ) );
	}
}
