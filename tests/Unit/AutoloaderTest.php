<?php
namespace Motored\Core\Tests\Unit;

use Motored\Core\Autoloader;

final class AutoloaderTest extends TestCase {

	public function test_maps_class_to_file(): void {
		$this->assertSame( '/base/src/Plugin.php', Autoloader::map( 'Motored\\Core\\Plugin', '/base/src/' ) );
	}

	public function test_maps_nested_namespaces(): void {
		$this->assertSame( '/base/src/Support/Cache.php', Autoloader::map( 'Motored\\Core\\Support\\Cache', '/base/src' ) );
		$this->assertSame( '/base/src/Modules/Waha/Client.php', Autoloader::map( '\\Motored\\Core\\Modules\\Waha\\Client', '/base/src/' ) );
	}

	public function test_ignores_foreign_namespaces(): void {
		$this->assertNull( Autoloader::map( 'Other\\Vendor\\Thing', '/base/src/' ) );
		$this->assertNull( Autoloader::map( 'Motored\\CoreExtra\\Thing', '/base/src/' ) );
	}

	public function test_registered_autoloader_resolves_real_classes(): void {
		$this->assertTrue( class_exists( \Motored\Core\Support\Logger::class ) );
	}
}
