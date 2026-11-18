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
