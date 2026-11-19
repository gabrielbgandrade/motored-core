<?php
/**
 * Storefront home search shortcode [motored_buscador layout="tabs|split"].
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Modules\Compatibility\Storefront as CompatStorefront;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the unified tire search box (Motorcycle + Size any-order cascade + Smart search).
 */
final class SearchShortcode {

	public const SHORTCODE = 'motored_buscador';

	/**
	 * Registers the shortcode (zero option reads or asset enqueues here).
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback for [motored_buscador layout="tabs|split"].
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function render( $atts ): string {
		if ( ! Config::enabled() ) {
			return '';
		}
		$atts = shortcode_atts(
			array(
				'layout' => Config::default_layout(),
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$layout        = 'split' === $atts['layout'] ? 'split' : 'tabs';
		$compat_active = \Motored\Core\Modules\Compatibility\Config::enabled();
		$shop_url      = Config::shop_url();
		$uid           = 'motored-busc-' . wp_unique_id();

		if ( $compat_active ) {
			CompatStorefront::enqueue_garage_script();
		}
		StorefrontAssets::enqueue_search_js();

		ob_start();
		?>
		<section class="motored-search motored-search--<?php echo esc_attr( $layout ); ?>" data-motored-search data-layout="<?php echo esc_attr( $layout ); ?>" data-shop-url="<?php echo esc_url( $shop_url ); ?>">
			<?php if ( 'tabs' === $layout ) : ?>
				<div class="motored-search__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Search tires', 'motored-core' ); ?>">
					<?php if ( $compat_active ) : ?>
						<button type="button" role="tab" class="motored-search__tab is-active" id="<?php echo esc_attr( $uid ); ?>-t-moto" aria-selected="true" aria-controls="<?php echo esc_attr( $uid ); ?>-p-moto" data-tab="moto">
							<?php esc_html_e( 'By motorcycle', 'motored-core' ); ?>
						</button>
					<?php endif; ?>
					<button type="button" role="tab" class="motored-search__tab<?php echo $compat_active ? '' : ' is-active'; ?>" id="<?php echo esc_attr( $uid ); ?>-t-size" aria-selected="<?php echo $compat_active ? 'false' : 'true'; ?>" aria-controls="<?php echo esc_attr( $uid ); ?>-p-size" data-tab="size">
						<?php esc_html_e( 'By tire size', 'motored-core' ); ?>
					</button>
					<button type="button" role="tab" class="motored-search__tab" id="<?php echo esc_attr( $uid ); ?>-t-smart" aria-selected="false" aria-controls="<?php echo esc_attr( $uid ); ?>-p-smart" data-tab="smart">
						<?php esc_html_e( 'Smart search', 'motored-core' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<div class="motored-search__grid">
				<div class="motored-search__panel<?php echo ( 'tabs' === $layout && $compat_active ) ? '' : ' is-active'; ?>" id="<?php echo esc_attr( $uid ); ?>-p-size" role="tabpanel" data-panel="size"<?php echo ( 'tabs' === $layout && $compat_active ) ? ' hidden' : ''; ?>>
					<h3 class="motored-search__title"><?php esc_html_e( 'Search tires by size', 'motored-core' ); ?></h3>
					<form class="motored-search__form" data-role="size-form">
						<div class="motored-search__fields">
							<div class="motored-search__field">
								<label for="<?php echo esc_attr( $uid ); ?>-w"><?php esc_html_e( 'Width', 'motored-core' ); ?></label>
								<select id="<?php echo esc_attr( $uid ); ?>-w" data-role="width">
									<option value=""><?php esc_html_e( 'Width', 'motored-core' ); ?></option>
								</select>
							</div>
							<div class="motored-search__field">
								<label for="<?php echo esc_attr( $uid ); ?>-p"><?php esc_html_e( 'Aspect ratio', 'motored-core' ); ?></label>
								<select id="<?php echo esc_attr( $uid ); ?>-p" data-role="profile">
									<option value=""><?php esc_html_e( 'Aspect ratio', 'motored-core' ); ?></option>
								</select>
							</div>
							<div class="motored-search__field">
								<label for="<?php echo esc_attr( $uid ); ?>-r"><?php esc_html_e( 'Rim', 'motored-core' ); ?></label>
								<select id="<?php echo esc_attr( $uid ); ?>-r" data-role="rim">
									<option value=""><?php esc_html_e( 'Rim', 'motored-core' ); ?></option>
								</select>
							</div>
						</div>
						<button type="submit" class="button motored-search__btn" data-role="size-submit" disabled>
							<?php esc_html_e( 'Search', 'motored-core' ); ?>
						</button>
					</form>
				</div>

				<?php if ( $compat_active ) : ?>
					<div class="motored-search__panel is-active" id="<?php echo esc_attr( $uid ); ?>-p-moto" role="tabpanel" data-panel="moto">
						<h3 class="motored-search__title"><?php esc_html_e( 'Search tires by motorcycle', 'motored-core' ); ?></h3>
						<form class="motored-search__form" data-role="moto-form">
							<div class="motored-search__fields">
								<div class="motored-search__field">
									<label for="<?php echo esc_attr( $uid ); ?>-b"><?php esc_html_e( 'Brand', 'motored-core' ); ?></label>
									<select id="<?php echo esc_attr( $uid ); ?>-b" data-role="brand" required>
										<option value=""><?php esc_html_e( 'Select brand...', 'motored-core' ); ?></option>
									</select>
								</div>
								<div class="motored-search__field">
									<label for="<?php echo esc_attr( $uid ); ?>-m"><?php esc_html_e( 'Model', 'motored-core' ); ?></label>
									<select id="<?php echo esc_attr( $uid ); ?>-m" data-role="model" disabled required>
										<option value=""><?php esc_html_e( 'Select model...', 'motored-core' ); ?></option>
									</select>
								</div>
								<div class="motored-search__field">
									<label for="<?php echo esc_attr( $uid ); ?>-y"><?php esc_html_e( 'Year', 'motored-core' ); ?></label>
									<select id="<?php echo esc_attr( $uid ); ?>-y" data-role="year" disabled required>
										<option value=""><?php esc_html_e( 'Select year...', 'motored-core' ); ?></option>
									</select>
								</div>
								<div class="motored-search__field" data-role="variant-wrap" hidden>
									<label for="<?php echo esc_attr( $uid ); ?>-v"><?php esc_html_e( 'Version', 'motored-core' ); ?></label>
									<select id="<?php echo esc_attr( $uid ); ?>-v" data-role="variant">
										<option value=""><?php esc_html_e( 'Select version...', 'motored-core' ); ?></option>
									</select>
								</div>
							</div>
							<button type="submit" class="button motored-search__btn" data-role="moto-submit" disabled>
								<?php esc_html_e( 'Search', 'motored-core' ); ?>
							</button>
						</form>
					</div>
				<?php endif; ?>

				<div class="motored-search__panel motored-search__panel--smart<?php echo 'split' === $layout ? ' is-active' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>-p-smart" role="tabpanel" data-panel="smart"<?php echo 'tabs' === $layout ? ' hidden' : ''; ?>>
					<h3 class="motored-search__title"><?php esc_html_e( 'Smart tire search', 'motored-core' ); ?></h3>
					<form class="motored-search__form motored-search__form--smart" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" data-role="smart-form">
						<input type="hidden" name="post_type" value="product" />
						<div class="motored-search__smart-wrap">
							<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-q"><?php esc_html_e( 'Search by size, rim, motorcycle or brand', 'motored-core' ); ?></label>
							<input id="<?php echo esc_attr( $uid ); ?>-q" class="motored-search__input" type="search" name="s" autocomplete="off" data-role="smart-input" placeholder="<?php esc_attr_e( 'Search by size (120/70-17), rim, motorcycle or brand...', 'motored-core' ); ?>" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-sug" />
							<ul id="<?php echo esc_attr( $uid ); ?>-sug" class="motored-search__suggest" role="listbox" data-role="smart-list" hidden></ul>
						</div>
						<button type="submit" class="button motored-search__btn">
							<?php esc_html_e( 'Search', 'motored-core' ); ?>
						</button>
					</form>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
