<?php
/**
 * Result of pricing a plan over a base amount.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

defined( 'ABSPATH' ) || exit;

/**
 * All amounts are integer cents.
 */
final class Quote {

	/**
	 * Constructor.
	 *
	 * @param Plan $plan         Plan priced.
	 * @param int  $base         Base amount (list price).
	 * @param int  $total        Base plus adjustment.
	 * @param int  $installments Number of installments.
	 * @param int  $installment  Regular installment.
	 * @param int  $last         Last installment (carries the leftover cents).
	 */
	public function __construct(
		public readonly Plan $plan,
		public readonly int $base,
		public readonly int $total,
		public readonly int $installments,
		public readonly int $installment,
		public readonly int $last
	) {
	}

	/**
	 * Adjustment over the base (negative = discount).
	 */
	public function fee(): int {
		return $this->total - $this->base;
	}

	/**
	 * All installments; their sum is exactly the total.
	 *
	 * @return list<int>
	 */
	public function schedule(): array {
		$list   = $this->installments > 1 ? array_fill( 0, $this->installments - 1, $this->installment ) : array();
		$list[] = $this->last;
		return $list;
	}
}
