<?php
/**
 * Server-side cache-safe renderer for the Compatibility product tab and [motored_compat_list] shortcode.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Modules\CatalogFilters\StorefrontAssets;

defined( 'ABSPATH' ) || exit;

/**
 * Renders compatible motorcycles grouped by brand in 2 columns, collapsed initially (6 brands visible),
 * capped to 120 inline entries (excess loaded on demand via REST) and searchable client-side including aliases.
 */
final class CompatListRenderer {

	public const INITIAL_BRANDS   = 6;
	public const MAX_INLINE_ITEMS = 120;

	/**
	 * Renders the complete server-side compatibility list block for a WooCommerce product.
	 *
	 * @param \WC_Product $product Product instance.
	 */
	public static function render_for_product( \WC_Product $product ): string {
		$product_id = $product->get_id();
		$data       = MatchEngine::compatible_motos_for_product( $product_id );
		if ( null === $data ) {
			return '';
		}

		Storefront::enqueue_garage_script();
		StorefrontAssets::enqueue_css();
		wp_enqueue_script(
			Storefront::HANDLE_TAB,
			MOTORED_CORE_URL . 'assets/js/compat-tab.js',
			array(),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script(
			Storefront::HANDLE_TAB,
			'window.motoredCompatCfg=window.motoredCompatCfg||' . wp_json_encode( Storefront::client_config() ) . ';',
			'before'
		);

		$all_items = is_array( $data['items'] ?? null ) ? $data['items'] : array();
		$total     = count( $all_items );
		$has_more  = $total > self::MAX_INLINE_ITEMS;
		$inline    = $has_more ? array_slice( $all_items, 0, self::MAX_INLINE_ITEMS ) : $all_items;
		$grouped   = self::group_by_brand( $inline );

		$full_size = Storefront::resolve_full_size_label( $product );
		$title     = '' !== $full_size
			? sprintf(
				/* translators: %s: full tire size label (e.g. 120/70ZR17). */
				__( 'Motorcycles compatible with %s', 'motored-core' ),
				$full_size
			)
			: __( 'Compatible motorcycles', 'motored-core' );

		$endpoint = rest_url( RestController::NAMESPACE . '/compat/product/' . $product_id . '/motos' );

		ob_start();
		?>
		<section class="motored-compat-list motored-compat-tab"
			data-compat-list
			data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
			data-url="<?php echo esc_url( $endpoint ); ?>"
			data-has-more="<?php echo $has_more ? '1' : '0'; ?>"
			data-total="<?php echo esc_attr( (string) $total ); ?>">
			<h3 class="motored-compat-list__title"><?php echo esc_html( $title ); ?></h3>
			<div class="motored-compat-tab__garage"></div>

			<?php if ( empty( $grouped ) ) : ?>
				<p class="motored-compat-list__empty"><?php esc_html_e( 'No compatible motorcycles found for this product yet.', 'motored-core' ); ?></p>
			<?php else : ?>
				<input type="search"
					class="motored-compat-list__search"
					data-role="compat-search"
					placeholder="<?php esc_attr_e( 'Search your motorcycle (e.g. Tornado, CB500, FZ)...', 'motored-core' ); ?>"
					aria-label="<?php esc_attr_e( 'Search compatible motorcycles', 'motored-core' ); ?>" />

				<ul class="motored-compat-list__grid" data-role="compat-grid">
					<?php
					$brand_idx = 0;
					foreach ( $grouped as $brand_name => $entries ) :
						++$brand_idx;
						$is_extra  = $brand_idx > self::INITIAL_BRANDS;
						$search_tx = array( strtolower( $brand_name ) );
						$slugs     = array();
						$parts     = array();
						foreach ( $entries as $e ) {
							$slugs[]     = (string) $e['slug'];
							$search_tx[] = strtolower(
								trim(
									(string) $e['model'] . ' ' . (string) $e['aliases'] . ' ' . (string) $e['variant'] . ' ' . (string) $e['years']
								)
							);
							$parts[]     = self::format_entry( $e );
						}
						?>
						<li class="motored-compat-list__brand"
							data-brand-row
							data-extra="<?php echo $is_extra ? '1' : '0'; ?>"
							data-search="<?php echo esc_attr( implode( ' ', array_unique( $search_tx ) ) ); ?>"
							data-slugs="<?php echo esc_attr( implode( ',', array_unique( $slugs ) ) ); ?>"
							<?php echo $is_extra ? 'hidden' : ''; ?>>
							<span class="motored-compat-list__brand-name"><?php echo esc_html( $brand_name ); ?>:</span>
							<span class="motored-compat-list__models"><?php echo esc_html( implode( '; ', $parts ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( count( $grouped ) > self::INITIAL_BRANDS || $has_more ) : ?>
					<button type="button" class="button motored-compat-list__toggle" data-role="compat-toggle" aria-expanded="false">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: total compatible motorcycle versions. */
								__( 'View all compatible motorcycles (%d)', 'motored-core' ),
								$total
							)
						);
						?>
					</button>
				<?php endif; ?>
			<?php endif; ?>

			<p class="motored-compat-disclaimer"><small><?php echo esc_html( Config::disclaimer() ); ?></small></p>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Groups compatible motorcycle entries alphabetically by brand name.
	 *
	 * @param list<array<string,mixed>> $items Raw MatchEngine items.
	 * @return array<string,list<array<string,mixed>>>
	 */
	public static function group_by_brand( array $items ): array {
		$grouped = array();
		foreach ( $items as $item ) {
			$brand = trim( (string) ( $item['brand'] ?? '' ) );
			if ( '' === $brand ) {
				continue;
			}
			$grouped[ $brand ][] = $item;
		}
		ksort( $grouped, SORT_NATURAL | SORT_FLAG_CASE );
		return $grouped;
	}

	/**
	 * Formats a single motorcycle version entry with axle and OEM/Equivalent (approx.) label.
	 *
	 * @param array<string,mixed> $entry MatchEngine item.
	 */
	public static function format_entry( array $entry ): string {
		$model   = trim( (string) ( $entry['model'] ?? '' ) );
		$variant = trim( (string) ( $entry['variant'] ?? '' ) );
		$years   = trim( (string) ( $entry['years'] ?? '' ) );
		$pos_arr = is_array( $entry['positions'] ?? null ) ? $entry['positions'] : array();
		$match   = (string) ( $entry['match'] ?? TireSpecs::FIT_OEM );
		$eq_from = trim( (string) ( $entry['equiv_from'] ?? '' ) );

		$pos_label = '';
		if ( count( $pos_arr ) >= 2 ) {
			$pos_label = __( 'Complete kit', 'motored-core' );
		} elseif ( in_array( TireSpecs::POS_FRONT, $pos_arr, true ) ) {
			$pos_label = __( 'Front', 'motored-core' );
		} elseif ( in_array( TireSpecs::POS_REAR, $pos_arr, true ) ) {
			$pos_label = __( 'Rear', 'motored-core' );
		}

		if ( TireSpecs::FIT_EQUIV === $match ) {
			$match_label = '' !== $eq_from
				? sprintf(
					/* translators: %s: OEM tire size replaced by this equivalent. */
					__( 'Equivalent approx. (%s)', 'motored-core' ),
					$eq_from
				)
				: __( 'Equivalent (approx.)', 'motored-core' );
		} elseif ( TireSpecs::FIT_ALT === $match ) {
			$match_label = __( 'Alternative', 'motored-core' );
		} else {
			$match_label = __( 'Original', 'motored-core' );
		}

		$name = trim( $model . ( '' !== $variant ? ' ' . $variant : '' ) );
		$meta = array_filter( array( $years, $pos_label, $match_label ) );
		return $name . ( ! empty( $meta ) ? ' (' . implode( ' · ', $meta ) . ')' : '' );
	}
}
