<?php
/**
 * Storefront integration: selector shortcode, ?moto= filtering, loop priming, fit markers and 3 product tabs.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Integration\WooCommerce\CompatibilityHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps public HTML 100% cache-safe while hydrating active Mi Garage compatibility client-side.
 */
final class Storefront {

	public const SELECTOR_SHORTCODE    = 'motored_compat_selector';
	public const COMPAT_LIST_SHORTCODE = 'motored_compat_list';
	public const FULL_SIZE_META        = '_motored_full_size';
	public const HANDLE_SELECTOR       = 'motored-compat-selector';
	public const HANDLE_GARAGE         = 'motored-compat-garage';
	public const HANDLE_TAB            = 'motored-compat-tab';

	/**
	 * Per-request primed index rows keyed by product_id (false = checked and not indexed).
	 *
	 * @var array<int,array<string,mixed>|false>
	 */
	private static array $primed_fits = array();

	/**
	 * Per-request flag preventing repeated script/config localization for compat-garage.js.
	 *
	 * @var bool
	 */
	private static bool $garage_enqueued = false;

	/**
	 * Registers storefront hooks (zero option reads, zero asset enqueues here).
	 */
	public function register(): void {
		add_shortcode( self::SELECTOR_SHORTCODE, array( $this, 'shortcode_selector' ) );
		add_shortcode( self::COMPAT_LIST_SHORTCODE, array( $this, 'shortcode_compat_list' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'wp_robots', array( $this, 'robots_noindex' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_catalog_assets' ) );

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
	 * Adds `noindex, follow` on ?moto= filtered URLs without blocking crawl in robots.txt.
	 *
	 * @param mixed $robots Robots directives.
	 * @return array<string,mixed>
	 */
	public function robots_noindex( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();
		if ( '' !== self::requested_moto_slug() && Config::enabled() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = false;
			$robots['follow']   = true;
		}
		return $robots;
	}

	/**
	 * Enqueues compat-garage.js only on WooCommerce catalog/product/account views when enabled.
	 */
	public function maybe_enqueue_catalog_assets(): void {
		if ( ! Config::enabled() ) {
			return;
		}
		$is_wc = ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
			|| ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );
		if ( $is_wc ) {
			self::enqueue_garage_script();
		}
	}

	/**
	 * Enqueues the client-side Mi Garage & badge script once per request.
	 */
	public static function enqueue_garage_script(): void {
		if ( self::$garage_enqueued ) {
			return;
		}
		self::$garage_enqueued = true;
		wp_enqueue_script(
			self::HANDLE_GARAGE,
			MOTORED_CORE_URL . 'assets/js/compat-garage.js',
			array(),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		$cfg = self::client_config();
		wp_add_inline_script(
			self::HANDLE_GARAGE,
			'window.motoredCompatCfg=' . wp_json_encode( $cfg ) . ';',
			'before'
		);
	}

	/**
	 * Shortcode [motored_compat_selector mode="shop|garage"].
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
			),
			is_array( $atts ) ? $atts : array(),
			self::SELECTOR_SHORTCODE
		);
		$mode = 'garage' === $atts['mode'] ? 'garage' : 'shop';

		self::enqueue_garage_script();
		wp_enqueue_script(
			self::HANDLE_SELECTOR,
			MOTORED_CORE_URL . 'assets/js/compat-selector.js',
			array(),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script(
			self::HANDLE_SELECTOR,
			'window.motoredCompatCfg=window.motoredCompatCfg||' . wp_json_encode( self::client_config() ) . ';',
			'before'
		);

		$id_prefix = 'motored-sel-' . wp_unique_id();
		ob_start();
		?>
		<form class="motored-compat-selector" data-mode="<?php echo esc_attr( $mode ); ?>" data-shop-url="<?php echo esc_url( Config::shop_url() ); ?>">
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
			<div class="motored-compat-selector__actions">
				<button type="submit" class="button" data-role="submit" disabled>
					<?php echo 'garage' === $mode ? esc_html__( 'Save to My Garage', 'motored-core' ) : esc_html__( 'Find compatible tires', 'motored-core' ); ?>
				</button>
			</div>
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
		if ( ! $query instanceof \WP_Query || ! Config::enabled() ) {
			return;
		}
		$moto = self::requested_moto_slug();
		if ( '' === $moto ) {
			return;
		}
		$pos      = self::requested_position();
		$ids      = MatchEngine::matching_product_ids( $moto, $pos );
		$existing = $query->get( 'post__in' );
		$merged   = self::intersect_post_in( is_array( $existing ) ? $existing : array(), $ids );
		$query->set( 'post__in', $merged );
	}

	/**
	 * Applies ?moto= and &pos= filtering to Gutenberg Query Loop / Product Collection blocks.
	 *
	 * @param mixed $query_vars Query vars array.
	 * @param mixed $block      WP_Block instance.
	 * @return array<string,mixed>
	 */
	public function filter_block_query_vars( $query_vars, $block = null ): array {
		unset( $block );
		$vars = is_array( $query_vars ) ? $query_vars : array();
		if ( ! Config::enabled() ) {
			return $vars;
		}
		if ( isset( $vars['post_type'] ) && 'product' !== $vars['post_type'] ) {
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
		$intersected    = array_values( array_intersect( $clean_existing, $matched ) );
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
		unset( $query );
		if ( ! is_array( $posts ) || empty( $posts ) || is_admin() || ! Config::enabled() ) {
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
		if ( empty( $ids ) ) {
			return $posts;
		}
		$rows = ProductIndexRepository::find_many( $ids );
		foreach ( $ids as $pid ) {
			self::$primed_fits[ $pid ] = $rows[ $pid ] ?? false;
		}
		return $posts;
	}

	/**
	 * Prints the active motorcycle filter bar above the shop loop when ?moto= is present.
	 */
	public function render_active_bike_bar(): void {
		if ( ! Config::enabled() ) {
			return;
		}
		$moto = self::requested_moto_slug();
		if ( '' === $moto ) {
			return;
		}
		$profile = MatchEngine::bike_profile( $moto );
		if ( null === $profile ) {
			echo '<div class="motored-compat-bar"><p>' . esc_html__( 'The selected motorcycle was not found.', 'motored-core' ) . '</p></div>';
			return;
		}
		$active_pos = self::requested_position();
		$tabs       = array(
			''                   => __( 'All', 'motored-core' ),
			TireSpecs::POS_FRONT => __( 'Front', 'motored-core' ),
			TireSpecs::POS_REAR  => __( 'Rear', 'motored-core' ),
			TireSpecs::POS_KIT   => __( 'Kits', 'motored-core' ),
		);
		$base_url   = remove_query_arg( array( 'pos', 'paged' ) );
		$clear      = remove_query_arg( array( 'moto', 'pos', 'paged' ) );

		echo '<div class="motored-compat-bar">';
		echo '<p class="motored-compat-bar__title"><strong>' . esc_html__( 'Tires compatible with:', 'motored-core' ) . '</strong> ' . esc_html( (string) $profile['label'] );
		echo ' <a class="motored-compat-bar__clear" href="' . esc_url( $clear ) . '">' . esc_html__( 'Clear filter', 'motored-core' ) . '</a></p>';
		echo '<div class="motored-compat-bar__positions">';
		foreach ( $tabs as $pos_key => $label ) {
			$url    = '' === $pos_key ? $base_url : add_query_arg( 'pos', $pos_key, $base_url );
			$active = $active_pos === $pos_key ? ' aria-current="true"' : '';
			echo '<a class="button' . ( $active_pos === $pos_key ? ' alt' : '' ) . '"' . $active . ' href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute string.
		}
		echo '</div>';
		echo '<p class="motored-compat-bar__disclaimer"><small>' . esc_html( Config::disclaimer() ) . '</small></p>';
		echo '</div>';
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
		$marker = self::fit_marker_html( $product->get_id(), 'loop' );
		if ( '' !== $marker ) {
			self::enqueue_garage_script();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in fit_marker_html().
			echo $marker;
		}
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
		self::enqueue_garage_script();
		$marker = self::fit_marker_html( $product->get_id(), 'single' );
		echo '<div class="motored-compat-banner" data-shop-url="' . esc_url( Config::shop_url() ) . '" data-disclaimer="' . esc_attr( Config::disclaimer() ) . '">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in fit_marker_html().
		echo $marker;
		echo '</div>';
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
	 * Appends "Full size" row in Technical information from _motored_full_size meta or automatic fallback.
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
		$out = array(
			'motored_full_size' => array(
				'label' => __( 'Full size', 'motored-core' ),
				'value' => '<p>' . esc_html( $full ) . '</p>',
			),
		);
		foreach ( $rows as $k => $v ) {
			$out[ (string) $k ] = $v;
		}
		return $out;
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
			$f = (string) ( $fit['front_canonical'] ?? '' );
			$r = (string) ( $fit['rear_canonical'] ?? '' );
			return ( '' !== $f && '' !== $r ) ? $f . ' + ' . $r : '';
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
	 * Client-side script configuration (REST URLs, login state, localized UI strings).
	 *
	 * @return array<string,mixed>
	 */
	public static function client_config(): array {
		$logged_in = is_user_logged_in();
		return array(
			'restBase'   => untrailingslashit( rest_url( RestController::NAMESPACE ) ),
			'nonce'      => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
			'loggedIn'   => $logged_in,
			'strict'     => Config::strict_specs(),
			'showIncomp' => Config::show_incompatible_badge(),
			'maxBikes'   => Config::max_garage_bikes(),
			'shopUrl'    => Config::shop_url(),
			'i18n'       => array(
				'brandPlaceholder' => __( 'Select brand...', 'motored-core' ),
				'modelPlaceholder' => __( 'Select model...', 'motored-core' ),
				'yearPlaceholder'  => __( 'Select year...', 'motored-core' ),
				'varPlaceholder'   => __( 'Select version...', 'motored-core' ),
				'oem'              => __( 'OEM size', 'motored-core' ),
				'alt'              => __( 'Alternative size', 'motored-core' ),
				'equiv'            => __( 'Equivalent size', 'motored-core' ),
				'front'            => __( 'Front', 'motored-core' ),
				'rear'             => __( 'Rear', 'motored-core' ),
				'kit'              => __( 'Complete kit', 'motored-core' ),
				/* translators: %s: active motorcycle label. */
				'compatWith'       => __( 'Compatible with your %s', 'motored-core' ),
				/* translators: %s: active motorcycle label. */
				'incompatWith'     => __( 'Does not match your %s', 'motored-core' ),
				'seeCompat'        => __( 'View compatible tires', 'motored-core' ),
				'chooseBike'       => __( 'Does it fit your bike? Select your motorcycle', 'motored-core' ),
				'verifySpecs'      => __( 'Check load/speed rating against your manual.', 'motored-core' ),
				'equivNote'        => __( 'replaces', 'motored-core' ),
				'activeBadge'      => __( 'Active', 'motored-core' ),
				'useBike'          => __( 'Use', 'motored-core' ),
				'filterTires'      => __( 'View tires', 'motored-core' ),
				'remove'           => __( 'Remove', 'motored-core' ),
				'emptyGarage'      => __( 'You have not saved any motorcycles yet.', 'motored-core' ),
				'searchMotos'      => __( 'Search your motorcycle (e.g. Tornado, CB500, FZ)...', 'motored-core' ),
				'noMotos'          => __( 'No compatible motorcycles found for this product yet.', 'motored-core' ),
				'loadErr'          => __( 'Could not load compatibility list.', 'motored-core' ),
			),
		);
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
	 * Resets static request caches (for unit tests).
	 */
	public static function reset(): void {
		self::$primed_fits     = array();
		self::$garage_enqueued = false;
	}
}
