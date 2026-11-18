<?php
/**
 * Compares the Mercado Pago Checkout Pro settings with the plans.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules; the caller passes the (read-only) gateway settings.
 */
final class GatewayCheck {

	/**
	 * Warnings for the admin (translated, plain text).
	 *
	 * @param array{discount:?float,commission:?float,installments:?int} $gateway      Gateway settings.
	 * @param int                                                        $plan_max_inst Highest installment count among active plans.
	 * @return list<string>
	 */
	public static function warnings( array $gateway, int $plan_max_inst ): array {
		$warnings = array();

		if ( null !== $gateway['discount'] && 0.0 !== $gateway['discount'] ) {
			$warnings[] = sprintf(
				/* translators: %s: percentage configured in the gateway */
				__( 'Mercado Pago Checkout Pro has a discount of %s%%. Set it to 0: the plans already apply the discounts.', 'motored-core' ),
				Presenter::percent( $gateway['discount'] )
			);
		}
		if ( null !== $gateway['commission'] && 0.0 !== $gateway['commission'] ) {
			$warnings[] = sprintf(
				/* translators: %s: percentage configured in the gateway */
				__( 'Mercado Pago Checkout Pro has a commission of %s%%. Set it to 0: the plans already apply the surcharges.', 'motored-core' ),
				Presenter::percent( $gateway['commission'] )
			);
		}
		if ( null !== $gateway['installments'] && $gateway['installments'] < $plan_max_inst ) {
			$warnings[] = sprintf(
				/* translators: 1: maximum installments in the gateway, 2: highest number of installments among the active plans */
				__( 'Mercado Pago Checkout Pro allows up to %1$d installments, but your longest active plan has %2$d.', 'motored-core' ),
				$gateway['installments'],
				$plan_max_inst
			);
		}

		return $warnings;
	}
}
