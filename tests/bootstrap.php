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

require_once dirname( __DIR__ ) . '/src/Autoloader.php';
\Motored\Core\Autoloader::register( dirname( __DIR__ ) . '/src/' );

// Test classes (Motored\Core\Tests\...) are autoloaded by Composer (autoload-dev).
