<?php
namespace Motored\Core\Tests\Unit\Modules\InstallGallery;

use Motored\Core\Modules\InstallGallery\GalleryItems;
use Motored\Core\Tests\Unit\TestCase;

final class GalleryItemsTest extends TestCase {

	public function test_install_ids_are_clean_and_capped(): void {
		$this->assertSame( array( 4, 9 ), GalleryItems::install_ids( array( '4', 9, 4, 0, 'x' ) ) );
		$this->assertSame( array(), GalleryItems::install_ids( '' ) );
		$this->assertCount( GalleryItems::MAX_INSTALL, GalleryItems::install_ids( range( 1, 40 ) ) );
	}

	public function test_parse_csv(): void {
		$this->assertSame( array( 3, 8 ), GalleryItems::parse_csv( '3,8,,3,abc,-1' ) );
		$this->assertSame( array(), GalleryItems::parse_csv( '' ) );
		$this->assertSame( array(), GalleryItems::parse_csv( array( 1 ) ) );
	}

	public function test_review_entries_drop_invalid_pairs_and_clamp_rating(): void {
		$raw = array(
			array( 'id' => '5', 'c' => 2, 'r' => 9 ),
			array( 'id' => 5, 'c' => 3, 'r' => 4 ),
			array( 'id' => 0, 'c' => 2 ),
			array( 'id' => 6 ),
			'junk',
			array( 'id' => 7, 'c' => '4' ),
		);
		$this->assertSame(
			array(
				array( 'id' => 5, 'c' => 3, 'r' => 4 ),
				array( 'id' => 7, 'c' => 4, 'r' => 0 ),
			),
			GalleryItems::review_entries( $raw )
		);
		$this->assertSame( array(), GalleryItems::review_entries( 'x' ) );
	}

	public function test_merge_puts_install_first_dedupes_and_caps(): void {
		$items = GalleryItems::merge(
			array( 1, 2 ),
			array( array( 'id' => 2, 'c' => 9, 'r' => 5 ), array( 'id' => 3, 'c' => 9, 'r' => 5 ) )
		);
		$this->assertSame(
			array(
				array( 'id' => 1, 'c' => 0, 'r' => 0 ),
				array( 'id' => 2, 'c' => 0, 'r' => 0 ),
				array( 'id' => 3, 'c' => 9, 'r' => 5 ),
			),
			$items
		);

		$many = array();
		foreach ( range( 100, 140 ) as $id ) {
			$many[] = array( 'id' => $id, 'c' => 1, 'r' => 4 );
		}
		$this->assertCount( GalleryItems::MAX_TOTAL, GalleryItems::merge( array( 1 ), $many ) );
	}

	public function test_layout_thumbs_chip_and_hidden(): void {
		$this->assertSame( array( 'thumb', 'thumb', 'thumb' ), GalleryItems::layout( 3, 8 ) );
		$this->assertSame( 0, GalleryItems::more( 8, 8 ) );
		$this->assertSame(
			array( 'thumb', 'thumb', 'thumb', 'chip', 'hidden', 'hidden' ),
			GalleryItems::layout( 6, 4 )
		);
		$this->assertSame( 3, GalleryItems::more( 6, 4 ) );
		$this->assertSame( array(), GalleryItems::layout( 0, 8 ) );
	}
}
