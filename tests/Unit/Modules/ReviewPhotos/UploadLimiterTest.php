<?php
namespace Motored\Core\Tests\Unit\Modules\ReviewPhotos;

use Brain\Monkey\Functions;
use Motored\Core\Modules\ReviewPhotos\UploadLimiter;
use Motored\Core\Tests\Unit\TestCase;

final class UploadLimiterTest extends TestCase {

	private const NOW = 1700000000;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	public function test_five_photos_per_hour_per_ip(): void {
		$this->assertSame( 5, UploadLimiter::remaining( '1.2.3.4', self::NOW ) );
		UploadLimiter::record( '1.2.3.4', self::NOW, 3 );
		$this->assertSame( 2, UploadLimiter::remaining( '1.2.3.4', self::NOW ) );
		UploadLimiter::record( '1.2.3.4', self::NOW, 4 );
		$this->assertSame( 0, UploadLimiter::remaining( '1.2.3.4', self::NOW ) );
	}

	public function test_other_ip_and_next_hour_are_independent(): void {
		UploadLimiter::record( '1.2.3.4', self::NOW, 5 );
		$this->assertSame( 5, UploadLimiter::remaining( '5.6.7.8', self::NOW ) );
		$this->assertSame( 5, UploadLimiter::remaining( '1.2.3.4', self::NOW + 3600 ) );
	}

	public function test_cache_keys_do_not_contain_the_ip(): void {
		UploadLimiter::record( '1.2.3.4', self::NOW, 1 );
		$this->assertStringNotContainsString( '1.2.3.4', json_encode( array( $GLOBALS['motored_fake_cache'], $this->transients ) ) );
	}
}
