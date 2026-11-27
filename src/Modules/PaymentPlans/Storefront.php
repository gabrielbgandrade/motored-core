<?php
/**
 * Product pages and loops: "6 cuotas sin interés de $X" + link to the shared dialog.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

defined( 'ABSPATH' ) || exit;

/**
 * Core WooCommerce hooks only; block themes use the [motored_payment_plans] shortcode.
 */
final class Storefront {

	public const SHORTCODE = 'motored_payment_plans';

	/**
	 * Prints the line on the single product page.
	 */
	public function render_single(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in html_for().
		echo $this->html_for( $this->global_product(), false );
	}

	/**
	 * Prints the line in shop and category loops.
	 */
	public function render_loop(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in html_for().
		echo $this->html_for( $this->global_product(), true );
	}

	/**
	 * Shortcode [motored_payment_plans id="123"]: the current product when id is omitted.
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function shortcode( $atts ): string {
		$atts    = shortcode_atts( array( 'id' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$id      = absint( $atts['id'] );
		$product = $id > 0 ? wc_get_product( $id ) : $this->global_product();
		return $this->html_for( $product instanceof \WC_Product ? $product : null, false );
	}

	/**
	 * Adds the formatted plans of the variation to the data WooCommerce sends to the browser.
	 *
	 * @param mixed $data      Variation data.
	 * @param mixed $product   Variable product.
	 * @param mixed $variation Variation.
	 * @return mixed
	 */
	public function filter_variation( $data, $product, $variation ) {
		unset( $product, $variation );
		if ( ! is_array( $data ) || empty( $data['is_in_stock'] ) || ! isset( $data['display_price'] ) || ! Config::enabled() ) {
			return $data;
		}
		$view = ProductView::build( Config::plans(), $data['display_price'] );
		if ( null !== $view ) {
			$data['motored_plans'] = $view;
		}
		return $data;
	}

	/**
	 * Markup for a product; '' when there is nothing to show (no price, out of stock, module off).
	 *
	 * @param \WC_Product|null $product Product.
	 * @param bool             $in_loop Whether this is a loop item (variable products then use the minimum price only).
	 */
	public function html_for( ?\WC_Product $product, bool $in_loop ): string {
		if ( null === $product || ! $product->is_type( array( 'simple', 'variable' ) ) || ! $product->is_in_stock() || ! Config::enabled() ) {
			return '';
		}

		$variable = $product->is_type( 'variable' );
		if ( $variable && $product instanceof \WC_Product_Variable ) {
			$price = $product->get_variation_price( 'min', true );
		} else {
			$price = '' === (string) $product->get_price() ? '' : wc_get_price_to_display( $product );
		}

		$view = '' === $price ? null : ProductView::build( Config::plans(), $price );
		if ( null === $view ) {
			return '';
		}

		PlanDialog::request();

		if ( $in_loop ) {
			// Product cards: list line, the transfer / account money price (when a cash plan discounts) and the button.
			$cash = '';
			if ( isset( $view['c'] ) ) {
				$cash = sprintf(
					'<span class="motored-plans-cash"><strong class="motored-plans-cash__price">%1$s</strong> <span class="motored-plans-cash__off">%2$s</span> <span class="motored-plans-cash__label">%3$s</span></span>',
					esc_html( $view['c']['p'] ),
					/* translators: %s: discount percentage. */
					esc_html( sprintf( __( '%s%% OFF', 'motored-core' ), $view['c']['o'] ) ),
					esc_html__( 'with transfer or account money', 'motored-core' )
				);
			}
			return sprintf(
				'<div class="motored-plans motored-plans--loop"><span class="motored-plans-line">%1$s</span>%2$s <a href="#%3$s" class="motored-plans-link" data-motored-plans="%4$s">%5$s</a></div>',
				esc_html( $view['l'] ),
				$cash,
				esc_attr( PlanDialog::DOM_ID ),
				esc_attr( (string) wp_json_encode( $view ) ),
				esc_html__( 'See payment methods', 'motored-core' )
			);
		}

		return sprintf(
			'<div class="motored-plans"%1$s><span class="motored-plans-line">%2$s</span> <a href="#%3$s" class="motored-plans-link" data-motored-plans="%4$s">%5$s</a></div>',
			$variable && ! $in_loop ? ' data-mv="1"' : '',
			esc_html( $view['l'] ),
			esc_attr( PlanDialog::DOM_ID ),
			esc_attr( (string) wp_json_encode( $view ) ),
			esc_html__( 'See all payment methods', 'motored-core' )
		);
	}

	/**
	 * The product of the current loop/page.
	 */
	private function global_product(): ?\WC_Product {
		$product = $GLOBALS['product'] ?? null;
		if ( $product instanceof \WC_Product ) {
			return $product;
		}
		$id    = get_the_ID();
		$found = $id ? wc_get_product( $id ) : null;
		return $found instanceof \WC_Product ? $found : null;
	}
}
