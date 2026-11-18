<?php
namespace Motored\Core\Tests\Unit\Modules\ReviewPhotos;

use Brain\Monkey\Functions;
use Motored\Core\Modules\ReviewPhotos\PhotoMeta;
use Motored\Core\Tests\Unit\TestCase;

final class PhotoMetaTest extends TestCase {

	public function test_normalize_keeps_unique_positive_ints_only(): void {
		$this->assertSame( array( 5, 7 ), PhotoMeta::normalize( array( '5', 7, 5, 0, -2, 'x', array( 1 ), null ) ) );
		$this->assertSame( array(), PhotoMeta::normalize( '' ) );
		$this->assertSame( array(), PhotoMeta::normalize( false ) );
	}

	public function test_save_writes_clean_list_and_skips_empty(): void {
		Functions\expect( 'update_comment_meta' )->once()->with( 9, '_motored_review_photos', array( 4, 6 ) );
		PhotoMeta::save( 9, array( 4, '6', 4, 0 ) );
		PhotoMeta::save( 10, array() );
	}

	public function test_delete_all_removes_only_flagged_attachments_and_the_meta(): void {
		Functions\when( 'get_comment_meta' )->justReturn( array( 11, 12, 13 ) );
		Functions\when( 'get_post_type' )->alias( fn( $id ) => 13 === $id ? 'post' : 'attachment' );
		Functions\when( 'get_post_meta' )->alias( fn( $id ) => 12 === $id ? '' : '1' );
		Functions\expect( 'wp_delete_attachment' )->once()->with( 11, true )->andReturn( (object) array() );
		Functions\expect( 'delete_comment_meta' )->once()->with( 9, '_motored_review_photos' );

		$this->assertSame( 1, PhotoMeta::delete_all( 9 ) );
	}
}
