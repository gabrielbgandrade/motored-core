<?php
namespace Motored\Core\Tests\Unit\Modules\InstallGallery;

use Brain\Monkey\Functions;
use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Modules\InstallGallery\Config;
use Motored\Core\Modules\InstallGallery\InstallGalleryModule;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class InstallGalleryModuleTest extends TestCase {

	private function context( bool $admin, bool $ajax = false, bool $cron = false ): Context {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
		Functions\when( 'wp_doing_cron' )->justReturn( $cron );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_SERVER['REQUEST_URI'] = '/producto/x/';
		return new Context();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	public function test_id_and_defaults(): void {
		$this->assertSame( 'install_gallery', ( new InstallGalleryModule() )->id() );
		$this->assertSame( 'yes', Config::DEFAULTS['enabled'] );
		$this->assertSame( '8', Config::DEFAULTS['visible'] );
		$this->assertSame( Config::PLACE_BEFORE_TABS, Config::DEFAULTS['placement'] );
	}

	public function test_config_clamps_visible_and_validates_placement(): void {
		$this->fake_storage();
		$this->options['motored_install_gallery'] = array(
			'visible'   => '99',
			'placement' => 'bogus',
		);
		$this->assertSame( 12, Config::visible() );
		$this->assertSame( Config::PLACE_BEFORE_TABS, Config::placement() );

		\Motored\Core\Support\Options::reset();
		$this->options['motored_install_gallery'] = array(
			'visible'   => '1',
			'placement' => Config::PLACE_TAB,
		);
		$this->assertSame( 8, Config::visible() );
		$this->assertSame( Config::PLACE_TAB, Config::placement() );
	}

	public function test_frontend_registers_display_tabs_shortcode_and_sync(): void {
		$this->context( false );
		Functions\expect( 'add_shortcode' )->once()->with( 'motored_install_gallery', \Mockery::type( 'array' ) );
		( new InstallGalleryModule() )->register();
		$this->assertNotFalse( has_action( 'woocommerce_after_single_product_summary' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_product_tabs' ) );
		$this->assertNotFalse( has_action( 'comment_post' ) );
		$this->assertNotFalse( has_action( 'transition_comment_status' ) );
		$this->assertNotFalse( has_action( 'deleted_comment' ) );
		$this->assertFalse( has_action( 'add_meta_boxes_product' ) );
		$this->assertNull( SectionRegistry::get( 'reviews' ) );
	}

	public function test_ajax_also_hooks_comment_post_for_sync(): void {
		$this->context( true, true );
		( new InstallGalleryModule() )->register();
		$this->assertNotFalse( has_action( 'comment_post' ) );
	}

	public function test_cron_only_follows_moderation(): void {
		$this->context( false, false, true );
		( new InstallGalleryModule() )->register();
		$this->assertNotFalse( has_action( 'transition_comment_status' ) );
		$this->assertNotFalse( has_action( 'deleted_comment' ) );
		$this->assertFalse( has_action( 'woocommerce_after_single_product_summary' ) );
		$this->assertFalse( has_action( 'comment_post' ) );
	}

	public function test_admin_adds_box_script_sync_action_and_settings(): void {
		$this->context( true );
		( new InstallGalleryModule() )->register();
		$this->assertNotNull( SectionRegistry::get( 'reviews' ) );
		$this->assertNotFalse( has_action( 'add_meta_boxes_product' ) );
		$this->assertNotFalse( has_action( 'woocommerce_admin_process_product_object' ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts' ) );
		$this->assertNotFalse( has_action( 'admin_post_motored_ig_sync_reviews' ) );
		$this->assertFalse( has_action( 'woocommerce_after_single_product_summary' ) );
	}

	public function test_registering_does_no_option_reads(): void {
		Functions\expect( 'get_option' )->never();
		$this->context( false );
		Functions\when( 'add_shortcode' )->justReturn( true );
		( new InstallGalleryModule() )->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_should_load_skips_only_cli(): void {
		$module = new InstallGalleryModule();
		$this->assertTrue( $module->should_load( $this->context( false ) ) );
		$this->assertTrue( $module->should_load( $this->context( true ) ) );
		$this->assertTrue( $module->should_load( $this->context( false, false, true ) ) );
	}
}
