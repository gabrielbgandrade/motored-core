<?php
/**
 * Pure pricing engine for payment plans.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

defined( 'ABSPATH' ) || exit;

/**
 * Integer-cent arithmetic only (no WordPress, no floats in the money path).
 */
final class PlanCalculator {

	/**
	 * Converts a decimal amount to cents (round half up).
	 *
	 * @param float|int|string $amount Amount in currency units.
	 */
	public static function to_cents( $amount ): int {
		$value = is_numeric( $amount ) ? (float) $amount : 0.0;
		return (int) round( $value * 100, 0, PHP_ROUND_HALF_UP );
	}

	/**
	 * Base + base * pct, rounded to cents (half up).
	 *
	 * @param int   $base Base in cents (negative is treated as 0).
	 * @param float $pct  Percent adjustment (negative = discount).
	 */
	public static function total( int $base, float $pct ): int {
		$base = max( 0, $base );
		// Percent in hundredths, so the whole operation is exact integer math.
		$hundredths = (int) round( $pct * 100, 0, PHP_ROUND_HALF_UP );
		$factor     = max( 0, 10000 + $hundredths );
		return intdiv( $base * $factor + 5000, 10000 );
	}

	/**
	 * Splits a total into N installments; the leftover cents go to the last one.
	 *
	 * @param int $total Total in cents.
	 * @param int $n     Installments (>= 1).
	 * @return list<int>
	 */
	public static function split( int $total, int $n ): array {
		$n       = max( 1, $n );
		$total   = max( 0, $total );
		$regular = intdiv( $total, $n );
		$list    = $n > 1 ? array_fill( 0, $n - 1, $regular ) : array();
		$list[]  = $total - $regular * ( $n - 1 );
		return $list;
	}

	/**
	 * Prices a plan over a base amount.
	 *
	 * @param Plan $plan Plan.
	 * @param int  $base Base in cents.
	 */
	public static function quote( Plan $plan, int $base ): Quote {
		$base     = max( 0, $base );
		$total    = self::total( $base, $plan->pct );
		$n        = max( 1, $plan->max_installments );
		$schedule = self::split( $total, $n );
		return new Quote( $plan, $base, $total, $n, $schedule[0], $schedule[ $n - 1 ] );
	}
}
