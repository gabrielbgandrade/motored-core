<?php
/**
 * ProductBanner module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ProductBanner;

use Motored\Core\Contracts\Module;
use Motored\Core\Integration\WooCommerce\ProductPageHooks;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Promo banner (image or animated GIF, optional link and countdown) at the top of the product summary: one
 * general banner for every product (WooCommerce > Settings > Motored > Banners) and, per product, "use the
 * general one", "use this one" or "none". No tables: one option and one product meta key.
 */
final class ProductBannerModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return Config::OPTION_KEY;
	}

	/**
	 * Frontend (render) and admin (settings + product box) only.
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		return $context->is_frontend() || $context->is_admin();
	}

	/**
	 * Registers hooks only (no I/O).
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$context = new Context();
		if ( $context->is_frontend() ) {
			ProductPageHooks::register_summary_top( array( new BannerDisplay(), 'render' ) );
		}
		if ( $context->is_admin() ) {
			( new BannerAdmin() )->register();
		}
	}
}
