<?php
/**
 * Sidebar faceted filters renderer ([motored_filtros] shortcode & FilterWidget body).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\ProductIndexRepository;
use Motored\Core\Modules\Compatibility\SizeParser;
use Motored\Core\Modules\Compatibility\TireSpecs;

defined( 'ABSPATH' ) || exit;

/**
 * Renders active filter chips, 11 collapsible disjunctive facet sections with real anchor links
 * (works 100% without JS), mobile Apply button and a "Clear all" link; plus the quick bar (layout="quick").
 */
final class FilterRenderer {

	public const SHORTCODE = 'motored_filtros';

	/**
	 * Registers the [motored_filtros] shortcode (zero option reads or asset enqueues here).
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Shortcode callback for [motored_filtros] (layout="sidebar" default, or layout="quick" for the bar above the grid).
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title'  => __( 'Filter results', 'motored-core' ),
				'layout' => 'sidebar',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		if ( 'quick' === $atts['layout'] ) {
			return self::render_quick();
		}
		$title = is_string( $atts['title'] ) ? $atts['title'] : '';
		return self::render( $title );
	}

	/**
	 * Request-scoped facet context shared by the sidebar, the quick bar and Api::active_filter_labels().
	 *
	 * @return array<string,mixed>|null Null when the module is off or facets are unavailable.
	 */
	private static function context(): ?array {
		static $cache = array();
		$key = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ];
		}
		if ( ! Config::enabled() ) {
			$cache[ $key ] = null;
			return null;
		}

		$state    = FilterState::from_query();
		$base_url = self::current_base_url();
		try {
			$snapshot = FacetEngine::snapshot( function_exists( 'is_search' ) && is_search() );
		} catch ( \RuntimeException $exception ) {
			\Motored\Core\Support\Logger::warning( 'Catalog facets are temporarily unavailable.' );
			$cache[ $key ] = null;
			return null;
		}

		$moto_ids       = null;
		$moto_lbl       = '';
		$moto_positions = array();
		$kit_matches    = null;
		if ( '' !== $state->moto() && \Motored\Core\Modules\Compatibility\Config::enabled() ) {
			$matches     = MatchEngine::matching_ids_by_position( $state->moto() );
			$kit_matches = $matches['kit'];
			$moto_ids    = array_fill_keys( $matches['all'], true );
			if ( empty( $state->attribute_terms( TireSpecs::ATTR_POSITION ) ) ) {
				foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR, TireSpecs::POS_KIT ) as $position ) {
					$moto_positions[ $position ] = array_fill_keys( $matches[ $position ], true );
				}
			}
			$prof     = MatchEngine::bike_profile( $state->moto() );
			$moto_lbl = is_array( $prof ) && ! empty( $prof['label'] ) ? (string) $prof['label'] : $state->moto();
		}

		$kit_ids = null;
		if ( '' !== $state->kit_size() ) {
			$kit_ids = ProductIndexRepository::kit_ids_with_size( $state->kit_size() );
		}

		$scope    = self::archive_scope();
		$computed = FacetEngine::compute_facets(
			$snapshot,
			$state,
			array(
				// Counts must match the grid: out-of-stock items leave it when WooCommerce hides them or with ?stock=1.
				'only_in_stock'  => Config::grid_hides_out_of_stock() || $state->in_stock(),
				'moto_ids'       => $moto_ids,
				'moto_positions' => $moto_positions,
				'kit_ids'        => $kit_ids,
				'cat_slugs'      => $scope['cat_slugs'],
				'universe_ids'   => $scope['universe_ids'],
				'sale_ids'       => FacetEngine::on_sale_id_map(),
			)
		);

		global $wp_query;
		$total = $wp_query instanceof \WP_Query && 'product_query' === $wp_query->get( 'wc_query' )
			? (int) $wp_query->found_posts
			: $computed['total'];

		$cache[ $key ] = array(
			'state'          => $state,
			'base_url'       => $base_url,
			'computed'       => $computed,
			'moto_positions' => $moto_positions,
			'chips'          => self::build_active_chips( $state, $base_url, $snapshot['labels'] ?? array(), $moto_lbl ),
			'kits'           => self::kits_hint( $state, $base_url, $snapshot['labels'] ?? array(), $kit_matches ),
			'total'          => $total,
		);
		return $cache[ $key ];
	}

	/**
	 * Plain values of the active filters, in chip order (e.g. "Michelin", "Rim diameter 17", "In stock").
	 *
	 * @return list<string>
	 */
	public static function active_labels(): array {
		$ctx = self::context();
		return null === $ctx ? array() : array_values( array_map( 'strval', array_column( $ctx['chips'], 'value' ) ) );
	}

	/**
	 * Renders the complete faceted filter panel HTML.
	 *
	 * @param string $title Optional panel heading.
	 */
	public static function render( string $title = '' ): string {
		$ctx = self::context();
		if ( null === $ctx ) {
			return '';
		}
		StorefrontAssets::enqueue_filters_js();

		$state          = $ctx['state'];
		$base_url       = $ctx['base_url'];
		$computed       = $ctx['computed'];
		$moto_positions = $ctx['moto_positions'];
		$chips          = $ctx['chips'];
		$kits           = $ctx['kits'];
		$total          = $ctx['total'];
		$labels         = self::facet_titles();

		ob_start();
		?>
		<aside class="motored-filters" data-motored-filters data-base-url="<?php echo esc_url( FilterState::strip_query_and_pagination( $base_url ) ); ?>" data-extra="<?php echo esc_attr( (string) wp_json_encode( $state->extra() ) ); ?>" data-moto="<?php echo esc_attr( $state->moto() ); ?>" data-pos="<?php echo esc_attr( $state->pos() ); ?>" data-kit="<?php echo esc_attr( $state->kit_size() ); ?>" data-stock="<?php echo $state->in_stock() ? '1' : ''; ?>" data-operators="<?php echo esc_attr( (string) wp_json_encode( $state->operators() ) ); ?>">
			<div class="motored-filters__head">
				<?php if ( '' !== $title ) : ?>
					<h3 class="motored-filters__title">
						<?php echo esc_html( $title ); ?>
						<?php if ( ! empty( $chips ) ) : ?>
							<span class="motored-filters__badge"><?php echo esc_html( (string) count( $chips ) ); ?></span>
						<?php endif; ?>
					</h3>
				<?php endif; ?>
				<?php if ( $state->has_any_filter() ) : ?>
					<a class="motored-filters__clear-all" href="<?php echo esc_url( $state->clear_url( $base_url ) ); ?>"><?php esc_html_e( 'Clear all', 'motored-core' ); ?></a>
				<?php endif; ?>
				<span class="motored-filters__count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of matching products. */
							_n( '%d result', '%d results', $total, 'motored-core' ),
							$total
						)
					);
					?>
				</span>
			</div>

			<?php if ( ! empty( $chips ) ) : ?>
				<div class="motored-filters__chips" aria-label="<?php esc_attr_e( 'Active filters', 'motored-core' ); ?>">
					<?php foreach ( $chips as $chip ) : ?>
						<a class="motored-chip" href="<?php echo esc_url( $chip['url'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: filter name */ __( 'Remove filter %s', 'motored-core' ), $chip['label'] ) ); ?>">
							<span><?php echo esc_html( $chip['label'] ); ?></span>
							<span class="motored-chip__x" aria-hidden="true">&times;</span>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( null !== $kits ) : ?>
				<p class="motored-filters__kits">
					<a href="<?php echo esc_url( $kits['url'] ); ?>"><?php echo esc_html( $kits['label'] ); ?></a>
				</p>
			<?php endif; ?>

			<div class="motored-filters__groups">
				<?php
				foreach ( Config::ALL_FACETS as $facet_key ) :
					$items = $computed['facets'][ $facet_key ] ?? array();
					if ( empty( $items ) ) {
						continue;
					}
					$facet_action = TireSpecs::ATTR_POSITION === $facet_key && ! empty( $moto_positions ) ? 'pos' : $facet_key;
					$has_active   = in_array( true, array_column( $items, 'active' ), true );
					?>
					<details class="motored-acc" data-facet-group="<?php echo esc_attr( $facet_key ); ?>"<?php echo $has_active ? ' open' : ''; ?>>
						<summary class="motored-acc__btn">
							<span><?php echo esc_html( $labels[ $facet_key ] ?? $facet_key ); ?></span>
							<span class="motored-acc__icon" aria-hidden="true"></span>
						</summary>
						<div class="motored-acc__panel">
							<ul class="motored-acc__list">
								<?php foreach ( $items as $item ) : ?>
									<?php $url = $state->toggle_url( $base_url, $facet_action, $item['slug'] ); ?>
									<li>
										<a class="motored-opt<?php echo $item['active'] ? ' is-active' : ''; ?>"
											href="<?php echo esc_url( $url ); ?>"
											data-facet="<?php echo esc_attr( $facet_action ); ?>"
											data-slug="<?php echo esc_attr( $item['slug'] ); ?>"
											aria-pressed="<?php echo $item['active'] ? 'true' : 'false'; ?>">
											<span class="motored-opt__box" aria-hidden="true"></span>
											<span class="motored-opt__label"><?php echo esc_html( self::facet_value_label( $facet_action, $item['slug'], $item['label'] ) ); ?></span>
											<span class="motored-opt__cnt">(<?php echo esc_html( (string) $item['count'] ); ?>)</span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</details>
				<?php endforeach; ?>
			</div>

			<div class="motored-filters__foot">
				<button type="button" class="button motored-filters__apply" data-role="apply" hidden>
					<?php esc_html_e( 'Apply filters', 'motored-core' ); ?>
				</button>
			</div>
		</aside>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Quick filter bar above the grid: an "In stock" toggle (when the catalog shows out-of-stock items) and the
	 * most common values of the current results (brand, rim, width, sale). Links toggle one filter and apply at
	 * once (AJAX with JS, plain links without it). Marked `data-motored-swap` so it follows every catalog swap.
	 */
	public static function render_quick(): string {
		$ctx = self::context();
		if ( null === $ctx ) {
			return '';
		}
		StorefrontAssets::enqueue_filters_js();

		$state    = $ctx['state'];
		$base_url = $ctx['base_url'];
		$items    = self::quick_items( $ctx['computed']['facets'] ?? array() );
		$stock    = ! Config::grid_hides_out_of_stock();
		if ( ! $stock && empty( $items ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="motored-quick" data-motored-quick data-motored-swap="motored-quick">
			<?php if ( $stock ) : ?>
				<a class="motored-quick__stock motored-opt<?php echo $state->in_stock() ? ' is-active' : ''; ?>" href="<?php echo esc_url( $state->toggle_url( $base_url, 'stock' ) ); ?>" aria-pressed="<?php echo $state->in_stock() ? 'true' : 'false'; ?>" rel="nofollow">
					<span class="motored-opt__box" aria-hidden="true"></span>
					<span class="motored-opt__label"><?php esc_html_e( 'In stock', 'motored-core' ); ?></span>
				</a>
			<?php endif; ?>
			<?php if ( ! empty( $items ) ) : ?>
				<div class="motored-quick__popular">
					<span class="motored-quick__title"><?php esc_html_e( 'Quick filters', 'motored-core' ); ?></span>
					<ul class="motored-quick__list">
						<?php foreach ( $items as $item ) : ?>
							<li>
								<a class="motored-opt<?php echo $item['active'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $state->toggle_url( $base_url, $item['facet'], $item['slug'] ) ); ?>" aria-pressed="<?php echo $item['active'] ? 'true' : 'false'; ?>" rel="nofollow">
									<span class="motored-opt__box" aria-hidden="true"></span>
									<span class="motored-opt__label"><?php echo esc_html( $item['label'] ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Picks the quick filters: per facet the active values plus the values with most products
	 * (brand 2, rim 2, width 1, sale 1).
	 *
	 * @param array<string,list<array<string,mixed>>> $facets Computed facets.
	 * @return list<array{facet:string,slug:string,label:string,active:bool}>
	 */
	private static function quick_items( array $facets ): array {
		$plan   = array(
			'product_brand'       => 2,
			TireSpecs::ATTR_RIM   => 2,
			TireSpecs::ATTR_WIDTH => 1,
			'oferta'              => 1,
		);
		$titles = self::facet_titles();
		$picked = array();
		foreach ( $plan as $facet => $limit ) {
			$items = $facets[ $facet ] ?? array();
			usort(
				$items,
				static function ( $a, $b ) {
					$by_active = (int) ! empty( $b['active'] ) <=> (int) ! empty( $a['active'] );
					return 0 !== $by_active ? $by_active : ( (int) $b['count'] <=> (int) $a['count'] );
				}
			);
			$taken = 0;
			foreach ( $items as $item ) {
				$active = ! empty( $item['active'] );
				if ( ! $active && ( $taken >= $limit || empty( $item['count'] ) ) ) {
					continue;
				}
				$value = self::facet_value_label( $facet, (string) $item['slug'], (string) $item['label'] );
				if ( TireSpecs::ATTR_RIM === $facet || TireSpecs::ATTR_WIDTH === $facet ) {
					$value = ( $titles[ $facet ] ?? '' ) . ' ' . $value;
				} elseif ( 'oferta' === $facet ) {
					$value = $titles['oferta'] ?? $value;
				}
				$picked[] = array(
					'facet'  => $facet,
					'slug'   => (string) $item['slug'],
					'label'  => trim( $value ),
					'active' => $active,
				);
				++$taken;
			}
		}
		return $picked;
	}

	/**
	 * Builds the active filter chip descriptors (including active motorcycle).
	 *
	 * @param FilterState                        $state      Active state.
	 * @param string                             $base_url   Base archive URL.
	 * @param array<string,array<string,string>> $label_map Snapshot labels map.
	 * @param string                             $moto_label Human-readable active motorcycle label.
	 * @return list<array{label:string,value:string,url:string}>
	 */
	public static function build_active_chips( FilterState $state, string $base_url, array $label_map, string $moto_label = '' ): array {
		$chips  = array();
		$titles = self::facet_titles();

		if ( '' !== $state->moto() ) {
			$chips[] = array(
				'label' => __( 'Motorcycle', 'motored-core' ) . ': ' . ( '' !== $moto_label ? $moto_label : $state->moto() ),
				'value' => '' !== $moto_label ? $moto_label : $state->moto(),
				'url'   => $state->toggle_url( $base_url, 'moto' ),
			);
			if ( '' !== $state->pos() ) {
				$chips[] = array(
					'label' => $titles[ TireSpecs::ATTR_POSITION ] . ': ' . TireSpecs::position_label( $state->pos() ),
					'value' => TireSpecs::position_label( $state->pos() ),
					'url'   => $state->toggle_url( $base_url, 'pos', $state->pos() ),
				);
			}
		}

		foreach ( $state->brands() as $b_slug ) {
			$b_name  = $label_map['product_brand'][ $b_slug ] ?? $b_slug;
			$chips[] = array(
				'label' => ( $titles['product_brand'] ?? __( 'Brand', 'motored-core' ) ) . ': ' . $b_name,
				'value' => $b_name,
				'url'   => $state->toggle_url( $base_url, 'product_brand', $b_slug ),
			);
		}

		foreach ( $state->attributes() as $attr_slug => $slugs ) {
			$prefix = $titles[ $attr_slug ] ?? $attr_slug;
			foreach ( $slugs as $slug ) {
				$t_name  = self::facet_value_label( $attr_slug, $slug, $label_map[ $attr_slug ][ $slug ] ?? $slug );
				$chips[] = array(
					'label' => $prefix . ': ' . $t_name,
					'value' => in_array( $attr_slug, Config::UNIT_ONLY_ATTRIBUTES, true ) ? $prefix . ' ' . $t_name : $t_name,
					'url'   => $state->toggle_url( $base_url, $attr_slug, $slug ),
				);
			}
		}

		if ( $state->on_sale() ) {
			$chips[] = array(
				'label' => __( 'On sale', 'motored-core' ),
				'value' => __( 'On sale', 'motored-core' ),
				'url'   => $state->toggle_url( $base_url, 'oferta' ),
			);
		}

		if ( $state->in_stock() ) {
			$chips[] = array(
				'label' => __( 'In stock', 'motored-core' ),
				'value' => __( 'In stock', 'motored-core' ),
				'url'   => $state->toggle_url( $base_url, 'stock' ),
			);
		}

		if ( '' !== $state->kit_size() ) {
			$chips[] = array(
				/* translators: %s: tire size, e.g. 130/70-17. */
				'label' => sprintf( __( 'Kits with %s', 'motored-core' ), $state->kit_size() ),
				/* translators: %s: tire size, e.g. 130/70-17. */
				'value' => sprintf( __( 'Kits with %s', 'motored-core' ), $state->kit_size() ),
				'url'   => $state->toggle_url( $base_url, 'kit_medida', $state->kit_size() ),
			);
		}

		return $chips;
	}

	/**
	 * Localizes known position slugs without changing the stored taxonomy term.
	 *
	 * @param string $facet   Facet key.
	 * @param string $slug    Term or compatibility position slug.
	 * @param string $fallback Stored label for this option.
	 */
	private static function facet_value_label( string $facet, string $slug, string $fallback ): string {
		if ( TireSpecs::ATTR_POSITION === $facet ) {
			$label = TireSpecs::position_label( TireSpecs::normalize_position( $slug ) );
			return '' !== $label ? $label : $fallback;
		}
		if ( in_array( $facet, array( TireSpecs::ATTR_CONSTRUCTION, TireSpecs::ATTR_TUBE, TireSpecs::ATTR_USAGE ), true ) ) {
			$fixed = \Motored\Core\Modules\Compatibility\AttributeInstaller::fixed_term_sets();
			if ( isset( $fixed[ $facet ][ $slug ] ) ) {
				return (string) $fixed[ $facet ][ $slug ];
			}
		}
		return $fallback;
	}

	/**
	 * Link to the kits that include the size the shopper filtered (kits never enter the width/profile/rim facets).
	 *
	 * Shown only when the filters name exactly one size: one width, one rim and at most one profile.
	 *
	 * @param FilterState                        $state     Active state.
	 * @param string                             $base_url  Base archive URL.
	 * @param array<string,array<string,string>> $label_map Snapshot labels map.
	 * @param list<int>|null                     $compatible_kits Compatible kit IDs, or null without a motorcycle.
	 * @return array{label:string,url:string}|null
	 */
	public static function kits_hint( FilterState $state, string $base_url, array $label_map, ?array $compatible_kits = null ): ?array {
		$canonical = self::selected_size( $state, $label_map );
		if ( '' === $canonical || '' !== $state->kit_size() ) {
			return null;
		}
		$kits = ProductIndexRepository::kit_ids_with_size( $canonical );
		if ( null !== $compatible_kits ) {
			$kits = array_intersect_key( $kits, array_fill_keys( $compatible_kits, true ) );
		}
		$count = Config::grid_hides_out_of_stock() ? count( array_filter( $kits ) ) : count( $kits );
		if ( $count <= 0 ) {
			return null;
		}
		return array(
			'label' => sprintf(
				/* translators: 1: number of kits, 2: tire size. */
				_n( 'There is also %1$d front + rear kit with %2$s. View kit', 'There are also %1$d front + rear kits with %2$s. View kits', $count, 'motored-core' ),
				$count,
				$canonical
			),
			'url'   => $state->kits_view( $canonical )->build_url( $base_url ),
		);
	}

	/**
	 * Canonical size named by the width/profile/rim filters, or '' when they do not name exactly one size.
	 *
	 * @param FilterState                        $state     Active state.
	 * @param array<string,array<string,string>> $label_map Snapshot labels map.
	 */
	public static function selected_size( FilterState $state, array $label_map ): string {
		$w = $state->attribute_terms( TireSpecs::ATTR_WIDTH );
		$p = $state->attribute_terms( TireSpecs::ATTR_PROFILE );
		$r = $state->attribute_terms( TireSpecs::ATTR_RIM );
		if ( 1 !== count( $w ) || 1 !== count( $r ) || count( $p ) > 1 ) {
			return '';
		}
		$wl  = (string) ( $label_map[ TireSpecs::ATTR_WIDTH ][ $w[0] ] ?? $w[0] );
		$rl  = (string) ( $label_map[ TireSpecs::ATTR_RIM ][ $r[0] ] ?? $r[0] );
		$raw = isset( $p[0] )
			? $wl . '/' . (string) ( $label_map[ TireSpecs::ATTR_PROFILE ][ $p[0] ] ?? $p[0] ) . '-' . $rl
			: $wl . '-' . $rl;
		return (string) SizeParser::canonical( $raw );
	}

	/**
	 * Human-readable titles for all 11 facets.
	 *
	 * @return array<string,string>
	 */
	public static function facet_titles(): array {
		return array(
			TireSpecs::ATTR_POSITION     => __( 'Position', 'motored-core' ),
			'product_brand'              => __( 'Brand', 'motored-core' ),
			TireSpecs::ATTR_WIDTH        => __( 'Width', 'motored-core' ),
			TireSpecs::ATTR_PROFILE      => __( 'Aspect ratio', 'motored-core' ),
			TireSpecs::ATTR_RIM          => __( 'Rim diameter', 'motored-core' ),
			TireSpecs::ATTR_LOAD_INDEX   => __( 'Load index', 'motored-core' ),
			TireSpecs::ATTR_SPEED_RATING => __( 'Speed rating', 'motored-core' ),
			TireSpecs::ATTR_CONSTRUCTION => __( 'Construction', 'motored-core' ),
			TireSpecs::ATTR_TUBE         => __( 'Tube type', 'motored-core' ),
			TireSpecs::ATTR_USAGE        => __( 'Riding style', 'motored-core' ),
			'oferta'                     => __( 'On sale', 'motored-core' ),
		);
	}

	/**
	 * Base product set of the current archive so facet counts match the grid.
	 *
	 * - Category archive: the category plus all descendants (WooCommerce includes child-category products).
	 * - Other product taxonomy archive (brand, tag, attribute): products in that term (1 query).
	 * - Product search: IDs of the search query without pagination (1 query, fields=ids).
	 *
	 * @return array{cat_slugs:list<string>,universe_ids:array<int,true>|null}
	 */
	private static function archive_scope(): array {
		$out = array(
			'cat_slugs'    => array(),
			'universe_ids' => null,
		);
		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			$term = get_queried_object();
			if ( ! $term instanceof \WP_Term ) {
				return $out;
			}
			if ( 'product_cat' === $term->taxonomy ) {
				$out['cat_slugs'][] = (string) $term->slug;
				$children           = get_term_children( (int) $term->term_id, 'product_cat' );
				if ( is_array( $children ) && ! empty( $children ) ) {
					$slugs = get_terms(
						array(
							'taxonomy'   => 'product_cat',
							'include'    => array_map( 'intval', $children ),
							'hide_empty' => false,
							'fields'     => 'id=>slug',
						)
					);
					if ( is_array( $slugs ) ) {
						foreach ( $slugs as $slug ) {
							$out['cat_slugs'][] = (string) $slug;
						}
					}
				}
				return $out;
			}
			$ids = get_objects_in_term( (int) $term->term_id, (string) $term->taxonomy );
			if ( is_array( $ids ) ) {
				$out['universe_ids'] = array_fill_keys( array_map( 'intval', $ids ), true );
			}
			return $out;
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			$raw_s = trim( (string) get_search_query( false ) );
			if ( strlen( $raw_s ) >= 2 ) {
				$ids                 = ProductSearch::search( $raw_s, Config::grid_hides_out_of_stock(), 200 );
				$out['universe_ids'] = array_fill_keys( $ids, true );
			} else {
				$query               = new \WP_Query(
					array(
						'post_type'              => 'product',
						'post_status'            => 'publish',
						's'                      => $raw_s,
						'fields'                 => 'ids',
						'posts_per_page'         => -1,
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				$out['universe_ids'] = array_fill_keys( array_map( 'intval', (array) $query->posts ), true );
			}
		}
		return $out;
	}

	/**
	 * Archive URL the filter links build on: the current product taxonomy term link, else the shop page.
	 */
	private static function current_base_url(): string {
		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) && '' !== $link ) {
					return $link;
				}
			}
		}
		return Config::shop_url();
	}
}
