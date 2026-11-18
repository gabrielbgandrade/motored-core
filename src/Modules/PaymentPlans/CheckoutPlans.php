<?php
/**
 * Classic checkout: plan selector, session, cart fee and order meta.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

use Motored\Core\Integration\MercadoPago\CheckoutPro;
use Motored\Core\Integration\WooCommerce\CheckoutState;

defined( 'ABSPATH' ) || exit;

/**
 * Active only while Mercado Pago Checkout Pro is the chosen gateway and the customer has a plan in the session.
 * Blocks checkout is NOT covered yet (see README).
 */
final class CheckoutPlans {

	public const FIELD = 'motored_plan';

	public const META_ID     = '_motored_plan_id';
	public const META_MAX    = '_motored_plan_max_installments';
	public const META_PCT    = '_motored_plan_pct';
	public const META_BASE   = '_motored_plan_base';
	public const META_FEE    = '_motored_plan_fee';
	public const META_TYPE   = '_motored_plan_type';
	public const META_CREDIT = '_motored_plan_excl_credit';
	public const WRAPPER_JS  = '.motored-plan-selector';

	/**
	 * Hook update_order_review: remembers the posted plan (or forgets it for other gateways).
	 *
	 * @param mixed $post_data Serialized checkout form.
	 */
	public function on_update_order_review( $post_data ): void {
		if ( ! Config::enabled() ) {
			return;
		}
		$data = array();
		parse_str( is_string( $post_data ) ? $post_data : '', $data );

		$method = isset( $data['payment_method'] ) && is_string( $data['payment_method'] ) ? $data['payment_method'] : '';
		if ( CheckoutPro::GATEWAY_ID !== $method ) {
			CheckoutState::set_plan_id( '' );
			return;
		}
		$posted = isset( $data[ self::FIELD ] ) && is_string( $data[ self::FIELD ] ) ? $data[ self::FIELD ] : '';
		$this->store( $posted );
	}

	/**
	 * Hook posted_data (place order): same rule, read from the submitted form.
	 *
	 * @param mixed $data Posted checkout data.
	 * @return mixed
	 */
	public function on_posted_data( $data ) {
		if ( ! is_array( $data ) || ! Config::enabled() ) {
			return $data;
		}
		if ( CheckoutPro::GATEWAY_ID !== ( $data['payment_method'] ?? '' ) ) {
			CheckoutState::set_plan_id( '' );
			return $data;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before building the posted data; the value is only matched against our plan ids.
		$posted = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) : CheckoutState::plan_id();
		$this->store( $posted );
		return $data;
	}

	/**
	 * Hook calculate_fees: negative fee for discounts, positive for surcharges, never taxed.
	 *
	 * @param mixed $cart Cart.
	 */
	public function add_fee( $cart ): void {
		if ( ! $cart instanceof \WC_Cart || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		$quote = $this->current_quote( $cart );
		if ( null === $quote || 0 === $quote->fee() ) {
			return;
		}
		$cart->fees_api()->add_fee(
			array(
				'name'    => Presenter::fee_label( $quote->plan ),
				'amount'  => $quote->fee() / 100,
				'taxable' => false,
			)
		);
	}

	/**
	 * Hook create_order: records the plan on the order (CRUD, HPOS-safe) or clears a stale one.
	 *
	 * @param mixed $order Order being created (or the reused unpaid one).
	 */
	public function save_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$cart  = CheckoutState::cart();
		$quote = null !== $cart ? $this->current_quote( $cart ) : null;

		if ( null === $quote ) {
			foreach ( array( self::META_ID, self::META_MAX, self::META_PCT, self::META_BASE, self::META_FEE, self::META_TYPE, self::META_CREDIT ) as $key ) {
				$order->delete_meta_data( $key );
			}
			return;
		}
		$order->update_meta_data( self::META_ID, $quote->plan->id );
		$order->update_meta_data( self::META_TYPE, $quote->plan->type );
		$order->update_meta_data( self::META_CREDIT, $quote->plan->exclude_credit ? '1' : '0' );
		$order->update_meta_data( self::META_MAX, (string) $quote->plan->max_installments );
		$order->update_meta_data( self::META_PCT, (string) $quote->plan->pct );
		$order->update_meta_data( self::META_BASE, wc_format_decimal( $quote->base / 100, 2 ) );
		$order->update_meta_data( self::META_FEE, wc_format_decimal( $quote->fee() / 100, 2 ) );
	}

	/**
	 * Hook before_payment: prints the selector.
	 */
	public function render_selector(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in selector_html().
		echo $this->selector_html();
	}

	/**
	 * Hook order_review_fragments: keeps the selector in sync with the chosen gateway.
	 *
	 * @param mixed $fragments Fragments (selector => html).
	 * @return mixed
	 */
	public function add_fragment( $fragments ) {
		if ( is_array( $fragments ) && Config::enabled() ) {
			$fragments[ self::WRAPPER_JS ] = $this->selector_html();
		}
		return $fragments;
	}

	/**
	 * Selector markup. The wrapper always exists (empty for other gateways) so the fragment can replace it.
	 */
	public function selector_html(): string {
		if ( ! Config::enabled() ) {
			return '';
		}
		$wrapper = '<div class="motored-plan-selector update_totals_on_change">%s</div>';
		$cart    = CheckoutState::cart();
		$plans   = Config::plans();
		$active  = $plans->active();
		if ( null === $cart || CheckoutPro::GATEWAY_ID !== CheckoutState::chosen_gateway() || array() === $active ) {
			return sprintf( $wrapper, '' );
		}

		$base     = CheckoutState::cart_base_cents( $cart );
		$selected = $plans->resolve( CheckoutState::plan_id() );
		$items    = '';
		foreach ( $active as $plan ) {
			$row    = Presenter::row( PlanCalculator::quote( $plan, $base ) );
			$text   = $row[0] . ': ' . $row[1] . ( '' !== $row[2] ? ' (' . $row[2] . ')' : '' );
			$items .= sprintf(
				'<li><label><input type="radio" name="%1$s" value="%2$s"%3$s> %4$s</label></li>',
				esc_attr( self::FIELD ),
				esc_attr( $plan->id ),
				null !== $selected && $selected->id === $plan->id ? ' checked' : '',
				esc_html( $text )
			);
		}

		return sprintf(
			$wrapper,
			'<p><strong>' . esc_html__( 'Choose your payment plan', 'motored-core' ) . '</strong></p><ul style="list-style:none;margin:0 0 1em;padding:0">' . $items . '</ul>'
		);
	}

	/**
	 * Quote for the current cart: null when the module is off, the gateway is another one or no plan is in the session.
	 * The plan is always re-validated (exists and is active, otherwise the default plan).
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function current_quote( \WC_Cart $cart ): ?Quote {
		// Cheapest checks first: this runs on every cart calculation, including mini-cart fragments.
		$id = CheckoutState::plan_id();
		if ( '' === $id || CheckoutPro::GATEWAY_ID !== CheckoutState::chosen_gateway() || ! Config::enabled() ) {
			return null;
		}
		$plan = Config::plans()->resolve( $id );
		return null === $plan ? null : PlanCalculator::quote( $plan, CheckoutState::cart_base_cents( $cart ) );
	}

	/**
	 * Validates and stores a requested plan id (unknown or inactive falls back to the default plan).
	 *
	 * @param string $requested Untrusted id.
	 */
	private function store( string $requested ): void {
		$plan = Config::plans()->resolve( sanitize_key( $requested ) );
		CheckoutState::set_plan_id( null === $plan ? '' : $plan->id );
	}
}
