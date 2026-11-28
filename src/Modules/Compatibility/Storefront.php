<?php
/**
 * Storefront integration: selector shortcode, ?moto= filtering, loop priming, fit markers and 3 product tabs.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Integration\WooCommerce\AttributeHooks;
use Motored\Core\Integration\WooCommerce\CompatibilityHooks;
use Motored\Core\Support\StorefrontAssets;
use Motored\Core\Modules\MiGarage\GarageAssets;
use Motored\Core\Modules\MiGarage\GarageEndpoint;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps public HTML 100% cache-safe while hydrating active Mi Garage compatibility client-side.
 */
final class Storefront {

	public const SELECTOR_SHORTCODE    = 'motored_compat_selector';
	public const COMPAT_LIST_SHORTCODE = 'motored_compat_list';
	public const FULL_SIZE_META        = '_motored_full_size';
	public const HANDLE_SELECTOR       = 'motored-compat-selector';
	public const HANDLE_TAB            = 'motored-compat-tab';

	/**
	 * Per-request primed index rows keyed by product_id (false = checked and not indexed).
	 *
	 * @var array<int,array<string,mixed>|false>
	 */
	private static array $primed_fits = array();

	/**
	 * Per-request flag preventing repeated script/config localization for compat-selector.js.
	 *
	 * @var bool
	 */
	private static bool $selector_enqueued = false;

	/**
	 * Per-request flag: the active-bike bar / garage CTA was already printed (classic hook, no-results hook or block).
	 *
	 * @var bool
	 */
	private static bool $bar_rendered = false;

	/**
	 * Per-request flag: the single product banner was already printed (classic hook or block template).
	 *
	 * @var bool
	 */
	private static bool $banner_rendered = false;

