<?php
/**
 * Limits the installments/payment types of a Checkout Pro preference to the order's plan (fail closed).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\MercadoPago;

use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\PaymentPlans\CheckoutPlans;
use Motored\Core\Modules\PaymentPlans\Config;
use Motored\Core\Modules\PaymentPlans\Plan;
use Motored\Core\Modules\PaymentPlans\PlanCalculator;
use Motored\Core\Modules\PaymentPlans\PreferenceRewriter;
use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The Mercado Pago plugin has no preference filter, so the outgoing HTTP request is the only hook (VERIFY.md #80).
 * `http_request_args` rewrites the body; when that is not possible it flags the request and `pre_http_request`
 * answers with a WP_Error, so a preference WITHOUT the plan limit is never sent for an order that has a plan.
 */
final class PreferenceFilter {

	public const FLAG = 'motored_plan_block';

	/**
	 * Registers the two HTTP filters (late for the args, first for the block).
	 */
	public static function register(): void {
		add_filter( 'http_request_args', array( self::class, 'filter_args' ), 9999, 2 );
		add_filter( 'pre_http_request', array( self::class, 'block' ), 1, 3 );
	}

	/**
	 * Rewrites POST api.mercadopago.com/v1/asgard/preferences; everything else is returned untouched.
	 *
	 * @param mixed $args Request args.
	 * @param mixed $url  Request URL.
	 * @return mixed
	 */
	public static function filter_args( $args, $url ) {
		if ( ! is_array( $args ) || ! is_string( $url ) || 'POST' !== strtoupper( (string) ( $args['method'] ?? '' ) ) || false === strpos( $url, CheckoutPro::PREFERENCE_PATH ) ) {
			return $args;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || CheckoutPro::API_HOST !== strtolower( (string) ( $parts['host'] ?? '' ) ) || CheckoutPro::PREFERENCE_PATH !== rtrim( (string) ( $parts['path'] ?? '' ), '/' ) ) {
			return $args;
		}
		return self::guard( $args );
	}

	/**
	 * Hook pre_http_request: turns the flag into a transport error (message for the customer, es_AR through i18n).
	 *
	 * @param mixed $pre  Previous short-circuit value.
	 * @param mixed $args Request args.
	 * @param mixed $url  Request URL (unused).
	 * @return mixed
	 */
	public static function block( $pre, $args, $url = null ) {
		unset( $url );
		if ( false !== $pre || ! is_array( $args ) || empty( $args[ self::FLAG ] ) ) {
			return $pre;
		}
		return new \WP_Error( 'motored_plan_blocked', __( 'We could not start the payment with the selected plan. Please try again in a few minutes or contact the store.', 'motored-core' ) );
	}

	/**
	 * Decides for one preference request.
	 *
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>
	 */
	private static function guard( array $args ): array {
		$body     = is_string( $args['body'] ?? null ) ? json_decode( $args['body'] ) : null;
		$order_id = is_object( $body ) && isset( $body->external_reference ) && is_string( $body->external_reference )
			? CheckoutPro::order_id_from_reference( $body->external_reference )
			: 0;
		$order    = OrderHooks::load_order( $order_id );

		if ( null === $order ) {
			// We cannot prove the order has no plan: with plans in use never send an unlimited preference.
			return Config::enabled() ? self::deny( $args, 'order', 0 ) : $args;
		}

		$plan_id = (string) $order->get_meta( CheckoutPlans::META_ID );
		if ( '' === $plan_id ) {
			return $args;
		}

		$max  = (int) $order->get_meta( CheckoutPlans::META_MAX );
		$type = (string) $order->get_meta( CheckoutPlans::META_TYPE );
		if ( '' === $type ) {
			$known = Config::plans()->all()[ $plan_id ] ?? null;
			$type  = null !== $known ? $known->type : '';
		}
		if ( $max < 1 || ! in_array( $type, array( Plan::TYPE_CASH, Plan::TYPE_CARD ), true ) ) {
			return self::deny( $args, 'plan', $order_id );
		}

		$rewriter = new PreferenceRewriter();
		$new      = $rewriter->apply( $body, $max, $type, PlanCalculator::to_cents( (float) $order->get_total() ), '0' !== (string) $order->get_meta( CheckoutPlans::META_CREDIT ) );
		$encoded  = null === $new ? false : wp_json_encode( $new );
		if ( null === $new || ! is_string( $encoded ) ) {
			return self::deny( $args, $rewriter->error() ?? 'encode', $order_id );
		}

		$args['body'] = $encoded;
		return $args;
	}

	/**
	 * Flags the request as blocked, logs without personal data and leaves a private order note.
	 *
	 * @param array<string,mixed> $args     Request args.
	 * @param string              $code     Reason code.
	 * @param int                 $order_id Order id (0 = unknown).
	 * @return array<string,mixed>
	 */
	private static function deny( array $args, string $code, int $order_id ): array {
		$args[ self::FLAG ] = $code;
		Logger::error(
			'Mercado Pago preference blocked: the plan limit could not be applied.',
			array(
				'reason'   => $code,
				'order_id' => $order_id,
			)
		);
		if ( $order_id > 0 ) {
			OrderHooks::add_private_note(
				$order_id,
				/* translators: %s: short reason code */
				sprintf( __( 'Payment blocked: the installments limit of the plan could not be applied to the Mercado Pago preference (%s).', 'motored-core' ), $code )
			);
		}
		return $args;
	}
}
