<?php
/**
 * Storefront: the banner at the top of the product summary.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ProductBanner;

defined( 'ABSPATH' ) || exit;

/**
 * Prints `.motored-promo-banner` (contract 1.10). The image is printed at full size so animated GIFs keep
 * their animation (resized GIFs lose it). Assets load only when a banner is printed.
 */
final class BannerDisplay {

	public const STYLE_HANDLE  = 'motored-product-banner';
	public const SCRIPT_HANDLE = 'motored-product-banner';
	private const ASSET_VER    = '-banner1';

	/**
	 * Hook callback.
	 */
	public function render(): void {
		$product = $GLOBALS['product'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in html().
		echo $this->html( $product );
	}

	/**
	 * Banner markup for a product ('' when none applies).
	 *
	 * @param \WC_Product $product Product.
	 */
	public function html( \WC_Product $product ): string {
		$banner = BannerResolver::resolve( Config::own( $product ), Config::general(), time() );
		if ( null === $banner ) {
			return '';
		}
		$src = wp_get_attachment_image_url( $banner['image_id'], 'full' );
		if ( ! is_string( $src ) || '' === $src ) {
			return '';
		}
		$meta = wp_get_attachment_metadata( $banner['image_id'] );
		$alt  = get_post_meta( $banner['image_id'], '_wp_attachment_image_alt', true );
		$img  = sprintf(
			'<img class="motored-promo-banner__img" src="%1$s" alt="%2$s"%3$s decoding="async" />',
			esc_url( $src ),
			esc_attr( is_string( $alt ) ? $alt : '' ),
			is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] )
				? ' width="' . (int) $meta['width'] . '" height="' . (int) $meta['height'] . '"'
				: ''
		);
		if ( '' !== $banner['link'] ) {
			$img = '<a class="motored-promo-banner__link" href="' . esc_url( $banner['link'] ) . '">' . $img . '</a>';
		}

		wp_enqueue_style( self::STYLE_HANDLE, MOTORED_CORE_URL . 'assets/css/product-banner.css', array(), MOTORED_CORE_VERSION . self::ASSET_VER );
		$countdown = '';
		if ( $banner['countdown'] ) {
			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				MOTORED_CORE_URL . 'assets/js/product-banner.js',
				array(),
				MOTORED_CORE_VERSION . self::ASSET_VER,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
			$countdown = $this->countdown( $banner['until'] );
		}
		return '<div class="motored-promo-banner">' . $img . $countdown . '</div>';
	}

	/**
	 * "Termina en 01d : 10h : 41m : 15s"; product-banner.js keeps it ticking and hides the banner at zero.
	 *
	 * @param int $until End timestamp.
	 */
	private function countdown( int $until ): string {
		$left  = BannerResolver::remaining( $until, time() );
		$units = array(
			'd' => _x( 'd', 'countdown days', 'motored-core' ),
			'h' => _x( 'h', 'countdown hours', 'motored-core' ),
			'm' => _x( 'm', 'countdown minutes', 'motored-core' ),
			's' => _x( 's', 'countdown seconds', 'motored-core' ),
		);
		$out = '';
		foreach ( $units as $key => $label ) {
			$out .= '<span class="motored-promo-banner__unit"><span class="motored-promo-banner__num" data-u="' . esc_attr( $key ) . '">'
				. esc_html( str_pad( (string) $left[ $key ], 2, '0', STR_PAD_LEFT ) ) . '</span><span class="motored-promo-banner__lbl">' . esc_html( $label ) . '</span></span>';
		}
		return '<p class="motored-promo-banner__countdown" data-motored-countdown="' . esc_attr( (string) ( $until * 1000 ) ) . '">'
			. '<span class="motored-promo-banner__ends">' . esc_html__( 'Ends in', 'motored-core' ) . '</span> ' . $out . '</p>';
	}
}
