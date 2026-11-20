<?php
namespace Motored\Core\Tests\Unit\Modules\ImageOptimizer;

use Brain\Monkey\Functions;
use Motored\Core\Modules\ImageOptimizer\Config;
use Motored\Core\Modules\ImageOptimizer\UploadHooks;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class UploadHooksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
	}

	public function test_filters_pass_through_unchanged_when_module_is_disabled(): void {
		$this->options['motored_image_optimizer'] = array( 'enabled' => 'no' );
		$hooks                                    = new UploadHooks();

		$this->assertSame( 2560, $hooks->filter_threshold( 2560 ) );
		$this->assertSame( 90, $hooks->filter_quality( 90, 'image/jpeg' ) );
		$this->assertSame( array(), $hooks->filter_output_format( array() ) );
		$this->assertSame( array( '1536x1536' => array() ), $hooks->filter_sizes( array( '1536x1536' => array() ) ) );
		Functions\expect( 'update_post_meta' )->never();
		$hooks->record_upload_meta( array( 'width' => 800 ), 42 );
	}

	public function test_filters_apply_configured_values_when_enabled(): void {
		$this->options['motored_image_optimizer'] = array(
			'enabled'           => 'yes',
			'max_side'          => '1400',
			'quality'           => '78',
			'convert_webp'      => 'yes',
			'convert_png_webp'  => 'no',
			'strip_exif'        => 'yes',
			'skip_unused_sizes' => 'yes',
		);
		Functions\when( 'wp_image_editor_supports' )->justReturn( true );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
		Functions\expect( 'update_post_meta' )->once()->with( 99, UploadHooks::META_KEY, \Mockery::type( 'array' ) );

		$hooks = new UploadHooks();
		$this->assertSame( 1400, $hooks->filter_threshold( 2560 ) );
		$this->assertSame( 78, $hooks->filter_quality( 82, 'image/jpeg' ) );
		$this->assertSame( 82, $hooks->filter_quality( 82, 'image/png' ) );
		$this->assertSame( array( 'image/jpeg' => 'image/webp' ), $hooks->filter_output_format( array() ) );
		$this->assertSame( array( 'medium' => array() ), $hooks->filter_sizes( array( 'medium' => array(), '1536x1536' => array() ) ) );
		$this->assertTrue( $hooks->filter_strip_meta( false ) );
		$hooks->record_upload_meta( array( 'width' => 1400 ), 99 );
	}
}
