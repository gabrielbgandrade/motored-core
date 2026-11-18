<?php
/**
 * Read-only view of the Mercado Pago Checkout Pro gateway settings (option keys read in the MP plugin 8.10.2 source; runtime unverified, see docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\MercadoPago;

defined( 'ABSPATH' ) || exit;

/**
 * Never writes and never calls Mercado Pago code.
 */
final class CheckoutPro {

	public const GATEWAY_ID = 'woo-mercado-pago-basic';

	/**
	 * Plugin version the preference/metadata adapters were validated against (source read in `reference/`).
	 */
	public const VALIDATED_VERSION = '8.10.2';

	/**
	 * Preference creation endpoint (`PreferencesClient::CREATE_PATH`) and the only host the plugin may call.
	 */
	public const PREFERENCE_PATH = '/v1/asgard/preferences';
	public const API_HOST        = 'api.mercadopago.com';

	/**
	 * Option holding the store prefix of `external_reference` (`Configs\Store::STORE_ID`); the plugin default is "WC-".
	 */
	public const STORE_ID_OPTION = '_mp_store_identificator';

	/**
	 * Discount, commission (percent) and maximum installments configured in the gateway; null = not readable.
	 *
	 * @return array{discount:?float,commission:?float,installments:?int}
	 */
	public static function settings(): array {
		$raw = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', array() );
		$raw = is_array( $raw ) ? $raw : array();

		$number = static fn( string $key ): ?float => isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? (float) $raw[ $key ] : null;
		// Discount and commission only apply when their "<key>_checkbox" is 'yes' (read in the MP plugin source, VERIFY.md).
		$actionable = static fn( string $key ): float => 'yes' === ( $raw[ $key . '_checkbox' ] ?? '' ) ? (float) ( $number( $key ) ?? 0.0 ) : 0.0;

		$installments = $number( 'installments' );
		if ( null !== $installments && 0.0 === $installments ) {
			$installments = 12.0; // The gateway treats 0 as 12.
		}

		return array(
			'discount'     => $actionable( 'gateway_discount' ),
			'commission'   => $actionable( 'commission' ),
			'installments' => null === $installments ? null : (int) $installments,
		);
	}

	/**
	 * Order id inside an `external_reference` ("<store id><order id>", `AbstractTransaction::getExternalReference`), or 0.
	 *
	 * @param string $reference external_reference of the preference.
	 */
	public static function order_id_from_reference( string $reference ): int {
		$prefix = get_option( self::STORE_ID_OPTION, 'WC-' );
		$prefix = is_string( $prefix ) ? $prefix : 'WC-';
		$rest   = '' !== $prefix && 0 === strpos( $reference, $prefix ) ? substr( $reference, strlen( $prefix ) ) : $reference;
		if ( 1 !== preg_match( '/^\D*(\d+)$/', $rest, $match ) ) {
			return 0;
		}
		return (int) $match[1];
	}

	/**
	 * Version of the active Mercado Pago plugin (file header), or null when it is not active. Admin screens only.
	 */
	public static function installed_version(): ?string {
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $active as $file ) {
			if ( ! is_string( $file ) || ! str_ends_with( $file, '/woocommerce-mercadopago.php' ) ) {
				continue;
			}
			if ( ! function_exists( 'get_plugin_data' ) ) {
				return null;
			}
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			return isset( $data['Version'] ) && is_string( $data['Version'] ) && '' !== $data['Version'] ? $data['Version'] : null;
		}
		return null;
	}

	/**
	 * Whether the active plugin is the validated version (false = review the integration; null = plugin not active).
	 */
	public static function version_matches(): ?bool {
		$version = self::installed_version();
		return null === $version ? null : self::VALIDATED_VERSION === $version;
	}

	/**
	 * Payments recorded by the plugin on the order, read from plain order meta (no plugin classes).
	 *
	 * Checkout Pro writes `Mercado Pago - Payment <id>` = "[Date ..]/[Amount ..]/[Payment Type ..]/[Payment Method ..]/[Paid ..]/..."
	 * and, for card payments only, `Mercado Pago - <id> - installments`.
	 *
	 * @param \WC_Order $order Order.
	 * @return list<array{id:string,type:string,amount:?float,paid:?float,installments:?int}>
	 */
	public static function payments( \WC_Order $order ): array {
		$found = array();
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = isset( $data['key'] ) && is_string( $data['key'] ) ? $data['key'] : '';
			if ( 1 === preg_match( '/^Mercado Pago - Payment (\d+)$/', $key, $m ) ) {
				$found[ $m[1] ]['line'] = is_scalar( $data['value'] ?? null ) ? (string) $data['value'] : '';
			} elseif ( 1 === preg_match( '/^Mercado Pago - (\d+) - installments$/', $key, $m ) && is_numeric( $data['value'] ?? null ) ) {
				$found[ $m[1] ]['installments'] = (int) $data['value'];
			}
		}

		$payments = array();
		foreach ( $found as $id => $info ) {
			$line   = (string) ( $info['line'] ?? '' );
			$field  = static function ( string $name ) use ( $line ): ?string {
				return 1 === preg_match( '/\[' . $name . ' ([^\]]*)\]/', $line, $m ) ? trim( $m[1] ) : null;
			};
			$amount = $field( 'Amount' );
			$paid   = $field( 'Paid' );

			$payments[] = array(
				'id'           => (string) $id,
				'type'         => (string) ( $field( 'Payment Type' ) ?? '' ),
				'amount'       => null !== $amount && is_numeric( $amount ) ? (float) $amount : null,
				'paid'         => null !== $paid && is_numeric( $paid ) ? (float) $paid : null,
				'installments' => $info['installments'] ?? null,
			);
		}
		return $payments;
	}
}
