<?php
namespace Motored\Core\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Motored\Core\Support\ImageProcessor;
use Motored\Core\Tests\Unit\TestCase;

final class ImageProcessorTest extends TestCase {

	private string $tmp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->tmp_dir = sys_get_temp_dir() . '/motored_img_test_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp_dir, 0777, true );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ): bool => $v instanceof \WP_Error );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ): string => rtrim( (string) $s, '/\\' ) . '/' );
		Functions\when( 'wp_unique_filename' )->alias( static fn( $dir, $name ): string => (string) $name );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $path ): void {
				if ( is_string( $path ) && file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);
	}

	protected function tearDown(): void {
		if ( '' !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			foreach ( (array) glob( $this->tmp_dir . '/*' ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) ) {
					unlink( $file );
				}
			}
			rmdir( $this->tmp_dir );
		}
		parent::tearDown();
	}

	public function test_target_size_scales_proportionally_only_when_exceeding_max_side(): void {
		$this->assertNull( ImageProcessor::target_size( 1600, 900, 1600 ) );
		$this->assertSame( array( 1600, 900 ), ImageProcessor::target_size( 3200, 1800, 1600 ) );
		$this->assertSame( array( 800, 1600 ), ImageProcessor::target_size( 1200, 2400, 1600 ) );
		$this->assertNull( ImageProcessor::target_size( 0, 2400, 1600 ) );
		$this->assertNull( ImageProcessor::target_size( 2000, 2000, 0 ) );
	}

	public function test_keep_sizes_filters_registered_subsizes(): void {
		$sizes = array(
			'thumbnail' => array( 'width' => 150 ),
			'medium'    => array( 'width' => 300 ),
			'1536x1536' => array( 'width' => 1536 ),
		);
		$this->assertSame(
			array(
				'thumbnail' => array( 'width' => 150 ),
				'medium'    => array( 'width' => 300 ),
			),
			ImageProcessor::keep_sizes( $sizes, array( 'thumbnail', 'medium', 'large' ) )
		);
		$this->assertSame( array(), ImageProcessor::keep_sizes( null, array( 'thumbnail' ) ) );
	}

	public function test_supports_webp_uses_wp_image_editor_supports(): void {
		Functions\when( 'wp_image_editor_supports' )->justReturn( true );
		$this->assertTrue( ImageProcessor::supports_webp() );

		Functions\when( 'wp_image_editor_supports' )->justReturn( false );
		$this->assertFalse( ImageProcessor::supports_webp() );
	}

	public function test_process_upload_converts_to_webp_and_falls_back_when_webp_save_fails(): void {
		$src = $this->tmp_dir . '/upload.tmp';
		file_put_contents( $src, str_repeat( 'a', 500 ) );

		Functions\when( 'wp_image_editor_supports' )->justReturn( true );

		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'maybe_exif_rotate' )->once()->andReturn( false );
		$editor->shouldReceive( 'get_size' )->once()->andReturn( array( 'width' => 3200, 'height' => 1600 ) );
		$editor->shouldReceive( 'resize' )->once()->with( 1600, 800, false )->andReturn( true );
		$editor->shouldReceive( 'set_quality' )->once()->with( 82 )->andReturn( true );
		$editor->shouldReceive( 'save' )->once()->with( $this->tmp_dir . '/review-abc.webp', 'image/webp' )
			->andReturn( new \WP_Error( 'webp_fail', 'fail' ) );
		$editor->shouldReceive( 'save' )->once()->with( $this->tmp_dir . '/review-abc.jpg', 'image/jpeg' )
			->andReturn(
				array(
					'path'      => $this->tmp_dir . '/review-abc.jpg',
					'file'      => 'review-abc.jpg',
					'width'     => 1600,
					'height'    => 800,
					'mime-type' => 'image/jpeg',
					'filesize'  => 200,
				)
			);
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );

		$res = ImageProcessor::process_upload(
			$src,
			$this->tmp_dir,
			'review-abc',
			'jpg',
			array(
				'max_side'    => 1600,
				'quality'     => 82,
				'prefer_webp' => true,
				'prefer_gd'   => true,
			)
		);

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'jpg', $res['ext'] );
		$this->assertSame( 'image/jpeg', $res['mime'] );
		$this->assertSame( $this->tmp_dir . '/review-abc.jpg', $res['path'] );
	}

	public function test_optimize_in_place_swaps_when_smaller_and_skips_when_larger(): void {
		$file = $this->tmp_dir . '/product-tire.jpg';
		file_put_contents( $file, str_repeat( 'X', 1000 ) );
		Functions\when( 'wp_check_filetype' )->justReturn( array( 'ext' => 'jpg', 'type' => 'image/jpeg' ) );

		// Case 1: output is smaller -> file is replaced in place at exact same path.
		$smaller_editor = \Mockery::mock( 'WP_Image_Editor' );
		$smaller_editor->shouldReceive( 'maybe_exif_rotate' )->once()->andReturn( false );
		$smaller_editor->shouldReceive( 'get_size' )->andReturn( array( 'width' => 1200, 'height' => 900 ) );
		$smaller_editor->shouldReceive( 'set_quality' )->once()->with( 82 )->andReturn( true );
		$smaller_editor->shouldReceive( 'save' )->once()->andReturnUsing(
			static function ( $dest ) {
				file_put_contents( $dest, str_repeat( 'Y', 400 ) );
				return array( 'path' => $dest, 'mime-type' => 'image/jpeg' );
			}
		);
		Functions\when( 'wp_get_image_editor' )->justReturn( $smaller_editor );

		$res = ImageProcessor::optimize_in_place( $file, array( 'max_side' => 1600, 'quality' => 82, 'strip_exif' => true ) );
		$this->assertTrue( $res['ok'] );
		$this->assertTrue( $res['changed'] );
		$this->assertSame( 1000, $res['bytes_before'] );
		$this->assertSame( 400, $res['bytes_after'] );
		$this->assertSame( 400, filesize( $file ) );

		// Case 2: output is LARGER and not resized -> original kept, temp cleaned up.
		$larger_editor = \Mockery::mock( 'WP_Image_Editor' );
		$larger_editor->shouldReceive( 'maybe_exif_rotate' )->once()->andReturn( false );
		$larger_editor->shouldReceive( 'get_size' )->andReturn( array( 'width' => 1200, 'height' => 900 ) );
		$larger_editor->shouldReceive( 'set_quality' )->once()->with( 82 )->andReturn( true );
		$larger_editor->shouldReceive( 'save' )->once()->andReturnUsing(
			static function ( $dest ) {
				file_put_contents( $dest, str_repeat( 'Z', 700 ) );
				return array( 'path' => $dest, 'mime-type' => 'image/jpeg' );
			}
		);
		Functions\when( 'wp_get_image_editor' )->justReturn( $larger_editor );

		$res2 = ImageProcessor::optimize_in_place( $file, array( 'max_side' => 1600, 'quality' => 82 ) );
		$this->assertTrue( $res2['ok'] );
		$this->assertFalse( $res2['changed'] );
		$this->assertSame( 400, $res2['bytes_after'] );
		$this->assertSame( 400, filesize( $file ) );
		$this->assertCount( 1, (array) glob( $this->tmp_dir . '/*' ), 'Temp file removed.' );
	}

	public function test_in_place_never_swaps_a_different_format_behind_the_original_url(): void {
		$file = $this->tmp_dir . '/banner.jpg';
		file_put_contents( $file, str_repeat( 'X', 1000 ) );
		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'maybe_exif_rotate' )->andReturn( false );
		$editor->shouldReceive( 'get_size' )->andReturn( array( 'width' => 800, 'height' => 600 ) );
		$editor->shouldReceive( 'set_quality' )->andReturn( true );
		// An output-format mapping re-routed the save to WebP (different extension and MIME).
		$editor->shouldReceive( 'save' )->once()->andReturnUsing(
			static function ( $dest ) {
				$webp = preg_replace( '/\.jpg$/', '.webp', (string) $dest );
				file_put_contents( (string) $webp, str_repeat( 'W', 300 ) );
				return array( 'path' => $webp, 'mime-type' => 'image/webp' );
			}
		);
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );

		$res = ImageProcessor::optimize_in_place( $file, array( 'mime' => 'image/jpeg' ) );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'format_changed', $res['error'] );
		$this->assertSame( str_repeat( 'X', 1000 ), file_get_contents( $file ), 'Original bytes untouched.' );
		$this->assertCount( 1, (array) glob( $this->tmp_dir . '/*' ), 'Stray WebP removed.' );
	}

	public function test_negligible_gain_is_discarded_and_max_side_zero_never_resizes(): void {
		$this->assertFalse( ImageProcessor::worth_keeping( 1000, 990, false ) );
		$this->assertTrue( ImageProcessor::worth_keeping( 1000, 970, false ) );
		$this->assertTrue( ImageProcessor::worth_keeping( 1000, 1000, true ) );
		$this->assertFalse( ImageProcessor::worth_keeping( 1000, 1001, true ) );
		$this->assertNull( ImageProcessor::target_size( 4000, 3000, 0 ) );

		$file = $this->tmp_dir . '/hero.jpg';
		file_put_contents( $file, str_repeat( 'X', 1000 ) );
		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'maybe_exif_rotate' )->andReturn( false );
		$editor->shouldReceive( 'get_size' )->andReturn( array( 'width' => 4000, 'height' => 1200 ) );
		$editor->shouldReceive( 'resize' )->never();
		$editor->shouldReceive( 'set_quality' )->andReturn( true );
		$editor->shouldReceive( 'save' )->once()->andReturnUsing(
			static function ( $dest ) {
				file_put_contents( (string) $dest, str_repeat( 'Y', 990 ) );
				return array( 'path' => $dest, 'mime-type' => 'image/jpeg' );
			}
		);
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );

		$res = ImageProcessor::optimize_in_place( $file, array( 'mime' => 'image/jpeg', 'max_side' => 0 ) );
		$this->assertTrue( $res['ok'] );
		$this->assertFalse( $res['changed'], '1% gain is not worth a re-encode.' );
		$this->assertSame( 1000, filesize( $file ) );
	}
}
