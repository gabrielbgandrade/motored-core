<?php
/**
 * Server-side cache-safe renderer for the Compatibility product tab and [motored_compat_list] shortcode.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Support\StorefrontAssets;
use Motored\Core\Modules\MiGarage\GarageAssets;
use Motored\Core\Support\SearchText;

defined( 'ABSPATH' ) || exit;

/**
 * Renders compatible motorcycles grouped by brand in 2 columns, collapsed initially (6 brands visible),
 * capped to 120 inline entries (excess loaded on demand via REST) and searchable client-side including aliases.
 *
 * The REST endpoint returns the SAME rows built by build_rows(), so expanding the list never changes labels or order.
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

		GarageAssets::enqueue();
		StorefrontAssets::enqueue_compat_css();
		StorefrontAssets::enqueue_ui_js();
		wp_enqueue_script(
			Storefront::HANDLE_TAB,
			MOTORED_CORE_URL . 'assets/js/compat-tab.js',
			array( GarageAssets::HANDLE, StorefrontAssets::HANDLE_UI ),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		$all_items = is_array( $data['items'] ?? null ) ? $data['items'] : array();
		$position  = (string) ( $data['position'] ?? '' );
		$total     = count( $all_items );
		$has_more  = $total > self::MAX_INLINE_ITEMS;
		$inline    = $has_more ? array_slice( $all_items, 0, self::MAX_INLINE_ITEMS ) : $all_items;
		$rows      = self::build_rows( $inline, $position );

		$full_size = Storefront::resolve_full_size_label( $product );
		$title     = '' !== $full_size
			? sprintf(
				/* translators: %s: full tire size label (e.g. 120/70ZR17). */
				__( 'Motorcycles compatible with %s', 'motored-core' ),
				$full_size
			)
			: __( 'Compatible motorcycles', 'motored-core' );

		$endpoint  = rest_url( RestController::NAMESPACE . '/compat/product/' . $product_id . '/motos' );
		$more_text = sprintf(
			/* translators: %d: total compatible motorcycle versions. */
			__( 'View all compatible motorcycles (%d)', 'motored-core' ),
			$total
		);

		ob_start();
		?>
		<section class="motored-compat-list motored-compat-tab"
			data-compat-list
			data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
			data-position="<?php echo esc_attr( $position ); ?>"
			data-url="<?php echo esc_url( $endpoint ); ?>"
			data-has-more="<?php echo $has_more ? '1' : '0'; ?>"
			data-total="<?php echo esc_attr( (string) $total ); ?>">
			<h3 class="motored-compat-list__title"><?php echo esc_html( $title ); ?></h3>
			<div class="motored-compat-tab__garage" aria-live="polite"></div>

			<?php if ( empty( $rows ) ) : ?>
				<p class="motored-compat-list__empty"><?php esc_html_e( 'No compatible motorcycles found for this product yet.', 'motored-core' ); ?></p>
			<?php else : ?>
				<input type="search"
					class="motored-compat-list__search"
					data-role="compat-search"
					placeholder="<?php esc_attr_e( 'Search your motorcycle (e.g. Tornado, CB500, FZ)...', 'motored-core' ); ?>"
					aria-label="<?php esc_attr_e( 'Search compatible motorcycles', 'motored-core' ); ?>" />

				<ul class="motored-compat-list__grid" data-role="compat-grid">
					<?php foreach ( $rows as $idx => $row ) : ?>
						<?php $is_extra = $idx >= self::INITIAL_BRANDS; ?>
						<li class="motored-compat-list__brand"
							data-brand-row
							data-extra="<?php echo $is_extra ? '1' : '0'; ?>"
							data-search="<?php echo esc_attr( $row['search'] ); ?>"
							data-slugs="<?php echo esc_attr( implode( ',', $row['slugs'] ) ); ?>"
							<?php echo $is_extra ? 'hidden' : ''; ?>>
							<span class="motored-compat-list__brand-name"><?php echo esc_html( $row['brand'] ); ?>:</span>
							<span class="motored-compat-list__models">
								<?php
								$entry_count = count( $row['entries'] );
								foreach ( $row['entries'] as $e_idx => $entry_item ) :
									if ( '' !== $entry_item['url'] ) :
										?>
										<a href="<?php echo esc_url( $entry_item['url'] ); ?>"><?php echo esc_html( $entry_item['text'] ); ?></a>
										<?php
									else :
										echo esc_html( $entry_item['text'] );
									endif;
									if ( $e_idx + 1 < $entry_count ) :
										echo '; ';
									endif;
								endforeach;
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="motored-compat-list__none" data-role="compat-none" hidden><?php esc_html_e( 'No motorcycle matches your search.', 'motored-core' ); ?></p>
				<p class="motored-compat-list__error" data-role="compat-error" hidden>
					<span><?php esc_html_e( 'Could not load compatibility list.', 'motored-core' ); ?></span>
					<button type="button" class="motored-compat-badge__link" data-role="compat-retry"><?php esc_html_e( 'Try again', 'motored-core' ); ?></button>
				</p>

				<?php if ( count( $rows ) > self::INITIAL_BRANDS || $has_more ) : ?>
					<button type="button" class="button motored-compat-list__toggle" data-role="compat-toggle" aria-expanded="false"
						data-label-more="<?php echo esc_attr( $more_text ); ?>"
						data-label-less="<?php esc_attr_e( 'Show less', 'motored-core' ); ?>">
						<?php echo esc_html( $more_text ); ?>
					</button>
				<?php endif; ?>
			<?php endif; ?>

			<p class="motored-compat-disclaimer"><small><?php echo esc_html( Config::disclaimer() ); ?></small></p>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Builds display rows (one per brand) shared by the HTML tab and the REST endpoint.
	 *
	 * @param list<array<string,mixed>> $items            Compatible motorcycle items.
	 * @param string                    $product_position Product position (front|rear|both|kit).
	 * @return list<array{brand:string,text:string,entries:list<array{text:string,url:string}>,search:string,slugs:list<string>}>
	 */
	public static function build_rows( array $items, string $product_position = '' ): array {
		$rows = array();
		foreach ( self::group_by_brand( $items ) as $brand_name => $entries ) {
			$brand       = (string) $brand_name;
			$search      = array( $brand );
			$slugs       = array();
			$parts       = array();
			$entry_items = array();
			foreach ( $entries as $e ) {
				$slug          = (string) ( $e['slug'] ?? '' );
				$slugs[]       = $slug;
				$search[]      = trim( (string) ( $e['model'] ?? '' ) . ' ' . (string) ( $e['aliases'] ?? '' ) . ' ' . (string) ( $e['variant'] ?? '' ) . ' ' . (string) ( $e['years'] ?? '' ) );
				$label         = self::format_entry( $e, $product_position );
				$url           = '' !== $slug ? (string) apply_filters( 'motored_compat_bike_page_url', '', $slug ) : '';
				$parts[]       = $label;
				$entry_items[] = array(
					'text' => $label,
					'url'  => $url,
				);
			}
			$rows[] = array(
				'brand'   => $brand,
				'text'    => implode( '; ', $parts ),
				'entries' => $entry_items,
				'search'  => SearchText::haystack( implode( ' ', array_unique( $search ) ) ),
				'slugs'   => array_values( array_unique( array_filter( $slugs ) ) ),
			);
		}
		return $rows;
	}

	/**
	 * Groups compatible motorcycle items by brand name (natural, case-insensitive order).
	 *
	 * @param list<array<string,mixed>> $items Compatible motorcycle items.
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
	 * Human position label: "Complete kit" only for kit PRODUCTS; a single tire fitting both axles is "Front / Rear".
	 *
	 * @param array<int,string> $positions        Matched axles.
	 * @param string            $product_position Product position (front|rear|both|kit).
	 */
	public static function position_label( array $positions, string $product_position = '' ): string {
		return TireSpecs::positions_label( $positions, $product_position );
	}

	/**
	 * Formats a single compatible motorcycle entry string, e.g. "XR 250 Tornado (2001–2024 · Rear · Original)".
	 *
	 * @param array<string,mixed> $entry            Single compatible motorcycle item.
	 * @param string              $product_position Product position (front|rear|both|kit).
	 */
	public static function format_entry( array $entry, string $product_position = '' ): string {
		$model   = trim( (string) ( $entry['model'] ?? '' ) );
		$variant = trim( (string) ( $entry['variant'] ?? '' ) );
		$years   = trim( (string) ( $entry['years'] ?? '' ) );
		$pos_arr = is_array( $entry['positions'] ?? null ) ? $entry['positions'] : array();
		$match   = (string) ( $entry['match'] ?? TireSpecs::FIT_OEM );
		$eq_from = trim( (string) ( $entry['equiv_from'] ?? '' ) );

		$pos_label = self::position_label( $pos_arr, $product_position );

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
