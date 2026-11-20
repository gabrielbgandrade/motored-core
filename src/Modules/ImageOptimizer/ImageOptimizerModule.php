<?php
/**
 * ImageOptimizer module bootstrap.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ImageOptimizer;

use Motored\Core\Contracts\Module;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Optimizes uploads via WordPress native hooks and processes existing Media Library images in Action Scheduler batches.
 * Adds ZERO overhead on public page views.
 */
final class ImageOptimizerModule implements Module {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'image_optimizer';
	}

	/**
	 * Skips public frontend page views (zero queries, zero hooks, zero assets on storefront pages).
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool {
		return ! $context->is_frontend();
	}

	/**
	 * Registers upload filters, Action Scheduler batch handler and (in admin) settings UI.
	 */
	public function register(): void {
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		( new UploadHooks() )->register();
		( new BulkRunner() )->register();

		if ( ( new Context() )->is_admin() ) {
			( new ImageOptimizerAdmin() )->register();
		}
	}
}
