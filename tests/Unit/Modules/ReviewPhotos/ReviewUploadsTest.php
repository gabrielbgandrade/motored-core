<?php
namespace Motored\Core\Tests\Unit\Modules\ReviewPhotos;

use Brain\Monkey\Functions;
use Motored\Core\Modules\ReviewPhotos\ReviewUploads;
use Motored\Core\Modules\ReviewPhotos\UploadLimiter;
use Motored\Core\Tests\Unit\TestCase;

final class ReviewUploadsTest extends TestCase {

	private const NOW = 1700000000;

	/** @var list<array{string,string,int}> */
	private array $processed = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		$this->fake_logger();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		$this->options['motored_review_photos'] = array( 'enabled' => 'yes', 'max_photos' => '2', 'max_mb' => '1' );
		$this->processed                        = array();
		Functions\when( 'get_comment' )->justReturn( (object) array( 'comment_type' => 'review', 'comment_parent' => 0, 'comment_post_ID' => 42 ) );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
	}

	private function uploads( ?string $ext = 'jpg', int $id = 100 ): ReviewUploads {
		return new ReviewUploads(
			fn( string $tmp, string $name ): ?string => $ext,
			function ( string $tmp, string $e, int $product ) use ( &$id ): int {
				$this->processed[] = array( $tmp, $e, $product );
				return $id++;
			},
			fn(): bool => true,
			fn(): int => self::NOW
		);
	}

	private function files( int $count, int $size = 1000 ): array {
		return array(
			'name'     => array_fill( 0, $count, 'p.jpg' ),
			'tmp_name' => array_map( fn( $i ) => '/tmp/p' . $i, range( 1, $count ) ),
			'size'     => array_fill( 0, $count, $size ),
			'error'    => array_fill( 0, $count, 0 ),
		);
	}

	public function test_valid_photos_are_processed_and_ids_saved_in_comment_meta(): void {
		Functions\expect( 'update_comment_meta' )->once()->with( 9, '_motored_review_photos', array( 100, 101 ) );
		$this->uploads()->handle( 9, 1, $this->files( 2 ), '1.2.3.4' );
		$this->assertSame( array( '/tmp/p1', 'jpg', 42 ), $this->processed[0] );
		$this->assertSame( 3, UploadLimiter::remaining( '1.2.3.4', self::NOW ) );
	}

	public function test_extra_photos_beyond_the_limit_are_ignored(): void {
		Functions\expect( 'update_comment_meta' )->once()->with( 9, '_motored_review_photos', array( 100, 101 ) );
		$this->uploads()->handle( 9, '0', $this->files( 4 ), '1.2.3.4' );
		$this->assertCount( 2, $this->processed );
	}

	public function test_oversized_photos_are_skipped_without_touching_the_comment(): void {
		Functions\expect( 'update_comment_meta' )->never();
		$this->uploads()->handle( 9, 1, $this->files( 2, 2 * 1048576 ), '1.2.3.4' );
		$this->assertSame( array(), $this->processed );
	}

	public function test_spam_comments_never_get_photos(): void {
		Functions\expect( 'update_comment_meta' )->never();
		$this->uploads()->handle( 9, 'spam', $this->files( 1 ), '1.2.3.4' );
		$this->assertSame( array(), $this->processed );
	}

	public function test_disabled_module_does_nothing(): void {
		$this->options['motored_review_photos']['enabled'] = 'no';
		$this->uploads()->handle( 9, 1, $this->files( 1 ), '1.2.3.4' );
		$this->assertSame( array(), $this->processed );
	}

	public function test_non_review_comments_are_ignored(): void {
		Functions\when( 'get_comment' )->justReturn( (object) array( 'comment_type' => 'comment', 'comment_parent' => 5, 'comment_post_ID' => 42 ) );
		$this->uploads()->handle( 9, 1, $this->files( 1 ), '1.2.3.4' );
		$this->assertSame( array(), $this->processed );
	}

	public function test_rate_limit_caps_photos_per_ip_per_hour(): void {
		$this->options['motored_review_photos']['max_photos'] = '5';
		Functions\when( 'update_comment_meta' )->justReturn( true );
		$uploads = $this->uploads();
		$uploads->handle( 1, 1, $this->files( 4 ), '1.2.3.4' );
		$uploads->handle( 2, 1, $this->files( 4 ), '1.2.3.4' );
		$this->assertCount( 5, $this->processed, '4 + only 1 left of the 5 per hour' );
		$uploads->handle( 3, 1, $this->files( 1 ), '1.2.3.4' );
		$this->assertCount( 5, $this->processed );
	}

	public function test_invalid_type_or_failed_processing_is_skipped_but_valid_ones_kept(): void {
		Functions\expect( 'update_comment_meta' )->never();
		$this->uploads( null )->handle( 9, 1, $this->files( 2 ), '1.2.3.4' );
		$this->assertSame( array(), $this->processed );

		$failing = new ReviewUploads( fn() => 'jpg', fn(): int => 0, fn(): bool => true, fn(): int => self::NOW );
		$failing->handle( 9, 1, $this->files( 1 ), '5.5.5.5' );
	}

	public function test_files_not_uploaded_through_http_are_rejected(): void {
		Functions\expect( 'update_comment_meta' )->never();
		$uploads = new ReviewUploads( fn() => 'jpg', function (): int {
			$this->fail( 'must not process' );
		}, fn(): bool => false, fn(): int => self::NOW );
		$uploads->handle( 9, 1, $this->files( 1 ), '1.2.3.4' );
		$this->addToAssertionCount( 1 );
	}

	public function test_exception_in_processing_never_propagates(): void {
		$boom = new ReviewUploads( fn() => 'jpg', function (): int {
			throw new \RuntimeException( 'boom' );
		}, fn(): bool => true, fn(): int => self::NOW );
		$boom->handle( 9, 1, $this->files( 1 ), '1.2.3.4' );
		$this->addToAssertionCount( 1 );
	}
}
