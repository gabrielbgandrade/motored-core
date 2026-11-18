<?php
/**
 * PaymentPlans module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

use Motored\Core\Contracts\Module;
use Motored\Core\Integration\MercadoPago\PreferenceFilter;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Integration\WooCommerce\PlanHooks;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Payment plans over the ERP (list) price: storefront line + dialog, classic checkout selector and fee.
 */
final class PaymentPlansModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'payment_plans';
	}

	/**
	 * Public pages, wc-ajax/admin-ajax (checkout refresh, variations), admin and cron (reconciliation job); never REST or CLI.
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		return $context->is_frontend() || $context->is_ajax() || $context->is_admin() || $context->is_cron();
	}

	/**
	 * Registers hooks only (no I/O, no option reads).
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::defaults() );
		$context = new Context();

		if ( $context->is_admin() ) {
			( new PaymentPlansAdmin() )->register();
		}

		// Reconciliation: status changes (webhook, admin, sync cron) and its delayed Action Scheduler job.
		$reconciler = new Reconciler();
		OrderHooks::register_status_changed( array( $reconciler, 'on_status_changed' ) );
		add_action( Reconciler::HOOK, array( $reconciler, 'run' ) );

		if ( ! $context->is_frontend() && ! $context->is_ajax() ) {
			return;
		}

		$store = new Storefront();
		PlanHooks::register_single( array( $store, 'render_single' ) );
		PlanHooks::register_loop( array( $store, 'render_loop' ) );
		PlanHooks::register_variation( array( $store, 'filter_variation' ) );
		add_shortcode( Storefront::SHORTCODE, array( $store, 'shortcode' ) );

		PreferenceFilter::register();

		$checkout = new CheckoutPlans();
		PlanHooks::register_selector( array( $checkout, 'render_selector' ) );
		PlanHooks::register_fragments( array( $checkout, 'add_fragment' ) );
		PlanHooks::register_update_order_review( array( $checkout, 'on_update_order_review' ) );
		PlanHooks::register_posted_data( array( $checkout, 'on_posted_data' ) );
		PlanHooks::register_fees( array( $checkout, 'add_fee' ) );
		PlanHooks::register_create_order( array( $checkout, 'save_order' ) );
	}
}
