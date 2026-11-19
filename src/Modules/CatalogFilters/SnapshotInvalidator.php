<?php
/**
 * Anti-storm cache invalidation for the CatalogFilters facet snapshot.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Marks the filter snapshot dirty ONLY on tracked taxonomy changes, stock-status transitions
 * and publish/trash/delete transitions — NEVER on ERP price or stock-quantity updates.
 * Enforces a 60-second rebuild cooldown and a concurrency lock to survive bulk ERP saves.
 */
final class SnapshotInvalidator {

	public const BUCKET           = 'catalog_filters';
	public const DIRTY_KEY        = 'snapshot_dirty';
	public const LAST_BUILD_KEY   = 'snapshot_last_build';
	public const LOCK_KEY         = 'snapshot_rebuild_lock';
	public const VERSION_OPT      = 'motored_catalog_filters_ver';
	public const REBUILD_COOLDOWN = 60;
	public const LOCK_TTL         = 30;

	/**
	 * Per-request guard so multiple hooks in one request only mark dirty once.
	 *
	 * @var bool
	 */
	private static bool $marked_this_request = false;

	/**
	 * Registers only lightweight change-detection hooks (never price or stock-quantity hooks).
	 */
	public function register(): void {
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 20, 6 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_change' ), 20, 3 );
		add_action( 'transition_post_status', array( $this, 'on_status_transition' ), 20, 3 );
		add_action( 'wp_trash_post', array( $this, 'on_delete_post' ) );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
	}

	/**
	 * Taxonomies whose term changes affect filter facets or catalog visibility.
	 *
	 * @return list<string>
	 */
	public static function tracked_taxonomies(): array {
		$taxes = array(
			'product_cat',
			'product_brand',
			'product_visibility',
		);
		foreach ( Config::ATTRIBUTE_SLUGS as $slug ) {
			$taxes[] = 'pa_' . $slug;
		}
		$taxes[] = 'pa_' . TireSpecs::ATTR_FRONT_SIZE;
		$taxes[] = 'pa_' . TireSpecs::ATTR_REAR_SIZE;
		return array_values( array_unique( $taxes ) );
	}

	/**
	 * Hook: set_object_terms — reacts ONLY to tracked taxonomies on products.
	 *
	 * @param mixed $object_id Object ID.
	 * @param mixed $terms     Terms.
	 * @param mixed $tt_ids    Term taxonomy IDs.
	 * @param mixed $taxonomy  Taxonomy name.
	 * @param mixed $append    Append flag.
	 * @param mixed $old_tt    Old term taxonomy IDs.
	 */
	public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt ): void {
		unset( $terms, $append );
		if ( ! in_array( (string) $taxonomy, self::tracked_taxonomies(), true ) ) {
			return;
		}
		if ( is_array( $tt_ids ) && is_array( $old_tt ) ) {
			$new_sorted = array_map( 'intval', $tt_ids );
			$old_sorted = array_map( 'intval', $old_tt );
			sort( $new_sorted );
			sort( $old_sorted );
			if ( $new_sorted === $old_sorted ) {
				return;
			}
		}
		$id = (int) $object_id;
		if ( $id > 0 && 'product' === get_post_type( $id ) ) {
			self::mark_dirty();
		}
	}

	/**
	 * Hook: woocommerce_product_set_stock_status (only when stock status actually flips).
	 *
	 * @param mixed $product_id   Product ID.
	 * @param mixed $stock_status Stock status.
	 * @param mixed $product      Product instance.
	 */
	public function on_stock_status_change( $product_id, $stock_status = '', $product = null ): void {
		unset( $stock_status, $product );
		if ( (int) $product_id > 0 ) {
			self::mark_dirty();
		}
	}

	/**
	 * Hook: transition_post_status — marks dirty only when a product enters or leaves publish.
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Old post status.
	 * @param mixed $post       WP_Post.
	 */
	public function on_status_transition( $new_status, $old_status, $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || 'product' !== $post->post_type ) {
			return;
		}
		$new_s = (string) $new_status;
		$old_s = (string) $old_status;
		if ( $new_s !== $old_s && ( 'publish' === $new_s || 'publish' === $old_s ) ) {
			self::mark_dirty();
		}
	}

	/**
	 * Hook: wp_trash_post / before_delete_post.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public function on_delete_post( $post_id ): void {
		$id = (int) $post_id;
		if ( $id > 0 && 'product' === get_post_type( $id ) ) {
			self::mark_dirty();
		}
	}

	/**
	 * Marks the cached snapshot as dirty (at most once per request; never flushes immediately on ERP storms).
	 */
	public static function mark_dirty(): void {
		if ( self::$marked_this_request ) {
			return;
		}
		self::$marked_this_request = true;
		if ( Cache::is_persistent() ) {
			Cache::set( self::DIRTY_KEY, time(), 86400, self::BUCKET );
		}
	}

	/**
	 * Whether the cached snapshot is dirty and eligible for rebuild (cooldown elapsed).
	 */
	public static function should_rebuild_now(): bool {
		if ( ! Cache::is_persistent() ) {
			return true;
		}
		$dirty_ts = (int) Cache::get( self::DIRTY_KEY, self::BUCKET );
		if ( $dirty_ts <= 0 ) {
			return false;
		}
		$last_build = (int) Cache::get( self::LAST_BUILD_KEY, self::BUCKET );
		return ( time() - $last_build ) >= self::REBUILD_COOLDOWN;
	}

	/**
	 * Acquires a short-lived rebuild lock to prevent stampedes when persistent cache is active.
	 */
	public static function acquire_lock(): bool {
		if ( ! Cache::is_persistent() ) {
			return true;
		}
		return Cache::add( self::LOCK_KEY, 1, self::LOCK_TTL, self::BUCKET );
	}

	/**
	 * Releases the rebuild lock and records the rebuild timestamp (clearing dirty flag).
	 *
	 * @param bool $rebuilt Whether a fresh snapshot was written.
	 */
	public static function finish_rebuild( bool $rebuilt = true ): void {
		if ( ! Cache::is_persistent() ) {
			return;
		}
		if ( $rebuilt ) {
			$now = time();
			Cache::set( self::LAST_BUILD_KEY, $now, 86400, self::BUCKET );
			Cache::delete( self::DIRTY_KEY, self::BUCKET );
			self::bump_version();
		}
		Cache::delete( self::LOCK_KEY, self::BUCKET );
	}

	/**
	 * Returns the current lightweight cache-busting version token for the sizes JSON endpoint.
	 */
	public static function version(): string {
		if ( Cache::is_persistent() ) {
			$v = Cache::get( 'sizes_ver', self::BUCKET );
			if ( is_string( $v ) && '' !== $v ) {
				return $v;
			}
			$v = (string) time();
			Cache::set( 'sizes_ver', $v, 86400, self::BUCKET );
			return $v;
		}
		$opt = (string) get_option( self::VERSION_OPT, '' );
		return '' !== $opt ? $opt : '1';
	}

	/**
	 * Bumps the public sizes JSON version token.
	 */
	public static function bump_version(): void {
		$next = (string) time();
		if ( Cache::is_persistent() ) {
			Cache::set( 'sizes_ver', $next, 86400, self::BUCKET );
		}
	}

	/**
	 * Resets per-request state (for unit tests).
	 */
	public static function reset(): void {
		self::$marked_this_request = false;
	}
}
