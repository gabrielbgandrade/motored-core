<?php
/**
 * Server-side, page-cache-safe motorcycle landing page renderer ([motored_moto]).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Modules\Compatibility\CatalogRepository;
use Motored\Core\Modules\Compatibility\Config as CompatConfig;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Modules\MiGarage\GarageAssets;
use Motored\Core\Modules\Seo\SeoContext;
use Motored\Core\Modules\Seo\SitemapFilters;
use Motored\Core\Support\Options;
use Motored\Core\Support\StorefrontAssets;

defined( 'ABSPATH' ) || exit;

// Queries over motored_compat_* and wp_posts are cached or bounded to single-page loads.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Renders the conversion-ordered motorcycle page anatomy (§10.9) and feeds Modules\Seo.
 */
final class BikeRenderer {

	public const SHORTCODE = 'motored_moto';

	/**
	 * Max `<title>` length (characters) before shorter variants are used.
	 */
	public const SEO_TITLE_MAX = 60;

	/**
	 * Max meta description length (characters).
	 */
	public const SEO_DESC_MAX = 160;

	/**
	 * Per-request memo of resolved page data keyed by post ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $page_data = array();

	/**
	 * Guards against double rendering via both shortcode and the_content filter.
	 *
	 * @var array<int,true>
	 */
	private static array $rendered_posts = array();

	/**
	 * Marks the first product image rendered on the page so only the LCP card uses loading="eager".
	 *
	 * @var bool
	 */
	private static bool $lcp_marked = false;

