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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the technical information box script on product edit screens.
	 */
	public function enqueue(): void {
		$screen = get_current_screen();
		if ( null !== $screen && 'product' === $screen->id && 'post' === $screen->base ) {
			wp_enqueue_script(
				'motored-tire-box',
				MOTORED_CORE_URL . 'assets/admin/motored-tire-box.js',
				array(),
				MOTORED_CORE_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
	}

	/**
	 * Adds the meta box in the main column of the product screen (below WooCommerce Product data).
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'motored-tire',
			__( 'Technical information', 'motored-core' ),
			array( $this, 'render' ),
			'product',
			'normal',
			'default'
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

		if ( $pid > 0 && ! AttributeHooks::is_simple_product( $pid ) ) {
			echo '<p class="description">' . esc_html__( 'Tire compatibility applies to simple products only. Switch Product data to Simple product to link this tire to motorcycles.', 'motored-core' ) . '</p>';
			Ui::box_close();
			return;
		}

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
		/* translators: %s: maximum weight in kilograms per tire. */
		echo '<div class="motored-box__pos" data-motored-load-kg="' . esc_attr( (string) wp_json_encode( TireSpecs::LOAD_KG ) ) . '" data-motored-kg-label="' . esc_attr__( 'Up to %s kg per tire', 'motored-core' ) . '" data-motored-kg-empty="' . esc_attr__( 'e.g. 69 = 325 kg', 'motored-core' ) . '"><label class="screen-reader-text" for="motored-tire-pos">' . esc_html__( 'Position', 'motored-core' ) . '</label>';
		echo '<select id="motored-tire-pos" data-motored-pos="1" name="' . esc_attr( self::FIELD ) . '[position]">' . $pos_opts . '</select></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

		echo '<p class="description" ' . $this->cell_attrs( 'none', $pos_cur ) . '>' . esc_html__( 'Choose the position above if this product is a motorcycle tire. The fields to fill in appear right away.', 'motored-core' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().

		if ( ! Config::enabled() ) {
			echo '<div class="notice notice-warning inline" ' . $this->cell_attrs( 'front rear both kit', $pos_cur ) . '><p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
			echo esc_html__( 'This sheet is saved, but the store does not show it yet: Compatibility is turned off.', 'motored-core' ) . ' ';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=motored&section=' . CompatibilityAdmin::SECTION ) ) . '">' . esc_html__( 'Turn it on in WooCommerce > Settings > Motored > Compatibility.', 'motored-core' ) . '</a>';
			echo '</p></div>';
		}

		// What this box is for, per position.
		echo '<p class="description motored-box__intro" ' . $this->cell_attrs( 'front rear both', $pos_cur ) . '>' . esc_html__( 'Fill in the tire as printed on its sidewall. These data build the Technical information tab of the store and link the tire to the motorcycles that use its size.', 'motored-core' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
		echo '<p class="description motored-box__intro" ' . $this->cell_attrs( 'kit', $pos_cur ) . '>' . esc_html__( 'A kit has two tires: fill in one sheet per tire. The kit is shown for a motorcycle only when the front tire fits its front wheel and the rear tire fits its rear wheel.', 'motored-core' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().

		// Section 1: the tire (single) or the front tire (kit).
		echo '<div class="motored-box__panel" ' . $this->cell_attrs( 'front rear both kit', $pos_cur ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
		echo '<div class="motored-box__panel-head" ' . $this->cell_attrs( 'kit', $pos_cur ) . '><h4 class="motored-box__panel-title">' . esc_html__( 'Front tire', 'motored-core' ) . '</h4></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
		$this->render_axle_fields(
			'',
			array(
				'size'         => $cur['size'],
				'line'         => $cur['line'],
				'load'         => $cur['load'],
				'speed'        => $cur['speed'],
				'construction' => $cur['construction'],
				'tube'         => $cur['tube'],
			),
			$fx
		);
		echo '</div>';

		// Section 2 (kits): the rear tire, with its own sheet.
		echo '<div class="motored-box__panel" ' . $this->cell_attrs( 'kit', $pos_cur ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
		echo '<div class="motored-box__panel-head"><h4 class="motored-box__panel-title">' . esc_html__( 'Rear tire', 'motored-core' ) . '</h4>';
		echo '<button type="button" class="button button-small" data-motored-copy-axle="1">' . esc_html__( 'Copy model, construction and tube from the front tire', 'motored-core' ) . '</button></div>';
		$this->render_axle_fields(
			'rear_',
			array(
				'size'         => $cur['rear_size'],
				'line'         => $cur['rear_line'],
				'load'         => $cur['rear_load'],
				'speed'        => $cur['rear_speed'],
				'construction' => $cur['rear_construction'],
				'tube'         => $cur['rear_tube'],
			),
			$fx
		);
		echo '</div>';

		// Section 3: whole-product optional fields (compact row, no boxed card).
		echo '<div class="motored-box__panel" ' . $this->cell_attrs( 'front rear both kit', $pos_cur ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
		echo '<div class="motored-box__grid">';
		echo '<div class="motored-box__cell">';
		Ui::field( __( 'Riding style', 'motored-core' ), 'motored-tire-usage', '<select id="motored-tire-usage" name="' . esc_attr( self::FIELD ) . '[usage]">' . $this->options_html( array( '' => '—' ) + (array) $fx[ TireSpecs::ATTR_USAGE ], $cur['usage'] ) . '</select>' );
		echo '</div><div class="motored-box__cell">';
		Ui::field(
			__( 'Size text to display (optional)', 'motored-core' ),
			'motored-tire-fullsize',
			'<input id="motored-tire-fullsize" type="text" name="' . esc_attr( Storefront::FULL_SIZE_META ) . '" value="' . esc_attr( $full_override ) . '" placeholder="120/70ZR17 + 180/55ZR17" />',
			__( 'Leave empty to build it from the sizes.', 'motored-core' )
		);
		echo '</div></div>';
		echo '</div>';

		if ( $pid > 0 ) {
			echo '<div class="motored-box__footer" ' . $this->cell_attrs( 'front rear both kit', $pos_cur ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell_attrs().
			$this->render_status( $pid, $cur, $fx );
			echo '</div>';
		}
		Ui::box_close();
	}

	/**
	 * Renders the fields of one tire (the single tire, or one axle of a kit): size and model, then the ratings.
	 *
	 * @param string                                 $prefix Form key prefix ('' = front/single, 'rear_' = kit rear).
	 * @param array<string,string>                   $vals   Current values (size, line, load, speed, construction, tube).
	 * @param array<string,array<int|string,string>> $fx     Fixed attribute labels.
	 */
	private function render_axle_fields( string $prefix, array $vals, array $fx ): void {
		$id   = '' === $prefix ? 'motored-tire-' : 'motored-tire-rear-';
		$name = static fn( string $key ): string => esc_attr( self::FIELD . '[' . ( 'size' === $key && '' !== $prefix ? 'rear_size' : $prefix . $key ) . ']' );
		// The rear size keeps its historic id so saved browser autofill and docs stay valid.
		$size_id = '' === $prefix ? 'motored-tire-size' : 'motored-tire-rear';
		$example = '' === $prefix ? '120/70ZR17 58W' : '180/55ZR17 73W';

		echo '<div class="motored-box__grid motored-box__grid--wide">';
		echo '<div class="motored-box__cell">';
		Ui::field(
			__( 'Size (as printed on the tire)', 'motored-core' ),
			$size_id,
			'<input id="' . esc_attr( $size_id ) . '" type="text" name="' . $name( 'size' ) . '" value="' . esc_attr( $vals['size'] ) . '" placeholder="' . esc_attr( $example ) . '" data-motored-size="' . esc_attr( '' === $prefix ? 'front' : 'rear' ) . '" />',
			/* translators: %s: example tire size with load and speed, e.g. 120/70ZR17 58W. */
			sprintf( __( 'For example %s. Load and speed typed after the size fill their fields.', 'motored-core' ), $example )
		);
		echo '</div><div class="motored-box__cell">';
		Ui::field( __( 'Model (optional)', 'motored-core' ), $id . 'line', '<input id="' . esc_attr( $id . 'line' ) . '" type="text" name="' . $name( 'line' ) . '" value="' . esc_attr( $vals['line'] ) . '" />' );
		echo '</div></div>';

		echo '<div class="motored-box__grid motored-box__grid--ratings">';
		echo '<div class="motored-box__cell">';
		$kg        = '' !== $vals['load'] && null !== TireSpecs::parse_load_index( $vals['load'] ) ? TireSpecs::load_kg( (int) $vals['load'] ) : null;
		$load_hint = null !== $kg
			? sprintf(
				/* translators: %s: maximum weight in kilograms per tire. */
				__( 'Up to %s kg per tire', 'motored-core' ),
				(string) $kg
			)
			: __( 'e.g. 69 = 325 kg', 'motored-core' );
		Ui::field(
			__( 'Load index', 'motored-core' ),
			$id . 'load',
			'<input id="' . esc_attr( $id . 'load' ) . '" type="number" min="20" max="120" name="' . $name( 'load' ) . '" value="' . esc_attr( $vals['load'] ) . '" data-motored-load="1" />'
			. '<span class="description motored-field__hint' . ( null !== $kg ? ' motored-field__hint--active' : '' ) . '" data-motored-load-hint="1">' . esc_html( $load_hint ) . '</span>'
		);
		echo '</div><div class="motored-box__cell">';
		Ui::field( __( 'Speed rating', 'motored-core' ), $id . 'speed', '<select id="' . esc_attr( $id . 'speed' ) . '" name="' . $name( 'speed' ) . '">' . $this->speed_options( $vals['speed'] ) . '</select>' );
		echo '</div><div class="motored-box__cell">';
		Ui::field(
			__( 'Construction', 'motored-core' ),
			$id . 'const',
			'<select id="' . esc_attr( $id . 'const' ) . '" name="' . $name( 'construction' ) . '">' . $this->options_html( array( '' => __( 'From the size', 'motored-core' ) ) + (array) $fx[ TireSpecs::ATTR_CONSTRUCTION ], $vals['construction'] ) . '</select>'
		);
		echo '</div><div class="motored-box__cell">';
		Ui::field( __( 'Tube type', 'motored-core' ), $id . 'tube', '<select id="' . esc_attr( $id . 'tube' ) . '" name="' . $name( 'tube' ) . '">' . $this->options_html( array( '' => '—' ) + (array) $fx[ TireSpecs::ATTR_TUBE ], $vals['tube'] ) . '</select>' );
		echo '</div></div>';
	}

	/**
	 * Speed rating <option> list (VR/ZR only when already stored, they are legacy codes).
	 *
	 * @param string $current Selected code.
	 */
	private function speed_options( string $current ): string {
		$out = '<option value="">&mdash;</option>';
		foreach ( array_keys( TireSpecs::SPEED_RANKS ) as $code ) {
			if ( in_array( $code, array( 'VR', 'ZR' ), true ) && $current !== $code ) {
				continue;
			}
			$kmh   = TireSpecs::SPEED_KMH[ $code ] ?? null;
			$label = null !== $kmh
				? ( '(W)' === $code ? $code . ' — +' . $kmh . ' km/h' : $code . ' — ' . $kmh . ' km/h' )
				: $code;
			$out  .= '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $out;
	}

	/**
	 * Saves the box: turns the form into the product's global attributes (+ the per-axle sheet of a kit).
	 *
	 * @param mixed $product WC_Product being saved (hook: woocommerce_admin_process_product_object).
	 */
	public function save( $product ): void {
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
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
		foreach ( array( 'position', 'size', 'rear_size', 'construction', 'speed', 'load', 'tube', 'usage', 'line', 'rear_line', 'rear_load', 'rear_speed', 'rear_construction', 'rear_tube' ) as $key ) {
			$in[ $key ] = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
		}

		// Explicitly marked "Not a tire": clear managed attributes only if it previously was a tire, never emit errors.
		if ( '' === $in['position'] ) {
			if ( '1' === (string) ( $raw['was_tire'] ?? '0' ) ) {
				$empty_terms = array();
				foreach ( TireAttributePlan::MANAGED as $slug ) {
					$empty_terms[ $slug ] = null;
				}
				$this->apply( $product, $empty_terms, array() );
				$product->delete_meta_data( Storefront::FULL_SIZE_META );
				$product->delete_meta_data( KitSpec::META );
			}
			return;
		}

		$plan = TireAttributePlan::build( $in );
		if ( ! empty( $plan['errors'] ) ) {
			foreach ( $plan['errors'] as $message ) {
				\WC_Admin_Meta_Boxes::add_error( $message );
			}
			return; // Keep existing attributes and display override intact when validation fails.
		}
		if ( null === $plan['terms'][ TireSpecs::ATTR_FRONT_SIZE ] && null === $plan['terms'][ TireSpecs::ATTR_REAR_SIZE ] ) {
			return;
		}

		// Optional display override (saved only after validation passes).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; sanitized here.
		$full = isset( $_POST[ Storefront::FULL_SIZE_META ] ) && is_string( $_POST[ Storefront::FULL_SIZE_META ] ) ? sanitize_text_field( wp_unslash( $_POST[ Storefront::FULL_SIZE_META ] ) ) : '';
		if ( '' === $full ) {
			$product->delete_meta_data( Storefront::FULL_SIZE_META );
		} else {
			$product->update_meta_data( Storefront::FULL_SIZE_META, $full );
		}

		$this->apply( $product, $plan['terms'], $plan['extra_terms'] );
		if ( null !== $plan['spec'] ) {
			$product->update_meta_data( KitSpec::META, $plan['spec'] );
		} else {
			$product->delete_meta_data( KitSpec::META );
		}
	}

	/**
	 * Applies the plan: sets the terms of every managed attribute and clears the ones the plan leaves empty.
	 *
	 * @param \WC_Product                                        $product Product.
	 * @param array<string,array{name:string,slug:string}|null>  $terms   Plan terms.
	 * @param array<string,list<array{name:string,slug:string}>> $extra   Additional terms per attribute.
	 */
	private function apply( \WC_Product $product, array $terms, array $extra ): void {
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
			$term_ids = array();
			foreach ( array_merge( array( $spec ), $extra[ $slug ] ?? array() ) as $one ) {
				$term_id = AttributeHooks::ensure_term( $taxonomy, $one['name'], $one['slug'] );
				if ( $term_id > 0 ) {
					$term_ids[] = $term_id;
				}
			}
			if ( empty( $term_ids ) ) {
				continue;
			}
			$visible = ! in_array( $slug, $hidden, true )
				&& ( $is_kit || ! in_array( $slug, array( TireSpecs::ATTR_FRONT_SIZE, TireSpecs::ATTR_REAR_SIZE ), true ) );
			$attr    = new \WC_Product_Attribute();
			$attr->set_id( AttributeHooks::attribute_id_by_slug( $slug ) );
			$attr->set_name( $taxonomy );
			$attr->set_options( $term_ids );
			$attr->set_visible( $visible );
			$attr->set_variation( false );
			$attrs[ $taxonomy ] = $attr;
			if ( $pid > 0 ) {
				wp_set_object_terms( $pid, $term_ids, $taxonomy, false );
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
	 * Current form values: the kit sheet meta when present, otherwise the product's attribute terms
	 * (so it also works for products filled in before the box existed).
	 *
	 * @param int $pid Product ID (0 on a brand-new product).
	 * @return array<string,string>
	 */
	private function current_values( int $pid ): array {
		$cur = array_fill_keys(
			array( 'position', 'size', 'rear_size', 'construction', 'speed', 'load', 'tube', 'usage', 'line', 'rear_line', 'rear_load', 'rear_speed', 'rear_construction', 'rear_tube' ),
			''
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
			$cur['rear_line'] = $cur['line'];
			$spec             = KitSpec::normalize( get_post_meta( $pid, KitSpec::META, true ) );
			if ( null === $spec ) {
				// Kit filled in before the per-axle sheet: only shared values are known.
				$cur['load']              = '';
				$cur['speed']             = '';
				$cur['rear_construction'] = $cur['construction'];
				$cur['rear_tube']         = $cur['tube'];
				return $cur;
			}
			foreach ( array(
				TireSpecs::POS_FRONT => '',
				TireSpecs::POS_REAR  => 'rear_',
			) as $axle => $prefix ) {
				$a                               = $spec[ $axle ];
				$size_key                        = '' === $prefix ? 'size' : 'rear_size';
				$cur[ $size_key ]                = $a['designation'];
				$cur[ $prefix . 'line' ]         = $a['line'];
				$cur[ $prefix . 'load' ]         = null !== $a['load'] ? (string) $a['load'] : '';
				$cur[ $prefix . 'speed' ]        = $a['speed'];
				$cur[ $prefix . 'construction' ] = $a['construction'];
				$cur[ $prefix . 'tube' ]         = $a['tube'];
			}
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
			} else {
				$codes = array(
					__( 'Front', 'motored-core' ) => TireSpecs::format_load_speed( TireSpecs::parse_load_index( $cur['load'] ), $cur['speed'] ),
					__( 'Rear', 'motored-core' )  => TireSpecs::format_load_speed( TireSpecs::parse_load_index( $cur['rear_load'] ), $cur['rear_speed'] ),
				);
				foreach ( $codes as $axle_label => $code ) {
					if ( '' !== $code ) {
						$preview_parts[] = $axle_label . ' ' . $code;
					}
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
			$label   = trim( (string) $item['brand'] . ' ' . (string) $item['model'] . ( '' !== $variant ? ' ' . $variant : '' ) . ' ' . (string) $item['years'] );
			$vid     = isset( $item['version_id'] ) ? (int) $item['version_id'] : 0;
			$url     = add_query_arg(
				$vid > 0
					? array(
						'post_type'    => 'product',
						'page'         => CompatibilityAdmin::PAGE,
						'tab'          => 'versions',
						'edit_version' => $vid,
					)
					: array(
						'post_type' => 'product',
						'page'      => CompatibilityAdmin::PAGE,
						'tab'       => 'versions',
						's'         => (string) ( $item['slug'] ?? '' ),
					),
				admin_url( 'edit.php' )
			);
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
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
