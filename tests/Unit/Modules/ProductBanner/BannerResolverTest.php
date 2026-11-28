<?php
namespace Motored\Core\Tests\Unit\Modules\ProductBanner;

use Motored\Core\Modules\ProductBanner\BannerResolver;
use Motored\Core\Modules\ProductBanner\Config;
use Motored\Core\Tests\Unit\TestCase;

final class BannerResolverTest extends TestCase {

	private const NOW = 1_800_000_000;

	private function own( string $mode, int $image = 0, int $until = 0 ): array {
		return array( 'mode' => $mode, 'image_id' => $image, 'link' => '', 'until' => $until, 'countdown' => true );
	}

	private function general( bool $enabled, int $image = 10, int $until = 0 ): array {
		return array( 'enabled' => $enabled, 'image_id' => $image, 'link' => '/ofertas/', 'until' => $until, 'countdown' => true );
	}

	public function test_none_hides_even_the_general_banner(): void {
		$this->assertNull( BannerResolver::resolve( $this->own( Config::MODE_NONE ), $this->general( true ), self::NOW ) );
	}

	public function test_own_banner_wins_over_the_general_one(): void {
		$banner = BannerResolver::resolve( $this->own( Config::MODE_OWN, 22 ), $this->general( true ), self::NOW );
		$this->assertSame( 22, $banner['image_id'] );
		$this->assertFalse( $banner['countdown'], 'No countdown without an end date.' );
	}

	public function test_own_mode_without_image_falls_back_to_the_general_banner(): void {
		$banner = BannerResolver::resolve( $this->own( Config::MODE_OWN ), $this->general( true ), self::NOW );
		$this->assertSame( 10, $banner['image_id'] );
		$this->assertSame( '/ofertas/', $banner['link'] );
	}

	public function test_general_banner_needs_to_be_enabled_and_have_an_image(): void {
		$this->assertNull( BannerResolver::resolve( $this->own( Config::MODE_DEFAULT ), $this->general( false ), self::NOW ) );
		$this->assertNull( BannerResolver::resolve( $this->own( Config::MODE_DEFAULT ), $this->general( true, 0 ), self::NOW ) );
	}

	public function test_expired_banners_are_hidden_and_live_ones_keep_the_countdown(): void {
		$this->assertNull( BannerResolver::resolve( $this->own( Config::MODE_DEFAULT ), $this->general( true, 10, self::NOW - 1 ), self::NOW ) );
		$banner = BannerResolver::resolve( $this->own( Config::MODE_DEFAULT ), $this->general( true, 10, self::NOW + 90061 ), self::NOW );
		$this->assertTrue( $banner['countdown'] );
		$this->assertSame( array( 'd' => 1, 'h' => 1, 'm' => 1, 's' => 1 ), BannerResolver::remaining( $banner['until'], self::NOW ) );
	}

	public function test_mode_falls_back_to_default(): void {
		$this->assertSame( Config::MODE_DEFAULT, Config::mode( 'weird' ) );
		$this->assertSame( Config::MODE_OWN, Config::mode( 'own' ) );
	}
}
