<?php
// Global-namespace doubles of the WooCommerce settings classes (not loaded in unit tests).

if ( ! class_exists( 'WC_Settings_Page' ) ) {
	// phpcs:ignore
	class WC_Settings_Page {
		/** @var list<string> */
		public static array $calls = array();
		public $id    = '';
		public $label = '';
		public function __construct() {}
		public function output() { self::$calls[] = 'parent_output'; }
		public function save() { self::$calls[] = 'parent_save'; }
	}
}
if ( ! class_exists( 'WC_Admin_Settings' ) ) {
	// phpcs:ignore
	class WC_Admin_Settings {
		/** @var list<string> */
		public static array $errors = array();
		public static function add_error( $text ) { self::$errors[] = (string) $text; }
	}
}

