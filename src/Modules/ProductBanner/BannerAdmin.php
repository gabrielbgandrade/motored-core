<?php
/**
 * Admin side: the "Banners" settings section and the "Product banner" box of the product screen.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ProductBanner;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Admin\Ui;
use Motored\Core\Integration\WooCommerce\AttributeHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Only instantiated in admin requests. The image picker is the native media modal (`wp.media`).
 */
final class BannerAdmin {

	public const SECTION      = 'product_banner';
	public const FIELD        = 'motored_banner';
	public const NONCE_FIELD  = 'motored_banner_nonce';
	public const NONCE_ACTION = 'motored_banner_box';
	public const HANDLE       = 'motored-product-banner-admin';
	public const IMAGE_TYPE   = 'motored_banner_image';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		SectionRegistry::add( self::SECTION, static fn(): string => __( 'Banners', 'motored-core' ), array( $this, 'settings' ), null, 45 );
		add_action( 'woocommerce_admin_field_' . self::IMAGE_TYPE, array( $this, 'render_image_setting' ) );
		AttributeHooks::register_product_box_hooks( array( $this, 'add_meta_box' ), array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Fields of WooCommerce > Settings > Motored > Banners.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		return array(
			array(
				'title' => __( 'Product page banner', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'An image or animated GIF at the top of every product page (a sale, free installation, a launch). Each product can use this banner, its own or none (Product data > "Product banner" box).', 'motored-core' ),
				'id'    => 'motored_product_banner_title',
			),
			array(
				'title'    => __( 'Show the general banner', 'motored-core' ),
				'desc'     => __( 'Print it on every product that does not choose its own or none.', 'motored-core' ),
				'id'       => 'motored_product_banner[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Image', 'motored-core' ),
				'id'       => 'motored_product_banner[image_id]',
				'type'     => self::IMAGE_TYPE,
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'    => __( 'Link', 'motored-core' ),
				'desc_tip' => __( 'Optional. Where the banner goes when clicked (e.g. the sale category).', 'motored-core' ),
				'id'       => 'motored_product_banner[link]',
				'type'     => 'url',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'    => __( 'Ends on', 'motored-core' ),
				'desc_tip' => __( 'Optional, site time. After this moment the banner disappears by itself.', 'motored-core' ),
				'id'       => 'motored_product_banner[until]',
				'type'     => 'datetime-local',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'    => __( 'Countdown', 'motored-core' ),
				'desc'     => __( 'Show "Ends in 01d : 10h : 41m : 15s" under the banner when it has an end date.', 'motored-core' ),
				'id'       => 'motored_product_banner[countdown]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['countdown'],
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_product_banner_title',
			),
		);
	}

	/**
	 * Image picker row of the settings section.
	 *
	 * @param array<string,mixed> $field WooCommerce field.
	 */
	public function render_image_setting( $field ): void {
		$id = is_scalar( \Motored\Core\Support\Options::get( Config::OPTION_KEY, 'image_id', '' ) ) ? (int) \Motored\Core\Support\Options::get( Config::OPTION_KEY, 'image_id', '' ) : 0;
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( (string) ( $field['title'] ?? '' ) ) . '</th><td class="forminp">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in picker().
		echo $this->picker( 'motored_product_banner[image_id]', 'motored-banner-general', $id );
		echo '</td></tr>';
	}

	/**
	 * Adds the box in the side column of the product screen.
	 */
	public function add_meta_box(): void {
		add_meta_box( 'motored-product-banner', __( 'Product banner', 'motored-core' ), array( $this, 'render' ), 'product', 'side', 'low' );
	}

	/**
	 * Renders the product box.
	 *
	 * @param mixed $post Current post.
	 */
	public function render( $post ): void {
		$pid     = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		$product = $pid > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		$raw     = $product instanceof \WC_Product ? $product->get_meta( Config::META_KEY ) : array();
		$raw     = is_array( $raw ) ? $raw : array();
		$mode    = Config::mode( $raw['mode'] ?? '' );
		$name    = self::FIELD;
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		Ui::box_open( true );
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[present]" value="1" />';
		$options = '';
		foreach (
			array(
				Config::MODE_DEFAULT => __( 'Use the general banner', 'motored-core' ),
				Config::MODE_OWN     => __( 'Use this product\'s banner', 'motored-core' ),
				Config::MODE_NONE    => __( 'No banner', 'motored-core' ),
			) as $value => $label
		) {
			$options .= '<option value="' . esc_attr( $value ) . '"' . selected( $mode, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		Ui::field( __( 'Banner', 'motored-core' ), 'motored-banner-mode', '<select id="motored-banner-mode" name="' . esc_attr( $name ) . '[mode]">' . $options . '</select>' );
		Ui::field( __( 'Image', 'motored-core' ), 'motored-banner-own', $this->picker( $name . '[image_id]', 'motored-banner-own', isset( $raw['image_id'] ) ? (int) $raw['image_id'] : 0 ), __( 'Used only with "Use this product\'s banner". JPG, PNG, WebP or animated GIF.', 'motored-core' ) );
		Ui::field( __( 'Link', 'motored-core' ), 'motored-banner-link', '<input type="url" id="motored-banner-link" name="' . esc_attr( $name ) . '[link]" value="' . esc_attr( (string) ( $raw['link'] ?? '' ) ) . '" placeholder="https://" />' );
		Ui::field( __( 'Ends on', 'motored-core' ), 'motored-banner-until', '<input type="datetime-local" id="motored-banner-until" name="' . esc_attr( $name ) . '[until]" value="' . esc_attr( (string) ( $raw['until'] ?? '' ) ) . '" />' );
		Ui::field( __( 'Countdown', 'motored-core' ), 'motored-banner-countdown', '<label><input type="checkbox" id="motored-banner-countdown" name="' . esc_attr( $name ) . '[countdown]" value="1"' . checked( ! empty( $raw['countdown'] ), true, false ) . ' /> ' . esc_html__( 'Show it when there is an end date', 'motored-core' ) . '</label>' );
		Ui::box_close();
	}

	/**
	 * Saves the box through the WooCommerce product CRUD (HPOS safe).
	 *
	 * @param mixed $product WC_Product being saved.
	 */
	public function save( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified right below.
		$posted = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : null;
		if ( null === $posted || empty( $posted['present'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified right below.
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}
		$image = isset( $posted['image_id'] ) && is_scalar( $posted['image_id'] ) ? absint( $posted['image_id'] ) : 0;
		if ( $image > 0 && ! wp_attachment_is_image( $image ) ) {
			$image = 0;
		}
		$until = isset( $posted['until'] ) && is_scalar( $posted['until'] ) ? sanitize_text_field( (string) $posted['until'] ) : '';
		$data  = array(
			'mode'      => Config::mode( $posted['mode'] ?? '' ),
			'image_id'  => $image,
			'link'      => isset( $posted['link'] ) && is_scalar( $posted['link'] ) ? esc_url_raw( (string) $posted['link'] ) : '',
			'until'     => 0 !== Config::timestamp( $until ) ? $until : '',
			'countdown' => ! empty( $posted['countdown'] ),
		);
		if ( Config::MODE_DEFAULT === $data['mode'] && 0 === $data['image_id'] && '' === $data['link'] && '' === $data['until'] ) {
			$product->delete_meta_data( Config::META_KEY );
			return;
		}
		$product->update_meta_data( Config::META_KEY, $data );
	}

	/**
	 * Picker script on the product screen and the Banners settings section only.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		$section     = isset( $_GET['section'] ) && is_string( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		$is_product  = 'product' === $screen->id && 'post' === $screen->base;
		$is_settings = 'woocommerce_page_wc-settings' === $screen->id && self::SECTION === $section;
		if ( ! $is_product && ! $is_settings ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( self::HANDLE, MOTORED_CORE_URL . 'assets/admin/motored-product-banner.js', array( 'media-editor', 'media-views' ), MOTORED_CORE_VERSION . '-banner1', true );
	}

	/**
	 * Hidden attachment id + preview + "Choose image" / "Remove" (motored-product-banner.js).
	 *
	 * @param string $name Input name.
	 * @param string $id   Input id.
	 * @param int    $attachment_id Current attachment.
	 */
	private function picker( string $name, string $id, int $attachment_id ): string {
		$preview = $attachment_id > 0 ? wp_get_attachment_image( $attachment_id, 'medium', false, array( 'class' => 'motored-box__preview' ) ) : '';
		return '<div class="motored-box__picker" data-motored-banner-picker data-title="' . esc_attr__( 'Banner image', 'motored-core' ) . '" data-button="' . esc_attr__( 'Use this image', 'motored-core' ) . '">'
			. '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $attachment_id > 0 ? (string) $attachment_id : '' ) . '" />'
			. '<span class="motored-box__picker-preview" data-preview>' . $preview . '</span>'
			. '<span class="motored-actions"><button type="button" class="button" data-choose>' . esc_html__( 'Choose image', 'motored-core' ) . '</button> '
			. '<button type="button" class="button-link button-link-delete" data-remove' . ( $attachment_id > 0 ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'motored-core' ) . '</button></span>'
			. '</div>';
	}
}
