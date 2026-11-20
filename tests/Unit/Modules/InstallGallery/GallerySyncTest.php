<?php
namespace Motored\Core\Tests\Unit\Modules\InstallGallery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\InstallGallery\GalleryStore;
use Motored\Core\Modules\InstallGallery\GallerySync;
use Motored\Core\Modules\ReviewPhotos\PhotoMeta;
use Motored\Core\Tests\Unit\TestCase;

final class GallerySyncTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Current derived list: none, unless a test overrides it.
		Functions\when( 'get_post_meta' )->justReturn( '' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function comment( int $id, int $product ): object {
		return (object) array( 'comment_ID' => $id, 'comment_post_ID' => $product );
	}

	public function test_rebuild_stores_photos_and_ratings_of_approved_reviews_newest_first_and_purges_caches(): void {
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\expect( 'get_comments' )->once()->andReturn( array( $this->comment( 7, 50 ), $this->comment( 6, 50 ) ) );
		Functions\when( 'get_comment_meta' )->alias(
			static function ( $id, $key ) {
				if ( 'rating' === $key ) {
					return 7 === $id ? '5' : '';
				}
				return 7 === $id ? array( 70, 71 ) : array( 60 );
			}
		);
		Functions\expect( 'update_post_meta' )->once()->with(
			50,
			'_motored_review_gallery',
			array(
				array( 'id' => 70, 'c' => 7, 'r' => 5 ),
				array( 'id' => 71, 'c' => 7, 'r' => 5 ),
				array( 'id' => 60, 'c' => 6, 'r' => 0 ),
			)
		);
		Functions\expect( 'clean_post_cache' )->once()->with( 50 );
		GalleryStore::rebuild( 50 );
	}

	public function test_rebuild_deletes_the_meta_when_no_review_has_photos_any_more(): void {
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_post_meta' )->justReturn( array( array( 'id' => 70, 'c' => 7, 'r' => 5 ) ) );
		Functions\when( 'get_comments' )->justReturn( array() );
		Functions\expect( 'delete_post_meta' )->once()->with( 50, '_motored_review_gallery' );
		GalleryStore::rebuild( 50 );
	}

	public function test_rebuild_writes_nothing_and_purges_nothing_when_unchanged(): void {
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_comments' )->justReturn( array() );
		Functions\expect( 'delete_post_meta' )->never();
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'clean_post_cache' )->never();
		GalleryStore::rebuild( 50 );
	}

	public function test_rebuild_all_uses_one_query_and_processes_each_unique_product(): void {
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_comments' )->justReturn( array( $this->comment( 1, 10 ) ) );
		Functions\when( 'get_comment_meta' )->alias(
			static fn( $id, $key ) => PhotoMeta::KEY === $key ? array( $id * 10 ) : '4'
		);
		$wpdb              = \Mockery::mock( 'wpdb_fake' );
		$wpdb->comments    = 'wp_comments';
		$wpdb->commentmeta = 'wp_commentmeta';
		$wpdb->postmeta    = 'wp_postmeta';
		$wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '10', '20', '10', '0' ) );
		$GLOBALS['wpdb'] = $wpdb;
		Functions\expect( 'update_post_meta' )->twice();
		$this->assertSame( 2, GalleryStore::rebuild_all() );
	}

	public function test_rebuild_ignores_other_post_types(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\expect( 'get_comments' )->never();
		GalleryStore::rebuild( 12 );
		GalleryStore::rebuild( 0 );
	}

	public function test_transition_rebuilds_only_reviews_with_photos(): void {
		Functions\when( 'get_comment_meta' )->alias( fn( $id ) => 7 === $id ? array( 70 ) : '' );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\expect( 'get_comments' )->once()->andReturn( array() );

		$sync = new GallerySync();
		$sync->on_transition( 'approved', 'unapproved', $this->comment( 8, 50 ) );
		$sync->on_transition( 'approved', 'approved', $this->comment( 7, 50 ) );
		$sync->on_transition( 'approved', 'unapproved', $this->comment( 7, 50 ) );
	}

	public function test_comment_post_rebuilds_only_when_approved(): void {
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\expect( 'get_comment' )->once()->with( 7 )->andReturn( $this->comment( 7, 50 ) );
		Functions\expect( 'get_comments' )->once()->andReturn( array() );

		$sync = new GallerySync();
		$sync->on_comment_post( 7, 0 );
		$sync->on_comment_post( 7, 'spam' );
		$sync->on_comment_post( 7, 1 );
	}

	public function test_deleted_comment_rebuilds_only_when_it_was_in_the_gallery(): void {
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_post_meta' )->justReturn( array( array( 'id' => 70, 'c' => 7, 'r' => 5 ) ) );
		Functions\expect( 'get_comments' )->once()->andReturn( array() );
		Functions\when( 'delete_post_meta' )->justReturn( true );

		$sync = new GallerySync();
		$sync->on_deleted( 9, $this->comment( 9, 50 ) ); // Spam purge of a comment without photos: no query.
		$sync->on_deleted( 7, null );
		$sync->on_deleted( 7, $this->comment( 7, 50 ) ); // In the gallery: rebuild once.
	}
}
