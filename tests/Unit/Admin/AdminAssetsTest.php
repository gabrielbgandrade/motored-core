<?php
namespace Motored\Core\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Motored\Core\Admin\AdminAssets;
use Motored\Core\Tests\Unit\TestCase;

final class AdminAssetsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MOTORED_CORE_URL' ) ) {
			define( 'MOTORED_CORE_URL', 'https://example.test/wp-content/plugins/motored-core/' );
			define( 'MOTORED_CORE_VERSION', '0.0.0' );
		}
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	public function test_stylesheet_loads_only_on_the_motored_tab(): void {
		$loaded = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src ) use ( &$loaded ) {
				$loaded[] = array( $handle, $src );
			}
		);

		$_GET = array( 'page' => 'wc-settings', 'tab' => 'general' );
		AdminAssets::enqueue();
		$_GET = array( 'page' => 'wc-orders' );
		AdminAssets::enqueue();
		AdminAssets::enqueue();
		$this->assertSame( array(), $loaded );

		$_GET = array( 'page' => 'wc-settings', 'tab' => 'motored', 'section' => 'messages' );
		AdminAssets::enqueue();
		$this->assertSame( array( array( 'motored-admin', 'https://example.test/wp-content/plugins/motored-core/assets/css/motored-admin.css' ) ), $loaded );
	}

	public function test_every_css_rule_is_scoped_and_uses_no_own_colors_or_fonts(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/motored-admin.css' );
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		foreach ( (array) preg_split( '/\}/', $css ) as $block ) {
			$selector = trim( (string) strstr( (string) $block, '{', true ) );
			if ( '' === $selector || str_starts_with( $selector, '@media' ) ) {
				continue;
			}
			foreach ( explode( ',', $selector ) as $one ) {
				$this->assertStringStartsWith( '.motored-admin', trim( $one ), $one );
			}
		}
		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}\b|rgba?\(|font-family|font-size|\bbackground/i', $css );
	}
}
