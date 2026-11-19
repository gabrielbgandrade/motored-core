<?php
/**
 * Sidebar faceted filters renderer ([motored_filtros] shortcode & FilterWidget body).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\TireSpecs;

defined( 'ABSPATH' ) || exit;

/**
 * Renders active filter chips, 11 collapsible disjunctive facet sections with real anchor links
 * (works 100% without JS), mobile Apply button and Clear filters link.
 */
final class FilterRenderer {

	public const SHORTCODE = 'motored_filtros';

	/**
	 * Facets expanded by default when no filters are active.
	 *
	 * @var list<string>
	 */
	private const DEFAULT_OPEN = array(
		TireSpecs::ATTR_POSITION,
		'product_brand',
		TireSpecs::ATTR_WIDTH,
		TireSpecs::ATTR_PROFILE,
		TireSpecs::ATTR_RIM,
	);

	/**
	 * Registers the [motored_filtros] shortcode (zero option reads or asset enqueues here).
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Shortcode callback for [motored_filtros].
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		$atts  = shortcode_atts(
			array(
				'title' => __( 'Filter results', 'motored-core' ),
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		$title = is_string( $atts['title'] ) ? $atts['title'] : '';
		return self::render( $title );
	}

	/**
	 * Renders the complete faceted filter panel HTML.
	 *
	 * @param string $title Optional panel heading.
	 */
	public static function render( string $title = '' ): string {
		if ( ! Config::enabled() ) {
			return '';
		}

		StorefrontAssets::enqueue_filters_js();

		$state    = FilterState::from_query();
		$base_url = self::current_base_url();
		$snapshot = FacetEngine::snapshot();

		$moto_ids = null;
		$moto_lbl = '';
		if ( '' !== $state->moto() && \Motored\Core\Modules\Compatibility\Config::enabled() ) {
			$ids      = MatchEngine::matching_product_ids( $state->moto(), $state->pos() );
			$moto_ids = array_fill_keys( $ids, true );
			$prof     = MatchEngine::bike_profile( $state->moto() );
			$moto_lbl = is_array( $prof ) && ! empty( $prof['label'] ) ? (string) $prof['label'] : $state->moto();
		}

		$cat_slug = '';
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$queried = get_queried_object();
			if ( is_object( $queried ) && isset( $queried->slug ) ) {
				$cat_slug = (string) $queried->slug;
			}
		}

		$computed = FacetEngine::compute_facets(
			$snapshot,
			$state,
			array(
				'only_in_stock' => Config::only_in_stock(),
				'moto_ids'      => $moto_ids,
				'cat_slug'      => $cat_slug,
				'sale_ids'      => FacetEngine::on_sale_id_map(),
			)
		);

		$labels = self::facet_titles();
		$chips  = self::build_active_chips( $state, $base_url, $snapshot['labels'] ?? array(), $moto_lbl );
		$uid    = 'motored-flt-' . wp_unique_id();

		ob_start();
		?>
		<aside class="motored-filters" data-motored-filters data-base-url="<?php echo esc_url( FilterState::strip_query_and_pagination( $base_url ) ); ?>" data-extra="<?php echo esc_attr( (string) wp_json_encode( $state->extra() ) ); ?>" data-moto="<?php echo esc_attr( $state->moto() ); ?>" data-pos="<?php echo esc_attr( $state->pos() ); ?>">
			<div class="motored-filters__head">
				<?php if ( '' !== $title ) : ?>
					<h3 class="motored-filters__title"><?php echo esc_html( $title ); ?></h3>
				<?php endif; ?>
				<span class="motored-filters__count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of matching products. */
							_n( '%d result', '%d results', $computed['total'], 'motored-core' ),
							$computed['total']
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

