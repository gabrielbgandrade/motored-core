<?php
/**
 * Cheap ERP-safe product index synchronization, batch reindex and optional dimension auto-fill.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Integration\WooCommerce\AttributeHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps motored_compat_product_index in sync when tire attributes change while ignoring
 * frequent ERP price/stock updates via terms_hash comparison.
 */
final class ProductIndexSync {

	public const REINDEX_HOOK   = 'motored_compat_reindex';
	public const SHUTDOWN_LIMIT = 20;
	public const REINDEX_BATCH  = 200;

	/**
	 * Product IDs marked dirty during this request.
	 *
	 * @var array<int,true>
	 */
	private static array $dirty_ids = array();

	/**
	 * Whether shutdown hook is registered for this request.
	 *
	 * @var bool
	 */
	private static bool $shutdown_hooked = false;

	/**
	 * Recursion guard for Phase 7 dimension auto-fill.
	 *
	 * @var array<int,true>
	 */
	private static array $autofilling = array();

	/**
	 * Registers hooks for product attribute changes, status transitions, deletions and AS batching.
	 */
	public function register(): void {
		add_action( 'woocommerce_after_product_object_save', array( $this, 'on_product_save' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_change' ), 20, 3 );
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 20, 6 );
		add_action( 'wp_trash_post', array( $this, 'on_delete_post' ) );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'transition_post_status', array( $this, 'on_status_transition' ), 10, 3 );
		add_action( self::REINDEX_HOOK, array( $this, 'run_reindex_action' ), 10, 1 );
	}

	/**
	 * Hook: woocommerce_product_set_stock_status — updates in_stock only when stock status transitions.
	 *
	 * Never hooks stock quantity changes so ERP quantity syncs incur 0 index writes.
	 *
	 * @param mixed $product_id   Product ID.
	 * @param mixed $stock_status Stock status ('instock'|'outofstock'|'onbackorder').
	 * @param mixed $product      Optional WC_Product instance.
	 */
	public function on_stock_status_change( $product_id, $stock_status = '', $product = null ): void {
		unset( $product );
		$id = (int) $product_id;
		if ( $id <= 0 ) {
			return;
		}
		$in_stock = 'outofstock' !== strtolower( trim( (string) $stock_status ) );
		ProductIndexRepository::update_stock_status( $id, $in_stock );
	}

	/**
	 * Hook: woocommerce_after_product_object_save (covers admin saves, CSV, REST and Woo CRUD).
	 *
	 * @param mixed $product WC_Product instance.
	 */
	public function on_product_save( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$id = $product->get_id();
		if ( $id <= 0 ) {
			return;
		}
		if ( 'publish' !== $product->get_status() || ! $product->is_type( 'simple' ) ) {
			ProductIndexRepository::delete( $id );
			return;
		}
		$this->mark_dirty( $id );
	}

