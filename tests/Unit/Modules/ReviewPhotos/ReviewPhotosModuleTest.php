<?php
namespace Motored\Core\Tests\Unit\Modules\ReviewPhotos;

use Brain\Monkey\Functions;
use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Modules\ReviewPhotos\Config;
use Motored\Core\Modules\ReviewPhotos\ReviewPhotosModule;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class ReviewPhotosModuleTest extends TestCase {

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
		$this->assertSame( 'review_photos', ( new ReviewPhotosModule() )->id() );
		$this->assertSame( 'no', Config::DEFAULTS['enabled'] );
		$this->assertSame( '3', Config::DEFAULTS['max_photos'] );
		$this->assertSame( '5', Config::DEFAULTS['max_mb'] );
	}

	public function test_config_clamps_values(): void {
		$this->fake_storage();
		$this->options['motored_review_photos'] = array( 'max_photos' => '99', 'max_mb' => '0' );
		$this->assertSame( 5, Config::max_photos() );
		$this->assertSame( 5, Config::max_mb() );
		$this->assertSame( 5 * 1048576, Config::max_bytes() );
	}

	public function test_frontend_registers_form_display_script_and_upload_hooks(): void {
		$this->context( false );
		( new ReviewPhotosModule() )->register();
		$this->assertNotFalse( has_filter( 'woocommerce_product_review_comment_form_args' ) );
		$this->assertNotFalse( has_action( 'woocommerce_review_after_comment_text' ) );
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts' ) );
		$this->assertNotFalse( has_action( 'comment_post' ) );
		$this->assertNotFalse( has_action( 'delete_comment' ) );
		$this->assertFalse( has_action( 'add_meta_boxes_comment' ) );
		$this->assertNull( SectionRegistry::get( 'reviews' ) );
	}

	public function test_cron_only_hooks_the_delete_cleanup(): void {
		$this->context( false, false, true );
		( new ReviewPhotosModule() )->register();
		$this->assertNotFalse( has_action( 'delete_comment' ) );
		$this->assertFalse( has_action( 'comment_post' ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts' ) );
	}

	public function test_admin_adds_section_meta_box_and_eraser(): void {
		$this->context( true );
		( new ReviewPhotosModule() )->register();
		$this->assertNotNull( SectionRegistry::get( 'reviews' ) );
		$this->assertNotFalse( has_action( 'add_meta_boxes_comment' ) );
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_erasers' ) );
		$this->assertFalse( has_action( 'comment_post' ) );
	}

	public function test_registering_does_no_option_reads(): void {
		Functions\expect( 'get_option' )->never();
		$this->context( false );
		( new ReviewPhotosModule() )->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_should_load_skips_only_cli(): void {
		$module = new ReviewPhotosModule();
		$this->assertTrue( $module->should_load( $this->context( false ) ) );
		$this->assertTrue( $module->should_load( $this->context( true ) ) );
		$this->assertTrue( $module->should_load( $this->context( false, false, true ) ) );
	}
}