			<div class="motored-filters__groups">
				<?php
				foreach ( Config::ALL_FACETS as $facet_key ) :
					$items = $computed['facets'][ $facet_key ] ?? array();
					if ( empty( $items ) ) {
						continue;
					}
					$has_active = 'oferta' === $facet_key
						? $state->on_sale()
						: ( 'product_brand' === $facet_key ? ! empty( $state->brands() ) : ! empty( $state->attribute_terms( $facet_key ) ) );
					$is_open    = $has_active || in_array( $facet_key, self::DEFAULT_OPEN, true );
					$panel_id   = $uid . '-' . $facet_key;
					?>
					<div class="motored-acc" data-facet-group="<?php echo esc_attr( $facet_key ); ?>">
						<button type="button" class="motored-acc__btn" aria-expanded="<?php echo $is_open ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>">
							<span><?php echo esc_html( $labels[ $facet_key ] ?? $facet_key ); ?></span>
							<span class="motored-acc__icon" aria-hidden="true"><?php echo $is_open ? '−' : '+'; ?></span>
						</button>
						<div id="<?php echo esc_attr( $panel_id ); ?>" class="motored-acc__panel"<?php echo $is_open ? '' : ' hidden'; ?>>
							<ul class="motored-acc__list">
								<?php foreach ( $items as $item ) : ?>
									<?php $url = $state->toggle_url( $base_url, $facet_key, $item['slug'] ); ?>
									<li>
										<a class="motored-opt<?php echo $item['active'] ? ' is-active' : ''; ?>"
											href="<?php echo esc_url( $url ); ?>"
											data-facet="<?php echo esc_attr( $facet_key ); ?>"
											data-slug="<?php echo esc_attr( $item['slug'] ); ?>"
											aria-pressed="<?php echo $item['active'] ? 'true' : 'false'; ?>">
											<span class="motored-opt__box" aria-hidden="true"></span>
											<span class="motored-opt__label"><?php echo esc_html( $item['label'] ); ?></span>
											<span class="motored-opt__cnt">(<?php echo esc_html( (string) $item['count'] ); ?>)</span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="motored-filters__foot">
				<button type="button" class="button motored-filters__apply" data-role="apply" hidden>
					<?php esc_html_e( 'Apply filters', 'motored-core' ); ?>
				</button>
				<?php if ( $state->has_any_filter() ) : ?>
					<a class="button motored-filters__clear" href="<?php echo esc_url( $state->clear_url( $base_url ) ); ?>">
						<?php esc_html_e( 'Clear filters', 'motored-core' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</aside>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Builds the active filter chip descriptors (including active motorcycle).
	 *
	 * @param FilterState                        $state      Active state.
	 * @param string                             $base_url   Base archive URL.
	 * @param array<string,array<string,string>> $label_map Snapshot labels map.
	 * @param string                             $moto_label Human-readable active motorcycle label.
	 * @return list<array{label:string,url:string}>
	 */
	public static function build_active_chips( FilterState $state, string $base_url, array $label_map, string $moto_label = '' ): array {
		$chips  = array();
		$titles = self::facet_titles();

		if ( '' !== $state->moto() ) {
			$chips[] = array(
				'label' => __( 'Motorcycle', 'motored-core' ) . ': ' . ( '' !== $moto_label ? $moto_label : $state->moto() ),
				'url'   => $state->toggle_url( $base_url, 'moto' ),
			);
		}

		foreach ( $state->brands() as $b_slug ) {
			$b_name  = $label_map['product_brand'][ $b_slug ] ?? $b_slug;
			$chips[] = array(
				'label' => ( $titles['product_brand'] ?? __( 'Brand', 'motored-core' ) ) . ': ' . $b_name,
				'url'   => $state->toggle_url( $base_url, 'product_brand', $b_slug ),
			);
		}

		foreach ( $state->attributes() as $attr_slug => $slugs ) {
			$prefix = $titles[ $attr_slug ] ?? $attr_slug;
			foreach ( $slugs as $slug ) {
				$t_name  = $label_map[ $attr_slug ][ $slug ] ?? $slug;
				$chips[] = array(
					'label' => $prefix . ': ' . $t_name,
					'url'   => $state->toggle_url( $base_url, $attr_slug, $slug ),
				);
			}
		}

		if ( $state->on_sale() ) {
			$chips[] = array(
				'label' => __( 'On sale', 'motored-core' ),
				'url'   => $state->toggle_url( $base_url, 'oferta' ),
			);
		}

		return $chips;
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
	 * Resolves the current shop/archive base URL cleanly.
	 */
	private static function current_base_url(): string {
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
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
