<?php
namespace Motored\Core\Tests\Unit\Modules\InstallGallery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\InstallGallery\GalleryAdmin;
use Motored\Core\Tests\Unit\TestCase;

final class GalleryAdminTest extends TestCase {

	protected function tearDown(): void {
		unset( $_POST['motored_ig_nonce'], $_POST['motored_igallery'], $_GET['post'] );
		parent::tearDown();
	}

	private function product( int $id = 50 ): object {
		return new class( $id ) {
			/** @var array<int,array<int,mixed>> */
			public array $calls = array();
			private int $id;
			public function __construct( int $id ) {
				$this->id = $id;
			}
			public function get_id(): int {
				return $this->id;
			}
			public function update_meta_data( string $key, $value ): void {
				$this->calls[] = array( 'update', $key, $value );
			}
			public function delete_meta_data( string $key ): void {
				$this->calls[] = array( 'delete', $key );
			}
		};
	}

	private function stubs(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_post_meta' )->justReturn( '' );
	}

	public function test_save_skips_when_present_flag_is_missing_for_quick_edit_safety(): void {
		$this->stubs();
		$_POST['motored_ig_nonce'] = 'ok';
		$_POST['motored_igallery'] = array( 'ids' => '5' );
		$product                   = $this->product();
		( new GalleryAdmin() )->save( $product );
		$this->assertSame( array(), $product->calls );
	}

	public function test_save_needs_a_valid_nonce_and_capability(): void {
		$this->stubs();
		$product = $this->product();

		$admin = new GalleryAdmin();
		$_POST['motored_igallery'] = array( 'present' => '1', 'ids' => '5' );
		$admin->save( $product ); // No nonce.

		$_POST['motored_ig_nonce'] = 'bad';
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$admin->save( $product );

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$admin->save( $product );
		$this->assertSame( array(), $product->calls );
	}

	public function test_save_keeps_only_editable_image_attachments_and_rebuilds_review_meta(): void {
		$this->stubs();
		$_POST['motored_ig_nonce'] = 'ok';
		$_POST['motored_igallery'] = array( 'present' => '1', 'ids' => '5,6,7,5' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->alias( fn( $id ) => 6 === $id ? 'post' : ( 50 === $id ? 'product' : 'attachment' ) );
		Functions\when( 'wp_attachment_is_image' )->alias( fn( $id ) => 7 !== $id );
		Functions\expect( 'get_comments' )->once()->andReturn( array() );
		Functions\when( 'delete_post_meta' )->justReturn( true );

		$product = $this->product();
		( new GalleryAdmin() )->save( $product );
		$this->assertSame( array( array( 'update', '_motored_install_photos', array( 5 ) ) ), $product->calls );
	}

	public function test_save_with_no_valid_photo_removes_the_meta(): void {
		$this->stubs();
		$_POST['motored_ig_nonce'] = 'ok';
		$_POST['motored_igallery'] = array( 'present' => '1', 'ids' => '' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\expect( 'get_comments' )->once()->andReturn( array() );
		Functions\when( 'delete_post_meta' )->justReturn( true );

		$product = $this->product();
		( new GalleryAdmin() )->save( $product );
		$this->assertSame( array( array( 'delete', '_motored_install_photos' ) ), $product->calls );
	}

	public function test_enqueue_loads_media_editor_dependency_only_on_product_screen(): void {
		if ( ! defined( 'MOTORED_CORE_URL' ) ) {
			define( 'MOTORED_CORE_URL', 'https://example.test/wp-content/plugins/motored-core/' );
			define( 'MOTORED_CORE_VERSION', '0.0.0' );
		}
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();

		$screen = (object) array( 'id' => 'dashboard', 'base' => 'dashboard' );
		Functions\when( 'get_current_screen' )->alias( static function () use ( &$screen ) { return $screen; } );
		Functions\expect( 'wp_enqueue_media' )->once()->with( array( 'post' => 50 ) );
		Functions\expect( 'wp_enqueue_script' )->once()->with(
			GalleryAdmin::HANDLE,
			'https://example.test/wp-content/plugins/motored-core/assets/admin/motored-install-gallery.js',
			array( 'media-editor' ),
			MOTORED_CORE_VERSION,
			true
		);

		$admin = new GalleryAdmin();
		$admin->enqueue();

		$_GET['post'] = '50';
		$screen       = (object) array( 'id' => 'product', 'base' => 'post' );
		$admin->enqueue();
	}

	public function test_settings_fields_use_the_module_option_without_autoload(): void {
		$this->stubs();
		$ids = array();
		foreach ( ( new GalleryAdmin() )->settings() as $field ) {
			if ( isset( $field['id'] ) && str_contains( (string) $field['id'], '[' ) ) {
				$ids[] = $field['id'];
				$this->assertStringStartsWith( 'motored_install_gallery[', $field['id'] );
				$this->assertFalse( $field['autoload'] );
			}
		}
		$this->assertContains( 'motored_install_gallery[placement]', $ids );
	}

	public function test_save_updates_changed_attachment_captions_inline(): void {
		$this->stubs();
		$_POST['motored_ig_nonce'] = 'ok';
		$_POST['motored_igallery'] = array(
			'present'  => '1',
			'ids'      => '5,8',
			'captions' => array(
				'5' => 'Benelli TRK 502',
				'8' => 'Yamaha MT-07',
			),
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->alias( fn( $id ) => 50 === $id ? 'product' : 'attachment' );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_caption' )->alias( fn( $id ) => 8 === $id ? 'Yamaha MT-07' : '' );
		Functions\expect( 'wp_update_post' )->once()->with(
			array(
				'ID'           => 5,
				'post_excerpt' => 'Benelli TRK 502',
			)
		);
		Functions\expect( 'get_comments' )->once()->andReturn( array() );
		Functions\when( 'delete_post_meta' )->justReturn( true );

		$product = $this->product();
		( new GalleryAdmin() )->save( $product );
		$this->assertSame( array( array( 'update', '_motored_install_photos', array( 5, 8 ) ) ), $product->calls );
	}
}
