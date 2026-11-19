<?php
namespace Motored\Core\Tests\Unit;

use Brain\Monkey\Functions;
use Motored\Core\Plugin;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\Fixtures\LoadingModule;
use Motored\Core\Tests\Unit\Fixtures\SkippedModule;

final class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'MOTORED_CORE_BASENAME' ) ) {
			define( 'MOTORED_CORE_BASENAME', 'motored-core/motored-core.php' );
		}
		$this->fake_storage();
		$this->fake_logger();
	}

	private function context( bool $admin = false ): Context {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		return new Context();
	}

	public function test_registers_nothing_when_woocommerce_is_missing(): void {
		$plugin = new Plugin( $this->context( true ), array( LoadingModule::class ), fn() => false );
		$plugin->boot();

		$this->assertNotFalse( has_action( 'init', array( $plugin, 'load_textdomain' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( $plugin, 'render_missing_woocommerce_notice' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( $plugin, 'run_migrations' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_get_settings_pages', array( $plugin, 'register_settings_page' ) ) );
		$this->assertSame( array(), LoadingModule::$log );
		$this->assertSame( array(), $plugin->loaded_modules() );
	}

	public function test_registers_only_modules_that_approve_loading(): void {
		$plugin = new Plugin( $this->context(), array( LoadingModule::class, SkippedModule::class ), fn() => true );
		$plugin->boot();

		$this->assertSame( array( 'registered:loading' ), LoadingModule::$log );
		$this->assertSame( array( 'loading' ), array_keys( $plugin->loaded_modules() ) );
	}

	public function test_skips_classes_that_do_not_implement_module(): void {
		$plugin = new Plugin( $this->context(), array( \stdClass::class, LoadingModule::class ), fn() => true );
		$plugin->boot();

		$this->assertSame( array( 'loading' ), array_keys( $plugin->loaded_modules() ) );
	}

	public function test_admin_hooks_only_in_admin_context(): void {
		$frontend = new Plugin( $this->context( false ), array(), fn() => true );
		$frontend->boot();
		$this->assertFalse( has_action( 'admin_init', array( $frontend, 'run_migrations' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_get_settings_pages', array( $frontend, 'register_settings_page' ) ) );

		$admin = new Plugin( $this->context( true ), array(), fn() => true );
		$admin->boot();
		$this->assertNotFalse( has_action( 'admin_init', array( $admin, 'run_migrations' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_get_settings_pages', array( $admin, 'register_settings_page' ) ) );
	}

	public function test_default_module_list_registers_waha_and_order_notifications(): void {
		$this->assertSame(
			array(
				\Motored\Core\Modules\Waha\WahaModule::class,
				\Motored\Core\Modules\OrderNotifications\OrderNotificationsModule::class,
				\Motored\Core\Modules\OrderTracking\OrderTrackingModule::class,
				\Motored\Core\Modules\Recovery\RecoveryModule::class,
				\Motored\Core\Modules\ReviewPhotos\ReviewPhotosModule::class,
				\Motored\Core\Modules\ReviewRequests\ReviewRequestsModule::class,
				\Motored\Core\Modules\PaymentPlans\PaymentPlansModule::class,
				\Motored\Core\Modules\Compatibility\CompatibilityModule::class,
				\Motored\Core\Modules\CatalogFilters\CatalogFiltersModule::class,
			),
			Plugin::MODULES
		);
	}

	public function test_boot_is_idempotent(): void {
		$plugin = new Plugin( $this->context(), array( LoadingModule::class ), fn() => true );
		$plugin->boot();
		$plugin->boot();
		$this->assertSame( array( 'registered:loading' ), LoadingModule::$log );
	}

	public function test_loads_textdomain_from_languages_dir(): void {
		Functions\expect( 'load_plugin_textdomain' )->once()->with( 'motored-core', false, 'motored-core/languages' );
		( new Plugin( $this->context(), array(), fn() => true ) )->load_textdomain();
	}

	public function test_activation_creates_general_option_without_autoload(): void {
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARSET=utf8mb4' );
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\expect( 'add_option' )->once()->with( 'motored_general', Plugin::GENERAL_DEFAULTS, '', false );
		Plugin::activate();
		$this->assertSame( '2026112101', $this->options['motored_schema_version'] );
	}

	public function test_deactivation_removes_every_scheduled_job_and_keeps_data(): void {
		$hooks = array();
		Functions\expect( 'as_unschedule_all_actions' )->times( count( Plugin::ACTION_HOOKS ) )->andReturnUsing(
			function ( $hook, $args, $group ) use ( &$hooks ) {
				$this->assertSame( 'motored-core', $group );
				$hooks[] = $hook;
			}
		);
		Functions\expect( 'delete_option' )->never();

		Plugin::deactivate();

		$this->assertSame( Plugin::ACTION_HOOKS, $hooks );
	}
}
