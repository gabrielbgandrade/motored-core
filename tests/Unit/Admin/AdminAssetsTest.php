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

	/**
	 * @return list<array{string,string}>
	 */
	private function run_enqueue(): array {
		$loaded = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src ) use ( &$loaded ) {
				$loaded[] = array( $handle, $src );
			}
		);
		AdminAssets::enqueue();
		return $loaded;
	}

	public function test_stylesheet_loads_only_on_motored_screens(): void {
		$screen = null;
		Functions\when( 'get_current_screen' )->alias(
			static function () use ( &$screen ) {
				return $screen;
			}
		);
		$css = array( array( 'motored-admin', 'https://example.test/wp-content/plugins/motored-core/assets/admin/motored-admin.css' ) );

		foreach ( array(
			array( 'page' => 'wc-settings', 'tab' => 'general' ),
			array( 'page' => 'wc-orders' ),
			array( 'page' => 'wc-orders', 'action' => 'trash' ),
			array( 'page' => 'woocommerce_page_other' ),
		) as $get ) {
			$_GET = $get;
			$this->assertSame( array(), $this->run_enqueue(), json_encode( $get ) );
		}

		$_GET = array( 'page' => 'wc-settings', 'tab' => 'motored', 'section' => 'messages' );
		$this->assertSame( $css, $this->run_enqueue() );
		$_GET = array( 'page' => 'motored-recovery' );
		$this->assertSame( $css, $this->run_enqueue() );

		// Order edit screen (legacy post.php: no page param; HPOS: wc-orders + action=edit).
		$screen = (object) array( 'id' => 'shop_order' );
		$_GET   = array( 'post' => '5', 'action' => 'edit' );
		$this->assertSame( $css, $this->run_enqueue() );
		$_GET = array( 'page' => 'wc-orders', 'action' => 'edit', 'id' => '5' );
		$this->assertSame( $css, $this->run_enqueue() );
		$_GET = array( 'page' => 'wc-orders' );
		$this->assertSame( array(), $this->run_enqueue(), 'HPOS order list' );

		$screen = (object) array( 'id' => 'edit-shop_order' );
		$_GET   = array();
		$this->assertSame( array(), $this->run_enqueue(), 'legacy order list' );
		// Comment edit screen: only when the comment has review photos.
		$screen = (object) array( 'id' => 'comment' );
		$_GET   = array( 'action' => 'editcomment', 'c' => '7' );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_comment_meta' )->justReturn( '' );
		$this->assertSame( array(), $this->run_enqueue(), 'comment without photos' );
		Functions\when( 'get_comment_meta' )->justReturn( array( 11 ) );
		$this->assertSame( $css, $this->run_enqueue(), 'comment with photos' );
		$_GET = array( 'action' => 'editcomment' );
		$this->assertSame( array(), $this->run_enqueue(), 'no comment id' );

		$screen = (object) array( 'id' => 'dashboard' );
		$this->assertSame( array(), $this->run_enqueue() );
	}

	public function test_every_css_rule_is_namespaced_and_uses_no_own_colors_or_fonts(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/motored-admin.css' );
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		foreach ( (array) preg_split( '/\}/', $css ) as $block ) {
			$selector = trim( (string) strstr( (string) $block, '{', true ) );
			if ( '' === $selector || str_starts_with( $selector, '@media' ) ) {
				continue;
			}
			foreach ( explode( ',', $selector ) as $one ) {
				$this->assertStringStartsWith( '.motored-', trim( $one ), $one );
			}
		}
		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}|rgba?\(|font-family|font-size|background/i', $css );
		foreach ( array( '.motored-table', '.motored-box', '.motored-field' ) as $shared ) {
			$this->assertStringContainsString( $shared, $css );
		}
	}
}
