<?php
/**
 * Compares what Mercado Pago recorded on a paid order with the plan the customer chose.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Integration\MercadoPago\CheckoutPro;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Never cancels or refunds: a divergence only adds a private note, sets the order on-hold and e-mails the administrator.
 * Reads plain order meta only (CheckoutPro::payments); runs when the order becomes processing/completed and again
 * DELAY seconds later (Action Scheduler), because the plugin may write its payment meta after the status change.
 */
final class Reconciler {

	public const HOOK       = 'motored_plans_reconcile';
	public const META_STATE = '_motored_plan_recon';
	public const DELAY      = 120;

	public const STATE_OK         = 'ok';
	public const STATE_DIVERGED   = 'diverged';
	public const STATE_UNVERIFIED = 'unverified';

	/**
	 * Plans with a lower maximum cannot be paid with Mercado Pago credit.
	 */
	public const CREDIT_MIN_INSTALLMENTS = 12;

	/**
	 * Whether a payment used Mercado Pago's own credit ("Cuotas sin Tarjeta"). The real type/method ids are
	 * unverified (VERIFY.md #86): the method `consumer_credits` or any non-card type containing "credit".
	 *
	 * @param string $type   Payment type id recorded by the plugin.
	 * @param string $method Payment method id recorded by the plugin.
	 */
	public static function is_mp_credit( string $type, string $method ): bool {
		return 'consumer_credits' === $method || ( str_contains( $type, 'credit' ) && 'credit_card' !== $type );
	}

