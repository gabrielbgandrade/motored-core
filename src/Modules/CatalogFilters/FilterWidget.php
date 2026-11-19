<?php
/**
 * Classic WordPress widget for CatalogFilters (works in any theme sidebar or off-canvas shop drawer).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps FilterRenderer::render() as a standard WP_Widget without any theme-specific markup.
 */
final class FilterWidget extends \WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'motored_catalog_filters',
			__( 'Motored: Catalog Filters', 'motored-core' ),
			array(
				'description' => __( 'Theme-agnostic faceted tire filters with active chips and motorcycle-aware counts.', 'motored-core' ),
			)
		);
	}

	/**
	 * Renders widget output on the storefront.
	 *
	 * @param array<string,mixed> $args     Sidebar arguments.
	 * @param array<string,mixed> $instance Saved widget settings.
	 */
	public function widget( $args, $instance ): void {
		if ( ! Config::enabled() ) {
			return;
		}
		$title = isset( $instance['title'] ) && is_string( $instance['title'] ) && '' !== trim( $instance['title'] )
			? $instance['title']
			: __( 'Filter results', 'motored-core' );

		$html = FilterRenderer::render( $title );
		if ( '' === $html ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standard WP sidebar wrapper + escaped renderer HTML.
		echo (string) ( $args['before_widget'] ?? '' ) . $html . (string) ( $args['after_widget'] ?? '' );
	}

	/**
	 * Renders widget configuration form in wp-admin.
	 *
	 * @param array<string,mixed> $instance Saved settings.
	 */
	public function form( $instance ): void {
		$title = isset( $instance['title'] ) && is_string( $instance['title'] ) ? $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'motored-core' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'Filter results', 'motored-core' ); ?>" />
		</p>
		<?php
	}

	/**
	 * Sanitizes widget settings on save.
	 *
	 * @param array<string,mixed> $new_instance Incoming values.
	 * @param array<string,mixed> $old_instance Previous values.
	 * @return array<string,string>
	 */
	public function update( $new_instance, $old_instance ): array {
		unset( $old_instance );
		return array(
			'title' => isset( $new_instance['title'] ) && is_string( $new_instance['title'] )
				? sanitize_text_field( $new_instance['title'] )
				: '',
		);
	}
}
