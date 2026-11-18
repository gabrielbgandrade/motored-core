<?php
namespace Motored\Core\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Motored\Core\Support\Cache;
use Motored\Core\Tests\Unit\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/object-cache.php';

final class CacheTest extends TestCase {

	private function persistent(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
	}

	public function test_keys_are_prefixed_and_versioned(): void {
		$this->persistent();
		$this->assertMatchesRegularExpression( '/^motored:1:default:[0-9a-f]{8}:a$/', Cache::key( 'a' ) );
	}

	public function test_set_get_roundtrip_and_false_is_cacheable(): void {
		$this->persistent();
		Cache::set( 'flag', false, 60 );
		$found = false;
		$this->assertFalse( Cache::get( 'flag', 'default', $found ) );
		$this->assertTrue( $found );
		Cache::get( 'nope', 'default', $found );
		$this->assertFalse( $found );
	}

	public function test_bump_invalidates_namespace(): void {
		$this->persistent();
		Cache::set( 'k', 'v', 60, 'ns' );
		Cache::set( 'k', 'other', 60, 'other_ns' );
		Cache::bump( 'ns' );
		$found = true;
		Cache::get( 'k', 'ns', $found );
		$this->assertFalse( $found );
		$this->assertSame( 'other', Cache::get( 'k', 'other_ns' ) );
	}

	public function test_works_after_cache_is_wiped(): void {
		$this->persistent();
		Cache::set( 'k', 'v', 60 );
		$GLOBALS['motored_fake_cache'] = array(); // Simulates Redis flush / outage.
		Cache::reset();
		$found = true;
		$this->assertNull( Cache::get( 'k', 'default', $found ) );
		$this->assertFalse( $found );
		$this->assertSame( 'fresh', Cache::remember( 'k', fn() => 'fresh', 60 ) );
	}

	public function test_remember_runs_callback_once(): void {
		$this->persistent();
		$calls = 0;
		$cb    = function () use ( &$calls ) {
			++$calls;
			return 'x';
		};
		$this->assertSame( 'x', Cache::remember( 'r', $cb, 60 ) );
		$this->assertSame( 'x', Cache::remember( 'r', $cb, 60 ) );
		$this->assertSame( 1, $calls );
	}

	public function test_add_acts_as_lock(): void {
		$this->persistent();
		$this->assertTrue( Cache::add( 'lock', 1, 30 ) );
		$this->assertFalse( Cache::add( 'lock', 1, 30 ) );
		Cache::delete( 'lock' );
		$this->assertTrue( Cache::add( 'lock', 1, 30 ) );
	}

	public function test_incr_counts(): void {
		$this->persistent();
		$this->assertSame( 1, Cache::incr( 'c' ) );
		$this->assertSame( 3, Cache::incr( 'c', 2 ) );
		$this->assertSame( 3, Cache::counter( 'c' ) );
	}

	public function test_transient_fallback_without_persistent_cache(): void {
		$this->fake_storage();
		$this->assertFalse( Cache::is_persistent() );
		Cache::set( 'k', array( 1 ), 0 );
		$this->assertSame( array( 1 ), Cache::get( 'k' ) );
		foreach ( $this->transient_ttls as $name => $ttl ) {
			$this->assertStringStartsWith( 'motored_', $name );
			$this->assertGreaterThan( 0, $ttl, 'Transients must never be set without expiry (autoload).' );
		}
		$this->assertTrue( Cache::add( 'lock', 1, 30 ) );
		$this->assertFalse( Cache::add( 'lock', 1, 30 ) );
		$this->assertSame( 1, Cache::incr( 'n' ) );
		$this->assertSame( 3, Cache::incr( 'n', 2 ) );
	}
}
