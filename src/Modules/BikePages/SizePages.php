<?php
/**
 * Stage 6: Opt-in tire-size landing pages (/medidas/{size}/) synced from motored_compat_sizes.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Modules\Compatibility\CatalogRepository;
use Motored\Core\Modules\Compatibility\CompatCache;
use Motored\Core\Modules\Compatibility\ProductIndexRepository;
use Motored\Core\Modules\Compatibility\SizeParser;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Modules\MiGarage\GarageAssets;
use Motored\Core\Modules\Seo\SeoContext;
use Motored\Core\Modules\Seo\SitemapFilters;
use Motored\Core\Support\StorefrontAssets;

defined( 'ABSPATH' ) || exit;

// Queries over motored_compat_* and wp_posts are cached or run inside Action Scheduler.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Registers CPT motored_tire_size (`medidas`), syncs sizes in batches and renders [motored_medida].
 */
final class SizePages {

	public const POST_TYPE         = 'motored_tire_size';
	public const BASE_SLUG         = 'medidas';
	public const SHORTCODE         = 'motored_medida';
	public const REWRITE_VER_OPT   = 'motored_size_pages_rewrite_ver';
	public const REWRITE_VERSION   = '1';
	public const SYNC_HOOK         = 'motored_bike_pages_sync_sizes';
	public const META_CANONICAL    = '_motored_size_canonical';
	public const META_SYNCED_TITLE = '_motored_synced_title';
	public const META_SYNCED_BODY  = '_motored_synced_content';
	public const CONTENT_MARKER    = '[motored_medida]';

