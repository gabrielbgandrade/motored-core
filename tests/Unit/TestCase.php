<?php
/**
 * Base test case: Brain Monkey + Mockery + in-memory fakes of options/transients.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Support\Cache;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\Fixtures\LoadingModule;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string,mixed> */
	protected array $options = array();
	/** @var array<string,bool|string|null> */
	protected array $autoload = array();
	/** @var array<string,mixed> */
	protected array $transients = array();
	/** @var array<string,int> */
	protected array $transient_ttls = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::reset();
		Cache::reset();
		SectionRegistry::reset();
		LoadingModule::$log = array();
		$this->options        = array();
		$this->autoload       = array();
		$this->transients     = array();
		$this->transient_ttls = array();
		$GLOBALS['motored_fake_cache'] = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fakes get/update_option and transients in memory; object cache is NOT persistent.
	 */
	protected function fake_storage(): void {
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ], $this->autoload[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl = 0 ) {
				$this->transients[ $name ]      = $value;
				$this->transient_ttls[ $name ] = $ttl;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
	}

	/**
	 * Stubs wc_get_logger() with a permissive mock.
	 */
	protected function fake_logger(): \Mockery\MockInterface {
		$logger = \Mockery::mock( 'WC_Logger_Fake' )->shouldIgnoreMissing();
		Functions\when( 'wc_get_logger' )->justReturn( $logger );
		return $logger;
	}
}