	/**
	 * Hook: set_object_terms — reacts ONLY to our 6 indexed tire taxonomies.
	 *
	 * @param mixed $object_id Object ID.
	 * @param mixed $terms     Terms.
	 * @param mixed $tt_ids    Term taxonomy IDs.
	 * @param mixed $taxonomy  Taxonomy name.
	 * @param mixed $append    Append flag.
	 * @param mixed $old_tt    Old term taxonomy IDs.
	 */
	public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt ): void {
		unset( $terms, $tt_ids, $append, $old_tt );
		if ( ! in_array( (string) $taxonomy, TireSpecs::indexed_taxonomies(), true ) ) {
			return;
		}
		$id = (int) $object_id;
		if ( $id > 0 && 'product' === get_post_type( $id ) ) {
			$this->mark_dirty( $id );
		}
	}

	/**
	 * Hook: wp_trash_post / before_delete_post.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public function on_delete_post( $post_id ): void {
		$id = (int) $post_id;
		if ( $id > 0 ) {
			unset( self::$dirty_ids[ $id ] );
			ProductIndexRepository::delete( $id );
		}
	}

	/**
	 * Hook: transition_post_status — removes unpublished products from the index.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       WP_Post.
	 */
	public function on_status_transition( $new_status, $old_status, $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_type ) || 'product' !== $post->post_type ) {
			return;
		}
		$id = (int) $post->ID;
		if ( 'publish' !== (string) $new_status && 'publish' === (string) $old_status ) {
			unset( self::$dirty_ids[ $id ] );
			ProductIndexRepository::delete( $id );
		} elseif ( 'publish' === (string) $new_status && 'publish' !== (string) $old_status ) {
			$this->mark_dirty( $id );
		}
	}

	/**
	 * Flushes dirty product IDs at shutdown (immediate if <= 20, queued via AS if bulk).
	 */
	public function flush_dirty(): void {
		if ( empty( self::$dirty_ids ) ) {
			return;
		}
		$ids             = array_keys( self::$dirty_ids );
		self::$dirty_ids = array();

		if ( count( $ids ) <= self::SHUTDOWN_LIMIT ) {
			foreach ( $ids as $id ) {
				self::sync_product( $id );
			}
			return;
		}

		foreach ( array_chunk( $ids, 50 ) as $chunk ) {
			$scheduled = ActionScheduler::schedule( time(), self::REINDEX_HOOK, array( array( 'ids' => $chunk ) ) );
			if ( ! $scheduled ) {
				foreach ( $chunk as $id ) {
					self::sync_product( (int) $id );
				}
			}
		}
	}

	/**
	 * Action Scheduler callback: either syncs explicit chunk IDs or continues a full-catalog cursor.
	 *
	 * @param mixed $payload Payload array (`ids` list or `cursor` int).
	 */
	public function run_reindex_action( $payload = array() ): void {
		$data = is_array( $payload ) ? $payload : array();
		if ( isset( $data['ids'] ) && is_array( $data['ids'] ) ) {
			foreach ( $data['ids'] as $id ) {
				$pid = (int) $id;
				if ( $pid > 0 ) {
					self::sync_product( $pid );
				}
			}
			return;
		}
		$cursor = isset( $data['cursor'] ) ? max( 0, (int) $data['cursor'] ) : 0;
		$next   = self::reindex_batch( $cursor, self::REINDEX_BATCH );
		if ( null !== $next ) {
			$scheduled = ActionScheduler::schedule( time() + 1, self::REINDEX_HOOK, array( array( 'cursor' => $next ) ) );
			$loops     = 0;
			while ( ! $scheduled && null !== $next && $loops < 25 ) {
				$next = self::reindex_batch( $next, self::REINDEX_BATCH );
				++$loops;
			}
		}
	}

	/**
	 * Schedules a full asynchronous reindex across published simple products.
	 */
	public static function schedule_full_reindex(): bool {
		return ActionScheduler::schedule( time(), self::REINDEX_HOOK, array( array( 'cursor' => 0 ) ) );
	}

	/**
	 * Synchronizes up to $limit published products with ID > $cursor. Returns next cursor or null when done.
	 *
	 * @param int $cursor Last processed product ID.
	 * @param int $limit  Batch size.
	 */
	public static function reindex_batch( int $cursor = 0, int $limit = self::REINDEX_BATCH ): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor scan over wp_posts.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				$cursor,
				$limit
			)
		);
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return null;
		}
		$last = $cursor;
		foreach ( $ids as $raw_id ) {
			$pid  = (int) $raw_id;
			$last = $pid;
			self::sync_product( $pid );
		}
		return count( $ids ) < $limit ? null : $last;
	}

	/**
	 * Synchronizes one product into motored_compat_product_index (exiting early when terms_hash matches).
	 *
	 * @param int $product_id Product ID.
	 * @return string 'unchanged'|'updated'|'deleted'
	 */
	public static function sync_product( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return 'deleted';
		}

		$post = get_post( $product_id );
		if ( ! is_object( $post ) || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			ProductIndexRepository::delete( $product_id );
			return 'deleted';
		}

		if ( ! AttributeHooks::is_simple_product( $product_id ) ) {
			unset( self::$dirty_ids[ $product_id ] );
			ProductIndexRepository::delete( $product_id );
			return 'deleted';
		}

		$raw  = self::read_product_taxonomies( $product_id );
		$hash = self::compute_terms_hash( $raw );
		if ( ProductIndexRepository::get_hash( $product_id ) === $hash ) {
			return 'unchanged';
		}

		$built = self::build_index_row( $product_id, $raw, $hash );
		if ( null === $built ) {
			ProductIndexRepository::delete( $product_id );
			return 'deleted';
		}

		$built['row']['in_stock'] = self::read_in_stock( $product_id ) ? 1 : 0;
		ProductIndexRepository::upsert( $built['row'] );

		if ( Config::auto_fill_dimensions() && ! isset( self::$autofilling[ $product_id ] ) ) {
			self::maybe_autofill_dimensions( $product_id, $built['row']['position'], $built['parsed_single'] );
		}

		return 'updated';
	}

	/**
	 * Pure builder transforming raw taxonomy terms into an index row (or null when product is not an indexable tire).
	 *
	 * @param int                                                $product_id Product ID.
	 * @param array<string,list<array{name:string,slug:string}>> $raw        Terms keyed by unprefixed slug.
	 * @param string                                             $hash       Precomputed terms hash.
	 * @return array{row:array{product_id:int,position:string,front_size_id:int|null,rear_size_id:int|null,front_load:int|null,rear_load:int|null,front_speed_rank:int|null,rear_speed_rank:int|null,construction:string|null,terms_hash:string},parsed_single:array{canonical:string,notation:string,width:string,aspect:int|null,rim:int}|null}|null
	 */
	public static function build_index_row( int $product_id, array $raw, string $hash ): ?array {
		$pos_terms = $raw[ TireSpecs::ATTR_POSITION ] ?? array();
		if ( count( $pos_terms ) !== 1 ) {
			return null;
		}
		$position = TireSpecs::normalize_position( $pos_terms[0]['slug'] );
		if ( '' === $position ) {
			$position = TireSpecs::normalize_position( $pos_terms[0]['name'] );
		}
		if ( '' === $position ) {
			return null;
		}

		$front_parsed = self::single_parsed_size( $raw[ TireSpecs::ATTR_FRONT_SIZE ] ?? array() );
		$rear_parsed  = self::single_parsed_size( $raw[ TireSpecs::ATTR_REAR_SIZE ] ?? array() );

		$front_size_id = null;
		$rear_size_id  = null;
		$parsed_single = null;

		if ( TireSpecs::POS_FRONT === $position ) {
			if ( null === $front_parsed ) {
				return null;
			}
			$front_row     = CatalogRepository::ensure_size( $front_parsed['canonical'] );
			$front_size_id = null !== $front_row ? $front_row['id'] : null;
			$parsed_single = $front_parsed;
		} elseif ( TireSpecs::POS_REAR === $position ) {
			if ( null === $rear_parsed ) {
				return null;
			}
			$rear_row      = CatalogRepository::ensure_size( $rear_parsed['canonical'] );
			$rear_size_id  = null !== $rear_row ? $rear_row['id'] : null;
			$parsed_single = $rear_parsed;
		} elseif ( TireSpecs::POS_BOTH === $position ) {
			$chosen = $front_parsed ?? $rear_parsed;
			if ( null === $chosen ) {
				return null;
			}
			$both_row      = CatalogRepository::ensure_size( $chosen['canonical'] );
			$both_id       = null !== $both_row ? $both_row['id'] : null;
			$front_size_id = $both_id;
			$rear_size_id  = $both_id;
			$parsed_single = $chosen;
		} elseif ( TireSpecs::POS_KIT === $position ) {
			if ( null === $front_parsed || null === $rear_parsed ) {
				return null;
			}
			$front_row     = CatalogRepository::ensure_size( $front_parsed['canonical'] );
			$rear_row      = CatalogRepository::ensure_size( $rear_parsed['canonical'] );
			$front_size_id = null !== $front_row ? $front_row['id'] : null;
			$rear_size_id  = null !== $rear_row ? $rear_row['id'] : null;
		}

		if ( TireSpecs::POS_KIT === $position ) {
			if ( null === $front_size_id || null === $rear_size_id ) {
				return null;
			}
		} elseif ( null === $front_size_id && null === $rear_size_id ) {
			return null;
		}

		// Kits do NOT assign per-axle load/speed from shared single-attribute values (avoids min(front, rear) bug).
		$front_load  = null;
		$rear_load   = null;
		$front_speed = null;
		$rear_speed  = null;

		if ( TireSpecs::POS_KIT !== $position ) {
			$load_val = self::single_load( $raw[ TireSpecs::ATTR_LOAD_INDEX ] ?? array() );
			$spd_val  = self::single_speed_rank( $raw[ TireSpecs::ATTR_SPEED_RATING ] ?? array() );
			if ( TireSpecs::POS_FRONT === $position || TireSpecs::POS_BOTH === $position ) {
				$front_load  = $load_val;
				$front_speed = $spd_val;
			}
			if ( TireSpecs::POS_REAR === $position || TireSpecs::POS_BOTH === $position ) {
				$rear_load  = $load_val;
				$rear_speed = $spd_val;
			}
		}

		$construction = self::single_construction( $raw[ TireSpecs::ATTR_CONSTRUCTION ] ?? array() );
		if ( null === $construction && TireSpecs::POS_KIT !== $position ) {
			// No Construction attribute: fall back to the printed size ("120/70ZR17" = radial; "120/70-17" says nothing).
			$size_attr  = ( TireSpecs::POS_REAR === $position || ( TireSpecs::POS_BOTH === $position && null === $front_parsed ) ) ? TireSpecs::ATTR_REAR_SIZE : TireSpecs::ATTR_FRONT_SIZE;
			$size_terms = $raw[ $size_attr ] ?? array();
			$desig      = 1 === count( $size_terms ) ? SizeParser::designation( $size_terms[0]['name'] ) : null;
			if ( null !== $desig && '' !== $desig['construction'] ) {
				$construction = $desig['construction'];
			}
		}

		return array(
			'row'           => array(
				'product_id'       => $product_id,
				'position'         => $position,
				'front_size_id'    => $front_size_id,
				'rear_size_id'     => $rear_size_id,
				'front_load'       => $front_load,
				'rear_load'        => $rear_load,
				'front_speed_rank' => $front_speed,
				'rear_speed_rank'  => $rear_speed,
				'construction'     => $construction,
				'terms_hash'       => $hash,
			),
			'parsed_single' => $parsed_single,
		);
	}

	/**
	 * Computes a stable MD5 fingerprint of the 6 indexed attributes to short-circuit ERP price/stock saves.
	 *
	 * @param array<string,list<array{name:string,slug:string}>> $raw Raw attribute terms.
	 */
	public static function compute_terms_hash( array $raw ): string {
		$canonical = array();
		foreach ( array(
			TireSpecs::ATTR_POSITION,
			TireSpecs::ATTR_FRONT_SIZE,
			TireSpecs::ATTR_REAR_SIZE,
			TireSpecs::ATTR_LOAD_INDEX,
			TireSpecs::ATTR_SPEED_RATING,
			TireSpecs::ATTR_CONSTRUCTION,
		) as $slug ) {
			$vals = array();
			foreach ( $raw[ $slug ] ?? array() as $term ) {
				$vals[] = $term['slug'] . ':' . $term['name'];
			}
			sort( $vals );
			$canonical[ $slug ] = $vals;
		}
		return md5( (string) wp_json_encode( $canonical ) );
	}

	/**
	 * Marks a product ID dirty and schedules shutdown flush once per request.
	 *
	 * @param int $product_id Product ID.
	 */
	private function mark_dirty( int $product_id ): void {
		self::$dirty_ids[ $product_id ] = true;
		if ( ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			add_action( 'shutdown', array( $this, 'flush_dirty' ) );
		}
	}

	/**
	 * Reads our 6 indexed taxonomy attributes for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string,list<array{name:string,slug:string}>>
	 */
	private static function read_product_taxonomies( int $product_id ): array {
		$out = array();
		foreach ( array(
			TireSpecs::ATTR_POSITION,
			TireSpecs::ATTR_FRONT_SIZE,
			TireSpecs::ATTR_REAR_SIZE,
			TireSpecs::ATTR_LOAD_INDEX,
			TireSpecs::ATTR_SPEED_RATING,
			TireSpecs::ATTR_CONSTRUCTION,
		) as $slug ) {
			$terms        = AttributeHooks::product_terms( $product_id, AttributeHooks::taxonomy_name( $slug ) );
			$out[ $slug ] = array_map(
				static fn( array $t ): array => array(
					'name' => $t['name'],
					'slug' => $t['slug'],
				),
				$terms
			);
		}
		return $out;
	}

	/**
	 * Parses a size term list when exactly one valid size is present.
	 *
	 * @param list<array{name:string,slug:string}> $terms Terms.
	 * @return array{canonical:string,notation:string,width:string,aspect:int|null,rim:int}|null
	 */
	private static function single_parsed_size( array $terms ): ?array {
		if ( count( $terms ) !== 1 ) {
			return null;
		}
		return SizeParser::parse( $terms[0]['name'] );
	}

	/**
	 * Extracts single load index or null.
	 *
	 * @param list<array{name:string,slug:string}> $terms Terms.
	 */
	private static function single_load( array $terms ): ?int {
		if ( count( $terms ) !== 1 ) {
			return null;
		}
		return TireSpecs::parse_load_index( $terms[0]['name'] );
	}

	/**
	 * Extracts single ETRTO speed rank or null.
	 *
	 * @param list<array{name:string,slug:string}> $terms Terms.
	 */
	private static function single_speed_rank( array $terms ): ?int {
		if ( count( $terms ) !== 1 ) {
			return null;
		}
		return TireSpecs::speed_rank( $terms[0]['name'] ) ?? TireSpecs::speed_rank( $terms[0]['slug'] );
	}

	/**
	 * Extracts single construction slug or null.
	 *
	 * @param list<array{name:string,slug:string}> $terms Terms.
	 */
	private static function single_construction( array $terms ): ?string {
		if ( count( $terms ) !== 1 ) {
			return null;
		}
		$val = TireSpecs::normalize_construction( $terms[0]['slug'] );
		if ( '' === $val ) {
			$val = TireSpecs::normalize_construction( $terms[0]['name'] );
		}
		return '' !== $val ? $val : null;
	}

	/**
	 * Phase 7 optional helper: fills empty Width, Aspect ratio and Rim diameter on single tires only.
	 *
	 * @param int                                                                               $product_id Product ID.
	 * @param string                                                                            $position   Product position.
	 * @param array{canonical:string,notation:string,width:string,aspect:int|null,rim:int}|null $parsed     Parsed tire size.
	 */
	private static function maybe_autofill_dimensions( int $product_id, string $position, ?array $parsed ): void {
		if ( TireSpecs::POS_KIT === $position || null === $parsed || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
			return;
		}

		self::$autofilling[ $product_id ] = true;
		try {
			$changed = false;
			$targets = array(
				TireSpecs::ATTR_WIDTH => $parsed['width'],
				TireSpecs::ATTR_RIM   => (string) $parsed['rim'],
			);
			if ( null !== $parsed['aspect'] ) {
				$targets[ TireSpecs::ATTR_PROFILE ] = (string) $parsed['aspect'];
			}

			foreach ( $targets as $attr_slug => $value ) {
				$taxonomy = AttributeHooks::taxonomy_name( $attr_slug );
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				if ( ! empty( AttributeHooks::product_terms( $product_id, $taxonomy ) ) ) {
					continue;
				}
				$term_slug = str_replace( '.', '-', strtolower( $value ) );
				$term_id   = AttributeHooks::ensure_term( $taxonomy, $value, $term_slug );
				if ( $term_id > 0 && AttributeHooks::set_product_attribute_term( $product, $taxonomy, $term_id ) ) {
					$changed = true;
				}
			}

			if ( $changed ) {
				$product->save();
			}
		} finally {
			unset( self::$autofilling[ $product_id ] );
		}
	}

	/**
	 * Reads whether a product is currently in stock (defaults to true when meta/WC is absent in tests).
	 *
	 * @param int $product_id Product ID.
	 */
	private static function read_in_stock( int $product_id ): bool {
		if ( function_exists( 'get_post_meta' ) ) {
			$status = get_post_meta( $product_id, '_stock_status', true );
			if ( is_string( $status ) && '' !== $status ) {
				return 'outofstock' !== strtolower( trim( $status ) );
			}
		}
		return true;
	}

	/**
	 * Resets static request state (for unit tests).
	 */
	public static function reset(): void {
		self::$dirty_ids       = array();
		self::$shutdown_hooked = false;
		self::$autofilling     = array();
	}
}