	/**
	 * Per-request memo of canonical size -> permalink.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $url_map = null;

	/**
	 * Registers CPT, hooks and Action Scheduler worker when dimension_pages() is enabled.
	 *
	 * @param bool $is_admin Whether running in admin context.
	 */
	public function register( bool $is_admin = false ): void {
		if ( ! Config::dimension_pages() ) {
			return;
		}
		add_action( 'init', array( $this, 'register_post_type' ), 5 );
		add_filter( 'motored_compat_size_page_url', array( $this, 'filter_size_page_url' ), 10, 2 );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'wp', array( $this, 'prepare_seo_context' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'the_content', array( $this, 'ensure_singular_rendered' ), 20 );
		add_action( self::SYNC_HOOK, array( $this, 'run_sync_batch' ), 10, 1 );

		if ( $is_admin ) {
			add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ) );
		}
	}

	/**
	 * Registers CPT `motored_tire_size` under `/medidas/`.
	 */
	public function register_post_type(): void {
		if ( ! function_exists( 'register_post_type' ) ) {
			return;
		}
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Tire size pages', 'motored-core' ),
					'singular_name' => __( 'Tire size page', 'motored-core' ),
				),
				'public'             => true,
				'has_archive'        => self::BASE_SLUG,
				'hierarchical'       => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=product',
				'show_in_rest'       => true,
				'supports'           => array( 'title', 'editor', 'revisions', 'custom-fields' ),
				'publicly_queryable' => true,
				'rewrite'            => array(
					'slug'       => self::BASE_SLUG,
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Flushes rewrite rules once on admin_init when enabled.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || self::REWRITE_VERSION === (string) get_option( self::REWRITE_VER_OPT, '' ) ) {
			return;
		}
		$this->register_post_type();
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		update_option( self::REWRITE_VER_OPT, self::REWRITE_VERSION, false );
	}

	/**
	 * Schedules batched size page sync via Action Scheduler.
	 */
	public static function schedule_sync(): bool {
		return ActionScheduler::schedule( time(), self::SYNC_HOOK, array( 0 ), true );
	}

	/**
	 * Action Scheduler batch callback syncing 20 sizes per job.
	 *
	 * @param int $offset Zero-based size offset.
	 */
	public function run_sync_batch( int $offset = 0 ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$s    = CatalogRepository::sizes_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, canonical FROM {$s} ORDER BY id ASC LIMIT %d OFFSET %d",
				20,
				max( 0, $offset )
			),
			ARRAY_A
		);
		$list = is_array( $rows ) ? $rows : array();
		foreach ( $list as $row ) {
			$this->upsert_size_post( (int) $row['id'], (string) $row['canonical'] );
		}
		if ( 20 === count( $list ) ) {
			ActionScheduler::schedule( time() + 1, self::SYNC_HOOK, array( $offset + 20 ), true );
		}
		self::reset();
		CompatCache::bump_catalog();
	}

	/**
	 * Resolves a canonical or printed tire size to its `/medidas/{size}/` permalink.
	 *
	 * @param mixed  $url      Existing URL.
	 * @param string $raw_size Canonical or printed tire size.
	 */
	public function filter_size_page_url( $url, string $raw_size ): string {
		$parsed = SizeParser::parse( $raw_size );
		$canon  = null !== $parsed ? $parsed['canonical'] : trim( $raw_size );
		if ( '' === $canon ) {
			return is_string( $url ) ? $url : '';
		}
		$map = self::size_url_map();
		return $map[ $canon ] ?? ( is_string( $url ) ? $url : '' );
	}

	/**
	 * Enqueues storefront CSS/JS on size landing pages.
	 */
	public function enqueue_assets(): void {
		if ( function_exists( 'is_singular' ) && is_singular( self::POST_TYPE ) ) {
			StorefrontAssets::enqueue_compat_css();
			GarageAssets::enqueue();
		}
	}

	/**
	 * Populates SeoContext for singular size pages.
	 */
	public function prepare_seo_context(): void {
		if ( is_admin() || ! function_exists( 'is_singular' ) || ! is_singular( self::POST_TYPE ) ) {
			return;
		}
		$pid = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		if ( $pid <= 0 ) {
			return;
		}
		$canon = (string) get_post_meta( $pid, self::META_CANONICAL, true );
		if ( '' === $canon ) {
			return;
		}
		$pids      = self::matching_product_ids_for_size( $canon );
		$indexable = count( $pids ) >= Config::index_min_products();
		$link      = function_exists( 'get_permalink' ) ? (string) get_permalink( $pid ) : home_url( '/' );
		SeoContext::set(
			array(
				'title'       => sprintf(
					/* translators: 1: tire size, 2: site name. */
					__( 'Motorcycle tire %1$s · Prices and compatible bikes | %2$s', 'motored-core' ),
					$canon,
					get_bloginfo( 'name' )
				),
				'description' => sprintf(
					/* translators: 1: tire size, 2: matching products count. */
					__( 'Buy %1$s motorcycle tires (%2$d options available). Check equivalents and compatible motorcycles.', 'motored-core' ),
					$canon,
					count( $pids )
				),
				'canonical'   => $link,
				'robots'      => $indexable ? 'index,follow' : 'noindex,follow',
			)
		);
	}

	/**
	 * Shortcode callback for `[motored_medida]`.
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'size' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$pid  = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
		$size = trim( (string) ( $atts['size'] ?? '' ) );
		if ( '' === $size && $pid > 0 ) {
			$size = (string) get_post_meta( $pid, self::META_CANONICAL, true );
		}
		if ( '' === $size ) {
			return '';
		}
		StorefrontAssets::enqueue_compat_css();
		GarageAssets::enqueue();

		$pids   = self::matching_product_ids_for_size( $size );
		$equivs = self::equivalents_for_size( $size );
		$bikes  = self::bikes_for_size( $size );

		ob_start();
		?>
		<div class="motored-bike-page">
			<section class="motored-bike-section">
				<h2>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: tire size, 2: matching product count. */
							__( 'Tires in size %1$s (%2$d)', 'motored-core' ),
							$size,
							count( $pids )
						)
					);
					?>
				</h2>
				<?php if ( empty( $pids ) ) : ?>
					<p><?php esc_html_e( 'No in-stock tires in this exact size right now.', 'motored-core' ); ?></p>
				<?php else : ?>
					<ul class="products columns-4">
						<?php
						foreach ( array_slice( $pids, 0, 24 ) as $prod_id ) :
							$link = get_permalink( $prod_id );
							?>
							<li class="product"><a href="<?php echo esc_url( is_string( $link ) ? $link : '#' ); ?>"><?php echo esc_html( get_the_title( $prod_id ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

			<?php if ( ! empty( $equivs ) ) : ?>
				<section class="motored-bike-section">
					<h2><?php esc_html_e( 'Approved equivalent sizes', 'motored-core' ); ?></h2>
					<ul>
						<?php foreach ( $equivs as $eq ) : ?>
							<li>
								<?php if ( '' !== $eq['url'] ) : ?>
									<a href="<?php echo esc_url( $eq['url'] ); ?>"><?php echo esc_html( $eq['canonical'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $eq['canonical'] ); ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $bikes ) ) : ?>
				<section class="motored-bike-section">
					<h2><?php esc_html_e( 'Motorcycles using this tire size', 'motored-core' ); ?></h2>
					<ul>
						<?php foreach ( $bikes as $bk ) : ?>
							<li><a href="<?php echo esc_url( $bk['url'] ); ?>"><?php echo esc_html( $bk['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Ensures `[motored_medida]` renders on singular size pages even when marker was removed.
	 *
	 * @param mixed $content Post content.
	 */
	public function ensure_singular_rendered( $content ): string {
		$html = is_string( $content ) ? $content : '';
		if ( is_admin() || ! function_exists( 'is_singular' ) || ! is_singular( self::POST_TYPE ) || str_contains( $html, 'motored-bike-page' ) ) {
			return $html;
		}
		return $html . $this->shortcode();
	}

	/**
	 * Upserts one size landing post keyed by `_motored_size_canonical`.
	 *
	 * @param int    $size_id   Size ID.
	 * @param string $canonical Canonical size.
	 */
	private function upsert_size_post( int $size_id, string $canonical ): void {
		if ( $size_id <= 0 || '' === $canonical ) {
			return;
		}
		global $wpdb;
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = %s AND pm.meta_value = %s
				LIMIT 1",
				self::META_CANONICAL,
				self::POST_TYPE,
				$canonical
			)
		);
		$title       = sprintf(
			/* translators: %s: canonical tire size. */
			__( 'Tire %s', 'motored-core' ),
			$canonical
		);
		$slug      = sanitize_title( str_replace( array( '/', '.' ), '-', $canonical ) );
		$indexable = count( self::matching_product_ids_for_size( $canonical ) ) >= Config::index_min_products();

		if ( $existing_id <= 0 ) {
			$pid = wp_insert_post(
				array(
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => self::CONTENT_MARKER,
				),
				true
			);
			if ( is_int( $pid ) && $pid > 0 ) {
				update_post_meta( $pid, self::META_CANONICAL, $canonical );
				update_post_meta( $pid, self::META_SYNCED_TITLE, $title );
				update_post_meta( $pid, self::META_SYNCED_BODY, self::CONTENT_MARKER );
				update_post_meta( $pid, SitemapFilters::INDEXABLE_META, $indexable ? '1' : '0' );
			}
			return;
		}

		update_post_meta( $existing_id, SitemapFilters::INDEXABLE_META, $indexable ? '1' : '0' );
	}

	/**
	 * Cached canonical_size => permalink map.
	 *
	 * @return array<string,string>
	 */
	private static function size_url_map(): array {
		if ( null !== self::$url_map ) {
			return self::$url_map;
		}
		self::$url_map = CompatCache::remember(
			'size_page_urls_v1',
			static function (): array {
				global $wpdb;
				if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
					return array();
				}
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.ID, pm.meta_value AS canon
						FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
						WHERE p.post_type = %s AND p.post_status = 'publish'",
						self::META_CANONICAL,
						self::POST_TYPE
					),
					ARRAY_A
				);
				$map  = array();
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$url = function_exists( 'get_permalink' ) ? get_permalink( (int) $row['ID'] ) : '';
					if ( is_string( $url ) && '' !== $url ) {
						$map[ (string) $row['canon'] ] = $url;
					}
				}
				return $map;
			},
			CompatCache::BUCKET_CATALOG,
			CompatCache::TTL_CATALOG
		);
		return self::$url_map;
	}

	/**
	 * Lists published in-stock product IDs carrying a given canonical tire size.
	 *
	 * @param string $canonical Canonical size.
	 * @return list<int>
	 */
	private static function matching_product_ids_for_size( string $canonical ): array {
		$size = CatalogRepository::find_size_by_canonical( $canonical );
		if ( null === $size ) {
			return array();
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$idx  = ProductIndexRepository::table();
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT i.product_id
				FROM {$idx} i
				INNER JOIN {$wpdb->posts} p ON p.ID = i.product_id
				WHERE p.post_type = 'product' AND p.post_status = 'publish' AND i.in_stock = 1
					AND (i.front_size_id = %d OR i.rear_size_id = %d)
				ORDER BY i.product_id DESC",
				(int) $size['id'],
				(int) $size['id']
			)
		);
		return array_values( array_filter( array_map( 'intval', is_array( $rows ) ? $rows : array() ), static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * Lists approved target equivalents for a canonical size.
	 *
	 * @param string $canonical Canonical size.
	 * @return list<array{canonical:string,url:string}>
	 */
	private static function equivalents_for_size( string $canonical ): array {
		$size = CatalogRepository::find_size_by_canonical( $canonical );
		if ( null === $size ) {
			return array();
		}
		$eqs = CatalogRepository::equivalences_from_sizes_batch( array( (int) $size['id'] ) );
		$map = self::size_url_map();
		$out = array();
		foreach ( $eqs as $eq ) {
			$c     = (string) $eq['target_canonical'];
			$out[] = array(
				'canonical' => $c,
				'url'       => $map[ $c ] ?? '',
			);
		}
		return $out;
	}

	/**
	 * Lists published bike pages using this size as an OEM fitment.
	 *
	 * @param string $canonical Canonical size.
	 * @return list<array{label:string,url:string}>
	 */
	private static function bikes_for_size( string $canonical ): array {
		$size = CatalogRepository::find_size_by_canonical( $canonical );
		if ( null === $size ) {
			return array();
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$f    = CatalogRepository::fitments_table();
		$v    = CatalogRepository::versions_table();
		$m    = CatalogRepository::models_table();
		$b    = CatalogRepository::brands_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT v.slug, b.name AS brand_name, m.name AS model_name
				FROM {$f} f
				INNER JOIN {$v} v ON v.id = f.version_id
				INNER JOIN {$m} m ON m.id = v.model_id
				INNER JOIN {$b} b ON b.id = m.brand_id
				WHERE f.size_id = %d AND f.fit_type = %s AND v.status = 'published'
				ORDER BY b.name ASC, m.name ASC
				LIMIT 24",
				(int) $size['id'],
				TireSpecs::FIT_OEM
			),
			ARRAY_A
		);
		$urls = HubLinks::version_slug_url_map();
		$seen = array();
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$u = $urls[ (string) $row['slug'] ] ?? '';
			if ( '' === $u || isset( $seen[ $u ] ) ) {
				continue;
			}
			$seen[ $u ] = true;
			$out[]      = array(
				'label' => trim( (string) $row['brand_name'] . ' ' . (string) $row['model_name'] ),
				'url'   => $u,
			);
		}
		return $out;
	}

	/**
	 * Resets per-request memo (for unit tests).
	 */
	public static function reset(): void {
		self::$url_map = null;
	}
}
