<?php
namespace Motored\Core\Tests\Unit\Modules\ImageOptimizer;

use Brain\Monkey\Functions;
use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Modules\ImageOptimizer\Config;
use Motored\Core\Modules\ImageOptimizer\ImageOptimizerModule;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class ImageOptimizerModuleTest extends TestCase {

	private function context( bool $admin, bool $ajax = false, bool $cron = false ): Context {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
		Functions\when( 'wp_doing_cron' )->justReturn( $cron );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_SERVER['REQUEST_URI'] = $admin ? '/wp-admin/upload.php' : '/producto/cubierta/';
		return new Context();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	public function test_id_defaults_and_clamped_config(): void {
		$this->assertSame( 'image_optimizer', ( new ImageOptimizerModule() )->id() );
		$this->assertSame( 'no', Config::DEFAULTS['enabled'] );
		$this->assertSame( '1600', Config::DEFAULTS['max_side'] );
		$this->assertSame( '82', Config::DEFAULTS['quality'] );
		$this->assertSame( 'no', Config::DEFAULTS['bulk_webp_subsizes'] );

		$this->fake_storage();
		$this->options['motored_image_optimizer'] = array(
			'max_side'        => '99999',
			'quality'         => '10',
			'bulk_batch_size' => '500',
		);
		$this->assertSame( 3000, Config::max_side() );
		$this->assertSame( 82, Config::quality() );
		$this->assertSame( 25, Config::bulk_batch_size() );
	}

	public function test_should_load_never_loads_on_public_frontend_pages(): void {
		$module = new ImageOptimizerModule();
		$this->assertFalse( $module->should_load( $this->context( false ) ) );
		$this->assertTrue( $module->should_load( $this->context( true ) ) );
		$this->assertTrue( $module->should_load( $this->context( false, false, true ) ) );
	}

	public function test_admin_registration_adds_section_and_hooks_without_reading_options(): void {
		Functions\expect( 'get_option' )->never();
		Functions\when( '__' )->returnArg();
		$this->context( true );
		( new ImageOptimizerModule() )->register();

		$this->assertNotNull( SectionRegistry::get( 'images' ) );
		$this->assertNotFalse( has_filter( 'big_image_size_threshold' ) );
		$this->assertNotFalse( has_filter( 'wp_editor_set_quality' ) );
		$this->assertNotFalse( has_filter( 'image_editor_output_format' ) );
		$this->assertNotFalse( has_action( 'motored_image_opt_batch' ) );
	}

	public function test_cron_registration_skips_admin_ui_and_reads_no_options(): void {
		Functions\expect( 'get_option' )->never();
		$this->context( false, false, true );
		( new ImageOptimizerModule() )->register();

		$this->assertNull( SectionRegistry::get( 'images' ) );
		$this->assertNotFalse( has_action( 'motored_image_opt_batch' ) );
	}
}
