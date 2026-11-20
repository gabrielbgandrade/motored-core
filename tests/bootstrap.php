<?php
/**
 * PHPUnit bootstrap: no WordPress is loaded; Brain Monkey stubs WordPress functions.
 *
 * @package Motored\Core
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Source files guard on ABSPATH.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/motored-core-abspath/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require_once dirname( __DIR__ ) . '/src/Autoloader.php';
\Motored\Core\Autoloader::register( dirname( __DIR__ ) . '/src/' );

// Test classes (Motored\Core\Tests\...) are autoloaded by Composer (autoload-dev).

// Minimal WP_Error for transport-failure tests.
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_message(): string { return $this->message; }
		public function get_error_code(): string { return $this->code; }
	}
}

// Minimal WP_Block (render_block_{name} third argument) for block-theme hook tests.
if ( ! class_exists( 'WP_Block' ) ) {
	// phpcs:ignore
	class WP_Block {
		/** @var array<string,mixed> */
		public $context = array();
	}
}
