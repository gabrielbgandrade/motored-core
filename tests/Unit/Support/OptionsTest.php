<?php
namespace Motored\Core\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class OptionsTest extends TestCase {

	public function test_option_name_is_prefixed(): void {
		$this->assertSame( 'motored_recovery', Options::option_name( 'recovery' ) );
	}

	public function test_invalid_slug_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		Options::option_name( 'Bad Slug' );
	}

	public function test_update_persists_with_autoload_false(): void {
		$this->fake_storage();
		Options::update( 'general', array( 'log_level' => 'debug' ) );
		$this->assertSame( array( 'log_level' => 'debug' ), $this->options['motored_general'] );
		$this->assertFalse( $this->autoload['motored_general'] );
	}

	public function test_only_bike_pages_is_autoloaded_and_sync_flips_it_once(): void {
		$this->fake_storage();
		// The single documented exception: every other module stays autoload=false.
		$this->assertSame( array( 'bike_pages' ), Options::AUTOLOAD_MODULES );
		$this->assertFalse( Options::autoload( 'compatibility' ) );
		Options::update( 'bike_pages', array( 'base_slug' => 'cubiertas-para' ) );
		$this->assertTrue( $this->autoload['motored_bike_pages'] );

		$flipped = array();
		Functions\when( 'wp_set_option_autoload' )->alias(
			static function ( $name, $autoload ) use ( &$flipped ): bool {
				$flipped[] = array( $name, $autoload );
				return true;
			}
		);
		// Stored but not in alloptions (created before the exception) -> flipped.
		Functions\when( 'wp_load_alloptions' )->justReturn( array() );
		Options::sync_autoload( 'bike_pages' );
		$this->assertSame( array( array( 'motored_bike_pages', true ) ), $flipped );

		// Never saved -> created EMPTY and autoloaded (a missing option is a query on every request); defaults stay
		// in code, so a later default change still applies.
		unset( $this->options['motored_bike_pages'], $this->autoload['motored_bike_pages'] );
		Options::register_defaults( 'bike_pages', array( 'base_slug' => 'cubiertas-para' ) );
		Options::sync_autoload( 'bike_pages' );
		$this->assertSame( array(), $this->options['motored_bike_pages'] );
		$this->assertSame( 'cubiertas-para', Options::get( 'bike_pages', 'base_slug' ) );
		$this->assertTrue( $this->autoload['motored_bike_pages'] );
		$this->assertCount( 1, $flipped );

		// Already autoloaded -> no write. Other modules -> never touched.
		Functions\when( 'wp_load_alloptions' )->justReturn( array( 'motored_bike_pages' => 'x' ) );
		Options::sync_autoload( 'bike_pages' );
		Options::sync_autoload( 'compatibility' );
		$this->assertCount( 1, $flipped );
	}

	public function test_update_merges_with_stored_values(): void {
		$this->fake_storage();
		$this->options['motored_general'] = array( 'a' => 1 );
		Options::update( 'general', array( 'b' => 2 ) );
		$this->assertSame( array( 'a' => 1, 'b' => 2 ), $this->options['motored_general'] );
	}

	public function test_defaults_fill_missing_keys_and_stored_wins(): void {
		$this->fake_storage();
		$this->options['motored_general'] = array( 'log_level' => 'info' );
		Options::register_defaults( 'general', array( 'log_level' => 'warning', 'delete_data_on_uninstall' => 'no' ) );
		$this->assertSame( 'info', Options::get( 'general', 'log_level' ) );
		$this->assertSame( 'no', Options::get( 'general', 'delete_data_on_uninstall' ) );
		$this->assertSame( 'x', Options::get( 'general', 'missing', 'x' ) );
	}

	public function test_reads_are_memoized_per_request(): void {
		Functions\expect( 'get_option' )->once()->with( 'motored_general', array() )->andReturn( array( 'a' => 1 ) );
		$this->assertSame( 1, Options::get( 'general', 'a' ) );
		$this->assertSame( 1, Options::get( 'general', 'a' ) );
	}

	public function test_is_enabled_handles_yes_no(): void {
		$this->fake_storage();
		$this->options['motored_general'] = array( 'flag' => 'yes' );
		$this->assertTrue( Options::is_enabled( 'general', 'flag' ) );
		$this->assertFalse( Options::is_enabled( 'general', 'other' ) );
	}

	public function test_ensure_adds_option_with_autoload_false(): void {
		Options::register_defaults( 'general', array( 'k' => 'v' ) );
		Functions\expect( 'add_option' )->once()->with( 'motored_general', array( 'k' => 'v' ), '', false );
		Options::ensure( 'general' );
	}
}