	/**
	 * Pure rules. Returns the (translated) divergences; empty = consistent.
	 *
	 * @param int                                                                                          $max         Plan maximum installments.
	 * @param string                                                                                       $type        Plan type.
	 * @param int                                                                                          $total_cents Order total in cents.
	 * @param list<array{id:string,type:string,method:string,amount:?float,paid:?float,installments:?int}> $payments    Payments recorded by the plugin.
	 * @return list<string>
	 */
	public static function evaluate( int $max, string $type, int $total_cents, array $payments ): array {
		$reasons  = array();
		$amount   = 0;
		$paid     = 0;
		$has_amt  = false;
		$all_paid = true;

		foreach ( $payments as $payment ) {
			$card = str_contains( $payment['type'], 'card' );
			if ( $card && null !== $payment['installments'] && $payment['installments'] > $max ) {
				$reasons[] = sprintf(
					/* translators: 1: installments used, 2: maximum of the plan */
					__( 'paid in %1$d installments but the plan allows up to %2$d', 'motored-core' ),
					$payment['installments'],
					$max
				);
			}
			if ( $max < self::CREDIT_MIN_INSTALLMENTS && self::is_mp_credit( $payment['type'], $payment['method'] ) ) {
				$reasons[] = __( 'paid with Mercado Pago credit (Cuotas sin Tarjeta) but the plan allows fewer than 12 installments', 'motored-core' );
			}
			if ( $card && Plan::TYPE_CASH === $type ) {
				$reasons[] = __( 'paid with a card but the plan is cash/transfer', 'motored-core' );
			}
			if ( null !== $payment['amount'] ) {
				$has_amt = true;
				$amount += PlanCalculator::to_cents( $payment['amount'] );
			}
			if ( null !== $payment['paid'] && $payment['paid'] > 0 ) {
				$paid += PlanCalculator::to_cents( $payment['paid'] );
			} else {
				$all_paid = false;
			}
		}

		if ( $has_amt && abs( $amount - $total_cents ) > 1 ) {
			$reasons[] = sprintf(
				/* translators: 1: amount recorded by Mercado Pago, 2: order total */
				__( 'the payment amount (%1$s) differs from the order total (%2$s)', 'motored-core' ),
				Presenter::money( $amount ),
				Presenter::money( $total_cents )
			);
		}
		if ( $all_paid && array() !== $payments && abs( $paid - $total_cents ) > 1 ) {
			$reasons[] = sprintf(
				/* translators: 1: amount paid by the customer, 2: order total */
				__( 'the customer paid %1$s but the order total is %2$s', 'motored-core' ),
				Presenter::money( $paid ),
				Presenter::money( $total_cents )
			);
		}
		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Listener of woocommerce_order_status_changed.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $from     Old status.
	 * @param mixed $to       New status.
	 * @param mixed $order    Order (optional).
	 */
	public function on_status_changed( $order_id, $from = '', $to = '', $order = null ): void {
		unset( $from );
		if ( ! in_array( $to, array( 'processing', 'completed' ), true ) ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : OrderHooks::load_order( (int) $order_id );
		if ( null === $order || '' === (string) $order->get_meta( CheckoutPlans::META_ID ) ) {
			return;
		}
		if ( self::STATE_OK !== $this->check( $order, false ) ) {
			ActionScheduler::schedule( time() + self::DELAY, self::HOOK, array( $order->get_id() ) );
		}
	}

	/**
	 * Action Scheduler handler (second and last look).
	 *
	 * @param mixed $order_id Order id.
	 */
	public function run( $order_id ): void {
		$order = OrderHooks::load_order( (int) $order_id );
		if ( null !== $order && '' !== (string) $order->get_meta( CheckoutPlans::META_ID ) ) {
			$this->check( $order, true );
		}
	}

	/**
	 * Verifies one order; returns the resulting state ('' when nothing could be decided yet).
	 *
	 * @param \WC_Order $order Order with plan meta.
	 * @param bool      $last Last attempt: no data at all is reported.
	 */
	private function check( \WC_Order $order, bool $last ): string {
		$state = (string) $order->get_meta( self::META_STATE );
		if ( in_array( $state, array( self::STATE_OK, self::STATE_DIVERGED ), true ) ) {
			return $state;
		}

		$payments = CheckoutPro::payments( $order );
		if ( array() === $payments ) {
			if ( $last && self::STATE_UNVERIFIED !== $state ) {
				$this->mark( $order, self::STATE_UNVERIFIED );
				$order->add_order_note( __( 'Payment plan not verified: no Mercado Pago payment data was found on the order.', 'motored-core' ) );
			}
			return '';
		}

		$reasons = self::evaluate(
			(int) $order->get_meta( CheckoutPlans::META_MAX ),
			(string) $order->get_meta( CheckoutPlans::META_TYPE ),
			PlanCalculator::to_cents( (float) $order->get_total() ),
			$payments
		);
		if ( array() === $reasons ) {
			$this->mark( $order, self::STATE_OK );
			return self::STATE_OK;
		}

		$this->mark( $order, self::STATE_DIVERGED );
		$note = sprintf(
			/* translators: %s: list of divergences */
			__( 'Payment plan mismatch: %s. The order was put on hold for review; nothing was cancelled or refunded.', 'motored-core' ),
			implode( '; ', $reasons )
		);
		Logger::warning( 'Payment plan mismatch on a paid order.', array( 'order_id' => $order->get_id() ) );
		$order->update_status( 'on-hold', $note );
		$this->notify_admin( $order, $reasons );
		return self::STATE_DIVERGED;
	}

	/**
	 * Stores the reconciliation state.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $state State.
	 */
	private function mark( \WC_Order $order, string $state ): void {
		$order->update_meta_data( self::META_STATE, $state );
		if ( self::STATE_DIVERGED === $state ) {
			// Set BEFORE the status change below: customer notices and Recovery check it (OrderHooks::has_plan_mismatch).
			$order->update_meta_data( OrderHooks::PLAN_MISMATCH_META, '1' );
		}
		$order->save_meta_data();
	}

	/**
	 * E-mails the store administrator (order number and reasons only, no customer data).
	 *
	 * @param \WC_Order         $order   Order.
	 * @param array<int,string> $reasons Divergences.
	 */
	private function notify_admin( \WC_Order $order, array $reasons ): void {
		$to = (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return;
		}
		$message = sprintf(
			/* translators: 1: order number, 2: divergences */
			__( 'Order #%1$s was paid with installments or an amount that its payment plan does not allow: %2$s.', 'motored-core' ),
			$order->get_order_number(),
			implode( '; ', $reasons )
		)
			. "\n\n" . __( 'The order was put on hold. Nothing was cancelled or refunded.', 'motored-core' )
			/* translators: %s: order admin URL */
			. "\n\n" . sprintf( __( 'Review it here: %s', 'motored-core' ), $order->get_edit_order_url() );

		wp_mail(
			$to,
			/* translators: %s: order number */
			sprintf( __( 'Review order #%s: payment plan mismatch', 'motored-core' ), $order->get_order_number() ),
			$message
		);
	}
}
