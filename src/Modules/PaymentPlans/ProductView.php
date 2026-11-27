<?php
/**
 * Storefront data for one price: the summary line and the rows of the dialog.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side single source of truth; the browser never recalculates anything.
 */
final class ProductView {

	/**
	 * Builds the view for a display price (tax handling already applied by WooCommerce).
	 *
	 * @param Plans            $plans         Configured plans.
	 * @param float|int|string $display_price Price in currency units.
	 * @return array{l:string,r:list<array{0:string,1:string,2:string}>,n?:string,a?:string,c?:array{p:string,o:string}}|null Null when there is nothing to show.
	 */
	public static function build( Plans $plans, $display_price ): ?array {
		$base = PlanCalculator::to_cents( $display_price );
		$list = $plans->default_plan();
		if ( $base <= 0 || null === $list ) {
			return null;
		}

		$rows = array();
		$cash = null;
		foreach ( $plans->active() as $plan ) {
			$quote  = PlanCalculator::quote( $plan, $base );
			$rows[] = Presenter::row( $quote );
			// Best one-payment price (transfer / account money): shown on product cards.
			if ( Plan::TYPE_CASH === $plan->type && $plan->pct < 0 && ( null === $cash || $quote->total < $cash->total ) ) {
				$cash = $quote;
			}
		}

		$list_quote = PlanCalculator::quote( $list, $base );
		$view       = array(
			'l' => Presenter::line( $list_quote ),
			'r' => $rows,
		);
		// Parts of the list line for markup that stresses the amount ("6 cuotas sin interés de <strong>$X</strong>").
		if ( $list_quote->installments >= 2 ) {
			$view['n'] = $list_quote->plan->name;
			$view['a'] = Presenter::money( $list_quote->installment );
		}
		if ( null !== $cash ) {
			$view['c'] = array(
				'p' => Presenter::money( $cash->total ),
				'o' => Presenter::percent( $cash->plan->pct ),
			);
		}
		return $view;
	}
}