	/**
	 * Registers storefront hooks (zero option reads, zero asset enqueues here).
	 */
	public function register(): void {
		add_shortcode( self::SELECTOR_SHORTCODE, array( $this, 'shortcode_selector' ) );
		add_shortcode( self::COMPAT_LIST_SHORTCODE, array( $this, 'shortcode_compat_list' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'wp_robots', array( $this, 'robots_noindex' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_catalog_assets' ) );
		add_action( 'template_redirect', array( $this, 'normalize_position_url' ), 5 );

		CompatibilityHooks::register_query_filters(
			array( $this, 'filter_product_query' ),
			array( $this, 'filter_block_query_vars' ),
			array( $this, 'preserve_layered_nav_link' )
		);

		CompatibilityHooks::register_display_hooks(
			array( $this, 'prime_loop_fits' ),
			array( $this, 'render_active_bike_bar' ),
			array( $this, 'render_loop_fit_marker' ),
			array( $this, 'render_single_fit_banner' )
		);

		CompatibilityHooks::register_block_hooks(
			array( $this, 'filter_collection_block' ),
			array( $this, 'filter_price_block' )
		);

		CompatibilityHooks::register_tab_hooks(
			array( $this, 'filter_product_tabs' ),
			array( $this, 'filter_tech_tab_heading' ),
			array( $this, 'append_full_size_attribute_row' )
		);
	}

	/**
	 * Registers `moto` and `pos` query vars.
	 *
	 * @param mixed $vars Query vars.
	 * @return list<string>
	 */
	public function register_query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = 'moto';
		$vars[] = 'pos';
		return array_values( array_unique( array_map( 'strval', $vars ) ) );
	}

	/**
	 * Adds `noindex, follow` on ?moto= catalog URLs without blocking crawl in robots.txt.
	 * Never noindexes product pages or the homepage when a ?moto= link is shared.
	 *
	 * @param mixed $robots Robots directives.
	 * @return array<string,mixed>
	 */
	public function robots_noindex( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();
		if ( self::is_catalog_request() && '' !== self::requested_moto_slug() && Config::enabled() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = false;
			$robots['follow']   = true;
		}
		return $robots;
	}

	/**
	 * Enqueues compat.css in <head> and compat-garage.js ONLY on views that actually render compatibility or Mi Garage UI.
	 */
	public function maybe_enqueue_catalog_assets(): void {
		// Cheap conditionals first: unrelated pages (home, pages, cart, checkout) never read the option (0 queries).
		$is_product = function_exists( 'is_product' ) && is_product();
		$is_account = function_exists( 'is_account_page' ) && is_account_page();
		$is_catalog = self::is_catalog_request();
		if ( ( ! $is_catalog && ! $is_product && ! $is_account ) || ! Config::enabled() ) {
			return;
		}
		if ( $is_catalog ) {
			StorefrontAssets::enqueue_compat_css();
			GarageAssets::enqueue();
			return;
		}
		if ( $is_product ) {
			$pid = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
			if ( $pid > 0 && null !== self::fit_for_product( $pid ) ) {
				StorefrontAssets::enqueue_compat_css();
				GarageAssets::enqueue();
			}
			return;
		}
		if ( $is_account ) {
			$is_garage = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( GarageEndpoint::ENDPOINT );
			if ( $is_garage ) {
				StorefrontAssets::enqueue_compat_css();
				GarageAssets::enqueue();
				return;
			}
			// Preserve shared-computer logout cleanup on /mi-cuenta/ without shipping JS to every account tab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Cookie presence flag only.
			if ( isset( $_COOKIE[ GarageEndpoint::LOGOUT_COOKIE ] ) ) {
				GarageAssets::enqueue();
			}
		}
	}

	/**
	 * Enqueues the vehicle cascade selector script once per request.
	 */
	public static function enqueue_selector_script(): void {
		if ( self::$selector_enqueued ) {
			return;
		}
		self::$selector_enqueued = true;
		StorefrontAssets::enqueue_ui_js();
		do_action( 'motored_compat_selector_assets' );
		wp_enqueue_script(
			self::HANDLE_SELECTOR,
			MOTORED_CORE_URL . 'assets/js/compat-selector.js',
			array( StorefrontAssets::HANDLE_UI ),
			MOTORED_CORE_VERSION . '-selector2',
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		$config = array(
			'restBase' => untrailingslashit( rest_url( RestController::NAMESPACE ) ),
			'shopUrl'  => Config::shop_url(),
			'i18n'     => array(
				'brandPlaceholder' => __( 'Select brand...', 'motored-core' ),
				'modelPlaceholder' => __( 'Select model...', 'motored-core' ),
				'yearPlaceholder'  => __( 'Select year...', 'motored-core' ),
				'varPlaceholder'   => __( 'Select version...', 'motored-core' ),
				'loading'          => __( 'Loading...', 'motored-core' ),
				'noModels'         => __( 'No models available', 'motored-core' ),
				'loadSelectErr'    => __( 'Could not load options. Please try again.', 'motored-core' ),
			),
		);
		wp_add_inline_script( self::HANDLE_SELECTOR, 'window.motoredCompatSelectorCfg=' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Shortcode [motored_compat_selector mode="shop|garage" lazy="0|1"].
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function shortcode_selector( $atts ): string {
		if ( ! Config::enabled() ) {
			return '';
		}
		$atts = shortcode_atts(
			array(
				'mode' => 'shop',
				'lazy' => '0',
			),
			is_array( $atts ) ? $atts : array(),
			self::SELECTOR_SHORTCODE
		);
		$mode = 'garage' === $atts['mode'] ? 'garage' : 'shop';

		StorefrontAssets::enqueue_compat_css();
		self::enqueue_selector_script();

		return self::selector_form_html( $mode, '1' === $atts['lazy'] );
	}

	/**
	 * Renders the cascade brand -> model -> year -> version selector markup.
	 *
	 * @param string $mode 'shop'|'garage'|'check'.
	 * @param bool   $lazy When true, defers fetching /compat/brands until the picker opens.
	 */
	private static function selector_form_html( string $mode, bool $lazy = false ): string {
		$id_prefix = 'motored-sel-' . wp_unique_id();
		$btn_label = __( 'Find compatible tires', 'motored-core' );
		if ( 'garage' === $mode ) {
			$btn_label = __( 'Save to My Garage', 'motored-core' );
		} elseif ( 'check' === $mode ) {
			$btn_label = __( 'Check compatibility', 'motored-core' );
		}
		ob_start();
		?>
		<form class="motored-compat-selector" data-mode="<?php echo esc_attr( $mode ); ?>" data-shop-url="<?php echo esc_url( Config::shop_url() ); ?>"<?php echo $lazy ? ' data-lazy="1"' : ''; ?>>
			<div class="motored-compat-selector__grid">
				<div class="motored-compat-selector__row">
					<label for="<?php echo esc_attr( $id_prefix ); ?>-brand"><?php esc_html_e( 'Brand', 'motored-core' ); ?></label>
					<select id="<?php echo esc_attr( $id_prefix ); ?>-brand" data-role="brand" required>
						<option value=""><?php esc_html_e( 'Select brand...', 'motored-core' ); ?></option>
					</select>
				</div>
				<div class="motored-compat-selector__row">
					<label for="<?php echo esc_attr( $id_prefix ); ?>-model"><?php esc_html_e( 'Model', 'motored-core' ); ?></label>
					<select id="<?php echo esc_attr( $id_prefix ); ?>-model" data-role="model" disabled required>
						<option value=""><?php esc_html_e( 'Select model...', 'motored-core' ); ?></option>
					</select>
				</div>
				<div class="motored-compat-selector__row">
					<label for="<?php echo esc_attr( $id_prefix ); ?>-year"><?php esc_html_e( 'Year', 'motored-core' ); ?></label>
					<select id="<?php echo esc_attr( $id_prefix ); ?>-year" data-role="year" disabled required>
						<option value=""><?php esc_html_e( 'Select year...', 'motored-core' ); ?></option>
					</select>
				</div>
				<div class="motored-compat-selector__row" data-role="variant-wrap" hidden>
					<label for="<?php echo esc_attr( $id_prefix ); ?>-variant"><?php esc_html_e( 'Version', 'motored-core' ); ?></label>
					<select id="<?php echo esc_attr( $id_prefix ); ?>-variant" data-role="variant">
						<option value=""><?php esc_html_e( 'Select version...', 'motored-core' ); ?></option>
					</select>
				</div>
			</div>
			<div class="motored-compat-selector__actions">
				<button type="submit" class="button alt wp-element-button" data-role="submit" disabled>
					<?php echo esc_html( $btn_label ); ?>
				</button>
				<?php if ( 'check' === $mode ) : ?>
					<button type="button" class="button wp-element-button motored-compat-selector__cancel" data-role="cancel">
						<?php esc_html_e( 'Cancel', 'motored-core' ); ?>
					</button>
				<?php endif; ?>
			</div>
			<p class="motored-compat-selector__msg" data-role="msg" aria-live="polite" hidden></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Applies ?moto= and &pos= filtering to classic WooCommerce shop/archive queries.
	 *
	 * @param mixed $query WP_Query instance.
	 */
	public function filter_product_query( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || ! self::is_catalog_request() || ! Config::enabled() ) {
			return;
		}
		$query->set( 'motored_prime_fits', true );
		$moto = self::requested_moto_slug();
		if ( '' === $moto ) {
			return;
		}
		$pos      = self::requested_position();
		$ids      = MatchEngine::matching_product_ids( $moto, $pos );
		$existing = $query->get( 'post__in' );
		$merged   = self::intersect_post_in( is_array( $existing ) ? $existing : array(), $ids );
		$query->set( 'post__in', $merged );
		if ( self::best_fit_order_applies() ) {
			$query->set( 'orderby', 'post__in' );
		}
	}

	/**
	 * Whether ?moto= results should follow the best-fit order of post__in (setting on, no shopper-chosen sorting).
	 */
	public static function best_fit_order_applies(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public catalog sorting parameter, read only.
		$chosen = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) && '' !== $_GET['orderby'];
		return ! $chosen && Config::oem_first_sort();
	}

	/**
	 * Applies ?moto= and &pos= filtering to Gutenberg Query Loop / Product Collection blocks.
	 *
	 * @param mixed $query_vars Query vars array.
	 * @param mixed $block      WP_Block instance.
	 * @return array<string,mixed>
	 */
	public function filter_block_query_vars( $query_vars, $block = null ): array {
		$vars = is_array( $query_vars ) ? $query_vars : array();
		if ( ! $block instanceof \WP_Block || empty( $block->context['query']['isProductCollectionBlock'] ) || ! Config::enabled() ) {
			return $vars;
		}
		$vars['motored_prime_fits'] = true;
		if ( empty( $block->context['query']['inherit'] ) || ! self::is_catalog_request() ) {
			return $vars;
		}
		$moto = self::requested_moto_slug();
		if ( '' === $moto ) {
			return $vars;
		}
		$pos              = self::requested_position();
		$ids              = MatchEngine::matching_product_ids( $moto, $pos );
		$existing         = isset( $vars['post__in'] ) && is_array( $vars['post__in'] ) ? $vars['post__in'] : array();
		$vars['post__in'] = self::intersect_post_in( $existing, $ids );
		if ( self::best_fit_order_applies() ) {
			$vars['orderby'] = 'post__in';
		}
		return $vars;
	}

	/**
	 * Preserves ?moto= and ?pos= across WooCommerce layered nav attribute filter links (never single product URLs).
	 *
	 * @param mixed $link Filter link URL.
	 * @return string
	 */
	public function preserve_layered_nav_link( $link ): string {
		$url  = (string) $link;
		$moto = self::requested_moto_slug();
		if ( '' === $moto || '' === $url || ! Config::enabled() ) {
			return $url;
		}
		$args = array( 'moto' => $moto );
		$pos  = self::requested_position();
		if ( '' !== $pos ) {
			$args['pos'] = $pos;
		}
		return add_query_arg( $args, $url );
	}

	/**
	 * Pure helper intersecting existing post__in with compatibility product IDs (empty -> [0]).
	 *
	 * @param array<int|string,mixed> $existing Existing post__in constraint.
	 * @param array<int,int>          $matched  Matching product IDs.
	 * @return list<int>
	 */
	public static function intersect_post_in( array $existing, array $matched ): array {
		if ( empty( $matched ) ) {
			return array( 0 );
		}
		if ( empty( $existing ) ) {
			return $matched;
		}
		$clean_existing = array_map( 'intval', $existing );
		// Keep the matched (best fit first) order.
		$intersected = array_values( array_intersect( $matched, $clean_existing ) );
		return empty( $intersected ) ? array( 0 ) : $intersected;
	}

	/**
	 * Primes motored_compat_product_index rows for all products in a shop/archive query in ONE SQL query.
	 *
	 * @param mixed $posts Array of WP_Post objects.
	 * @param mixed $query WP_Query object.
	 * @return mixed
	 */
	public function prime_loop_fits( $posts, $query = null ) {
		if ( ! is_array( $posts ) || empty( $posts ) || is_admin() ) {
			return $posts;
		}
		if ( $query instanceof \WP_Query && ! $query->is_main_query() && ! $query->get( 'motored_prime_fits' ) ) {
			return $posts;
		}
		$ids = array();
		foreach ( $posts as $post ) {
			if ( is_object( $post ) && isset( $post->ID, $post->post_type ) && 'product' === $post->post_type ) {
				$pid = (int) $post->ID;
				if ( $pid > 0 && ! array_key_exists( $pid, self::$primed_fits ) ) {
					$ids[] = $pid;
				}
			}
		}
		if ( empty( $ids ) || ! Config::enabled() ) {
			return $posts;
		}
		$rows = ProductIndexRepository::find_many( $ids );
		foreach ( $ids as $pid ) {
			self::$primed_fits[ $pid ] = $rows[ $pid ] ?? false;
		}
		return $posts;
	}

	/**
	 * Prints the active motorcycle bar above the shop loop (or the Mi Garage CTA mount when no ?moto= is present).
	 *
	 * Hooked to woocommerce_before_shop_loop AND woocommerce_no_products_found, so the bar (with "Clear filter")
	 * is still shown when the motorcycle has no tires; a per-request flag prevents printing it twice.
	 */
	public function render_active_bike_bar(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in active_bike_bar_html().
		echo self::active_bike_bar_html();
	}

	/**
	 * Builds the active-bike bar (with ?moto=) or the cache-safe garage CTA mount (without it), once per request.
	 */
	public static function active_bike_bar_html(): string {
		if ( self::$bar_rendered || ! Config::enabled() ) {
			return '';
		}
		$moto = self::requested_moto_slug();
		if ( '' === $moto ) {
			// Only where ?moto= actually filters the grid (main shop/archive/product search), never [products] shortcodes.
			if ( ! Config::garage_cta() || ! self::is_catalog_request() ) {
				return '';
			}
			self::$bar_rendered = true;
			StorefrontAssets::enqueue_compat_css();
			GarageAssets::enqueue();
			// Identical for every visitor: compat-garage.js fills it only when a Mi Garage bike is active.
			return '<div class="motored-garage-cta" data-role="garage-cta" aria-live="polite" hidden></div>';
		}
		self::$bar_rendered = true;
		StorefrontAssets::enqueue_compat_css();
		GarageAssets::enqueue();
		$clear   = remove_query_arg( array( 'moto', 'pos', 'paged' ) );
		$profile = MatchEngine::bike_profile( $moto );
		if ( null === $profile ) {
			return '<div class="motored-compat-bar"><p>' . esc_html__( 'The selected motorcycle was not found.', 'motored-core' )
				. ' <a class="motored-compat-bar__clear" href="' . esc_url( $clear ) . '">' . esc_html__( 'Clear filter', 'motored-core' ) . '</a></p></div>';
		}
		$active_pos = self::requested_position();
		// Same partition as the grid below (MatchEngine::matching_ids_by_position()): a tab count always matches its grid.
		$counts    = MatchEngine::matching_product_counts( (string) $profile['slug'] );
		$tabs      = array(
			''                   => __( 'All', 'motored-core' ),
			TireSpecs::POS_FRONT => TireSpecs::position_label( TireSpecs::POS_FRONT, true ),
			TireSpecs::POS_REAR  => TireSpecs::position_label( TireSpecs::POS_REAR, true ),
			TireSpecs::POS_KIT   => TireSpecs::position_label( TireSpecs::POS_KIT, true ),
		);
		$base_url  = remove_query_arg( array( 'pos', 'paged', 'product-page', 'filter_' . TireSpecs::ATTR_POSITION, 'query_type_' . TireSpecs::ATTR_POSITION ) );
		$total_cnt = (int) ( $counts['all'] ?? 0 );

		$out = '<div class="motored-compat-bar" data-moto="' . esc_attr( (string) $profile['slug'] ) . '">';
		// Actions first (1.7): pick another motorcycle (opens Mi Garage) or drop the motorcycle filter.
		$garage_url = GarageAssets::garage_url();
		$out       .= '<p class="motored-compat-bar__actions">';
		if ( '' !== $garage_url ) {
			$out .= '<a class="motored-compat-bar__change" href="' . esc_url( $garage_url ) . '" data-motored-garage-open>' . esc_html__( 'Choose another motorcycle', 'motored-core' ) . '</a>'
				. ' <span class="motored-compat-bar__sep" aria-hidden="true">/</span> ';
		}
		$out .= '<a class="motored-compat-bar__clear" href="' . esc_url( $clear ) . '">' . esc_html__( 'Remove motorcycle', 'motored-core' ) . '</a></p>';
		$out .= '<p class="motored-compat-bar__title"><strong>' . esc_html__( 'Tires compatible with', 'motored-core' ) . ' ' . esc_html( (string) $profile['label'] ) . '</strong>';
		$out .= ' <span class="motored-compat-bar__count">' . esc_html(
			sprintf(
				/* translators: %d: number of compatible products. */
				_n( '%d result', '%d results', $total_cnt, 'motored-core' ),
				$total_cnt
			)
		) . '</span></p>';

		// Factory sizes as printed in the manual, so a shopper can double-check them at a glance.
		$size_parts = array();
		foreach ( array(
			TireSpecs::POS_FRONT => TireSpecs::position_label( TireSpecs::POS_FRONT ),
			TireSpecs::POS_REAR  => TireSpecs::position_label( TireSpecs::POS_REAR ),
		) as $axis => $axis_label ) {
			$oem = array();
			foreach ( is_array( $profile[ $axis ] ?? null ) ? $profile[ $axis ] : array() as $canon => $entry ) {
				if ( is_array( $entry ) && TireSpecs::FIT_OEM === ( $entry['type'] ?? '' ) ) {
					$oem[] = (string) ( $entry['desig'] ?? $canon );
				}
			}
			if ( ! empty( $oem ) ) {
				$size_parts[] = $axis_label . ' ' . implode( ' / ', $oem );
			}
		}
		if ( ! empty( $size_parts ) ) {
			$out .= '<p class="motored-compat-bar__sizes">' . esc_html__( 'Factory tire sizes:', 'motored-core' ) . ' <strong>' . esc_html( implode( ' · ', $size_parts ) ) . '</strong></p>';
		}
		$active_cnt = '' === $active_pos ? $total_cnt : (int) ( $counts[ $active_pos ] ?? 0 );
		if ( 0 === $active_cnt ) {
			$out .= '<p class="motored-compat-bar__empty">' . esc_html__( 'We do not have tires for this motorcycle yet. Leave your contact and we will notify you when they arrive.', 'motored-core' ) . '</p>';
			$out .= '<div class="motored-compat-bar__notify" data-motored-notify data-moto="' . esc_attr( (string) $profile['slug'] ) . '" data-pos="' . esc_attr( '' !== $active_pos ? $active_pos : 'all' ) . '" data-label="' . esc_attr( (string) $profile['label'] ) . '"></div>';
		}
		$links = '';
		foreach ( $tabs as $pos_key => $label ) {
			$cnt       = '' === $pos_key ? $total_cnt : (int) ( $counts[ $pos_key ] ?? 0 );
			$tab_label = sprintf( '%s (%d)', $label, $cnt );
			$is_cur    = $active_pos === $pos_key;
			if ( '' !== $pos_key && ! $is_cur && 0 === $cnt ) {
				$links .= '<span class="motored-compat-bar__tab is-disabled" aria-disabled="true">' . esc_html( $tab_label ) . '</span> ';
				continue;
			}
			$url    = '' === $pos_key ? $base_url : add_query_arg( 'pos', $pos_key, $base_url );
			$links .= '<a class="motored-compat-bar__tab' . ( $is_cur ? ' is-active' : '' ) . '"' . ( $is_cur ? ' aria-current="true"' : '' ) . ' href="' . esc_url( $url ) . '">' . esc_html( $tab_label ) . '</a> ';
		}
		$out .= '<div class="motored-compat-bar__positions">' . $links . '</div>';
		// Other Mi Garage bikes (client-side, cache-safe): compat-garage.js fills it.
		$out .= '<div class="motored-compat-bar__switch" data-role="bar-switch" hidden></div>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * Prints the cache-safe hidden fitment marker inside loop cards.
	 */
	public function render_loop_fit_marker(): void {
		if ( ! Config::enabled() ) {
			return;
		}
		$product = CompatibilityHooks::current_product();
		if ( null === $product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in loop_marker_html().
		echo self::loop_marker_html( $product->get_id() );
	}

	/**
	 * Loop marker plus the assets that hydrate it (empty string for non-tire products).
	 *
	 * @param int $product_id Product ID.
	 */
	public static function loop_marker_html( int $product_id ): string {
		$marker = self::fit_marker_html( $product_id, 'loop' );
		if ( '' !== $marker ) {
			StorefrontAssets::enqueue_compat_css();
			GarageAssets::enqueue();
		}
		return $marker;
	}

	/**
	 * Prints the cache-safe fitment marker and compatibility banner mount on single product pages.
	 */
	public function render_single_fit_banner(): void {
		if ( ! Config::enabled() ) {
			return;
		}
		$product = CompatibilityHooks::current_product();
		if ( null === $product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in single_banner_html().
		echo self::single_banner_html( $product->get_id() );
	}

	/**
	 * Single product banner (marker + status mount + lazy picker), once per request.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function single_banner_html( int $product_id ): string {
		if ( self::$banner_rendered ) {
			return '';
		}
		$marker = self::fit_marker_html( $product_id, 'single' );
		if ( '' === $marker ) {
			return '';
		}
		self::$banner_rendered = true;
		StorefrontAssets::enqueue_compat_css();
		GarageAssets::enqueue();
		self::enqueue_selector_script();
		$out  = '<div class="motored-compat-banner" data-shop-url="' . esc_url( Config::shop_url() ) . '">';
		$out .= $marker;
		$out .= '<div class="motored-compat-banner__status" aria-live="polite"></div>';
		$out .= '<div class="motored-compat-banner__picker" data-role="picker" hidden>';
		$out .= '<div class="motored-compat-banner__saved" data-role="saved-bikes" hidden></div>';
		$out .= self::selector_form_html( 'check', true );
		$out .= '</div>';
		$out .= '</div>';
		return $out;
	}

	/**
	 * Block themes: prints the active-bike bar before the main-query Product Collection block.
	 *
	 * @param mixed $content Rendered block HTML.
	 * @param mixed $block   Parsed block array.
	 */
	public function filter_collection_block( $content, $block = null ): string {
		$html = is_string( $content ) ? $content : '';
		if ( ! Config::enabled() || ! is_array( $block ) || empty( $block['attrs']['query']['inherit'] ) ) {
			return $html;
		}
		return self::is_catalog_request() ? self::active_bike_bar_html() . $html : $html;
	}

	/**
	 * Main WooCommerce catalog request (shop, product taxonomy archive or product search).
	 */
	public static function is_catalog_request(): bool {
		if ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the public search post type.
		$type = isset( $_GET['post_type'] ) && is_string( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		return function_exists( 'is_search' ) && is_search() && 'product' === $type;
	}

	/**
	 * Block themes: appends the fit marker after each Product Price block (loop cards), or the single banner
	 * after the main product's price on a block single-product template.
	 *
	 * @param mixed $content  Rendered block HTML.
	 * @param mixed $block    Parsed block array.
	 * @param mixed $instance WP_Block instance (carries the postId context).
	 */
	public function filter_price_block( $content, $block = null, $instance = null ): string {
		unset( $block );
		$html = is_string( $content ) ? $content : '';
		if ( ! Config::enabled() || ! $instance instanceof \WP_Block ) {
			return $html;
		}
		$pid = isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : 0;
		if ( $pid <= 0 ) {
			return $html;
		}
		$is_main = function_exists( 'is_product' ) && is_product() && (int) get_queried_object_id() === $pid;
		return $html . ( $is_main ? self::single_banner_html( $pid ) : self::loop_marker_html( $pid ) );
	}

	/**
	 * Filters WooCommerce product tabs: renames Additional Information -> Technical information and adds Compatibility tab.
	 *
	 * @param mixed $tabs Product tabs.
	 * @return array<string,array<string,mixed>>
	 */
	public function filter_product_tabs( $tabs ): array {
		$tabs = is_array( $tabs ) ? $tabs : array();
		if ( ! Config::enabled() ) {
			return $tabs;
		}
		if ( Config::rename_tech_tab() && isset( $tabs['additional_information'] ) && is_array( $tabs['additional_information'] ) ) {
			$tabs['additional_information']['title'] = __( 'Technical information', 'motored-core' );
		}
		$product = CompatibilityHooks::current_product();
		if ( Config::show_compat_tab() && null !== $product && null !== self::fit_for_product( $product->get_id() ) ) {
			$tabs['motored_compatibility'] = array(
				'title'    => __( 'Compatibility', 'motored-core' ),
				'priority' => 25,
				'callback' => array( $this, 'render_compat_tab' ),
			);
		}
		return $tabs;
	}

	/**
	 * Renames the Additional Information inner H2 heading to Technical information.
	 *
	 * @param mixed $heading Original heading.
	 */
	public function filter_tech_tab_heading( $heading ): string {
		if ( Config::enabled() && Config::rename_tech_tab() ) {
			return __( 'Technical information', 'motored-core' );
		}
		return is_string( $heading ) ? $heading : '';
	}

	/**
	 * Formats Technical information rows in a fixed commercial/technical order (Brand -> Model -> Full size -> Position -> Index -> Tube -> Construction -> Usage).
	 *
	 * @param mixed $rows    Attribute display rows.
	 * @param mixed $product WC_Product instance.
	 * @return array<string,array{label:string,value:string}>
	 */
	public function append_full_size_attribute_row( $rows, $product ): array {
		$rows = is_array( $rows ) ? $rows : array();
		if ( ! Config::enabled() || ! $product instanceof \WC_Product ) {
			return $rows;
		}
		$full = self::resolve_full_size_label( $product );
		if ( '' === $full ) {
			return $rows;
		}

		$fit      = self::fit_for_product( $product->get_id() );
		$is_kit   = is_array( $fit ) && TireSpecs::POS_KIT === (string) $fit['position'];
		$kit_spec = $is_kit ? self::kit_spec_for( $product, $fit ) : null;
		$attr_lbl = AttributeInstaller::attribute_labels();
		$fixed_tx = AttributeInstaller::fixed_term_sets();
		$managed  = array();
		$consumed = array();
		foreach ( TireAttributePlan::MANAGED as $slug ) {
			$managed[ 'attribute_pa_' . $slug ] = $slug;
		}

		$out = array();

		// 1. Brand (from product attribute if present, else native WooCommerce product_brand taxonomy).
		foreach ( array( 'attribute_pa_marca', 'attribute_product_brand', 'product_brand' ) as $bk ) {
			if ( isset( $rows[ $bk ] ) ) {
				$out[ $bk ]      = $rows[ $bk ];
				$consumed[ $bk ] = true;
				break;
			}
		}
		if ( empty( $consumed ) ) {
			$brands = AttributeHooks::product_brand_names( $product->get_id() );
			if ( ! empty( $brands ) ) {
				$out['motored_brand'] = array(
					'label' => __( 'Brand', 'motored-core' ),
					'value' => '<p>' . esc_html( implode( ', ', $brands ) ) . '</p>',
				);
			}
		}

		// 2. Model (force label "Model" even on existing stores where pa_linea was created as "Línea").
		$line_key = 'attribute_pa_' . TireSpecs::ATTR_LINE;
		if ( null === $kit_spec && isset( $rows[ $line_key ] ) && is_array( $rows[ $line_key ] ) ) {
			$line_row          = $rows[ $line_key ];
			$line_row['label'] = __( 'Model', 'motored-core' );
			$out[ $line_key ]  = $line_row;
		}

		// 3. Full size.
		$out['motored_full_size'] = array(
			'label' => __( 'Full size', 'motored-core' ),
			'value' => '<p>' . esc_html( $full ) . '</p>',
		);

		// 4 & 5. Front / Rear sizes (kits only — single tires already display Full size).
		if ( null !== $kit_spec ) {
			// Kit sheet: one row per tire (size, model, index, construction, tube).
			$out['motored_front_tire'] = array(
				'label' => __( 'Front tire', 'motored-core' ),
				'value' => '<p>' . esc_html( self::axle_summary( $kit_spec[ TireSpecs::POS_FRONT ] ) ) . '</p>',
			);
			$out['motored_rear_tire']  = array(
				'label' => __( 'Rear tire', 'motored-core' ),
				'value' => '<p>' . esc_html( self::axle_summary( $kit_spec[ TireSpecs::POS_REAR ] ) ) . '</p>',
			);
		} elseif ( $is_kit ) {
			foreach ( array( TireSpecs::ATTR_FRONT_SIZE, TireSpecs::ATTR_REAR_SIZE ) as $sz_slug ) {
				$sz_key = 'attribute_pa_' . $sz_slug;
				if ( isset( $rows[ $sz_key ] ) && is_array( $rows[ $sz_key ] ) ) {
					$sz_row          = $rows[ $sz_key ];
					$sz_row['label'] = $attr_lbl[ $sz_slug ] ?? ( $sz_row['label'] ?? '' );
					$out[ $sz_key ]  = $sz_row;
				}
			}
		}

		// 6. Position (always localize label and fixed term value even if DB terms were seeded in English).
		$pos_key = 'attribute_pa_' . TireSpecs::ATTR_POSITION;
		if ( isset( $rows[ $pos_key ] ) && is_array( $rows[ $pos_key ] ) ) {
			$pos_row          = $rows[ $pos_key ];
			$pos_row['label'] = $attr_lbl[ TireSpecs::ATTR_POSITION ] ?? ( $pos_row['label'] ?? '' );
			$raw_pos_val      = isset( $pos_row['value'] ) ? self::plain_text( (string) $pos_row['value'] ) : '';
			$norm_pos         = is_array( $fit ) && ! empty( $fit['position'] )
				? (string) $fit['position']
				: TireSpecs::normalize_position( $raw_pos_val );
			$loc_pos          = '' !== $norm_pos ? TireSpecs::position_label( $norm_pos ) : '';
			if ( '' !== $loc_pos ) {
				$pos_row['value'] = '<p>' . esc_html( $loc_pos ) . '</p>';
			}
			$out[ $pos_key ] = $pos_row;
		}

		// 7. Combined Load & Speed Index (single tires only — e.g. "69H (up to 325 kg · 210 km/h)").
		if ( ! $is_kit ) {
			$load  = null;
			$speed = '';
			if ( is_array( $fit ) ) {
				$is_rear = TireSpecs::POS_REAR === (string) $fit['position'];
				$raw_ld  = $is_rear ? ( $fit['rear_load'] ?? null ) : ( $fit['front_load'] ?? null );
				$raw_sp  = $is_rear ? ( $fit['rear_speed_rank'] ?? null ) : ( $fit['front_speed_rank'] ?? null );
				$load    = null !== $raw_ld ? (int) $raw_ld : null;
				$speed   = null !== $raw_sp ? TireSpecs::speed_code_for_rank( (int) $raw_sp ) : '';
			}
			$load_key  = 'attribute_pa_' . TireSpecs::ATTR_LOAD_INDEX;
			$speed_key = 'attribute_pa_' . TireSpecs::ATTR_SPEED_RATING;
			if ( null === $load && isset( $rows[ $load_key ]['value'] ) ) {
				$load = TireSpecs::parse_load_index( self::plain_text( (string) $rows[ $load_key ]['value'] ) );
			}
			if ( '' === $speed && isset( $rows[ $speed_key ]['value'] ) ) {
				$speed = trim( self::plain_text( (string) $rows[ $speed_key ]['value'] ) );
			}
			$formatted = TireSpecs::format_load_speed( $load, $speed );
			if ( '' !== $formatted ) {
				$out['motored_load_speed'] = array(
					'label' => __( 'Index', 'motored-core' ),
					'value' => '<p>' . esc_html( $formatted ) . '</p>',
				);
			}
		}

		// 8-10. Tube type, Construction, Riding style (always omit Width, Aspect ratio, Rim diameter).
		foreach ( array(
			TireSpecs::ATTR_TUBE,
			TireSpecs::ATTR_CONSTRUCTION,
			TireSpecs::ATTR_USAGE,
		) as $spec_slug ) {
			$spec_key = 'attribute_pa_' . $spec_slug;
			$per_axle = null !== $kit_spec && TireSpecs::ATTR_USAGE !== $spec_slug;
			if ( isset( $rows[ $spec_key ] ) && is_array( $rows[ $spec_key ] ) && ! $per_axle ) {
				$spec_row          = $rows[ $spec_key ];
				$spec_row['label'] = $attr_lbl[ $spec_slug ] ?? ( $spec_row['label'] ?? '' );
				if ( TireSpecs::ATTR_CONSTRUCTION === $spec_slug && isset( $spec_row['value'] ) ) {
					$norm_c = TireSpecs::normalize_construction( self::plain_text( (string) $spec_row['value'] ) );
					if ( '' !== $norm_c && isset( $fixed_tx[ TireSpecs::ATTR_CONSTRUCTION ][ $norm_c ] ) ) {
						$spec_row['value'] = '<p>' . esc_html( (string) $fixed_tx[ TireSpecs::ATTR_CONSTRUCTION ][ $norm_c ] ) . '</p>';
					}
				}
				$out[ $spec_key ] = $spec_row;
			}
		}

		// 11. Any extra non-tire attributes / WC weight & dimensions at the end.
		foreach ( $rows as $k => $v ) {
			$key = (string) $k;
			if ( ! isset( $managed[ $key ] ) && ! isset( $consumed[ $key ] ) ) {
				$out[ $key ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Kit sheet of a product when it still describes the indexed sizes (an ERP may have rewritten the attributes).
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $fit     Index row.
	 * @return array<string,mixed>|null
	 */
	public static function kit_spec_for( \WC_Product $product, array $fit ): ?array {
		$spec = KitSpec::normalize( $product->get_meta( KitSpec::META ) );
		if ( null === $spec || ! KitSpec::matches_sizes( $spec, $fit['front_canonical'] ?? null, $fit['rear_canonical'] ?? null ) ) {
			return null;
		}
		return $spec;
	}

	/**
	 * One-line summary of a kit tire: "100/80-17 · MT60 RS · 52H (up to 200 kg · 210 km/h) · Diagonal · Tubeless (TL)".
	 *
	 * @param array<string,mixed> $axle Kit sheet axle.
	 */
	public static function axle_summary( array $axle ): string {
		$fx    = AttributeInstaller::fixed_term_sets();
		$parts = array( (string) $axle['designation'] );
		if ( '' !== (string) $axle['line'] ) {
			$parts[] = (string) $axle['line'];
		}
		$index = TireSpecs::format_load_speed( null !== $axle['load'] ? (int) $axle['load'] : null, (string) $axle['speed'] );
		if ( '' !== $index ) {
			$parts[] = $index;
		}
		foreach ( array(
			TireSpecs::ATTR_CONSTRUCTION => (string) $axle['construction'],
			TireSpecs::ATTR_TUBE         => (string) $axle['tube'],
		) as $attr => $slug ) {
			if ( '' !== $slug && isset( $fx[ $attr ][ $slug ] ) ) {
				$parts[] = (string) $fx[ $attr ][ $slug ];
			}
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Resolves _motored_full_size meta override or auto-generates a human-readable full size string.
	 *
	 * @param \WC_Product $product Product object.
	 */
	public static function resolve_full_size_label( \WC_Product $product ): string {
		$override = trim( (string) $product->get_meta( self::FULL_SIZE_META ) );
		if ( '' !== $override ) {
			return $override;
		}
		$fit = self::fit_for_product( $product->get_id() );
		if ( null === $fit ) {
			return '';
		}
		$const = isset( $fit['construction'] ) && is_string( $fit['construction'] ) ? $fit['construction'] : '';
		if ( TireSpecs::POS_KIT === $fit['position'] ) {
			$kit_spec = self::kit_spec_for( $product, $fit );
			if ( null !== $kit_spec ) {
				return (string) $kit_spec[ TireSpecs::POS_FRONT ]['designation'] . ' + ' . (string) $kit_spec[ TireSpecs::POS_REAR ]['designation'];
			}
			$f = (string) ( $fit['front_canonical'] ?? '' );
			$r = (string) ( $fit['rear_canonical'] ?? '' );
			if ( '' === $f || '' === $r ) {
				return '';
			}
			$fp = SizeParser::parse( $f );
			$rp = SizeParser::parse( $r );
			$fs = null !== $fp ? SizeParser::format_full_size( $fp, MatchEngine::axle_construction( $fit, TireSpecs::POS_FRONT ), TireSpecs::speed_code_for_rank( null !== $fit['front_speed_rank'] ? (int) $fit['front_speed_rank'] : null ) ) : $f;
			$rs = null !== $rp ? SizeParser::format_full_size( $rp, MatchEngine::axle_construction( $fit, TireSpecs::POS_REAR ), TireSpecs::speed_code_for_rank( null !== $fit['rear_speed_rank'] ? (int) $fit['rear_speed_rank'] : null ) ) : $r;
			return $fs . ' + ' . $rs;
		}
		$canon      = TireSpecs::POS_REAR === $fit['position']
			? (string) ( $fit['rear_canonical'] ?? '' )
			: (string) ( $fit['front_canonical'] ?? '' );
		$speed_rank = TireSpecs::POS_REAR === $fit['position']
			? ( $fit['rear_speed_rank'] ?? null )
			: ( $fit['front_speed_rank'] ?? null );
		$parsed     = '' !== $canon ? SizeParser::parse( $canon ) : null;
		if ( null === $parsed ) {
			return $canon;
		}
		$speed = null !== $speed_rank ? TireSpecs::speed_code_for_rank( (int) $speed_rank ) : '';
		return SizeParser::format_full_size( $parsed, $const, $speed );
	}

	/**
	 * Shortcode [motored_compat_list product_id="0"] for rendering the compatible motorcycles block anywhere.
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function shortcode_compat_list( $atts = array() ): string {
		if ( ! Config::enabled() ) {
			return '';
		}
		$atts    = shortcode_atts(
			array(
				'product_id' => 0,
			),
			is_array( $atts ) ? $atts : array(),
			self::COMPAT_LIST_SHORTCODE
		);
		$pid     = absint( $atts['product_id'] ?? 0 );
		$product = $pid > 0 && function_exists( 'wc_get_product' )
			? wc_get_product( $pid )
			: CompatibilityHooks::current_product();
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}
		return CompatListRenderer::render_for_product( $product );
	}

	/**
	 * Renders the server-side cached Compatibility tab body (grouped by brand in 2 columns).
	 */
	public function render_compat_tab(): void {
		$product = CompatibilityHooks::current_product();
		if ( null === $product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside CompatListRenderer::render_for_product().
		echo CompatListRenderer::render_for_product( $product );
	}

	/**
	 * Builds the compact JSON `<span hidden data-motored-fit>` element for a product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $context    'loop'|'single'.
	 */
	public static function fit_marker_html( int $product_id, string $context = 'loop' ): string {
		$fit = self::fit_for_product( $product_id );
		if ( null === $fit ) {
			return '';
		}
		$payload = array(
			'p'  => (string) $fit['position'],
			'f'  => $fit['front_canonical'],
			'r'  => $fit['rear_canonical'],
			'fl' => $fit['front_load'],
			'rl' => $fit['rear_load'],
			'fs' => $fit['front_speed_rank'],
			'rs' => $fit['rear_speed_rank'],
			'c'  => $fit['construction'],
		);
		// Kit sheet: construction per axle (ev() falls back to `c`, so HTML cached before this still works).
		if ( ! empty( $fit['front_construction'] ) ) {
			$payload['fc'] = $fit['front_construction'];
		}
		if ( ! empty( $fit['rear_construction'] ) ) {
			$payload['rc'] = $fit['rear_construction'];
		}
		return sprintf(
			'<span hidden class="motored-fit-marker" data-ctx="%s" data-motored-fit="%s"></span>',
			esc_attr( $context ),
			esc_attr( (string) wp_json_encode( $payload ) )
		);
	}

	/**
	 * Looks up a product index row using the per-request primed cache first.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string,mixed>|null
	 */
	public static function fit_for_product( int $product_id ): ?array {
		if ( $product_id <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $product_id, self::$primed_fits ) ) {
			$found                            = ProductIndexRepository::find( $product_id );
			self::$primed_fits[ $product_id ] = null !== $found ? $found : false;
		}
		$row = self::$primed_fits[ $product_id ];
		return is_array( $row ) ? $row : null;
	}


	/**
	 * Reads sanitized ?moto= query parameter.
	 */
	public static function requested_moto_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public catalog URL filter.
		return isset( $_GET['moto'] ) && is_string( $_GET['moto'] ) ? sanitize_title( wp_unslash( $_GET['moto'] ) ) : '';
	}

	/**
	 * Reads sanitized ?pos= query parameter (front|rear|kit or '').
	 */
	public static function requested_position(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public catalog URL filter.
		$pos = isset( $_GET['pos'] ) && is_string( $_GET['pos'] ) ? sanitize_key( wp_unslash( $_GET['pos'] ) ) : '';
		return in_array( $pos, array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR, TireSpecs::POS_KIT ), true ) ? $pos : '';
	}

	/**
	 * Consolidates old single-position URLs and gives an explicit compatibility position priority.
	 * Legacy multi-position or combined-axle selections keep their native taxonomy behavior.
	 */
	public function normalize_position_url(): void {
		$filter_key = 'filter_' . TireSpecs::ATTR_POSITION;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public catalog URL filter.
		$legacy = isset( $_GET[ $filter_key ] ) && is_string( $_GET[ $filter_key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $filter_key ] ) ) : '';
		if ( '' === $legacy || ! self::is_catalog_request() || '' === self::requested_moto_slug() || ! Config::enabled() ) {
			return;
		}
		$position = self::requested_position();
		if ( '' === $position ) {
			$position = TireSpecs::normalize_position( $legacy );
			if ( ! in_array( $position, array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR, TireSpecs::POS_KIT ), true ) ) {
				return;
			}
		}
		$url = add_query_arg( 'pos', $position, remove_query_arg( array( $filter_key, 'query_type_' . TireSpecs::ATTR_POSITION ) ) );
		if ( wp_safe_redirect( $url ) ) {
			exit;
		}
	}

	/**
	 * Strips HTML tags from an attribute display value (safe in isolated unit tests without WordPress).
	 *
	 * @param string $html HTML snippet.
	 */
	private static function plain_text( string $html ): string {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $html );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback for isolated unit tests without WordPress.
		return strip_tags( $html );
	}

	/**
	 * Resets static request caches (for unit tests).
	 */
	public static function reset(): void {
		self::$primed_fits = array();
		MatchEngine::reset();
		GarageAssets::reset();
		self::$selector_enqueued = false;
		self::$bar_rendered      = false;
		self::$banner_rendered   = false;
	}
}
