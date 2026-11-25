<?php
/**
 * Automatic idempotent sync between Compatibility catalog signatures and motored_bike CPT posts.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Modules\Compatibility\CatalogRepository;
use Motored\Core\Modules\Compatibility\CompatCache;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\ProductIndexRepository;
use Motored\Core\Modules\Seo\Redirects;
use Motored\Core\Modules\Seo\SitemapFilters;

defined( 'ABSPATH' ) || exit;

// Direct lookups over motored_compat_* and wp_postmeta run inside Action Scheduler / admin batches.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Listens to catalog & product index events, schedules batched Action Scheduler jobs, and upserts CPT posts.
 */
final class BikePageSync {

	public const SYNC_MODEL_HOOK   = 'motored_bike_pages_sync_model';
	public const FULL_REBUILD_HOOK = 'motored_bike_pages_rebuild_batch';
	public const INDEXABILITY_HOOK = 'motored_bike_pages_indexability_batch';

	public const META_SIG            = '_motored_sig';
	public const META_MODEL_ID       = '_motored_model_id';
	public const META_PRIMARY_SLUG   = '_motored_primary_slug';
	public const META_VERSION_SLUGS  = '_motored_version_slugs';
	public const META_SYNCED_TITLE   = '_motored_synced_title';
	public const META_SYNCED_CONTENT = '_motored_synced_content';
	public const META_PREV_SLUG      = '_motored_prev_slug';
	public const META_YEARS_LABEL    = '_motored_years_label';
	public const META_OEM_SIZES      = '_motored_oem_sizes';
	public const CONTENT_MARKER      = '[motored_moto]';
	public const BATCH_SIZE          = 20;
	public const SYNC_VERSION        = 5;
	public const SYNC_VER_OPT        = 'motored_bike_pages_sync_ver';

	/**
	 * Registers catalog listener and Action Scheduler worker hooks.
	 */
	public function register(): void {
		add_action( 'motored_compat_catalog_changed', array( $this, 'on_catalog_changed' ), 10, 4 );
		add_action( 'motored_compat_product_index_changed', array( $this, 'on_product_index_changed' ), 10, 1 );
		add_action( self::SYNC_MODEL_HOOK, array( $this, 'sync_model_action' ), 10, 1 );
		add_action( self::FULL_REBUILD_HOOK, array( $this, 'run_rebuild_batch' ), 10, 1 );
		add_action( self::INDEXABILITY_HOOK, array( $this, 'recompute_indexability_batch' ), 10, 1 );
	}

	/**
	 * Resolves affected model IDs on catalog mutation and queues deduped AS jobs (0 heavy work inline).
	 *
	 * @param string              $entity  brand|model|version|fitment.
	 * @param int                 $id      Record ID.
	 * @param string              $op      upsert|update|status|delete.
	 * @param array<string,mixed> $context Pre-mutation context.
	 */
	public function on_catalog_changed( string $entity, int $id, string $op, array $context = array() ): void {
		unset( $op );
		if ( ! Config::auto_create() ) {
			return;
		}
		foreach ( $this->resolve_affected_models( $entity, $id, $context ) as $model_id ) {
			ActionScheduler::schedule( time() + 1, self::SYNC_MODEL_HOOK, array( $model_id ), true );
		}
	}

	/**
	 * Debounces indexability recomputation when a tire product enters/leaves the compatibility index or changes stock.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_index_changed( int $product_id ): void {
		unset( $product_id );
		if ( ! Config::enabled() ) {
			return;
		}
		ActionScheduler::schedule( time() + 10, self::INDEXABILITY_HOOK, array( 0 ), true );
	}

	/**
	 * Registers the availability listeners in EVERY context (stock also flips on frontend checkouts and gateway
	 * callbacks). They fire only on real stock/publish changes, never on page views, and only queue work.
	 */
	public function register_availability_listeners(): void {
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_changed' ), 20, 1 );
		add_action( 'transition_post_status', array( $this, 'on_product_status_transition' ), 20, 3 );
	}

