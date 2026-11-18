<?php
/**
 * Plugin bootstrap and module orchestration.
 *
 * @package Motored\Core
 */

namespace Motored\Core;

use Motored\Core\Contracts\Module;
use Motored\Core\Support\Context;
use Motored\Core\Support\Logger;
use Motored\Core\Support\Migrations;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the plugin lifecycle. This is the only class (besides the
 * autoloader) loaded on a public request.
 */
final class Plugin {

	/**
	 * Module class names; each module adds one line here.
	 *
	 * @var list<class-string<Module>>
	 */
	public const MODULES = array(
		Modules\Waha\WahaModule::class,
		Modules\OrderNotifications\OrderNotificationsModule::class,
		Modules\Recovery\RecoveryModule::class,
		Modules\ReviewPhotos\ReviewPhotosModule::class,
		Modules\ReviewRequests\ReviewRequestsModule::class,
	);

	/**
	 * Action Scheduler hooks owned by the plugin (group motored-core).
	 *
	 * @var list<string>
	 */
	public const ACTION_HOOKS = array( 'motored_waha_send', 'motored_recovery_cleanup', 'motored_recovery_scan' );

	/**
	 * Defaults of the built-in "general" settings.
	 *
	 * @var array<string,string>
	 */
	public const GENERAL_DEFAULTS = array(
		'delete_data_on_uninstall' => 'no',
		'log_level'                => 'warning',
	);

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Modules that passed should_load() and were registered.
	 *
	 * @var array<string,Module>
	 */
	private array $loaded = array();

	/**
	 * Constructor.
	 *
	 * @param Context           $context           Request context.
	 * @param array<int,string> $modules        Module class names.
	 * @param \Closure|null     $woocommerce_check Returns true when WooCommerce is active (injectable for tests).
	 */
	public function __construct(
		private Context $context,
		private array $modules = self::MODULES,
		private ?\Closure $woocommerce_check = null
	) {
	}

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self( new Context() );
		}
		return self::$instance;
	}

	/**
	 * Activation callback: creates the general option and runs migrations.
	 */
	public static function activate(): void {
		Options::register_defaults( 'general', self::GENERAL_DEFAULTS );
		Options::ensure( 'general' );
		( new Migrations( self::migrations() ) )->run();
		Modules\Recovery\Retention::schedule();
		Modules\Recovery\Scanner::schedule();
	}

	/**
	 * Deactivation callback: removes our Action Scheduler jobs so they do not fail every run while the
	 * plugin is inactive (activation and admin_init re-create the recurring ones). Data is kept.
	 */
	public static function deactivate(): void {
		foreach ( self::ACTION_HOOKS as $hook ) {
			Integration\ActionScheduler::unschedule_all( $hook );
		}
	}

	/**
	 * Migration registry (id => up callable).
	 *
	 * Future modules add entries such as '2026120101' => array( SomeMigration::class, 'up' ).
	 *
	 * @return array<int|string,callable():mixed>
	 */
	public static function migrations(): array {
		return array(
			'2026111801' => \Closure::fromCallable( array( Modules\Recovery\RecoveryMigration::class, 'up' ) ),
			'2026111901' => \Closure::fromCallable( array( Modules\Recovery\SuppressionMigration::class, 'up' ) ),
		);
	}

	/**
	 * Boots the plugin (hooked on plugins_loaded priority 20).
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Translations load on init so no translation function runs earlier.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
			return;
		}

		Options::register_defaults( 'general', self::GENERAL_DEFAULTS );

		if ( $this->context->is_admin() ) {
			add_action( 'admin_init', array( $this, 'run_migrations' ) );
			// WooCommerce applies this filter only while building its settings screens.
			add_filter( 'woocommerce_get_settings_pages', array( $this, 'register_settings_page' ) );
		}

		$this->register_modules();
	}

	/**
	 * Loads the plugin text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'motored-core', false, dirname( MOTORED_CORE_BASENAME ) . '/languages' );
	}

	/**
	 * Runs pending migrations (admin only).
	 */
	public function run_migrations(): void {
		( new Migrations( self::migrations() ) )->run();
	}

	/**
	 * Adds the "Motored" tab. The tab class is only autoloaded here.
	 *
	 * @param mixed $pages Settings pages.
	 * @return array<int,mixed>
	 */
	public function register_settings_page( $pages ) {
		$pages = is_array( $pages ) ? $pages : array();
		if ( class_exists( \WC_Settings_Page::class ) ) {
			$pages[] = new Admin\SettingsTab();
		}
		return $pages;
	}

	/**
	 * Admin notice shown when WooCommerce is not active.
	 */
	public function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Motored Core needs WooCommerce to be active. The plugin stays disabled until you activate it.', 'motored-core' )
		);
	}

	/**
	 * Modules registered for this request, keyed by id.
	 *
	 * @return array<string,Module>
	 */
	public function loaded_modules(): array {
		return $this->loaded;
	}

	/**
	 * Instantiates modules and registers only those whose should_load() approves.
	 */
	private function register_modules(): void {
		foreach ( $this->modules as $class_name ) {
			if ( ! is_subclass_of( $class_name, Module::class ) ) {
				Logger::error( 'Configured module does not implement the Module contract.', array( 'class' => $class_name ) );
				continue;
			}
			$module = new $class_name();
			if ( ! $module->should_load( $this->context ) ) {
				continue;
			}
			$module->register();
			$this->loaded[ $module->id() ] = $module;
		}
	}

	/**
	 * Whether WooCommerce is available.
	 */
	private function woocommerce_active(): bool {
		return null !== $this->woocommerce_check
			? (bool) ( $this->woocommerce_check )()
			: class_exists( 'WooCommerce' );
	}
}
