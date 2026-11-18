<?php
/**
 * Customer-facing text for plans (single source of truth for storefront and checkout).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

defined( 'ABSPATH' ) || exit;

/**
 * Builds plain-text strings on the server. Discounts show "$total (X% OFF)"; surcharges NEVER show the percentage.
 */
final class Presenter {

	/**
	 * Plain-text price (no markup), e.g. "$ 1.234,00".
	 *
	 * @param int $cents Amount in cents.
	 */
	public static function money( int $cents ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $cents / 100 ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Discount percentage without trailing zeros ("5", "7,5").
	 *
	 * @param float $pct Percent (sign ignored).
	 */
	public static function percent( float $pct ): string {
		return rtrim( rtrim( number_format( abs( $pct ), 2, ',', '' ), '0' ), ',' );
	}

	/**
	 * "N payments of $X" (empty for a single payment).
	 *
	 * @param Quote $quote Quote.
	 */
	public static function installments_text( Quote $quote ): string {
		if ( $quote->installments < 2 ) {
			return '';
		}
		return sprintf(
			/* translators: 1: number of installments, 2: amount of each installment */
			_n( '%1$d payment of %2$s', '%1$d payments of %2$s', $quote->installments, 'motored-core' ),
			$quote->installments,
			self::money( $quote->installment )
		);
	}

	/**
	 * Row for the dialog and the checkout list: name, main amount, detail.
	 *
	 * @param Quote $quote Quote.
	 * @return array{0:string,1:string,2:string}
	 */
	public static function row( Quote $quote ): array {
		$total        = self::money( $quote->total );
		$installments = self::installments_text( $quote );

		if ( $quote->plan->pct < 0 ) {
			return array(
				$quote->plan->name,
				sprintf(
					/* translators: 1: total price, 2: discount percentage */
					__( '%1$s (%2$s%% OFF)', 'motored-core' ),
					$total,
					self::percent( $quote->plan->pct )
				),
				$installments,
			);
		}

		if ( '' === $installments ) {
			return array( $quote->plan->name, $total, '' );
		}

		return array(
			$quote->plan->name,
			$installments,
			/* translators: %s: total price */
			sprintf( __( 'Total %s', 'motored-core' ), $total ),
		);
	}

	/**
	 * The one-line summary shown under the price, e.g. "6 cuotas sin interés de $ 1.000,00".
	 *
	 * @param Quote $quote Quote of the list plan.
	 */
	public static function line( Quote $quote ): string {
		if ( $quote->installments < 2 ) {
			/* translators: 1: plan name, 2: total price */
			return sprintf( __( '%1$s: %2$s', 'motored-core' ), $quote->plan->name, self::money( $quote->total ) );
		}
		/* translators: 1: plan name (e.g. "6 interest-free installments"), 2: amount of each installment */
		return sprintf( __( '%1$s of %2$s', 'motored-core' ), $quote->plan->name, self::money( $quote->installment ) );
	}

	/**
	 * Name of the cart fee.
	 *
	 * @param Plan $plan Plan.
	 */
	public static function fee_label( Plan $plan ): string {
		if ( Plan::TYPE_CASH === $plan->type ) {
			return __( 'Discount for paying by transfer, account money or cash', 'motored-core' );
		}
		if ( $plan->pct < 0 ) {
			return sprintf(
				/* translators: %d: number of installments */
				_n( 'Discount for paying in %d installment', 'Discount for paying in %d installments', $plan->max_installments, 'motored-core' ),
				$plan->max_installments
			);
		}
		return sprintf(
			/* translators: %d: number of installments */
			_n( 'Financing in %d installment', 'Financing in %d installments', $plan->max_installments, 'motored-core' ),
			$plan->max_installments
		);
	}
}
