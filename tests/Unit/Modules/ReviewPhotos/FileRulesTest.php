<?php
namespace Motored\Core\Tests\Unit\Modules\ReviewPhotos;

use Motored\Core\Modules\ReviewPhotos\FileRules;
use PHPUnit\Framework\TestCase;

final class FileRulesTest extends TestCase {

	private function file( int $size = 1000, int $error = UPLOAD_ERR_OK, string $tmp = '/tmp/x' ): array {
		return array( 'name' => 'a.jpg', 'tmp_name' => $tmp, 'size' => $size, 'error' => $error );
	}

	public function test_normalize_flattens_multiple_input_and_drops_empty_slots(): void {
		$field = array(
			'name'     => array( 'a.jpg', 'b.png', '' ),
			'tmp_name' => array( '/t/1', '/t/2', '' ),
			'size'     => array( 10, 20, 0 ),
			'error'    => array( 0, 0, UPLOAD_ERR_NO_FILE ),
		);
		$files = FileRules::normalize( $field );
		$this->assertCount( 2, $files );
		$this->assertSame( array( 'name' => 'b.png', 'tmp_name' => '/t/2', 'size' => 20, 'error' => 0 ), $files[1] );
		$this->assertSame( array(), FileRules::normalize( 'nope' ) );
		$this->assertSame( array(), FileRules::normalize( array( 'name' => 'x' ) ) );
	}

	public function test_keep_sizes_leaves_only_the_gallery_sizes(): void {
		$sizes = array( 'thumbnail' => 1, 'medium' => 2, 'large' => 3, 'woocommerce_single' => 4, '1536x1536' => 5 );
		$this->assertSame( array( 'thumbnail' => 1, 'medium' => 2, 'large' => 3 ), FileRules::keep_sizes( $sizes ) );
		$this->assertSame( array(), FileRules::keep_sizes( null ) );
	}

	public function test_select_limits_count_and_size_and_errors(): void {
		$files  = array( $this->file(), $this->file( 6000 ), $this->file( 1000, UPLOAD_ERR_INI_SIZE ), $this->file( 0 ), $this->file(), $this->file(), $this->file() );
		$result = FileRules::select( $files, 2, 5000, 10 );
		$this->assertCount( 2, $result['accepted'] );
		$this->assertSame( 5, $result['rejected'] );
	}

	public function test_select_respects_the_rate_limit_allowance(): void {
		$files = array( $this->file(), $this->file(), $this->file() );
		$this->assertCount( 1, FileRules::select( $files, 3, 5000, 1 )['accepted'] );
		$this->assertSame( array(), FileRules::select( $files, 3, 5000, 0 )['accepted'] );
	}

	public function test_select_ignores_files_without_temp_path(): void {
		$this->assertSame( array(), FileRules::select( array( $this->file( 10, 0, '' ) ), 3, 5000, 5 )['accepted'] );
	}

	public function test_extension_accepts_only_matching_jpeg_png_webp(): void {
		$jpeg = array( 'ext' => 'jpg', 'type' => 'image/jpeg' );
		$this->assertSame( 'jpg', FileRules::extension( $jpeg, array( 800, 600, 2 ) ) );
		$this->assertSame( 'jpg', FileRules::extension( array( 'ext' => 'JPG', 'type' => 'image/jpeg' ), array( 800, 600, 2 ) ) );
		$this->assertSame( 'png', FileRules::extension( array( 'ext' => 'png', 'type' => 'image/png' ), array( 800, 600, 3 ) ) );
		$this->assertSame( 'png', FileRules::extension( array( 'ext' => 'PNG', 'type' => 'image/png' ), array( 800, 600, 3 ) ) );
		$this->assertSame( 'webp', FileRules::extension( array( 'ext' => 'webp', 'type' => 'image/webp' ), array( 800, 600, 18 ) ) );
	}

	public function test_extension_rejects_spoofed_gif_svg_and_mismatches(): void {
		$this->assertNull( FileRules::extension( array( 'ext' => 'jpg', 'type' => 'image/jpeg' ), array( 8, 6, 1 ) ), 'GIF data named .jpg' );
		$this->assertNull( FileRules::extension( array( 'ext' => 'jpg', 'type' => 'image/jpeg' ), array( 8, 6, 3 ) ), 'PNG data named .jpg' );
		$this->assertNull( FileRules::extension( array( 'ext' => false, 'type' => false ), array( 8, 6, 2 ) ), 'WordPress refused it' );
		$this->assertNull( FileRules::extension( array( 'ext' => 'svg', 'type' => 'image/svg+xml' ), false ), 'SVG: no image data' );
		$this->assertNull( FileRules::extension( array( 'ext' => 'gif', 'type' => 'image/gif' ), array( 8, 6, 1 ) ) );
		$this->assertNull( FileRules::extension( array( 'ext' => 'jpg', 'type' => 'image/jpeg' ), false ) );
	}

	public function test_extension_rejects_empty_and_huge_images(): void {
		$jpeg = array( 'ext' => 'jpg', 'type' => 'image/jpeg' );
		$this->assertNull( FileRules::extension( $jpeg, array( 0, 10, 2 ) ) );
		$this->assertNull( FileRules::extension( $jpeg, array( 9000, 9000, 2 ) ) );
	}

	public function test_target_size_only_shrinks_large_images(): void {
		$this->assertNull( FileRules::target_size( 1600, 900 ) );
		$this->assertSame( array( 1600, 900 ), FileRules::target_size( 3200, 1800 ) );
		$this->assertSame( array( 1200, 1600 ), FileRules::target_size( 3000, 4000 ) );
		$this->assertNull( FileRules::target_size( 0, 5000 ) );
	}

	public function test_mime_of_canonical_extension(): void {
		$this->assertSame( 'image/webp', FileRules::mime( 'webp' ) );
		$this->assertSame( '', FileRules::mime( 'gif' ) );
	}
}
