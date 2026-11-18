<?php
/**
 * Applies the plan limits to a Checkout Pro preference body (pure).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

defined( 'ABSPATH' ) || exit;

/**
 * Works on the decoded body as objects (`json_decode( $json )`), so empty JSON objects stay `{}` when re-encoded.
 * Only `payment_methods.installments`, `payment_methods.default_installments` (clamp) and, for cash plans,
 * `payment_methods.excluded_payment_types` are touched; every other key is left as the plugin sent it.
 */
final class PreferenceRewriter {

	/**
	 * Payment type ids excluded by cash plans (Mercado Pago payment types; `account_money` must never be excluded).
	 */
	public const CASH_EXCLUDED_TYPES = array( 'credit_card', 'debit_card' );

	/**
	 * Payment method ids excluded by every plan: "Cuotas sin Tarjeta" (Mercado Pago credit) finances outside the plan
	 * (the id and whether MP accepts it in `excluded_payment_methods` are unverified, VERIFY.md #86).
	 */
	public const EXCLUDED_METHODS = array( 'consumer_credits' );

	public const ERR_BODY   = 'body';
	public const ERR_ITEMS  = 'items';
	public const ERR_TOTAL  = 'total';
	public const ERR_FORMAT = 'format';

	/**
	 * Result of the last call (null = ok).
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Error code of the last apply() call, or null.
	 */
	public function error(): ?string {
		return $this->error;
	}

	/**
	 * Returns the body with the plan limits, or null (see error()) when it cannot be safely changed or verified.
	 *
	 * @param mixed  $body        Decoded body (object).
	 * @param int    $max         Maximum installments of the plan.
	 * @param string $type        Plan type (cash|card).
	 * @param int    $total_cents Order total in cents.
	 */
	public function apply( $body, int $max, string $type, int $total_cents ): ?object {
		$this->error = null;

		if ( ! is_object( $body ) || $max < 1 ) {
			return $this->fail( self::ERR_BODY );
		}
		if ( ! $this->items_match( $body->items ?? null, $total_cents ) ) {
			return $this->fail( isset( $body->items ) && is_array( $body->items ) && array() !== $body->items ? self::ERR_TOTAL : self::ERR_ITEMS );
		}

		$methods = $body->payment_methods ?? new \stdClass();
		if ( is_array( $methods ) && array() === $methods ) {
			$methods = new \stdClass();
		}
		if ( ! is_object( $methods ) ) {
			return $this->fail( self::ERR_FORMAT );
		}

		$current               = $methods->installments ?? null;
		$methods->installments = is_numeric( $current ) && (int) $current > 0 ? min( (int) $current, $max ) : $max;

		$default = $methods->default_installments ?? null;
		if ( is_numeric( $default ) && (int) $default > $max ) {
			$methods->default_installments = $max;
		}

		$excluded = $methods->excluded_payment_methods ?? array();
		if ( ! is_array( $excluded ) ) {
			return $this->fail( self::ERR_FORMAT );
		}
		$known = array();
		foreach ( $excluded as $entry ) {
			if ( ! is_object( $entry ) || ! isset( $entry->id ) || ! is_string( $entry->id ) ) {
				return $this->fail( self::ERR_FORMAT );
			}
			$known[] = $entry->id;
		}
		foreach ( self::EXCLUDED_METHODS as $id ) {
			if ( ! in_array( $id, $known, true ) ) {
				$excluded[] = (object) array( 'id' => $id );
			}
		}
		$methods->excluded_payment_methods = $excluded;

		if ( Plan::TYPE_CASH === $type ) {
			$types = $methods->excluded_payment_types ?? array();
			if ( ! is_array( $types ) ) {
				return $this->fail( self::ERR_FORMAT );
			}
			$have = array();
			foreach ( $types as $entry ) {
				if ( ! is_object( $entry ) || ! isset( $entry->id ) || ! is_string( $entry->id ) ) {
					return $this->fail( self::ERR_FORMAT );
				}
				$have[] = $entry->id;
			}
			foreach ( self::CASH_EXCLUDED_TYPES as $id ) {
				if ( ! in_array( $id, $have, true ) ) {
					$types[] = (object) array( 'id' => $id );
				}
			}
			$methods->excluded_payment_types = $types;
		}

		$body->payment_methods = $methods;
		return $body;
	}

	/**
	 * Sum of unit_price * quantity equals the order total (1 cent tolerance).
	 *
	 * @param mixed $items       Items array.
	 * @param int   $total_cents Order total in cents.
	 */
	private function items_match( $items, int $total_cents ): bool {
		if ( ! is_array( $items ) || array() === $items ) {
			return false;
		}
		$sum = 0;
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! isset( $item->unit_price ) || ! is_numeric( $item->unit_price ) ) {
				return false;
			}
			$quantity = $item->quantity ?? 1;
			if ( ! is_numeric( $quantity ) || (float) $quantity <= 0 ) {
				return false;
			}
			$sum += PlanCalculator::to_cents( (float) $item->unit_price * (float) $quantity );
		}
		return abs( $sum - $total_cents ) <= 1;
	}

	/**
	 * Records an error and returns null.
	 *
	 * @param string $code Error code.
	 * @return null
	 */
	private function fail( string $code ) {
		$this->error = $code;
		return null;
	}
}
