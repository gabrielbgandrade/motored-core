<?php
namespace Motored\Core\Tests\Unit\Support;

use Motored\Core\Support\Db;
use Motored\Core\Tests\Unit\TestCase;

final class DbTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function get_charset_collate(): string {
				return 'DEFAULT CHARSET=utf8mb4';
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_table_names_use_prefix(): void {
		$this->assertSame( 'wp_motored_', Db::prefix() );
		$this->assertSame( 'wp_motored_events', Db::table( 'events' ) );
		$this->assertSame( 'DEFAULT CHARSET=utf8mb4', Db::charset_collate() );
	}

	public function test_invalid_table_name_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		Db::table( 'bad name; DROP' );
	}
}
