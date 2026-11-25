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
 * Registers lightweight core SEO hooks by context (0 queries on unrelated requests).
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
			( new HeadRenderer() )->register();
			( new SitemapFilters() )->register();
			add_action( 'template_redirect', array( new Redirects(), 'maybe_redirect' ), 1 );
		}

		if ( $context->is_admin() ) {
			( new SeoAdmin() )->register();
		}
	}
}
