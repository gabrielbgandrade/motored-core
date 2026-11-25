<?php
/**
 * Internal link graph and archive/brand hub integration for BikePages (S1-2, S1-3).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Modules\Compatibility\CompatCache;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Modules\Seo\SeoContext;
use Motored\Core\Modules\Seo\SitemapFilters;

defined( 'ABSPATH' ) || exit;

// Cached lookup over wp_posts/wp_postmeta; table names come from $wpdb.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Resolves version slugs -> bike page URLs in O(1) and renders brand/archive hubs.
 */
final class HubLinks {

	/**
	 * Per-request memo of version_slug => permalink.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $slug_url_map = null;

	public const HUB_SHORTCODE = 'motored_bike_hub';

	/**
	 * Registers filter and archive hub hooks.
	 */
	public function register(): void {
		$this->register_url_filter();
		add_action( 'wp', array( $this, 'prepare_hub_seo_context' ) );
		// Classic themes print the archive description; block themes use the plugin hub templates + shortcode.
		add_filter( 'get_the_archive_description', array( $this, 'append_hub_directory' ) );
		add_filter( 'get_the_archive_title', array( $this, 'filter_archive_title' ) );
		add_shortcode( self::HUB_SHORTCODE, array( $this, 'hub_shortcode' ) );
	}

