<?php
namespace Motored\Core\Tests\Unit\Modules\InstallGallery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\InstallGallery\Config;
use Motored\Core\Modules\InstallGallery\GalleryDisplay;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class GalleryDisplayTest extends TestCase {

	private function product( array $meta ): void {
		if ( ! defined( 'MOTORED_CORE_URL' ) ) {
			define( 'MOTORED_CORE_URL', 'https://example.test/wp-content/plugins/motored-core/' );
			define( 'MOTORED_CORE_VERSION', '0.0.0' );
		}
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		Functions\when( 'get_the_ID' )->justReturn( 50 );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $meta[ $key ] ?? '' );
	}

	private function render_stubs(): void {
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static fn( $id, $size = 'large' ) => 'https://x.test/p' . $id . '-' . $size . '.jpg'
		);
		Functions\when( 'wp_get_attachment_caption' )->alias( fn( $id ) => 1 === $id ? 'Yamaha MT-07' : '' );
		Functions\when( 'wp_get_attachment_image' )->alias( fn( $id, $size = '', $icon = false, $attr = array() ) => '<img alt="' . ( $attr['alt'] ?? '' ) . '" src="https://x.test/p' . $id . '-thumb.jpg">' );
		Functions\when( 'get_the_title' )->justReturn( 'Pirelli Diablo Rosso' );
		Functions\when( 'get_permalink' )->justReturn( 'https://x.test/producto/neumatico/' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
	}

	public function test_product_without_photos_costs_no_option_read_and_prints_nothing(): void {
		$this->product( array() );
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		$this->assertSame( '', ( new GalleryDisplay() )->shortcode() );
	}

	public function test_disabled_gallery_prints_nothing(): void {
		$this->fake_storage();
		$this->options['motored_install_gallery'] = array( 'enabled' => 'no' );
		$this->product( array( '_motored_install_photos' => array( 5 ) ) );
		Functions\expect( 'wp_enqueue_script' )->never();
		$this->assertSame( '', ( new GalleryDisplay() )->shortcode() );
	}

	public function test_prints_strip_with_chip_hidden_links_filters_and_assets(): void {
		$this->fake_storage();
		$this->options['motored_install_gallery'] = array( 'visible' => '4' );
		$this->product(
			array(
				'_motored_install_photos' => array( 1, 2, 3 ),
				'_motored_review_gallery' => array( array( 'id' => 9, 'c' => 31, 'r' => 5 ), array( 'id' => 10, 'c' => 32, 'r' => 4 ) ),
			)
		);
		$this->render_stubs();
		Functions\expect( 'wp_enqueue_style' )->once()->with( GalleryDisplay::CSS_HANDLE, \Mockery::type( 'string' ), array(), \Mockery::any() );
		Functions\expect( 'wp_enqueue_script' )->once()->with( GalleryDisplay::HANDLE, \Mockery::type( 'string' ), array(), \Mockery::any(), \Mockery::type( 'array' ) );

		$html = ( new GalleryDisplay() )->shortcode();

		$this->assertSame( 4, substr_count( $html, '<img ' ), '3 thumbs + 1 chip thumbnail; hidden item loads 0 bytes' );
		$this->assertSame( 5, substr_count( $html, 'data-motored-g' ) );
		$this->assertSame( 1, substr_count( $html, ' hidden></a>' ) );
		$this->assertStringContainsString( '>+2</span>', $html );
		$this->assertStringContainsString( 'data-cap="Yamaha MT-07"', $html );
		$this->assertStringContainsString( 'data-rating="5"', $html );
		$this->assertStringContainsString( 'data-rv="https://x.test/producto/neumatico/#comment-31"', $html );
		$this->assertStringContainsString( 'data-ig-filter="install"', $html );
		$this->assertStringContainsString( 'data-ig-filter="review"', $html );
		// SEO/a11y alt: install caption, then '<product> — customer photo' for review photos.
		$this->assertStringContainsString( 'alt="Yamaha MT-07"', $html );
		$this->assertStringContainsString( 'alt="Pirelli Diablo Rosso — customer photo"', $html );
		$this->assertStringContainsString( 'aria-pressed="true"', $html );
	}

	public function test_placement_gating_and_product_tab_work_exclusively(): void {
		$this->fake_storage();
		$this->options['motored_install_gallery'] = array( 'placement' => Config::PLACE_TAB );
		$this->product( array( '_motored_install_photos' => array( 1, 2 ) ) );
		$this->render_stubs();
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		$display = new GalleryDisplay();

		ob_start();
		$display->render_before_tabs();
		$display->render_after_tabs();
		$action_out = (string) ob_get_clean();
		$this->assertSame( '', $action_out );

		$tabs = $display->filter_product_tab( array( 'description' => array( 'title' => 'Desc' ) ) );
		$this->assertArrayHasKey( 'motored_install_gallery', $tabs );
		$this->assertStringContainsString( '(2)', $tabs['motored_install_gallery']['title'] );

		ob_start();
		( $tabs['motored_install_gallery']['callback'] )();
		$tab_out = (string) ob_get_clean();
		$this->assertStringContainsString( 'motored-install-gallery', $tab_out );
		$this->assertStringNotContainsString( '<h2', $tab_out );
	}

	public function test_block_theme_product_details_block_gets_the_gallery_once_in_the_chosen_position(): void {
		$this->fake_storage();
		$this->options['motored_install_gallery'] = array( 'placement' => Config::PLACE_AFTER_TABS );
		$this->product( array( '_motored_install_photos' => array( 1 ) ) );
		$this->render_stubs();
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		$display = new GalleryDisplay();
		$html    = $display->filter_details_block( '<div class="tabs">T</div>' );
		$this->assertStringStartsWith( '<div class="tabs">T</div><section class="motored-install-gallery"', $html );
		// A theme that also fires the classic hook never gets a second copy.
		ob_start();
		$display->render_after_tabs();
		$this->assertSame( '', (string) ob_get_clean() );
		$this->assertSame( 'x', $display->filter_details_block( 'x' ) );
	}

	public function test_does_not_print_twice_for_same_product(): void {
		$this->fake_storage();
		$this->product( array( '_motored_install_photos' => array( 1 ) ) );
		$this->render_stubs();
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		$display = new GalleryDisplay();
		$this->assertNotSame( '', $display->shortcode() );
		$this->assertSame( '', $display->shortcode() );
	}

	public function test_resolves_product_id_from_queried_object_when_loop_id_is_outer_page(): void {
		$this->fake_storage();
		$this->product( array( '_motored_install_photos' => array( 1 ) ) );
		Functions\when( 'get_the_ID' )->justReturn( 999 );
		Functions\when( 'get_post_type' )->alias( fn( $id ) => 50 === $id ? 'product' : 'page' );
		Functions\when( 'is_product' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 50 );
		$this->render_stubs();
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		$this->assertStringContainsString( 'motored-install-gallery', ( new GalleryDisplay() )->shortcode() );
	}

	public function test_below_gallery_placement_embeds_review_content_for_split_lightbox(): void {
		$this->fake_storage();
		$this->options['motored_install_gallery'] = array( 'placement' => Config::PLACE_BELOW_GALLERY );
		$this->product(
			array(
				'_motored_review_gallery' => array( array( 'id' => 9, 'c' => 31, 'r' => 5 ) ),
			)
		);
		$this->render_stubs();
		Functions\when( 'get_comments' )->justReturn(
			array(
				(object) array(
					'comment_ID'       => '31',
					'comment_author'   => 'Juan Perez',
					'comment_date_gmt' => '2026-11-20 12:00:00',
					'comment_content'  => 'Excelente agarre en ripio y asfalto.',
				),
			)
		);
		Functions\when( 'wp_date' )->justReturn( '20 nov 2026' );
		Functions\when( 'wc_review_is_from_verified_owner' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		ob_start();
		( new GalleryDisplay() )->render_below_gallery();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-placement="below_gallery"', $html );
		$this->assertStringContainsString( 'data-author="Juan Perez"', $html );
		$this->assertStringContainsString( 'data-date="20 nov 2026"', $html );
		$this->assertStringContainsString( 'data-review-text="Excelente agarre en ripio y asfalto."', $html );
		$this->assertStringContainsString( 'data-verified="1"', $html );
		$this->assertStringContainsString( 'data-prod-name="Pirelli Diablo Rosso"', $html );
	}
}
