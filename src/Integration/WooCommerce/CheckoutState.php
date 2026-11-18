<?php
/**
 * WooCommerce session and cart reading for the classic checkout (unverified, see docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration\WooCommerce;

use Motored\Core\Modules\PaymentPlans\PlanCalculator;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that touches WC()->session and cart totals for PaymentPlans.
 */
final class CheckoutState {

	public const SESSION_KEY = 'motored_plan';

	/**
	 * Gateway id currently chosen (WooCommerce session), or ''.
	 */
	public static function chosen_gateway(): string {
		$session = self::session();
		$value   = null !== $session ? $session->get( 'chosen_payment_method' ) : '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Plan id stored in the session ('' = none chosen).
	 */
	public static function plan_id(): string {
		$session = self::session();
		$value   = null !== $session ? $session->get( self::SESSION_KEY ) : '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Stores the plan id ('' clears it).
	 *
	 * @param string $id Plan id.
	 */
	public static function set_plan_id( string $id ): void {
		$session = self::session();
		if ( null !== $session ) {
			$session->set( self::SESSION_KEY, '' === $id ? null : $id );
		}
	}

	/**
	 * Base of the plans at checkout in cents: product lines after coupons plus their taxes, WITHOUT shipping
	 * and WITHOUT fees (so our own fee never feeds back into the base).
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function cart_base_cents( \WC_Cart $cart ): int {
		$cents = PlanCalculator::to_cents( (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax() );
		if ( $cents > 0 ) {
			return $cents;
		}
		// During the fee action the cart totals may not be filled yet: sum the lines instead.
		$sum = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			if ( is_array( $item ) ) {
				$sum += (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 );
			}
		}
		return PlanCalculator::to_cents( $sum );
	}

	/**
	 * Current cart, or null outside a cart request.
	 */
	public static function cart(): ?\WC_Cart {
		$wc = function_exists( 'WC' ) ? WC() : null;
		return is_object( $wc ) && $wc->cart instanceof \WC_Cart ? $wc->cart : null;
	}

	/**
	 * WooCommerce session handler, or null (REST, CLI).
	 */
	private static function session(): ?\WC_Session {
		$wc = function_exists( 'WC' ) ? WC() : null;
		return is_object( $wc ) && $wc->session instanceof \WC_Session ? $wc->session : null;
	}
}
