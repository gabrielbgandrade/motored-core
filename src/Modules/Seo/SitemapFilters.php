<?php
/**
 * Core XML sitemap filters, <lastmod> entries, Redis caching and robots.txt optimization.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

// Direct taxonomy-post join excludes brands with zero indexable bikes from wp_sitemaps (cached in Support\Cache).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Keeps WordPress' core XML sitemaps clean, fast (Redis-backed), accurate (<lastmod>) and free of thin/private URLs.
 */
final class SitemapFilters {

	public const INDEXABLE_META = '_motored_indexable';
	public const CACHE_BUCKET   = 'seo_sitemap';

	/**
	 * Registers sitemap and robots.txt filters.
	 */
	public function register(): void {
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'filter_provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_posts_query_args' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'filter_posts_entry' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'filter_taxonomies_query_args' ), 10, 2 );
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 20, 2 );
	}

	/**
	 * Invalidates cached sitemap lookups (called on post status/SEO meta writes).
	 */
	public static function invalidate_cache(): void {
		Cache::bump( self::CACHE_BUCKET );
	}

	/**
	 * Removes the `users` XML sitemap provider when enabled (prevents admin username exposure and crawl waste).
	 *
	 * @param mixed  $provider Sitemap provider instance.
	 * @param string $name     Provider name (`posts`, `taxonomies`, `users`).
	 * @return mixed
	 */
	public function filter_provider( $provider, string $name ) {
		if ( 'users' === $name && Config::disable_user_sitemap() ) {
			return false;
		}
		return $provider;
	}

	/**
	 * Excludes non-indexable landing pages, transactional WooCommerce pages, hidden products and manually noindexed posts.
	 *
	 * @param mixed  $args      WP_Query args for the sitemap provider.
	 * @param string $post_type Post type slug.
	 * @return array<string,mixed>
	 */
	public function filter_posts_query_args( $args, string $post_type ): array {
		$args = is_array( $args ) ? $args : array();

		if ( 'page' === $post_type ) {
			$exclude = self::excluded_page_ids();
			if ( array() !== $exclude ) {
				$args['post__not_in'] = array_values( array_unique( array_merge( (array) ( $args['post__not_in'] ?? array() ), $exclude ) ) );
			}
		}

		if ( ! Config::enabled() ) {
			return $args;
		}

		if ( in_array( $post_type, array( 'motored_bike', 'motored_tire_size' ), true ) ) {
			$meta_query         = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
			$meta_query[]       = array(
				'key'     => self::INDEXABLE_META,
				'value'   => '1',
				'compare' => '=',
			);
			$meta_query[]       = self::not_noindexed_meta_clause();
			$args['meta_query'] = $meta_query;
			return $args;
		}

		if ( in_array( $post_type, array( 'product', 'page', 'post' ), true ) && Config::site_wide() ) {
			$meta_query         = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
			$meta_query[]       = self::not_noindexed_meta_clause();
			$args['meta_query'] = $meta_query;

			if ( 'product' === $post_type ) {
				$tax_query         = isset( $args['tax_query'] ) && is_array( $args['tax_query'] ) ? $args['tax_query'] : array();
				$tax_query[]       = array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				);
				$args['tax_query'] = $tax_query;
			}
		}

		return $args;
	}

	/**
	 * Injects W3C `<lastmod>` into XML sitemap post entries so search engines prioritize fresh products and bike pages.
	 *
	 * @param mixed $entry Sitemap URL entry array.
	 * @param mixed $post  WP_Post object.
	 * @return array<string,mixed>
	 */
	public function filter_posts_entry( $entry, $post ): array {
		$entry = is_array( $entry ) ? $entry : array();
		if ( ! Config::enabled() || ! is_object( $post ) || empty( $post->post_modified_gmt ) ) {
			return $entry;
		}
		$raw = (string) $post->post_modified_gmt;
		if ( '' === $raw || '0000-00-00 00:00:00' === $raw ) {
			return $entry;
		}
		$ts = strtotime( $raw . ' GMT' );
		if ( false !== $ts ) {
			$entry['lastmod'] = gmdate( 'c', $ts );
		}
		return $entry;
	}

	/**
	 * Excludes empty/non-indexable brand hubs (cached in Redis via Support\Cache) and thin product tags.
	 *
	 * @param mixed  $args     get_terms() arguments.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<string,mixed>
	 */
	public function filter_taxonomies_query_args( $args, string $taxonomy ): array {
		$args = is_array( $args ) ? $args : array();
		if ( ! Config::enabled() ) {
			return $args;
		}
		if ( 'product_tag' === $taxonomy && Config::site_wide() ) {
			$args['include'] = array( 0 ); // Keep thin product tag archives out of the XML sitemap.
			return $args;
		}
		if ( in_array( $taxonomy, array( 'product_cat', 'product_brand' ), true ) && Config::site_wide() ) {
			$meta_query         = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
			$meta_query[]       = self::not_noindexed_meta_clause();
			$args['meta_query'] = $meta_query;
			return $args;
		}
		if ( 'motored_bike_brand' !== $taxonomy ) {
			return $args;
		}
		$clean           = self::indexable_brand_term_ids();
		$args['include'] = empty( $clean ) ? array( 0 ) : $clean;
		return $args;
	}

	/**
	 * Appends e-commerce crawl-budget protections and XML sitemap URL to virtual `robots.txt`.
	 *
	 * @param mixed $output Existing robots.txt body.
	 * @param mixed $public Site visibility (`blog_public`).
	 */
	public function filter_robots_txt( $output, $public ): string {
		$text = is_string( $output ) ? $output : '';
		if ( ! $public || ! Config::robots_txt_enabled() ) {
			return $text;
		}

		$rules = array(
			'Disallow: /*?add-to-cart=*',
			'Disallow: /*?orderby=*',
			'Disallow: /*?filter_*',
			'Disallow: /*?min_price=*',
			'Disallow: /*?max_price=*',
			'Disallow: /*?s=*',
		);

		foreach ( self::excluded_page_ids() as $pid ) {
			if ( function_exists( 'get_permalink' ) ) {
				$url  = get_permalink( $pid );
				$path = is_string( $url ) ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
				if ( '' !== $path && '/' !== $path ) {
					$rules[] = 'Disallow: ' . trailingslashit( $path );
				}
			}
		}

		$rules   = array_values( array_unique( $rules ) );
		$sitemap = 'Sitemap: ' . esc_url_raw( home_url( '/wp-sitemap.xml' ) );

		$append = array();
		foreach ( $rules as $rule ) {
			if ( ! str_contains( $text, $rule ) ) {
				$append[] = $rule;
			}
		}
		$has_sitemap = (bool) preg_match( '/^Sitemap:/mi', $text );

		if ( array() !== $append ) {
			$text = rtrim( $text ) . "\n" . implode( "\n", $append ) . "\n";
		}
		if ( ! $has_sitemap ) {
			$text = rtrim( $text ) . "\n\n" . $sitemap . "\n";
		}
		return $text;
	}

	/**
	 * Page IDs that must stay out of XML sitemaps and robots crawling (Cart, Checkout, My Account, Mi Garage).
	 *
	 * @return list<int>
	 */
	private static function excluded_page_ids(): array {
		$ids    = array();
		$garage = (int) get_option( 'motored_mi_garage_page_id', 0 );
		if ( $garage > 0 ) {
			$ids[] = $garage;
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wc_page ) {
				$pid = (int) wc_get_page_id( $wc_page );
				if ( $pid > 0 ) {
					$ids[] = $pid;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Meta query clause excluding posts explicitly marked noindex in Motored SEO.
	 *
	 * @return array<string,mixed>
	 */
	private static function not_noindexed_meta_clause(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => HeadRenderer::META_ROBOTS,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => HeadRenderer::META_ROBOTS,
				'value'   => array( 'noindex,follow', 'noindex,nofollow' ),
				'compare' => 'NOT IN',
			),
		);
	}

	/**
	 * Indexable bike brand term IDs (cached in Redis when persistent object cache is active).
	 *
	 * @return list<int>
	 */
	private static function indexable_brand_term_ids(): array {
		$producer = static function (): array {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->term_taxonomy ) ) {
				return array();
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed WP core tables and constant meta key.
			$term_ids = $wpdb->get_col(
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
						AND (bot.meta_value IS NULL OR bot.meta_value NOT IN ('noindex,follow','noindex,nofollow'))",
					self::INDEXABLE_META,
					HeadRenderer::META_ROBOTS,
					'motored_bike_brand'
				)
			);
			return array_values( array_filter( array_map( 'intval', is_array( $term_ids ) ? $term_ids : array() ), static fn( int $id ): bool => $id > 0 ) );
		};

		if ( function_exists( 'wp_using_ext_object_cache' ) && Cache::is_persistent() ) {
			$cached = Cache::remember( 'brand_terms', $producer, 3600, self::CACHE_BUCKET );
			return is_array( $cached ) ? $cached : array();
		}

		return $producer();
	}
}
