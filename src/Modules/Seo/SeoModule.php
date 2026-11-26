<?php
/**
 * Seo module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

use Motored\Core\Contracts\Module;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers lightweight full-site SEO hooks by context (0 extra queries on normal requests, Redis-cached).
 */
final class SeoModule implements Module {

	/**
	 * Module identifier.
	 */
	public function id(): string {
		return 'seo';
	}

	/**
	 * Loads on frontend, admin and sitemap/cron requests.
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		return $context->is_frontend() || $context->is_admin() || $context->is_cron();
	}

	/**
	 * Attaches context-specific SEO hooks (zero option reads or queries during register).
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$context = new Context();

		if ( $context->is_frontend() ) {
			( new SiteContextBuilder() )->register();
			( new HeadRenderer() )->register();
			( new ProductSchemaEnhancer() )->register();
			( new SitemapFilters() )->register();
			( new MerchantFeed() )->register();
			add_action( 'template_redirect', array( new Redirects(), 'maybe_redirect' ), 1 );
		}

		if ( $context->is_admin() ) {
			( new SeoAdmin() )->register();
			add_action( 'admin_init', array( MerchantFeed::class, 'maybe_flush_rewrites' ) );
			add_action( 'post_updated', array( Redirects::class, 'on_post_updated' ), 10, 3 );
			add_action( 'update_option_motored_seo', array( MerchantFeed::class, 'invalidate_cache' ) );
		}

		if ( $context->is_admin() || $context->is_cron() ) {
			add_action( 'transition_post_status', array( SitemapFilters::class, 'invalidate_cache' ) );
			add_action( 'transition_post_status', array( MerchantFeed::class, 'invalidate_cache' ) );
			add_action( 'woocommerce_update_product', array( MerchantFeed::class, 'invalidate_cache' ) );
			add_action( 'woocommerce_product_set_stock_status', array( MerchantFeed::class, 'invalidate_cache' ) );
		}
	}
}