	/**
	 * H1 of the hubs: "Cubiertas por moto" / "Cubiertas para motos Benelli" instead of "Archivo: Páginas de moto".
	 *
	 * @param mixed $title Archive title.
	 */
	public function filter_archive_title( $title ): string {
		$title = is_string( $title ) ? $title : '';
		if ( ! Config::enabled() ) {
			return $title;
		}
		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( PostType::POST_TYPE ) ) {
			return __( 'Tires by motorcycle', 'motored-core' );
		}
		if ( function_exists( 'is_tax' ) && is_tax( PostType::BRAND_TAXONOMY ) && function_exists( 'get_queried_object' ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				/* translators: %s: motorcycle brand name. */
				return sprintf( __( 'Tires for %s motorcycles', 'motored-core' ), $term->name );
			}
		}
		return $title;
	}

	/**
	 * Shortcode [motored_bike_hub]: the hub body used by the block templates (archive: brands + every bike page;
	 * brand hub: models with factory sizes). Empty outside those two views.
	 */
	public function hub_shortcode(): string {
		if ( ! Config::enabled() ) {
			return '';
		}
		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( PostType::POST_TYPE ) ) {
			return self::render_archive_brands_directory() . self::render_all_bikes_directory();
		}
		if ( function_exists( 'is_tax' ) && is_tax( PostType::BRAND_TAXONOMY ) && function_exists( 'get_queried_object' ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				return self::render_brand_models_directory( $term );
			}
		}
		return '';
	}

	/**
	 * Every published bike page grouped by brand (crawl path from the hub to each page): 1 posts query + 1 terms query.
	 */
	private static function render_all_bikes_directory(): string {
		if ( ! function_exists( 'get_posts' ) ) {
			return '';
		}
		$posts = get_posts(
			array(
				'post_type'              => PostType::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded hub directory (~50–250 bike pages), one query.
				'no_found_rows'          => true,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'update_post_meta_cache' => false,
			)
		);
		if ( empty( $posts ) ) {
			return '';
		}
		$by_brand = array();
		foreach ( $posts as $p ) {
			$terms = get_the_terms( $p, PostType::BRAND_TAXONOMY );
			$brand = is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ? $terms[0] : null;
			$key   = null !== $brand ? $brand->name : '';
			if ( ! isset( $by_brand[ $key ] ) ) {
				$by_brand[ $key ] = array(
					'term'  => $brand,
					'posts' => array(),
				);
			}
			$by_brand[ $key ]['posts'][] = $p;
		}
		ksort( $by_brand, SORT_NATURAL | SORT_FLAG_CASE );
		$out = '<div class="motored-bike-hub motored-bike-hub--all">';
		foreach ( $by_brand as $name => $group ) {
			$term_link = $group['term'] instanceof \WP_Term ? get_term_link( $group['term'] ) : '';
			$out      .= '<h2>' . ( is_string( $term_link ) && '' !== $term_link
				? '<a href="' . esc_url( $term_link ) . '">' . esc_html( (string) $name ) . '</a>'
				: esc_html( (string) $name ) ) . '</h2><ul class="motored-bike-hub__models">';
			foreach ( $group['posts'] as $p ) {
				$out .= '<li><a href="' . esc_url( (string) get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></li>';
			}
			$out .= '</ul>';
		}
		return $out . '</div>';
	}

	/**
	 * Registers only the slug -> bike page URL filter. REST needs it too: the product banner, the garage and
	 * the hydrated compatibility tab get their profiles/rows from REST, and without it they fall back to ?moto=.
	 */
	public function register_url_filter(): void {
		add_filter( 'motored_compat_bike_page_url', array( $this, 'filter_bike_page_url' ), 10, 2 );
	}

	/**
	 * Resolves a motorcycle version slug to its published `motored_bike` permalink.
	 *
	 * @param mixed  $url          Incoming URL.
	 * @param string $version_slug Version slug.
	 */
	public function filter_bike_page_url( $url, string $version_slug ): string {
		if ( ! Config::enabled() ) {
			return is_string( $url ) ? $url : '';
		}
		$clean = sanitize_title( $version_slug );
		if ( '' === $clean ) {
			return is_string( $url ) ? $url : '';
		}
		$map = self::version_slug_url_map();
		return $map[ $clean ] ?? ( is_string( $url ) ? $url : '' );
	}

	/**
	 * Builds and caches the full version_slug -> bike page permalink map in 1 query (0 with Redis).
	 *
	 * @return array<string,string>
	 */
	public static function version_slug_url_map(): array {
		if ( null !== self::$slug_url_map ) {
			return self::$slug_url_map;
		}
		self::$slug_url_map = CompatCache::remember(
			'bike_page_urls_v2',
			static function (): array {
				global $wpdb;
				if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
					return array();
				}
				// Full post rows in the same query, so get_permalink() below is served from the post cache
				// (it was 1 query per bike page — 46 extra queries on every product page without Redis).
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.*, pm.meta_value AS motored_slugs_json
						FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
						WHERE p.post_type = %s AND p.post_status = 'publish'",
						BikePageSync::META_VERSION_SLUGS,
						PostType::POST_TYPE
					),
					ARRAY_A
				);
				$map  = array();
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$pid   = (int) $row['ID'];
					$slugs = json_decode( (string) $row['motored_slugs_json'], true );
					if ( ! is_array( $slugs ) || empty( $slugs ) ) {
						continue;
					}
					if ( function_exists( 'update_post_cache' ) && class_exists( '\WP_Post' ) ) {
						unset( $row['motored_slugs_json'] );
						$post_obj = new \WP_Post( (object) $row );
						$posts    = array( $post_obj );
						update_post_cache( $posts );
					}
					$link = function_exists( 'get_permalink' ) ? get_permalink( $pid ) : '';
					if ( ! is_string( $link ) || '' === $link ) {
						$link = home_url( '/' . Config::base_slug() . '/' . (string) $row['post_name'] . '/' );
					}
					foreach ( $slugs as $vs ) {
						$clean = sanitize_title( (string) $vs );
						if ( '' !== $clean ) {
							$map[ $clean ] = $link;
						}
					}
				}
				return $map;
			},
			CompatCache::BUCKET_CATALOG,
			CompatCache::TTL_CATALOG
		);
		return self::$slug_url_map;
	}

	/**
	 * Populates SeoContext on `/cubiertas-para/` archive and `/cubiertas-para/marca/{brand}/` hub.
	 */
	public function prepare_hub_seo_context(): void {
		if ( ! Config::enabled() || is_admin() ) {
			return;
		}
		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( PostType::POST_TYPE ) ) {
			$archive_url = function_exists( 'get_post_type_archive_link' ) ? (string) get_post_type_archive_link( PostType::POST_TYPE ) : home_url( '/' . Config::base_slug() . '/' );
			$counts      = BikePageSync::preview_counts();
			SeoContext::set(
				array(
					'title'       => __( 'Tires by motorcycle model · Size catalog', 'motored-core' ),
					'description' => __( 'Find OEM, alternative and equivalent tire sizes for every motorcycle model.', 'motored-core' ),
					'canonical'   => $archive_url,
					'robots'      => $counts['indexable'] > 0 ? 'index,follow' : 'noindex,follow',
					'schema'      => array(
						array(
							'@type'           => 'BreadcrumbList',
							'itemListElement' => array(
								array(
									'@type'    => 'ListItem',
									'position' => 1,
									'name'     => __( 'Home', 'motored-core' ),
									'item'     => home_url( '/' ),
								),
								array(
									'@type'    => 'ListItem',
									'position' => 2,
									'name'     => __( 'Tires by motorcycle', 'motored-core' ),
									'item'     => $archive_url,
								),
							),
						),
					),
				)
			);
			return;
		}

		if ( function_exists( 'is_tax' ) && is_tax( PostType::BRAND_TAXONOMY ) && function_exists( 'get_queried_object' ) ) {
			$term = get_queried_object();
			if ( ! $term instanceof \WP_Term ) {
				return;
			}
			$term_url  = function_exists( 'get_term_link' ) ? (string) get_term_link( $term ) : '';
			$has_index = self::brand_has_indexable_bikes( (int) $term->term_id );
			$archive   = function_exists( 'get_post_type_archive_link' ) ? (string) get_post_type_archive_link( PostType::POST_TYPE ) : home_url( '/' . Config::base_slug() . '/' );
			SeoContext::set(
				array(
					'title'       => sprintf(
						/* translators: %s: motorcycle brand name. */
						__( 'Tires for %s motorcycles · Models and sizes', 'motored-core' ),
						$term->name
					),
					'description' => sprintf(
						/* translators: %s: motorcycle brand name. */
						__( 'Check factory tire sizes and available stock for %s motorcycles.', 'motored-core' ),
						$term->name
					),
					'canonical'   => $term_url,
					'robots'      => $has_index ? 'index,follow' : 'noindex,follow',
					'schema'      => array(
						array(
							'@type'           => 'BreadcrumbList',
							'itemListElement' => array(
								array(
									'@type'    => 'ListItem',
									'position' => 1,
									'name'     => __( 'Home', 'motored-core' ),
									'item'     => home_url( '/' ),
								),
								array(
									'@type'    => 'ListItem',
									'position' => 2,
									'name'     => __( 'Tires by motorcycle', 'motored-core' ),
									'item'     => $archive,
								),
								array(
									'@type'    => 'ListItem',
									'position' => 3,
									'name'     => $term->name,
									'item'     => $term_url,
								),
							),
						),
					),
				)
			);
		}
	}

	/**
	 * Enhances CPT archive and brand hub descriptions with a clean directory of brands/models and OEM sizes.
	 *
	 * @param mixed $description Existing archive description.
	 */
	public function append_hub_directory( $description ): string {
		$desc = is_string( $description ) ? $description : '';
		if ( ! Config::enabled() || is_admin() ) {
			return $desc;
		}
		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( PostType::POST_TYPE ) ) {
			return $desc . self::render_archive_brands_directory();
		}
		if ( function_exists( 'is_tax' ) && is_tax( PostType::BRAND_TAXONOMY ) && function_exists( 'get_queried_object' ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				return $desc . self::render_brand_models_directory( $term );
			}
		}
		return $desc;
	}

	/**
	 * Renders brand pills on the `/cubiertas-para/` archive hub.
	 */
	private static function render_archive_brands_directory(): string {
		if ( ! function_exists( 'get_terms' ) ) {
			return '';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => PostType::BRAND_TAXONOMY,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}
		$out = '<div class="motored-bike-hub"><h2>' . esc_html__( 'Browse by motorcycle brand', 'motored-core' ) . '</h2><ul class="motored-bike-hub__brands">';
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}
			$out .= '<li><a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . ' <span class="motored-bike-hub__cnt">(' . esc_html( (string) $term->count ) . ')</span></a></li>';
		}
		$out .= '</ul></div>';
		return $out;
	}

	/**
	 * Renders model links + OEM tire sizes on `/cubiertas-para/marca/{brand}/`.
	 *
	 * @param \WP_Term $term Brand term.
	 */
	private static function render_brand_models_directory( \WP_Term $term ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return '';
		}
		$posts = get_posts(
			array(
				'post_type'      => PostType::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => array(
					array(
						'taxonomy' => PostType::BRAND_TAXONOMY,
						'field'    => 'term_id',
						'terms'    => (int) $term->term_id,
					),
				),
			)
		);
		if ( empty( $posts ) ) {
			return '';
		}
		$slugs = array();
		foreach ( $posts as $p ) {
			$slug = (string) get_post_meta( $p->ID, BikePageSync::META_PRIMARY_SLUG, true );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		$profiles = MatchEngine::bike_profile_many( $slugs );

		$out = '<div class="motored-bike-hub"><ul class="motored-bike-hub__models">';
		foreach ( $posts as $p ) {
			$slug  = (string) get_post_meta( $p->ID, BikePageSync::META_PRIMARY_SLUG, true );
			$prof  = $profiles[ $slug ] ?? null;
			$sizes = '';
			if ( is_array( $prof ) ) {
				$parts = array();
				foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ) as $ax ) {
					$oem = array();
					foreach ( is_array( $prof[ $ax ] ?? null ) ? $prof[ $ax ] : array() as $canon => $entry ) {
						if ( is_array( $entry ) && TireSpecs::FIT_OEM === ( $entry['type'] ?? '' ) ) {
							$oem[] = (string) ( $entry['desig'] ?? $canon );
						}
					}
					if ( ! empty( $oem ) ) {
						$parts[] = TireSpecs::position_label( $ax ) . ' ' . implode( ' / ', $oem );
					}
				}
				$sizes = implode( ' · ', $parts );
			}
			$link = get_permalink( $p->ID );
			$out .= '<li><a href="' . esc_url( is_string( $link ) ? $link : '#' ) . '"><strong>' . esc_html( get_the_title( $p->ID ) ) . '</strong></a>';
			if ( '' !== $sizes ) {
				$out .= ' — <span>' . esc_html( $sizes ) . '</span>';
			}
			$out .= '</li>';
		}
		$out .= '</ul></div>';
		return $out;
	}

	/**
	 * Checks whether a brand term contains at least one indexable bike post.
	 *
	 * @param int $term_id Brand term ID.
	 */
	private static function brand_has_indexable_bikes( int $term_id ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->term_taxonomy ) ) {
			return false;
		}
		$cnt = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->term_taxonomy} tt
				INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE tt.taxonomy = %s AND tt.term_id = %d
					AND p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value = '1'",
				SitemapFilters::INDEXABLE_META,
				PostType::BRAND_TAXONOMY,
				$term_id,
				PostType::POST_TYPE
			)
		);
		return $cnt > 0;
	}

	/**
	 * Resets per-request memoized URL map (for unit tests or post updates).
	 */
	public static function reset(): void {
		self::$slug_url_map = null;
	}
}
