<?php
namespace Motored\Core\Tests\Unit\Modules\ImageOptimizer;

use Brain\Monkey\Functions;
use Motored\Core\Modules\ImageOptimizer\Config;
use Motored\Core\Modules\ImageOptimizer\ImageOptimizerAdmin;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class ImageOptimizerAdminTest extends TestCase {

	public function test_settings_fields_use_module_option_without_autoload(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_image_editor_supports' )->justReturn( true );

		$ids = array();
		foreach ( ( new ImageOptimizerAdmin() )->settings() as $field ) {
			if ( isset( $field['id'] ) && str_contains( (string) $field['id'], '[' ) ) {
				$ids[] = $field['id'];
				$this->assertStringStartsWith( 'motored_image_optimizer[', $field['id'] );
				$this->assertFalse( $field['autoload'] );
			}
		}
		$this->assertContains( 'motored_image_optimizer[enabled]', $ids );
		$this->assertContains( 'motored_image_optimizer[bulk_webp_subsizes]', $ids );
	}

	public function test_status_row_reflects_disabled_and_active_state(): void {
		$this->fake_storage();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_image_editor_supports' )->justReturn( true );

		$admin = new ImageOptimizerAdmin();
		$off   = $admin->status_rows( array() );
		$this->assertSame( 'Off', $off['Image optimization'] );

		Options::reset();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$this->options['motored_image_optimizer'] = array( 'enabled' => 'yes', 'convert_webp' => 'yes' );
		$on                                       = $admin->status_rows( array() );
		$this->assertStringContainsString( 'Active', $on['Image optimization'] );
		$this->assertStringContainsString( 'WebP', $on['Image optimization'] );
	}
}
