<?php
/**
 * "Motorcycle tire" meta box on the product edit screen: one place to fill in what a tire is.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Admin\Ui;
use Motored\Core\Integration\WooCommerce\AttributeHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces filling six global attributes by hand: the owner picks the position, types the size as printed on the
 * tire and, optionally, load/speed/tube/use. Saving turns that into the global attributes (see TireAttributePlan),
 * which the compatibility index and the Catalog Filters already read. The box also shows the motorcycles that fit.
 */
final class ProductTireAdmin {

	public const NONCE_FIELD  = 'motored_tire_nonce';
	public const NONCE_ACTION = 'motored_tire_box';
	public const FIELD        = 'motored_tire';

	private const MAX_LISTED = 16;

	/**
	 * Registers the box and its save handler (admin only).
	 */
	public function register(): void {
		AttributeHooks::register_product_box_hooks( array( $this, 'add_meta_box' ), array( $this, 'save' ) );
	}

	/**
	 * Adds the meta box in the main column of the product screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'motored-tire',
			__( 'Technical information', 'motored-core' ),
			array( $this, 'render' ),
			'product',
			'normal',
			'high'
		);
	}

	/**
	 * Renders the box.
	 *
	 * @param mixed $post Current post.
	 */
	public function render( $post ): void {
		$pid = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '[present]" value="1" />';

		Ui::box_open( true );

		if ( ! $this->attributes_ready() ) {
			echo '<p><strong>' . esc_html__( 'The tire attributes are not installed yet.', 'motored-core' ) . '</strong> ';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=motored&section=' . CompatibilityAdmin::SECTION ) ) . '">' . esc_html__( 'Install them in WooCommerce > Settings > Motored > Compatibility (step 1).', 'motored-core' ) . '</a></p>';
			Ui::box_close();
			return;
		}

		$cur           = $this->current_values( $pid );
		$fx            = AttributeInstaller::fixed_term_sets();
		$pos_cur       = $cur['position'];
		$full_override = $pid > 0 ? (string) get_post_meta( $pid, Storefront::FULL_SIZE_META, true ) : '';

		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '[was_tire]" value="' . ( '' !== $pos_cur ? '1' : '0' ) . '" />';

		$pos_opts  = '<option value="">' . esc_html__( 'Not a tire', 'motored-core' ) . '</option>';
		$pos_names = array(
			TireSpecs::POS_FRONT => __( 'Front tire', 'motored-core' ),
			TireSpecs::POS_REAR  => __( 'Rear tire', 'motored-core' ),
			TireSpecs::POS_BOTH  => __( 'Front / Rear (both axles)', 'motored-core' ),
			TireSpecs::POS_KIT   => __( 'Kit (front + rear)', 'motored-core' ),
		);
		foreach ( $pos_names as $key => $label ) {
			$pos_opts .= '<option value="' . esc_attr( $key ) . '"' . selected( $pos_cur, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}

		// The select lives in the box header (moved there by motored-tire-box.js, like WooCommerce does with the product type).
		echo '<div class="motored-box__pos"><label class="screen-reader-text" for="motored-tire-pos">' . esc_html__( 'Position', 'motored-core' ) . '</label>';
		echo '<select id="motored-tire-pos" data-motored-pos="1" name="' . esc_attr( self::FIELD ) . '[position]">' . $pos_opts . '</select></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

		echo '<p class="description" ' . $this->cell_attrs( 'none', $pos_cur ) . '>' . esc_html__( 'Choose the position above if this product is a motorcycle tire. The fields to fill in appear right away.', 'motored-core' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().

		$speed_opts = '<option value="">&mdash;</option>';
		foreach ( array_keys( TireSpecs::SPEED_RANKS ) as $code ) {
			if ( in_array( $code, array( 'VR', 'ZR' ), true ) && $cur['speed'] !== $code ) {
				continue;
			}
			$kmh         = TireSpecs::SPEED_KMH[ $code ] ?? null;
			$speed_lbl   = null !== $kmh
				? ( '(W)' === $code ? $code . ' — +' . $kmh . ' km/h' : $code . ' — ' . $kmh . ' km/h' )
				: $code;
			$speed_opts .= '<option value="' . esc_attr( $code ) . '"' . selected( $cur['speed'], $code, false ) . '>' . esc_html( $speed_lbl ) . '</option>';
		}

		$single_size_lbl = __( 'Size (as printed on the tire)', 'motored-core' );
		$kit_size_lbl    = __( 'Front size', 'motored-core' );
		$size_lbl        = TireSpecs::POS_KIT === $pos_cur ? $kit_size_lbl : $single_size_lbl;

		// Row 1: Size (+ Rear size on kits), Model, Riding style.
		echo '<div class="motored-box__grid" ' . $this->cell_attrs( 'front rear both kit', $pos_cur ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
		$this->cell_open( 'front rear both kit', $pos_cur );
		Ui::field(
			$size_lbl,
			'motored-tire-size',
			'<input id="motored-tire-size" type="text" name="' . esc_attr( self::FIELD ) . '[size]" value="' . esc_attr( $cur['size'] ) . '" placeholder="160/60ZR17" data-label-single="' . esc_attr( $single_size_lbl ) . '" data-label-kit="' . esc_attr( $kit_size_lbl ) . '" />'
		);
		echo '</div>';
		$this->cell_open( 'kit', $pos_cur );
		Ui::field( __( 'Rear size', 'motored-core' ), 'motored-tire-rear', '<input id="motored-tire-rear" type="text" name="' . esc_attr( self::FIELD ) . '[rear_size]" value="' . esc_attr( $cur['rear_size'] ) . '" placeholder="180/55ZR17" />' );
		echo '</div>';
		$this->cell_open( 'front rear both kit', $pos_cur );
		Ui::field( __( 'Model', 'motored-core' ), 'motored-tire-line', '<input id="motored-tire-line" type="text" name="' . esc_attr( self::FIELD ) . '[line]" value="' . esc_attr( $cur['line'] ) . '" placeholder="MT60 RS" />' );
		echo '</div>';
		$this->cell_open( 'front rear both kit', $pos_cur );
		Ui::field( __( 'Riding style', 'motored-core' ), 'motored-tire-usage', '<select id="motored-tire-usage" name="' . esc_attr( self::FIELD ) . '[usage]">' . $this->options_html( array( '' => '—' ) + (array) $fx[ TireSpecs::ATTR_USAGE ], $cur['usage'] ) . '</select>' );
		echo '</div>';
		echo '</div>';

		// Row 2 (single tires only): Load index, Speed rating, Construction, Tube type.
		echo '<div class="motored-box__grid" ' . $this->cell_attrs( 'front rear both', $pos_cur ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
		$this->cell_open( 'front rear both', $pos_cur );
		Ui::field( __( 'Load index', 'motored-core' ), 'motored-tire-load', '<input id="motored-tire-load" type="number" min="20" max="120" name="' . esc_attr( self::FIELD ) . '[load]" value="' . esc_attr( $cur['load'] ) . '" placeholder="69" />' );
		echo '</div>';
		$this->cell_open( 'front rear both', $pos_cur );
		Ui::field( __( 'Speed rating', 'motored-core' ), 'motored-tire-speed', '<select id="motored-tire-speed" name="' . esc_attr( self::FIELD ) . '[speed]">' . $speed_opts . '</select>' );
		echo '</div>';
		$this->cell_open( 'front rear both', $pos_cur );
		Ui::field(
			__( 'Construction', 'motored-core' ),
			'motored-tire-const',
			'<select id="motored-tire-const" name="' . esc_attr( self::FIELD ) . '[construction]">' . $this->options_html( array( '' => __( 'Automatic (from the size)', 'motored-core' ) ) + (array) $fx[ TireSpecs::ATTR_CONSTRUCTION ], $cur['construction'] ) . '</select>',
			__( 'ZR/R = radial, B = belted. Choose to override.', 'motored-core' )
		);
		echo '</div>';
		$this->cell_open( 'front rear both', $pos_cur );
		Ui::field( __( 'Tube type', 'motored-core' ), 'motored-tire-tube', '<select id="motored-tire-tube" name="' . esc_attr( self::FIELD ) . '[tube]">' . $this->options_html( array( '' => '—' ) + (array) $fx[ TireSpecs::ATTR_TUBE ], $cur['tube'] ) . '</select>' );
		echo '</div>';
		echo '</div>';

		// Collapsible optional display override.
		echo '<details class="motored-box__details" ' . $this->cell_attrs( 'front rear both kit', $pos_cur ) . ( '' !== $full_override ? ' open' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
		echo '<summary>' . esc_html__( 'Customize displayed size text (optional)', 'motored-core' ) . '</summary>';
		echo '<div class="motored-narrow">';
		Ui::field(
			__( 'Size text to display (optional)', 'motored-core' ),
			'motored-tire-fullsize',
			'<input id="motored-tire-fullsize" type="text" name="' . esc_attr( Storefront::FULL_SIZE_META ) . '" value="' . esc_attr( $full_override ) . '" placeholder="160/60ZR17" />',
			__( 'Leave empty to generate it automatically from the size, construction and speed rating.', 'motored-core' )
		);
		echo '</div></details>';

		if ( $pid > 0 ) {
			echo '<div class="motored-box__footer" ' . $this->cell_attrs( 'front rear both kit', $pos_cur ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
			$this->render_status( $pid, $cur, $fx );
			echo '</div>';
		}
		Ui::box_close();
	}

	/**
	 * Saves the box: turns the form into the product's global attributes.
	 *
	 * @param mixed $product WC_Product being saved (hook: woocommerce_admin_process_product_object).
	 */
	public function save( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified and sanitized below.
		$raw = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : null;
		if ( null === $raw || empty( $raw['present'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value is only compared.
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}

		$in = array();
		foreach ( array( 'position', 'size', 'rear_size', 'construction', 'speed', 'load', 'tube', 'usage', 'line' ) as $key ) {
			$in[ $key ] = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
		}

		// Explicitly marked "Not a tire": clear managed attributes only if it previously was a tire, never emit errors.
		if ( '' === $in['position'] ) {
			if ( '1' === (string) ( $raw['was_tire'] ?? '0' ) ) {
				$empty_terms = array();
				foreach ( TireAttributePlan::MANAGED as $slug ) {
					$empty_terms[ $slug ] = null;
				}
				$this->apply( $product, $empty_terms );
				$product->delete_meta_data( Storefront::FULL_SIZE_META );
			}
			return;
		}

		// Optional display override.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; sanitized here.
		$full = isset( $_POST[ Storefront::FULL_SIZE_META ] ) && is_string( $_POST[ Storefront::FULL_SIZE_META ] ) ? sanitize_text_field( wp_unslash( $_POST[ Storefront::FULL_SIZE_META ] ) ) : '';
		if ( '' === $full ) {
			$product->delete_meta_data( Storefront::FULL_SIZE_META );
		} else {
			$product->update_meta_data( Storefront::FULL_SIZE_META, $full );
		}

		$plan = TireAttributePlan::build( $in );
		foreach ( $plan['errors'] as $message ) {
			\WC_Admin_Meta_Boxes::add_error( $message );
		}
		if ( ! empty( $plan['errors'] ) && null === $plan['terms'][ TireSpecs::ATTR_POSITION ] ) {
			return;
		}
		if ( null === $plan['terms'][ TireSpecs::ATTR_FRONT_SIZE ] && null === $plan['terms'][ TireSpecs::ATTR_REAR_SIZE ] ) {
			return; // Invalid size: keep what the product already had.
		}
		$this->apply( $product, $plan['terms'] );
	}

	/**
	 * Applies the plan: sets one term per managed attribute and clears the ones the plan leaves empty.
	 *
	 * @param \WC_Product                                       $product Product.
	 * @param array<string,array{name:string,slug:string}|null> $terms   Plan terms.
	 */
	private function apply( \WC_Product $product, array $terms ): void {
		$attrs  = $product->get_attributes( 'edit' );
		$attrs  = is_array( $attrs ) ? $attrs : array();
		$pid    = $product->get_id();
		$is_kit = isset( $terms[ TireSpecs::ATTR_POSITION ]['slug'] ) && 'kit' === $terms[ TireSpecs::ATTR_POSITION ]['slug'];
		$hidden = array( TireSpecs::ATTR_WIDTH, TireSpecs::ATTR_PROFILE, TireSpecs::ATTR_RIM );

		foreach ( TireAttributePlan::MANAGED as $slug ) {
			$taxonomy = AttributeHooks::taxonomy_name( $slug );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$spec = $terms[ $slug ] ?? null;
			if ( null === $spec ) {
				unset( $attrs[ $taxonomy ] );
				if ( $pid > 0 ) {
					wp_set_object_terms( $pid, array(), $taxonomy );
				}
				continue;
			}
			$term_id = AttributeHooks::ensure_term( $taxonomy, $spec['name'], $spec['slug'] );
			if ( $term_id <= 0 ) {
				continue;
			}
			$visible = ! in_array( $slug, $hidden, true )
				&& ( $is_kit || ! in_array( $slug, array( TireSpecs::ATTR_FRONT_SIZE, TireSpecs::ATTR_REAR_SIZE ), true ) );
			$attr    = new \WC_Product_Attribute();
			$attr->set_id( AttributeHooks::attribute_id_by_slug( $slug ) );
			$attr->set_name( $taxonomy );
			$attr->set_options( array( $term_id ) );
			$attr->set_visible( $visible );
			$attr->set_variation( false );
			$attrs[ $taxonomy ] = $attr;
			if ( $pid > 0 ) {
				wp_set_object_terms( $pid, array( $term_id ), $taxonomy, false );
			}
		}

		$position = 0;
		foreach ( $attrs as $attr ) {
			if ( $attr instanceof \WC_Product_Attribute ) {
				$attr->set_position( $position++ );
			}
		}
		$product->set_attributes( $attrs );
	}

	/**
	 * Whether every managed global attribute exists.
	 */
	private function attributes_ready(): bool {
		foreach ( TireAttributePlan::MANAGED as $slug ) {
			if ( ! taxonomy_exists( AttributeHooks::taxonomy_name( $slug ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Current form values, read back from the product's attribute terms (so it also works for existing products).
	 *
	 * @param int $pid Product ID (0 on a brand-new product).
	 * @return array{position:string,size:string,rear_size:string,construction:string,speed:string,load:string,tube:string,usage:string,line:string}
	 */
	private function current_values( int $pid ): array {
		$cur = array(
			'position'     => '',
			'size'         => '',
			'rear_size'    => '',
			'construction' => '',
			'speed'        => '',
			'load'         => '',
			'tube'         => '',
			'usage'        => '',
			'line'         => '',
		);
		if ( $pid <= 0 ) {
			return $cur;
		}
		$first = static function ( string $slug ) use ( $pid ): array {
			$list = AttributeHooks::product_terms( $pid, AttributeHooks::taxonomy_name( $slug ) );
			return $list[0] ?? array(
				'name' => '',
				'slug' => '',
			);
		};

		$cur['position']     = TireSpecs::normalize_position( (string) $first( TireSpecs::ATTR_POSITION )['slug'] );
		$cur['construction'] = TireSpecs::normalize_construction( (string) $first( TireSpecs::ATTR_CONSTRUCTION )['slug'] );
		$speed               = (string) $first( TireSpecs::ATTR_SPEED_RATING )['name'];
		$cur['speed']        = $speed;
		$cur['load']         = (string) $first( TireSpecs::ATTR_LOAD_INDEX )['name'];
		$cur['tube']         = (string) $first( TireSpecs::ATTR_TUBE )['slug'];
		$cur['usage']        = (string) $first( TireSpecs::ATTR_USAGE )['slug'];
		$cur['line']         = (string) $first( TireSpecs::ATTR_LINE )['name'];

		$front = (string) $first( TireSpecs::ATTR_FRONT_SIZE )['name'];
		$rear  = (string) $first( TireSpecs::ATTR_REAR_SIZE )['name'];
		if ( TireSpecs::POS_KIT === $cur['position'] ) {
			$cur['size']      = $front;
			$cur['rear_size'] = $rear;
			return $cur;
		}
		$main = '' !== $front ? $front : $rear;
		if ( '' === $main ) {
			// Product filled in the old way: rebuild the size from Width / Aspect ratio / Rim.
			$w = (string) $first( TireSpecs::ATTR_WIDTH )['name'];
			$p = (string) $first( TireSpecs::ATTR_PROFILE )['name'];
			$r = (string) $first( TireSpecs::ATTR_RIM )['name'];
			if ( '' !== $w && '' !== $r ) {
				$main = '' !== $p ? $w . '/' . $p . '-' . $r : $w . '-' . $r;
			}
		}
		$parsed = '' !== $main ? SizeParser::parse( $main ) : null;
		if ( null !== $parsed ) {
			$main = SizeParser::format_full_size( $parsed, $cur['construction'], $cur['speed'] );
		}
		$cur['size'] = $main;
		return $cur;
	}

	/**
	 * Storefront preview + compatible motorcycles for this tire.
	 *
	 * @param int                                    $pid Product ID.
	 * @param array<string,string>                   $cur Current parsed values.
	 * @param array<string,array<int|string,string>> $fx  Fixed attribute labels.
	 */
	private function render_status( int $pid, array $cur, array $fx ): void {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( $product instanceof \WC_Product && null !== Storefront::fit_for_product( $pid ) ) {
			$preview_parts = array();
			$full          = Storefront::resolve_full_size_label( $product );
			if ( '' !== $full ) {
				$preview_parts[] = $full;
			}
			$pos_slug  = TireAttributePlan::POSITION_TERMS[ $cur['position'] ] ?? '';
			$pos_label = '' !== $pos_slug ? (string) ( $fx[ TireSpecs::ATTR_POSITION ][ $pos_slug ] ?? '' ) : '';
			if ( '' !== $pos_label ) {
				$preview_parts[] = $pos_label;
			}
			if ( TireSpecs::POS_KIT !== $cur['position'] ) {
				$load_speed = TireSpecs::format_load_speed( TireSpecs::parse_load_index( $cur['load'] ), $cur['speed'] );
				if ( '' !== $load_speed ) {
					$preview_parts[] = $load_speed;
				}
			}
			if ( ! empty( $preview_parts ) ) {
				echo '<p class="motored-box__preview"><strong>' . esc_html__( 'Storefront summary:', 'motored-core' ) . '</strong> ' . esc_html( implode( ' · ', $preview_parts ) ) . '</p>';
			}
		}

		echo '<h4 class="motored-box__title">' . esc_html__( 'Compatible motorcycles', 'motored-core' ) . '</h4>';
		if ( 'publish' !== get_post_status( $pid ) ) {
			echo '<p class="description">' . esc_html__( 'The tire is linked to motorcycles once the product is published.', 'motored-core' ) . '</p>';
			return;
		}
		$res = MatchEngine::compatible_motos_for_product( $pid );
		if ( null === $res ) {
			echo '<p>' . esc_html__( 'Not linked yet. Choose the position, enter the size and update the product.', 'motored-core' ) . '</p>';
			return;
		}
		$items = $res['items'];
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'The size is valid, but no published motorcycle in the catalog uses it yet.', 'motored-core' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of compatible motorcycles. */
				__( 'Compatible motorcycles found: %d', 'motored-core' ),
				count( $items )
			)
		) . '</p><ul class="motored-box__motos">';
		foreach ( array_slice( $items, 0, self::MAX_LISTED ) as $item ) {
			$variant = trim( (string) ( $item['variant'] ?? '' ) );
			echo '<li>' . esc_html( trim( (string) $item['brand'] . ' ' . (string) $item['model'] . ( '' !== $variant ? ' ' . $variant : '' ) . ' ' . (string) $item['years'] ) ) . '</li>';
		}
		if ( count( $items ) > self::MAX_LISTED ) {
			echo '<li>' . esc_html(
				sprintf(
					/* translators: %d: number of motorcycles not listed. */
					__( '...and %d more.', 'motored-core' ),
					count( $items ) - self::MAX_LISTED
				)
			) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Opens a field cell shown only for the given positions (server-side initial state; the script updates it live).
	 *
	 * @param string $positions Space-separated positions this cell applies to.
	 * @param string $current   Currently selected position ('' = none).
	 */
	private function cell_open( string $positions, string $current ): void {
		echo '<div class="motored-box__cell" ' . $this->cell_attrs( $positions, $current ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
	}

	/**
	 * Attributes of a position-dependent element.
	 *
	 * @param string $positions Space-separated positions (or "none" for the empty state).
	 * @param string $current   Currently selected position.
	 */
	private function cell_attrs( string $positions, string $current ): string {
		$key = '' === $current ? 'none' : $current;
		return 'data-motored-for="' . esc_attr( $positions ) . '"' . ( in_array( $key, explode( ' ', $positions ), true ) ? '' : ' hidden' );
	}

	/**
	 * Builds <option> markup for a value => label map.
	 *
	 * @param array<int|string,string> $options  Options.
	 * @param string                   $selected Selected value.
	 */
	private function options_html( array $options, string $selected ): string {
		$out = '';
		foreach ( $options as $value => $label ) {
			$out .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( $selected, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $out;
	}
}
