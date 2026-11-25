<?php
/**
 * Core XML sitemap filters (tracked in docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

defined( 'ABSPATH' ) || exit;

// Direct taxonomy-post join excludes brands with zero indexable bikes from wp_sitemaps.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Excludes non-indexable BikePages/SizePages posts and empty brand hubs from wp_sitemaps.
 */
final class SitemapFilters {

	public const INDEXABLE_META = '_motored_indexable';

	/**
	 * Registers sitemap query filters.
	 */
	public function register(): void {
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_posts_query_args' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'filter_taxonomies_query_args' ), 10, 2 );
	}

	/**
	 * Excludes motored_bike / motored_tire_size posts marked non-indexable (`_motored_indexable = '0'`).
	 *
	 * @param mixed  $args      WP_Query args for the sitemap provider.
	 * @param string $post_type Post type slug.
	 * @return array<string,mixed>
	 */
	public function filter_posts_query_args( $args, string $post_type ): array {
		$args = is_array( $args ) ? $args : array();
		if ( 'page' === $post_type ) {
			$garage = (int) get_option( 'motored_mi_garage_page_id', 0 );
			if ( $garage > 0 ) {
				$args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), array( $garage ) ) ) );
			}
		}
		if ( ! Config::enabled() || ! in_array( $post_type, array( 'motored_bike', 'motored_tire_size' ), true ) ) {
			return $args;
		}
		$meta_query         = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$meta_query[]       = array(
			'key'     => self::INDEXABLE_META,
			'value'   => '1',
			'compare' => '=',
		);
		$meta_query[]       = array(
			'relation' => 'OR',
			array(
				'key'     => HeadRenderer::META_ROBOTS,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => HeadRenderer::META_ROBOTS,
				'value'   => 'noindex,follow',
				'compare' => '!=',
			),
		);
		$args['meta_query'] = $meta_query;
		return $args;
	}

	/**
	 * Excludes brand hub terms that have zero indexable bike posts.
	 *
	 * @param mixed  $args     get_terms() arguments.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<string,mixed>
	 */
	public function filter_taxonomies_query_args( $args, string $taxonomy ): array {
		$args = is_array( $args ) ? $args : array();
		if ( ! Config::enabled() || 'motored_bike_brand' !== $taxonomy ) {
			return $args;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->term_taxonomy ) ) {
			return $args;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed WP core tables and constant meta key.
		$term_ids        = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT tt.term_id
				FROM {$wpdb->term_taxonomy} tt
				INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} bot ON bot.post_id = p.ID AND bot.meta_key = %s
				WHERE tt.taxonomy = %s
					AND p.post_type = 'motored_bike'
					AND p.post_status = 'publish'
					AND pm.meta_value = '1'
					AND (bot.meta_value IS NULL OR bot.meta_value != 'noindex,follow')",
				self::INDEXABLE_META,
				HeadRenderer::META_ROBOTS,
				'motored_bike_brand'
			)
		);
		$clean           = array_values( array_filter( array_map( 'intval', is_array( $term_ids ) ? $term_ids : array() ), static fn( int $id ): bool => $id > 0 ) );
		$args['include'] = empty( $clean ) ? array( 0 ) : $clean;
		return $args;
	}
}