	/**
	 * Hook woocommerce_product_set_stock_status: an indexed tire flipped in/out of stock -> recompute indexability.
	 *
	 * @param mixed $product_id Product ID.
	 */
	public function on_stock_status_changed( $product_id ): void {
		$this->maybe_recompute_for_product( (int) $product_id );
	}

	/**
	 * Hook transition_post_status: an indexed tire entered or left `publish` -> recompute indexability.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       WP_Post.
	 */
	public function on_product_status_transition( $new_status, $old_status, $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->post_type, $post->ID ) || 'product' !== $post->post_type ) {
			return;
		}
		if ( (string) $new_status === (string) $old_status || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
			return;
		}
		$this->maybe_recompute_for_product( (int) $post->ID );
	}

	/**
	 * Queues the debounced indexability sweep only for products that are in the compatibility index.
	 *
	 * @param int $product_id Product ID.
	 */
	private function maybe_recompute_for_product( int $product_id ): void {
		if ( $product_id <= 0 || ! Config::enabled() || null === ProductIndexRepository::find( $product_id ) ) {
			return;
		}
		$this->on_product_index_changed( $product_id );
	}

	/**
	 * Queues a full batched rebuild across all models (20 models per AS job).
	 */
	public static function schedule_full_rebuild(): bool {
		return ActionScheduler::schedule( time(), self::FULL_REBUILD_HOOK, array( 0 ), true );
	}

	/**
	 * Hook admin_init (plugin screens only): queues ONE full rebuild when the generated-content format changes
	 * (v2: titles always in the site locale; v3: slugs without the year + 301; v4: `_motored_oem_sizes` for listing
	 * cards). Edited titles are preserved by the
	 * synced-title rule.
	 */
	public static function maybe_schedule_resync(): void {
		if ( ! Config::enabled() || (string) self::SYNC_VERSION === (string) get_option( self::SYNC_VER_OPT, '' ) ) {
			return;
		}
		if ( self::schedule_full_rebuild() ) {
			update_option( self::SYNC_VER_OPT, (string) self::SYNC_VERSION, false );
		}
	}

	/**
	 * Action Scheduler batch callback rebuilding 20 models at a time.
	 *
	 * @param int $offset Zero-based model offset.
	 */
	public function run_rebuild_batch( int $offset = 0 ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$m    = CatalogRepository::models_table();
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$m} ORDER BY id ASC LIMIT %d OFFSET %d",
				self::BATCH_SIZE,
				max( 0, $offset )
			)
		);
		$list = array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ), static fn( int $v ): bool => $v > 0 ) );
		foreach ( $list as $mid ) {
			$this->sync_model( $mid );
		}
		if ( count( $list ) === self::BATCH_SIZE ) {
			ActionScheduler::schedule( time() + 1, self::FULL_REBUILD_HOOK, array( $offset + self::BATCH_SIZE ), true );
		}
	}

	/**
	 * Action callback syncing all `motored_bike` CPT pages for a single motorcycle model.
	 *
	 * @param int $model_id Model ID.
	 */
	public function sync_model_action( int $model_id ): void {
		$this->sync_model( $model_id );
	}

	/**
	 * Synchronizes all `motored_bike` CPT pages for a single motorcycle model (idempotent by `_motored_sig`).
	 *
	 * @param int $model_id Model ID.
	 * @return array{created:int,updated:int,trashed:int}
	 */
	public function sync_model( int $model_id ): array {
		// Titles/slugs are stored content seen by every visitor: always generate them in the SITE locale (es_AR),
		// never in the locale of whoever triggered the job (admin user profile, CLI, async runner).
		$site_locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		$switched    = '' !== $site_locale
			&& function_exists( 'determine_locale' ) && function_exists( 'switch_to_locale' )
			&& determine_locale() !== $site_locale
			&& switch_to_locale( $site_locale );
		try {
			return $this->sync_model_in_locale( $model_id );
		} finally {
			if ( $switched && function_exists( 'restore_previous_locale' ) ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Sync body; runs with the site locale active (see sync_model()).
	 *
	 * @param int $model_id Model ID.
	 * @return array{created:int,updated:int,trashed:int}
	 */
	private function sync_model_in_locale( int $model_id ): array {
		$stats = array(
			'created' => 0,
			'updated' => 0,
			'trashed' => 0,
		);
		if ( $model_id <= 0 || ! Config::enabled() ) {
			return $stats;
		}

		$model    = self::load_model_with_brand( $model_id );
		$existing = self::find_posts_for_model( $model_id );

		if ( null === $model ) {
			foreach ( $existing as $post_id => $row ) {
				if ( 'trash' !== $row['post_status'] && function_exists( 'wp_trash_post' ) ) {
					wp_trash_post( $post_id );
					++$stats['trashed'];
				}
			}
			CompatCache::bump_catalog();
			return $stats;
		}

		$versions = self::load_versions_for_model( $model_id, Config::pages_for_drafts() );
		$ver_ids  = array_map( static fn( array $v ): int => (int) $v['id'], $versions );
		$fitments = CatalogRepository::fitments_for_versions_full( $ver_ids );
		$groups   = Signature::group_model_versions( $model, $versions, $fitments );
		$current  = array();
		foreach ( $existing as $row ) {
			if ( 'trash' !== $row['post_status'] && '' !== $row['sig'] ) {
				$current[ $row['sig'] ] = $row['post_name'];
			}
		}
		$groups      = Signature::assign_post_slugs( $groups, $current );
		$brand_term  = self::ensure_brand_term( (string) $model['brand_name'], (string) $model['brand_slug'] );
		$active_sigs = array();
		$kept_urls   = array();

		foreach ( $groups as $g ) {
			$active_sigs[ $g['sig_key'] ] = true;
			$existing_post                = self::find_post_by_signature( $g['sig_key'] );
			$g['oem_sizes']               = Signature::oem_designations( $fitments[ (int) $g['primary_version_id'] ] ?? array() );
			$res                          = $this->upsert_signature_post( $model, $g, $brand_term, $existing_post );
			if ( 'created' === $res['op'] ) {
				++$stats['created'];
			} elseif ( 'updated' === $res['op'] ) {
				++$stats['updated'];
			}
			if ( $res['post_id'] > 0 && '' !== $res['url'] ) {
				$kept_urls[] = $res['url'];
			}
		}

		$fallback_url = $kept_urls[0] ?? '';
		foreach ( $existing as $post_id => $row ) {
			if ( isset( $active_sigs[ $row['sig'] ] ) ) {
				continue;
			}
			if ( 'trash' !== $row['post_status'] ) {
				if ( '' !== $fallback_url && function_exists( 'get_permalink' ) ) {
					$old_url = get_permalink( $post_id );
					if ( is_string( $old_url ) && '' !== $old_url ) {
						Redirects::add( $old_url, $fallback_url, 301 );
					}
				}
				if ( function_exists( 'wp_trash_post' ) ) {
					wp_trash_post( $post_id );
					++$stats['trashed'];
				}
			}
		}

		CompatCache::bump_catalog();
		return $stats;
	}

	/**
	 * Lightweight AS job updating `_motored_indexable` without touching post title/content.
	 *
	 * @param int $offset Zero-based post offset.
	 */
	public function recompute_indexability_batch( int $offset = 0 ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts ) ) {
			return;
		}
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status = 'publish'
				ORDER BY ID ASC
				LIMIT %d OFFSET %d",
				PostType::POST_TYPE,
				self::BATCH_SIZE,
				max( 0, $offset )
			)
		);
		$list = array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ), static fn( int $v ): bool => $v > 0 ) );
		foreach ( $list as $pid ) {
			$primary_slug = (string) get_post_meta( $pid, self::META_PRIMARY_SLUG, true );
			$raw_slugs    = get_post_meta( $pid, self::META_VERSION_SLUGS, true );
			$slugs        = is_string( $raw_slugs ) ? json_decode( $raw_slugs, true ) : array();
			if ( '' === $primary_slug ) {
				continue;
			}
			$has_published = false;
			foreach ( is_array( $slugs ) ? $slugs : array( $primary_slug ) as $vs ) {
				$v_row = CatalogRepository::find_version_by_slug( (string) $vs, true );
				if ( null !== $v_row ) {
					$has_published = true;
					$primary_slug  = (string) $v_row['slug'];
					break;
				}
			}
			$indexable = self::is_signature_indexable( $primary_slug, $has_published );
			update_post_meta( $pid, SitemapFilters::INDEXABLE_META, $indexable ? '1' : '0' );
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $pid );
			}
		}
		if ( count( $list ) === self::BATCH_SIZE ) {
			ActionScheduler::schedule( time() + 1, self::INDEXABILITY_HOOK, array( $offset + self::BATCH_SIZE ), true );
		}
	}

	/**
	 * Computes what the automatic sync would create/update/trash without writing posts (admin dry-run).
	 *
	 * @return array{pages:int,indexable:int,noindex:int}
	 */
	public static function preview_counts(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
			return array(
				'pages'     => 0,
				'indexable' => 0,
				'noindex'   => 0,
			);
		}
		$pages = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				PostType::POST_TYPE
			)
		);
		$idx   = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value = '1'",
				SitemapFilters::INDEXABLE_META,
				PostType::POST_TYPE
			)
		);
		return array(
			'pages'     => $pages,
			'indexable' => $idx,
			'noindex'   => max( 0, $pages - $idx ),
		);
	}

	/**
	 * Creates or updates one signature post while preserving manual editor titles/content.
	 *
	 * @param array<string,mixed>      $model         Model row.
	 * @param array<string,mixed>      $group         Signature group from Signature::group_model_versions().
	 * @param int                      $brand_term_id Brand term ID.
	 * @param array<string,mixed>|null $existing Existing post row (`ID`, `post_title`, `post_content`, `post_name`, `post_status`).
	 * @return array{op:string,post_id:int,url:string}
	 */
	private function upsert_signature_post( array $model, array $group, int $brand_term_id, ?array $existing ): array {
		$sig_key      = (string) $group['sig_key'];
		$title        = (string) $group['title'];
		$slug         = (string) $group['post_slug'];
		$primary_slug = (string) $group['primary_slug'];
		$indexable    = self::is_signature_indexable( $primary_slug, (bool) $group['has_published'] );

		if ( null === $existing ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => PostType::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => self::CONTENT_MARKER,
				),
				true
			);
			if ( ! is_int( $post_id ) || $post_id <= 0 ) {
				return array(
					'op'      => 'error',
					'post_id' => 0,
					'url'     => '',
				);
			}
			self::write_post_meta( $post_id, $model, $group, $title, $indexable );
			if ( $brand_term_id > 0 && function_exists( 'wp_set_object_terms' ) ) {
				wp_set_object_terms( $post_id, array( $brand_term_id ), PostType::BRAND_TAXONOMY, false );
			}
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $post_id );
			}
			$url = function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';
			return array(
				'op'      => 'created',
				'post_id' => $post_id,
				'url'     => $url,
			);
		}

		$post_id        = (int) $existing['ID'];
		$old_name       = (string) $existing['post_name'];
		$old_url        = function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';
		$synced_title   = (string) get_post_meta( $post_id, self::META_SYNCED_TITLE, true );
		$synced_content = (string) get_post_meta( $post_id, self::META_SYNCED_CONTENT, true );

		$update = array( 'ID' => $post_id );
		if ( 'publish' !== $existing['post_status'] ) {
			$update['post_status'] = 'publish';
		}
		if ( '' === $synced_title || $existing['post_title'] === $synced_title ) {
			$update['post_title'] = $title;
		}
		if ( '' === $synced_content || trim( (string) $existing['post_content'] ) === $synced_content ) {
			$update['post_content'] = self::CONTENT_MARKER;
		}
		if ( $old_name !== $slug ) {
			$update['post_name'] = $slug;
		}

		wp_update_post( $update );
		self::write_post_meta( $post_id, $model, $group, $title, $indexable );

		if ( $brand_term_id > 0 && function_exists( 'wp_set_object_terms' ) ) {
			wp_set_object_terms( $post_id, array( $brand_term_id ), PostType::BRAND_TAXONOMY, false );
		}
		if ( $old_name !== $slug && '' !== $old_name ) {
			update_post_meta( $post_id, self::META_PREV_SLUG, $old_name );
			$new_url = function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';
			if ( '' !== $old_url && '' !== $new_url && $old_url !== $new_url ) {
				Redirects::add( $old_url, $new_url, 301 );
			}
		}
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
		$url = function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';
		return array(
			'op'      => 'updated',
			'post_id' => $post_id,
			'url'     => $url,
		);
	}

	/**
	 * Writes machine-managed metadata on a bike page post.
	 *
	 * @param int                 $post_id   Post ID.
	 * @param array<string,mixed> $model     Model row.
	 * @param array<string,mixed> $group     Signature group.
	 * @param string              $title     Generated title.
	 * @param bool                $indexable Whether indexable.
	 */
	private static function write_post_meta( int $post_id, array $model, array $group, string $title, bool $indexable ): void {
		update_post_meta( $post_id, self::META_SIG, (string) $group['sig_key'] );
		update_post_meta( $post_id, self::META_MODEL_ID, (int) $model['id'] );
		update_post_meta( $post_id, self::META_PRIMARY_SLUG, (string) $group['primary_slug'] );
		update_post_meta( $post_id, self::META_VERSION_SLUGS, (string) wp_json_encode( $group['version_slugs'] ) );
		update_post_meta( $post_id, self::META_YEARS_LABEL, (string) $group['years_label'] );
		update_post_meta( $post_id, self::META_OEM_SIZES, (string) wp_json_encode( $group['oem_sizes'] ?? array() ) );
		update_post_meta( $post_id, self::META_SYNCED_TITLE, $title );
		update_post_meta( $post_id, self::META_SYNCED_CONTENT, self::CONTENT_MARKER );
		update_post_meta( $post_id, SitemapFilters::INDEXABLE_META, $indexable ? '1' : '0' );
	}

	/**
	 * Indexability rule (§10.3): >= 1 published version AND >= index_min_products() AVAILABLE compatible products
	 * (Availability: published, visible, in stock or on backorder).
	 *
	 * @param string $primary_slug  Published version slug.
	 * @param bool   $has_published Whether at least one version in the signature is published.
	 */
	public static function is_signature_indexable( string $primary_slug, bool $has_published ): bool {
		if ( ! $has_published || '' === $primary_slug ) {
			return false;
		}
		$min = Config::index_min_products();
		if ( 0 === $min ) {
			return true;
		}
		// Same rule as the page hero ("N cubiertas en stock"): published + visible + in stock or on backorder.
		return Availability::count( MatchEngine::matching_product_ids( $primary_slug ), $min ) >= $min;
	}

	/**
	 * Resolves model IDs affected by a catalog mutation event.
	 *
	 * @param string              $entity  Entity name.
	 * @param int                 $id      Record ID.
	 * @param array<string,mixed> $context Pre-mutation context.
	 * @return list<int>
	 */
	private function resolve_affected_models( string $entity, int $id, array $context ): array {
		if ( 'model' === $entity ) {
			return $id > 0 ? array( $id ) : array();
		}
		if ( 'version' === $entity ) {
			$mid = (int) ( $context['model_id'] ?? 0 );
			if ( $mid > 0 ) {
				return array( $mid );
			}
			$ver = CatalogRepository::find_version( $id );
			return null !== $ver ? array( (int) $ver['model_id'] ) : array();
		}
		if ( 'fitment' === $entity ) {
			$vid = (int) ( $context['version_id'] ?? 0 );
			if ( $vid > 0 ) {
				$ver = CatalogRepository::find_version( $vid );
				return null !== $ver ? array( (int) $ver['model_id'] ) : array();
			}
			return array();
		}
		if ( 'brand' === $entity && $id > 0 ) {
			$models = CatalogRepository::models( $id, false );
			return array_values( array_filter( array_map( static fn( array $m ): int => (int) $m['id'], $models ), static fn( int $mid ): bool => $mid > 0 ) );
		}
		return array();
	}

	/**
	 * Loads model + parent brand info.
	 *
	 * @param int $model_id Model ID.
	 * @return array<string,mixed>|null
	 */
	private static function load_model_with_brand( int $model_id ): ?array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		$m   = CatalogRepository::models_table();
		$b   = CatalogRepository::brands_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.id, m.brand_id, m.name, m.slug, m.cc, m.aliases, b.name AS brand_name, b.slug AS brand_slug
				FROM {$m} m
				INNER JOIN {$b} b ON b.id = m.brand_id
				WHERE m.id = %d
				LIMIT 1",
				$model_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Loads versions for a model (published only, or published + draft when pages_for_drafts is enabled).
	 *
	 * @param int  $model_id       Model ID.
	 * @param bool $include_drafts Whether to include draft versions.
	 * @return list<array<string,mixed>>
	 */
	private static function load_versions_for_model( int $model_id, bool $include_drafts ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$v      = CatalogRepository::versions_table();
		$filter = $include_drafts ? '' : "AND status = 'published'";
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, model_id, slug, year_from, year_to, variant, status
				FROM {$v}
				WHERE model_id = %d {$filter}
				ORDER BY year_from DESC, variant ASC",
				$model_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Finds an existing bike page post by its signature meta.
	 *
	 * @param string $sig_key Signature key (`model_id:sha1`).
	 * @return array<string,mixed>|null
	 */
	private static function find_post_by_signature( string $sig_key ): ?array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_content, p.post_name, p.post_status
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = %s AND pm.meta_value = %s
				LIMIT 1",
				self::META_SIG,
				PostType::POST_TYPE,
				$sig_key
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Finds all existing bike page posts linked to a model ID.
	 *
	 * @param int $model_id Model ID.
	 * @return array<int,array{ID:int,post_status:string,post_name:string,sig:string}>
	 */
	private static function find_posts_for_model( int $model_id ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_status, p.post_name, pm_sig.meta_value AS sig
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_mod ON pm_mod.post_id = p.ID AND pm_mod.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_sig ON pm_sig.post_id = p.ID AND pm_sig.meta_key = %s
				WHERE p.post_type = %s AND pm_mod.meta_value = %s",
				self::META_MODEL_ID,
				self::META_SIG,
				PostType::POST_TYPE,
				(string) $model_id
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$pid         = (int) $row['ID'];
			$out[ $pid ] = array(
				'ID'          => $pid,
				'post_status' => (string) $row['post_status'],
				'post_name'   => (string) ( $row['post_name'] ?? '' ),
				'sig'         => (string) ( $row['sig'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Finds or creates the `motored_bike_brand` term for a brand.
	 *
	 * @param string $name Brand name.
	 * @param string $slug Brand slug.
	 */
	private static function ensure_brand_term( string $name, string $slug ): int {
		if ( '' === $name || ! function_exists( 'term_exists' ) || ! function_exists( 'wp_insert_term' ) ) {
			return 0;
		}
		$existing = term_exists( $slug, PostType::BRAND_TAXONOMY );
		if ( is_array( $existing ) && ! empty( $existing['term_id'] ) ) {
			return (int) $existing['term_id'];
		}
		$created = wp_insert_term( $name, PostType::BRAND_TAXONOMY, array( 'slug' => $slug ) );
		if ( is_array( $created ) && ! empty( $created['term_id'] ) ) {
			return (int) $created['term_id'];
		}
		return 0;
	}
}