	/**
	 * Registers shortcode, asset enqueuing, SEO context preparation and singular content fallback.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'wp', array( $this, 'prepare_seo_context' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'the_content', array( $this, 'ensure_singular_rendered' ), 20 );
	}

	/**
	 * Enqueues shared storefront CSS and Mi Garage hydration script in <head> on BikePages views.
	 */
	public function enqueue_assets(): void {
		if ( ! Config::enabled() ) {
			return;
		}
		$owned = ( function_exists( 'is_singular' ) && is_singular( PostType::POST_TYPE ) )
			|| ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( PostType::POST_TYPE ) )
			|| ( function_exists( 'is_tax' ) && is_tax( PostType::BRAND_TAXONOMY ) );
		if ( $owned ) {
			StorefrontAssets::enqueue_compat_css();
			GarageAssets::enqueue();
		}
	}

	/**
	 * Populates SeoContext at `wp` hook before `wp_head` runs on singular motorcycle pages.
	 */
	public function prepare_seo_context(): void {
		if ( ! Config::enabled() || is_admin() || ! function_exists( 'is_singular' ) || ! is_singular( PostType::POST_TYPE ) ) {
			return;
		}
		$post_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		if ( $post_id <= 0 ) {
			return;
		}
		$data = $this->page_data( $post_id );
		if ( null === $data ) {
			return;
		}

		// Query modifiers (?pos=, ?v=, ?paged > 1) are never indexed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public query var inspection for robots.
		$has_pos = isset( $_GET['pos'] ) && '' !== (string) wp_unslash( $_GET['pos'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public query var inspection for robots.
		$has_var   = isset( $_GET['v'] ) && '' !== (string) wp_unslash( $_GET['v'] );
		$indexable = ! empty( $data['indexable'] ) && ! $has_pos && ! $has_var;

		SeoContext::set(
			array(
				'title'       => $data['seo_title'],
				'description' => $data['seo_desc'],
				'canonical'   => $data['permalink'],
				'robots'      => $indexable ? 'index,follow' : 'noindex,follow',
				'og_image'    => $data['og_image'],
				'og_type'     => 'website',
				'schema'      => $data['schema'],
			)
		);
	}

	/**
	 * Shortcode callback for [motored_moto].
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		if ( ! Config::enabled() ) {
			return '';
		}
		$atts = shortcode_atts(
			array(
				'slug'  => '',
				'brand' => '',
				'model' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$post_id = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
		$slug    = sanitize_title( (string) ( $atts['slug'] ?? '' ) );
		// Archive/search/brand listings print every post's content: there a bike page is a 0-query summary card,
		// never the full page (10 full pages = 10 product grids and ~175 extra queries on /cubiertas-para/).
		$is_own_page = function_exists( 'is_singular' ) && is_singular( PostType::POST_TYPE );
		if ( $post_id > 0 && '' === $slug && ! $is_own_page ) {
			return self::listing_card_html( $post_id );
		}
		if ( $post_id > 0 && '' === $slug ) {
			self::$rendered_posts[ $post_id ] = true;
			$data                             = $this->page_data( $post_id );
		} else {
			$data = $this->page_data_for_slug( $slug, $post_id );
		}
		if ( null === $data ) {
			return '';
		}
		StorefrontAssets::enqueue_compat_css();
		GarageAssets::enqueue();
		return $this->render_html( $data );
	}

	/**
	 * Ensures singular `motored_bike` pages render even if the post content marker was cleared.
	 *
	 * @param mixed $content Post content.
	 */
	public function ensure_singular_rendered( $content ): string {
		$html = is_string( $content ) ? $content : '';
		if ( ! Config::enabled() || is_admin() || ! function_exists( 'is_singular' ) || ! is_singular( PostType::POST_TYPE ) ) {
			return $html;
		}
		$post_id = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
		if ( $post_id <= 0 || isset( self::$rendered_posts[ $post_id ] ) ) {
			return $html;
		}
		if ( str_contains( $html, 'motored-bike-page' ) ) {
			return $html;
		}
		$data = $this->page_data( $post_id );
		if ( null === $data ) {
			return $html;
		}
		self::$rendered_posts[ $post_id ] = true;
		return $html . $this->render_html( $data );
	}

	/**
	 * Listing card for a bike page (archives, search): OEM sizes from post meta written by the sync + link.
	 * Reads only primed post meta and the permalink, so a listing of N bike pages adds 0 queries.
	 *
	 * @param int $post_id Bike page post ID.
	 */
	public static function listing_card_html( int $post_id ): string {
		$raw   = json_decode( (string) get_post_meta( $post_id, BikePageSync::META_OEM_SIZES, true ), true );
		$parts = array();
		foreach ( array(
			'f' => TireSpecs::POS_FRONT,
			'r' => TireSpecs::POS_REAR,
		) as $key => $axis ) {
			$list = is_array( $raw ) && is_array( $raw[ $key ] ?? null ) ? array_filter( array_map( 'strval', $raw[ $key ] ) ) : array();
			if ( ! empty( $list ) ) {
				$parts[] = TireSpecs::position_label( $axis ) . ' ' . implode( ' / ', $list );
			}
		}
		$link = function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';
		$out  = '<div class="motored-bike-card">';
		if ( ! empty( $parts ) ) {
			$out .= '<p class="motored-bike-card__sizes">' . esc_html__( 'Factory tire sizes:', 'motored-core' ) . ' <strong>' . esc_html( implode( ' · ', $parts ) ) . '</strong></p>';
		}
		if ( '' !== $link ) {
			$out .= '<p><a class="motored-bike-card__link" href="' . esc_url( $link ) . '">' . esc_html__( 'View tires', 'motored-core' ) . '</a></p>';
		}
		return $out . '</div>';
	}

	/**
	 * Renders a standalone "Complete the set" suggestion card for any motorcycle slug.
	 *
	 * @param string $bike_slug          Published motorcycle version slug.
	 * @param int    $exclude_product_id Optional current product ID to avoid recommending itself.
	 */
	public static function pair_block_html( string $bike_slug, int $exclude_product_id = 0 ): string {
		$front_ids = MatchEngine::matching_product_ids( $bike_slug, TireSpecs::POS_FRONT );
		$rear_ids  = MatchEngine::matching_product_ids( $bike_slug, TireSpecs::POS_REAR );
		$front_p   = self::pick_best_product( $front_ids, $exclude_product_id );
		$rear_p    = self::pick_best_product( $rear_ids, $exclude_product_id );
		if ( null === $front_p || null === $rear_p ) {
			return '';
		}
		$total = (float) $front_p['price'] + (float) $rear_p['price'];
		$out   = '<section class="motored-bike-pair" aria-labelledby="motored-pair-heading">';
		$out  .= '<h2 id="motored-pair-heading">' . esc_html__( 'Complete the set (Front + Rear)', 'motored-core' ) . '</h2>';
		$out  .= '<div class="motored-bike-pair__grid">';
		foreach ( array(
			TireSpecs::POS_FRONT => $front_p,
			TireSpecs::POS_REAR  => $rear_p,
		) as $pos => $item ) {
			$out .= '<article class="motored-bike-pair__card">';
			$out .= '<span class="motored-bike-pair__pos">' . esc_html( TireSpecs::position_label( $pos ) ) . '</span>';
			$out .= '<a class="motored-bike-pair__title" href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>';
			$out .= '<span class="motored-bike-pair__price">' . self::format_price( $item['price'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Formatted by wc_price() / escaped fallback.
			$out .= '</article>';
		}
		$out .= '</div>';
		$out .= '<p class="motored-bike-pair__total"><strong>' . esc_html__( 'Estimated pair total:', 'motored-core' ) . '</strong> ' . self::format_price( $total ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Formatted by wc_price() / escaped fallback.
		$out .= '</section>';
		return $out;
	}

	/**
	 * Builds and caches all structured data needed by both SeoContext and render_html() for a CPT post.
	 *
	 * @param int $post_id CPT post ID.
	 * @return array<string,mixed>|null
	 */
	private function page_data( int $post_id ): ?array {
		if ( isset( self::$page_data[ $post_id ] ) ) {
			return self::$page_data[ $post_id ];
		}
		$primary_slug = (string) get_post_meta( $post_id, BikePageSync::META_PRIMARY_SLUG, true );
		if ( '' === $primary_slug ) {
			return null;
		}
		$data = $this->page_data_for_slug( $primary_slug, $post_id );
		if ( null !== $data ) {
			self::$page_data[ $post_id ] = $data;
		}
		return $data;
	}

	/**
	 * Builds full page data from a primary motorcycle version slug.
	 *
	 * @param string $primary_slug Version slug.
	 * @param int    $post_id      Post ID (0 when shortcode used outside CPT).
	 * @return array<string,mixed>|null
	 */
	private function page_data_for_slug( string $primary_slug, int $post_id ): ?array {
		$profile = MatchEngine::bike_profile( $primary_slug );
		if ( null === $profile ) {
			return null;
		}
		$years_label = $post_id > 0 ? (string) get_post_meta( $post_id, BikePageSync::META_YEARS_LABEL, true ) : '';
		if ( '' === $years_label ) {
			$years_label = (string) ( $profile['years'] ?? '' );
		}
		$brand_name = (string) ( $profile['brand'] ?? '' );
		$model_name = (string) ( $profile['model'] ?? '' );
		$display    = trim( $brand_name . ' ' . $model_name . ( '' !== $years_label ? ' (' . $years_label . ')' : '' ) );

		// One candidate pass for every section; kits are never in the front/rear lists (same buckets as ?pos=).
		$lists         = MatchEngine::matching_ids_by_position( $primary_slug );
		$all_ids       = Availability::ids( $lists['all'] );
		$available_map = array_fill_keys( $all_ids, true );
		$front_units   = array_values( array_filter( $lists['front'], static fn( int $id ): bool => isset( $available_map[ $id ] ) ) );
		$rear_units    = array_values( array_filter( $lists['rear'], static fn( int $id ): bool => isset( $available_map[ $id ] ) ) );
		$kit_ids       = array_values( array_filter( $lists['kit'], static fn( int $id ): bool => isset( $available_map[ $id ] ) ) );

		$product_summary = self::summarize_products( $all_ids );
		$min_products    = Config::index_min_products();
		// Never expose stale index robots while the asynchronous availability sweep is waiting.
		$indexable = $product_summary['in_stock_count'] >= $min_products;

		$oem_front = self::extract_oem_list( $profile[ TireSpecs::POS_FRONT ] ?? array() );
		$oem_rear  = self::extract_oem_list( $profile[ TireSpecs::POS_REAR ] ?? array() );
		$faq       = Config::faq_enabled() ? self::build_faq( $display, $oem_front, $oem_rear, ! empty( $front_units ) && ! empty( $rear_units ), ! empty( $kit_ids ) ) : array();

		$permalink = $post_id > 0 && function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';
		if ( '' === $permalink ) {
			$permalink = home_url( '/' );
		}

		$siblings    = $post_id > 0 ? self::sibling_pages( $post_id ) : array();
		$brand_link  = self::brand_hub_link( $post_id, $brand_name );
		$other_bikes = self::other_bikes_same_size( $post_id, $oem_front, $oem_rear );
		$size_links  = Config::dimension_pages() ? self::size_page_links( array_merge( $oem_front, $oem_rear ) ) : array();

		$seo_title = self::seo_title( $display, (string) get_bloginfo( 'name' ) );
		$seo_desc  = self::seo_description( $display, self::format_oem_summary( $oem_front, $oem_rear ), (int) $product_summary['in_stock_count'] );

		$schema = self::build_schema(
			$display,
			$brand_name,
			$model_name,
			$permalink,
			$brand_link,
			$product_summary['items'],
			$faq
		);

		return array(
			'post_id'     => $post_id,
			'slug'        => $primary_slug,
			'profile'     => $profile,
			'brand'       => $brand_name,
			'model'       => $model_name,
			'years_label' => $years_label,
			'display'     => $display,
			'permalink'   => $permalink,
			'indexable'   => $indexable,
			'oem_front'   => $oem_front,
			'oem_rear'    => $oem_rear,
			'front_ids'   => $front_units,
			'rear_ids'    => $rear_units,
			'kit_ids'     => $kit_ids,
			'all_ids'     => $all_ids,
			'summary'     => $product_summary,
			'faq'         => $faq,
			'siblings'    => $siblings,
			'brand_link'  => $brand_link,
			'other_bikes' => $other_bikes,
			'size_links'  => $size_links,
			'seo_title'   => $seo_title,
			'seo_desc'    => trim( $seo_desc ),
			'og_image'    => $product_summary['og_image'],
			'schema'      => $schema,
		);
	}

	/**
	 * Renders the full motorcycle landing page HTML (never outputs <h1>; theme owns the H1).
	 *
	 * @param array<string,mixed> $data Page data built by page_data_for_slug().
	 */
	private function render_html( array $data ): string {
		self::$lcp_marked = false;
		$slug             = (string) $data['slug'];
		$display          = (string) $data['display'];
		$summary          = $data['summary'];
		$in_stock         = (int) ( $summary['in_stock_count'] ?? 0 );
		$min_price        = $summary['min_price'] ?? null;
		$cuotas           = self::installment_label();

		ob_start();
		?>
		<div class="motored-bike-page" data-motored-bike-page data-moto="<?php echo esc_attr( $slug ); ?>" data-label="<?php echo esc_attr( $display ); ?>">
			<div class="motored-bike-hero">
				<div class="motored-bike-hero__badges">
					<span class="motored-bike-hero__chip" data-motored-bike-chip data-slug="<?php echo esc_attr( $slug ); ?>" hidden>
						&#10003; <?php esc_html_e( 'Your motorcycle', 'motored-core' ); ?>
					</span>
					<span class="motored-bike-hero__stat">
						<strong>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: in-stock compatible tire count. */
									_n( '%d tire in stock', '%d tires in stock', $in_stock, 'motored-core' ),
									$in_stock
								)
							);
							?>
						</strong>
					</span>
					<?php if ( null !== $min_price ) : ?>
						<span class="motored-bike-hero__stat">
							<?php echo esc_html__( 'From', 'motored-core' ) . ' ' . self::format_price( (float) $min_price ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Formatted by wc_price(). ?>
						</span>
					<?php endif; ?>
					<?php if ( '' !== $cuotas ) : ?>
						<span class="motored-bike-hero__stat"><?php echo esc_html( $cuotas ); ?></span>
					<?php endif; ?>
					<span class="motored-bike-hero__stat"><?php esc_html_e( 'Shipping across the country', 'motored-core' ); ?></span>
				</div>
				<div class="motored-bike-hero__nav">
					<a class="motored-bike-hero__jump" href="#delanteras"><?php echo esc_html( sprintf( '%s (%d)', TireSpecs::position_label( TireSpecs::POS_FRONT, true ), count( $data['front_ids'] ) ) ); ?></a>
					<a class="motored-bike-hero__jump" href="#traseras"><?php echo esc_html( sprintf( '%s (%d)', TireSpecs::position_label( TireSpecs::POS_REAR, true ), count( $data['rear_ids'] ) ) ); ?></a>
					<?php if ( ! empty( $data['kit_ids'] ) ) : ?>
						<a class="motored-bike-hero__jump" href="#kits"><?php echo esc_html( sprintf( '%s (%d)', TireSpecs::position_label( TireSpecs::POS_KIT, true ), count( $data['kit_ids'] ) ) ); ?></a>
					<?php endif; ?>
					<button type="button" class="button alt wp-element-button" data-motored-use-bike="<?php echo esc_attr( $slug ); ?>">
						<?php esc_html_e( 'Save to My Garage', 'motored-core' ); ?>
					</button>
				</div>
			</div>

			<section class="motored-bike-sizes" aria-labelledby="motored-sizes-heading">
				<h2 id="motored-sizes-heading"><?php esc_html_e( 'Factory tire sizes', 'motored-core' ); ?></h2>
				<?php echo self::render_sizes_table( $data['profile'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside helper. ?>
				<p class="motored-bike-sizes__hint">
					<a href="#por-que-sirve"><?php esc_html_e( 'Understand the compatibility types', 'motored-core' ); ?></a>
				</p>
			</section>

			<?php if ( ! empty( $data['siblings'] ) ) : ?>
				<nav class="motored-bike-versions" aria-label="<?php esc_attr_e( 'Is this your version?', 'motored-core' ); ?>">
					<span class="motored-bike-versions__label"><?php esc_html_e( 'Is this your version?', 'motored-core' ); ?></span>
					<span class="motored-bike-versions__cur"><?php echo esc_html( $data['years_label'] ); ?></span>
					<?php foreach ( $data['siblings'] as $sib ) : ?>
						<a class="motored-bike-versions__link" href="<?php echo esc_url( $sib['url'] ); ?>"><?php echo esc_html( $sib['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<?php
			$sections = array(
				array(
					'id'    => 'delanteras',
					'pos'   => TireSpecs::POS_FRONT,
					'title' => sprintf( '%s (%d)', TireSpecs::position_label( TireSpecs::POS_FRONT, true ), count( $data['front_ids'] ) ),
					'ids'   => $data['front_ids'],
					'empty' => sprintf(
						/* translators: %s: motorcycle model display name. */
						__( 'We do not have front tires for %s yet. Leave your contact and we will notify you when they arrive.', 'motored-core' ),
						$display
					),
				),
				array(
					'id'    => 'traseras',
					'pos'   => TireSpecs::POS_REAR,
					'title' => sprintf( '%s (%d)', TireSpecs::position_label( TireSpecs::POS_REAR, true ), count( $data['rear_ids'] ) ),
					'ids'   => $data['rear_ids'],
					'empty' => sprintf(
						/* translators: %s: motorcycle model display name. */
						__( 'We do not have rear tires for %s yet. Leave your contact and we will notify you when they arrive.', 'motored-core' ),
						$display
					),
				),
				array(
					'id'    => 'kits',
					'pos'   => TireSpecs::POS_KIT,
					'title' => sprintf( '%s (%d)', TireSpecs::position_label( TireSpecs::POS_KIT, true ), count( $data['kit_ids'] ) ),
					'ids'   => $data['kit_ids'],
					'empty' => '',
				),
			);

			foreach ( $sections as $sec ) :
				if ( 'kits' === $sec['id'] && empty( $sec['ids'] ) ) {
					continue;
				}
				?>
				<section class="motored-bike-section" id="<?php echo esc_attr( $sec['id'] ); ?>">
					<h2><?php echo esc_html( $sec['title'] ); ?></h2>
					<?php if ( ! empty( $sec['ids'] ) ) : ?>
						<?php echo self::render_product_grid( $sec['ids'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered via WooCommerce product loop templates. ?>
						<?php if ( count( $sec['ids'] ) > 24 ) : ?>
							<p><a href="
							<?php
							echo esc_url(
								add_query_arg(
									array(
										'moto' => $slug,
										'pos'  => $sec['pos'],
									),
									CompatConfig::shop_url()
								)
							);
							?>
										"><?php esc_html_e( 'View all compatible tires', 'motored-core' ); ?></a></p>
						<?php endif; ?>
					<?php elseif ( '' !== $sec['empty'] ) : ?>
						<p class="motored-bike-section__empty"><?php echo esc_html( $sec['empty'] ); ?></p>
						<?php if ( Config::notify_enabled() ) : ?>
							<div class="motored-bike-notify" data-motored-notify data-moto="<?php echo esc_attr( $slug ); ?>" data-pos="<?php echo esc_attr( $sec['pos'] ); ?>" data-label="<?php echo esc_attr( $display ); ?>"></div>
						<?php endif; ?>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<?php echo self::pair_block_html( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside helper. ?>

			<section class="motored-bike-legend" id="por-que-sirve">
				<h2><?php esc_html_e( 'Why each option fits your motorcycle', 'motored-core' ); ?></h2>
				<ul>
					<li><strong><?php esc_html_e( 'Original (OEM)', 'motored-core' ); ?>:</strong> <?php esc_html_e( 'Exact factory size specified by the motorcycle manufacturer.', 'motored-core' ); ?></li>
					<li><strong><?php esc_html_e( 'Approved alternative size', 'motored-core' ); ?>:</strong> <?php esc_html_e( 'Validated fitment size accepted for this model without modifications.', 'motored-core' ); ?></li>
					<li><strong><?php esc_html_e( 'Equivalent to factory size', 'motored-core' ); ?>:</strong> <?php esc_html_e( 'Approved dimensional equivalent maintaining rim diameter and load/speed safety margins.', 'motored-core' ); ?></li>
				</ul>
			</section>

			<section class="motored-bike-trust">
				<p><?php esc_html_e( 'Compatibility depends on the available technical data. Missing load, speed or construction data requires checking the manufacturer manual before purchase.', 'motored-core' ); ?></p>
				<p class="motored-compat-disclaimer"><small><?php echo esc_html( CompatConfig::disclaimer() ); ?></small></p>
			</section>

			<?php if ( ! empty( $data['faq'] ) ) : ?>
				<section class="motored-bike-faq" id="faq">
					<h2><?php esc_html_e( 'Frequently asked questions', 'motored-core' ); ?></h2>
					<?php foreach ( $data['faq'] as $item ) : ?>
						<details class="motored-bike-faq__item">
							<summary><?php echo esc_html( $item['q'] ); ?></summary>
							<p><?php echo esc_html( $item['a'] ); ?></p>
						</details>
					<?php endforeach; ?>
				</section>
			<?php endif; ?>

			<nav class="motored-bike-links" aria-label="<?php esc_attr_e( 'Related links', 'motored-core' ); ?>">
				<?php if ( ! empty( $data['brand_link'] ) ) : ?>
					<p>
						<?php esc_html_e( 'Brand catalog:', 'motored-core' ); ?>
						<a href="<?php echo esc_url( $data['brand_link']['url'] ); ?>"><?php echo esc_html( $data['brand_link']['label'] ); ?></a>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $data['size_links'] ) ) : ?>
					<p>
						<?php esc_html_e( 'Tire sizes for this bike:', 'motored-core' ); ?>
						<?php
						$scnt = count( $data['size_links'] );
						foreach ( $data['size_links'] as $idx => $sl ) :
							?>
							<a href="<?php echo esc_url( $sl['url'] ); ?>"><?php echo esc_html( $sl['label'] ); ?></a>
							<?php
							echo $idx + 1 < $scnt ? ' · ' : '';
						endforeach;
						?>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $data['other_bikes'] ) ) : ?>
					<p>
						<?php esc_html_e( 'Other motorcycles using these tire sizes:', 'motored-core' ); ?>
						<?php
						$ocnt = count( $data['other_bikes'] );
						foreach ( $data['other_bikes'] as $idx => $ob ) :
							?>
							<a href="<?php echo esc_url( $ob['url'] ); ?>"><?php echo esc_html( $ob['label'] ); ?></a>
							<?php
							echo $idx + 1 < $ocnt ? ' · ' : '';
						endforeach;
						?>
					</p>
				<?php endif; ?>
			</nav>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the theme-agnostic WooCommerce product grid for an ordered list of product IDs.
	 *
	 * @param array<int,int> $product_ids Ordered product IDs.
	 */
	private static function render_product_grid( array $product_ids ): string {
		$ids = array_values( array_filter( array_map( 'intval', $product_ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( empty( $ids ) ) {
			return '';
		}
		$query = new \WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'post__in'            => $ids,
				'orderby'             => 'post__in',
				'posts_per_page'      => min( 24, count( $ids ) ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		if ( ! $query->have_posts() ) {
			return '';
		}

		$eager_filter = static function ( array $attr ): array {
			if ( ! self::$lcp_marked ) {
				self::$lcp_marked      = true;
				$attr['loading']       = 'eager';
				$attr['fetchpriority'] = 'high';
			}
			return $attr;
		};
		add_filter( 'wp_get_attachment_image_attributes', $eager_filter );

		ob_start();
		if ( function_exists( 'woocommerce_product_loop_start' ) ) {
			woocommerce_product_loop_start();
		} else {
			echo '<ul class="products columns-4">';
		}
		while ( $query->have_posts() ) {
			$query->the_post();
			if ( function_exists( 'wc_get_template_part' ) ) {
				wc_get_template_part( 'content', 'product' );
			} else {
				echo '<li class="product"><a href="' . esc_url( (string) get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
			}
		}
		if ( function_exists( 'woocommerce_product_loop_end' ) ) {
			woocommerce_product_loop_end();
		} else {
			echo '</ul>';
		}
		wp_reset_postdata();
		remove_filter( 'wp_get_attachment_image_attributes', $eager_filter );

		return (string) ob_get_clean();
	}

	/**
	 * Renders the OEM factory sizes table for front and rear axles.
	 *
	 * @param array<string,mixed> $profile Bike profile.
	 */
	private static function render_sizes_table( array $profile ): string {
		$rows = array();
		foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ) as $axis ) {
			foreach ( is_array( $profile[ $axis ] ?? null ) ? $profile[ $axis ] : array() as $canon => $entry ) {
				if ( ! is_array( $entry ) || TireSpecs::FIT_OEM !== ( $entry['type'] ?? '' ) ) {
					continue;
				}
				$load   = isset( $entry['min_load'] ) ? (int) $entry['min_load'] : null;
				$speed  = isset( $entry['min_speed'] ) ? TireSpecs::speed_code_for_rank( (int) $entry['min_speed'] ) : '';
				$const  = isset( $entry['req_const'] ) && '' !== (string) $entry['req_const'] ? (string) $entry['req_const'] : '—';
				$rows[] = array(
					'axis'  => TireSpecs::position_label( $axis ),
					'size'  => (string) ( $entry['desig'] ?? $canon ),
					'index' => '' !== TireSpecs::format_load_speed( $load, $speed ) ? TireSpecs::format_load_speed( $load, $speed ) : '—',
					'const' => $const,
				);
			}
		}
		if ( empty( $rows ) ) {
			return '';
		}
		$out  = '<div class="motored-bike-sizes__scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Factory tire sizes', 'motored-core' ) . '"><table class="motored-bike-sizes__table"><thead><tr>';
		$out .= '<th>' . esc_html__( 'Position', 'motored-core' ) . '</th>';
		$out .= '<th>' . esc_html__( 'Factory size', 'motored-core' ) . '</th>';
		$out .= '<th>' . esc_html__( 'Minimum index', 'motored-core' ) . '</th>';
		$out .= '<th>' . esc_html__( 'Construction', 'motored-core' ) . '</th>';
		$out .= '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$out .= '<tr><td>' . esc_html( $r['axis'] ) . '</td><td><strong>' . esc_html( $r['size'] ) . '</strong></td><td>' . esc_html( $r['index'] ) . '</td><td>' . esc_html( $r['const'] ) . '</td></tr>';
		}
		$out .= '</tbody></table></div>';
		return $out;
	}

	/**
	 * Extracts unique OEM tire designations for one axis.
	 *
	 * @param array<string,mixed> $axis_map Profile front/rear map.
	 * @return list<string>
	 */
	private static function extract_oem_list( array $axis_map ): array {
		$list = array();
		foreach ( $axis_map as $canon => $entry ) {
			if ( is_array( $entry ) && TireSpecs::FIT_OEM === ( $entry['type'] ?? '' ) ) {
				$list[] = (string) ( $entry['desig'] ?? $canon );
			}
		}
		return array_values( array_unique( array_filter( $list ) ) );
	}

	/**
	 * Formats single-line factory tire sizes text for SEO meta descriptions and FAQs.
	 *
	 * @param array<int,string> $oem_front Front OEM sizes.
	 * @param array<int,string> $oem_rear  Rear OEM sizes.
	 */
	private static function format_oem_summary( array $oem_front, array $oem_rear ): string {
		$parts = array();
		if ( ! empty( $oem_front ) ) {
			$parts[] = TireSpecs::position_label( TireSpecs::POS_FRONT ) . ' ' . implode( ' / ', $oem_front );
		}
		if ( ! empty( $oem_rear ) ) {
			$parts[] = TireSpecs::position_label( TireSpecs::POS_REAR ) . ' ' . implode( ' / ', $oem_rear );
		}
		if ( empty( $parts ) ) {
			return '';
		}
		return __( 'Factory sizes:', 'motored-core' ) . ' ' . implode( ', ', $parts );
	}

	/**
	 * Summarizes compatible in-stock products (count, min price, OG image and ItemList entries).
	 *
	 * @param array<int,int> $product_ids Ordered compatible product IDs.
	 * @return array{in_stock_count:int,min_price:float|null,og_image:string,items:list<array{name:string,url:string}>}
	 */
	private static function summarize_products( array $product_ids ): array {
		$out = array(
			'in_stock_count' => 0,
			'min_price'      => null,
			'og_image'       => '',
			'items'          => array(),
		);
		if ( empty( $product_ids ) || ! function_exists( 'wc_get_product' ) ) {
			return $out;
		}
		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ! $product->is_visible() ) {
				continue;
			}
			if ( Availability::is_available( $product ) ) {
				++$out['in_stock_count'];
				$price = (float) wc_get_price_to_display( $product );
				if ( $price > 0 && ( null === $out['min_price'] || $price < $out['min_price'] ) ) {
					$out['min_price'] = $price;
				}
			}
			if ( '' === $out['og_image'] && function_exists( 'wp_get_attachment_image_url' ) ) {
				$img_id = (int) $product->get_image_id();
				if ( $img_id > 0 ) {
					$img = wp_get_attachment_image_url( $img_id, 'large' );
					if ( is_string( $img ) ) {
						$out['og_image'] = $img;
					}
				}
			}
			if ( count( $out['items'] ) < 12 ) {
				$out['items'][] = array(
					'name' => $product->get_name(),
					'url'  => (string) get_permalink( $pid ),
				);
			}
		}
		return $out;
	}

	/**
	 * Picks the best-fit in-stock product from an ordered ID list (excluding optional current product).
	 *
	 * @param array<int,int> $product_ids Ordered compatible IDs.
	 * @param int            $exclude_id  Product ID to exclude.
	 * @return array{id:int,name:string,url:string,price:float}|null
	 */
	private static function pick_best_product( array $product_ids, int $exclude_id = 0 ): ?array {
		if ( empty( $product_ids ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		foreach ( $product_ids as $pid ) {
			if ( $pid === $exclude_id ) {
				continue;
			}
			$product = wc_get_product( $pid );
			if ( ! Availability::is_available( $product ) ) {
				continue;
			}
			$price = (float) wc_get_price_to_display( $product );
			if ( $price <= 0 ) {
				continue;
			}
			return array(
				'id'    => $pid,
				'name'  => $product->get_name(),
				'url'   => (string) get_permalink( $pid ),
				'price' => $price,
			);
		}
		return null;
	}

	/**
	 * Builds data-driven FAQ entries (rendered visibly AND emitted as FAQPage JSON-LD).
	 *
	 * @param string            $display   Motorcycle display label.
	 * @param array<int,string> $oem_front Front OEM sizes.
	 * @param array<int,string> $oem_rear  Rear OEM sizes.
	 * @param bool              $has_pair  Whether both front and rear products are available.
	 * @param bool              $has_kits  Whether combo kits are available.
	 * @return list<array{q:string,a:string}>
	 */
	private static function build_faq( string $display, array $oem_front, array $oem_rear, bool $has_pair, bool $has_kits ): array {
		$faq      = array();
		$size_txt = self::format_oem_summary( $oem_front, $oem_rear );
		if ( '' !== $size_txt ) {
			$faq[] = array(
				'q' => sprintf(
					/* translators: %s: motorcycle model display name. */
					__( 'What tire size does the %s use?', 'motored-core' ),
					$display
				),
				'a' => $size_txt . '. ' . CompatConfig::disclaimer(),
			);
		}
		$faq[] = array(
			'q' => __( 'Can I use an equivalent or alternative tire size?', 'motored-core' ),
			'a' => __( 'Yes, provided the rim diameter matches and the load index and speed rating meet or exceed the factory specification.', 'motored-core' ),
		);
		$faq[] = array(
			'q' => __( 'Do you sell the complete front and rear tire set?', 'motored-core' ),
			'a' => ( $has_pair || $has_kits )
				? __( 'Yes, you can combine a compatible front and rear tire or choose a ready-to-fit kit listed on this page.', 'motored-core' )
				: __( 'Availability varies by axle; if one axle is currently out of stock, you can leave your contact on this page to be notified as soon as it arrives.', 'motored-core' ),
		);
		$faq[] = array(
			'q' => __( 'How do I know if a tire fits my exact year and version?', 'motored-core' ),
			'a' => __( 'Check the sidewall markings on your current tires and compare them with the factory sizes table above, or save your motorcycle in My Garage to see compatibility badges across the store.', 'motored-core' ),
		);
		return $faq;
	}

	/**
	 * Builds JSON-LD nodes: BreadcrumbList, ItemList, optional FAQPage and Motorcycle subject (never Product).
	 *
	 * @param string                              $display    Motorcycle display label.
	 * @param string                              $brand_name Brand name.
	 * @param string                              $model_name Model name.
	 * @param string                              $permalink  Current page URL.
	 * @param array{label:string,url:string}|null $brand_link Brand hub link.
	 * @param list<array{name:string,url:string}> $items      Compatible products.
	 * @param list<array{q:string,a:string}>      $faq        Visible FAQ entries.
	 * @return list<array<string,mixed>>
	 */
	private static function build_schema( string $display, string $brand_name, string $model_name, string $permalink, ?array $brand_link, array $items, array $faq ): array {
		$archive = function_exists( 'get_post_type_archive_link' ) ? (string) get_post_type_archive_link( PostType::POST_TYPE ) : home_url( '/' . Config::base_slug() . '/' );
		$crumbs  = array(
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
		);
		$pos     = 3;
		if ( null !== $brand_link ) {
			$crumbs[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => $brand_name,
				'item'     => $brand_link['url'],
			);
		}
		$crumbs[] = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => $display,
			'item'     => $permalink,
		);

		$nodes = array(
			array(
				'@type'           => 'BreadcrumbList',
				'itemListElement' => $crumbs,
			),
			array(
				'@type' => 'WebPage',
				'name'  => $display,
				'url'   => $permalink,
				'about' => array(
					'@type' => 'Motorcycle',
					'name'  => trim( $brand_name . ' ' . $model_name ),
					'brand' => array(
						'@type' => 'Brand',
						'name'  => $brand_name,
					),
				),
			),
		);

		if ( ! empty( $items ) ) {
			$list_elements = array();
			foreach ( $items as $i => $item ) {
				$list_elements[] = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => $item['name'],
					'url'      => $item['url'],
				);
			}
			$nodes[] = array(
				'@type'           => 'ItemList',
				'itemListElement' => $list_elements,
			);
		}

		if ( ! empty( $faq ) ) {
			$entities = array();
			foreach ( $faq as $row ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $row['q'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $row['a'],
					),
				);
			}
			$nodes[] = array(
				'@type'      => 'FAQPage',
				'mainEntity' => $entities,
			);
		}

		return $nodes;
	}

	/**
	 * Resolves sibling signature pages for the same model_id (`¿Es tu versión?`).
	 *
	 * @param int $post_id Current bike post ID.
	 * @return list<array{label:string,url:string}>
	 */
	private static function sibling_pages( int $post_id ): array {
		$model_id = (int) get_post_meta( $post_id, BikePageSync::META_MODEL_ID, true );
		if ( $model_id <= 0 ) {
			return array();
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, pm_yr.meta_value AS years_label
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_mod ON pm_mod.post_id = p.ID AND pm_mod.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pm_yr ON pm_yr.post_id = p.ID AND pm_yr.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = 'publish' AND pm_mod.meta_value = %s AND p.ID != %d
				ORDER BY p.post_title ASC",
				BikePageSync::META_MODEL_ID,
				BikePageSync::META_YEARS_LABEL,
				PostType::POST_TYPE,
				(string) $model_id,
				$post_id
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$pid  = (int) $row['ID'];
			$link = function_exists( 'get_permalink' ) ? get_permalink( $pid ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				$lbl   = trim( (string) ( $row['years_label'] ?? '' ) );
				$out[] = array(
					'label' => '' !== $lbl ? $lbl : get_the_title( $pid ),
					'url'   => $link,
				);
			}
		}
		return $out;
	}

	/**
	 * Resolves the brand taxonomy hub link for a bike post.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $brand_name Brand name.
	 * @return array{label:string,url:string}|null
	 */
	private static function brand_hub_link( int $post_id, string $brand_name ): ?array {
		if ( $post_id <= 0 || ! function_exists( 'wp_get_post_terms' ) || ! function_exists( 'get_term_link' ) ) {
			return null;
		}
		$terms = wp_get_post_terms( $post_id, PostType::BRAND_TAXONOMY );
		if ( ! is_array( $terms ) || empty( $terms ) || ! $terms[0] instanceof \WP_Term ) {
			return null;
		}
		$url = get_term_link( $terms[0] );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		return array(
			'label' => sprintf(
				/* translators: %s: brand name. */
				__( 'All %s motorcycles', 'motored-core' ),
				$brand_name
			),
			'url'   => $url,
		);
	}

	/**
	 * Finds up to 8 other published bike pages sharing one of the OEM tire sizes.
	 *
	 * @param int               $post_id   Current post ID.
	 * @param array<int,string> $oem_front Front OEM sizes.
	 * @param array<int,string> $oem_rear  Rear OEM sizes.
	 * @return list<array{label:string,url:string}>
	 */
	private static function other_bikes_same_size( int $post_id, array $oem_front, array $oem_rear ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ( empty( $oem_front ) && empty( $oem_rear ) ) ) {
			return array();
		}
		$size_canon = $oem_rear[0] ?? $oem_front[0] ?? '';
		$size       = '' !== $size_canon ? CatalogRepository::find_size_by_canonical( $size_canon ) : null;
		if ( null === $size ) {
			return array();
		}
		$f           = CatalogRepository::fitments_table();
		$v           = CatalogRepository::versions_table();
		$m           = CatalogRepository::models_table();
		$b           = CatalogRepository::brands_table();
		$rows        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT v.slug, b.name AS brand_name, m.name AS model_name, v.year_from, v.year_to, v.variant
				FROM {$f} f
				INNER JOIN {$v} v ON v.id = f.version_id
				INNER JOIN {$m} m ON m.id = v.model_id
				INNER JOIN {$b} b ON b.id = m.brand_id
				WHERE f.size_id = %d AND f.fit_type = 'oem' AND v.status = 'published'
				ORDER BY b.name ASC, m.name ASC
				LIMIT 16",
				(int) $size['id']
			),
			ARRAY_A
		);
		$urls        = HubLinks::version_slug_url_map();
		$current_url = $post_id > 0 && function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';
		$seen_urls   = array( $current_url => true );
		$out         = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$u = $urls[ (string) $row['slug'] ] ?? '';
			if ( '' === $u || isset( $seen_urls[ $u ] ) ) {
				continue;
			}
			$seen_urls[ $u ] = true;
			$out[]           = array(
				'label' => trim( (string) $row['brand_name'] . ' ' . (string) $row['model_name'] ),
				'url'   => $u,
			);
			if ( count( $out ) >= 8 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Resolves links to Stage-6 dimension pages when enabled.
	 *
	 * @param array<int,string> $sizes Canonical/printed sizes.
	 * @return list<array{label:string,url:string}>
	 */
	private static function size_page_links( array $sizes ): array {
		$out = array();
		foreach ( array_unique( $sizes ) as $sz ) {
			$url = (string) apply_filters( 'motored_compat_size_page_url', '', $sz );
			if ( '' !== $url ) {
				$out[] = array(
					'label' => $sz,
					'url'   => $url,
				);
			}
		}
		return $out;
	}

	/**
	 * Reads PaymentPlans installment summary safely without coupling modules.
	 */
	private static function installment_label(): string {
		$pp = Options::all( 'payment_plans' );
		if ( 'yes' !== (string) ( $pp['enabled'] ?? 'no' ) ) {
			return '';
		}
		$max = (int) ( $pp['max_installments'] ?? 0 );
		if ( $max <= 1 ) {
			return '';
		}
		return sprintf(
			/* translators: %d: maximum interest-free installments. */
			__( 'Up to %d interest-free installments', 'motored-core' ),
			$max
		);
	}

	/**
	 * `<title>` for a bike page: the longest variant that fits SERP width (~60 chars), keyword first.
	 *
	 * 1. "Cubiertas Benelli TRK 502 (2017+): medidas y precios | Motored"
	 * 2. "Cubiertas Benelli TRK 502 (2017+): medidas y precios"
	 * 3. "Cubiertas Benelli TRK 502 (2017+)" (always used when nothing shorter fits)
	 *
	 * @param string $display   Brand + model + year range.
	 * @param string $site_name Site name.
	 */
	public static function seo_title( string $display, string $site_name ): string {
		/* translators: %s: brand + model + year range. */
		$with_topic = sprintf( __( 'Tires %s: sizes and prices', 'motored-core' ), $display );
		$candidates = array();
		if ( '' !== trim( $site_name ) ) {
			$candidates[] = $with_topic . ' | ' . trim( $site_name );
		}
		$candidates[] = $with_topic;
		foreach ( $candidates as $title ) {
			if ( self::text_length( $title ) <= self::SEO_TITLE_MAX ) {
				return $title;
			}
		}
		/* translators: %s: brand + model + year range. */
		return sprintf( __( 'Tires %s', 'motored-core' ), $display );
	}

	/**
	 * Meta description <= 160 chars: full sentence (sizes + count + shipping), else name + sizes, else cut at a word.
	 *
	 * @param string $display   Brand + model + year range.
	 * @param string $size_desc Factory sizes summary ('' when unknown).
	 * @param int    $count     Available compatible products.
	 */
	public static function seo_description( string $display, string $size_desc, int $count ): string {
		$sizes = '' !== $size_desc ? $size_desc . '.' : '';
		$full  = trim(
			sprintf(
				/* translators: 1: motorcycle name, 2: factory tire sizes, 3: product count. */
				__( 'Tires for %1$s. %2$s %3$d compatible options available with shipping across the country.', 'motored-core' ),
				$display,
				$sizes,
				$count
			)
		);
		if ( self::text_length( $full ) <= self::SEO_DESC_MAX ) {
			return $full;
		}
		$short = trim(
			sprintf(
				/* translators: 1: motorcycle name, 2: factory tire sizes. */
				__( 'Tires for %1$s. %2$s', 'motored-core' ),
				$display,
				$sizes
			)
		);
		if ( self::text_length( $short ) <= self::SEO_DESC_MAX ) {
			return $short;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $short, 0, self::SEO_DESC_MAX - 1, 'UTF-8' ) : substr( $short, 0, self::SEO_DESC_MAX - 1 );
		$pos = strrpos( $cut, ' ' );
		return rtrim( false !== $pos ? substr( $cut, 0, $pos ) : $cut, ' ,.·' ) . '…';
	}

	/**
	 * Character length (multibyte-safe when mbstring is available).
	 *
	 * @param string $text Text.
	 */
	private static function text_length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Formats a price cleanly using WooCommerce if loaded.
	 *
	 * @param float $amount Numeric price.
	 */
	private static function format_price( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( (string) wc_price( $amount ) );
		}
		return '$' . number_format( $amount, 0, ',', '.' );
	}

	/**
	 * Resets per-request memos (for unit tests).
	 */
	public static function reset(): void {
		self::$page_data      = array();
		self::$rendered_posts = array();
		self::$lcp_marked     = false;
	}
}
