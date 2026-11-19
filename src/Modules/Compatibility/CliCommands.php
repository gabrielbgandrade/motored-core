<?php
/**
 * WP-CLI commands for Compatibility maintenance.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `wp motored compat reindex` and `wp motored compat seed-attributes`.
 */
final class CliCommands {

	/**
	 * Registers WP-CLI commands when running under WP-CLI.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && is_callable( array( 'WP_CLI', 'add_command' ) ) ) {
			call_user_func( array( 'WP_CLI', 'add_command' ), 'motored compat', self::class );
		}
	}

	/**
	 * Rebuilds the product compatibility index in batches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp motored compat reindex
	 *
	 * @subcommand reindex
	 */
	public function reindex(): void {
		$cursor  = 0;
		$batches = 0;
		do {
			$cursor = ProductIndexSync::reindex_batch( $cursor, ProductIndexSync::REINDEX_BATCH );
			++$batches;
		} while ( null !== $cursor );

		self::log_success( sprintf( 'Reindexed product compatibility batches: %d.', $batches ) );
	}

	/**
	 * Installs the 12 global WooCommerce attributes and fixed initial terms.
	 *
	 * ## EXAMPLES
	 *
	 *     wp motored compat seed-attributes
	 *
	 * @subcommand seed-attributes
	 */
	public function seed_attributes(): void {
		$attrs = AttributeInstaller::install_attributes();
		$terms = AttributeInstaller::seed_fixed_terms();
		self::log_success(
			sprintf(
				'Attributes created: %d, existing: %d. Terms seeded: %d, skipped: %d.',
				$attrs['created'],
				$attrs['existing'],
				$terms['seeded'],
				$terms['skipped']
			)
		);
	}

	/**
	 * Outputs a WP-CLI success line if available.
	 *
	 * @param string $message Message.
	 */
	private static function log_success( string $message ): void {
		if ( is_callable( array( 'WP_CLI', 'success' ) ) ) {
			call_user_func( array( 'WP_CLI', 'success' ), $message );
		}
	}
}
