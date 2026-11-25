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
		Modules\OrderTracking\OrderTrackingModule::class,
		Modules\Recovery\RecoveryModule::class,
		Modules\ReviewPhotos\ReviewPhotosModule::class,
		Modules\InstallGallery\InstallGalleryModule::class,
		Modules\ImageOptimizer\ImageOptimizerModule::class,
		Modules\ReviewRequests\ReviewRequestsModule::class,
		Modules\PaymentPlans\PaymentPlansModule::class,
		Modules\Compatibility\CompatibilityModule::class,
		Modules\MiGarage\MiGarageModule::class,
		Modules\CatalogFilters\CatalogFiltersModule::class,
		Modules\Seo\SeoModule::class,
		Modules\BikePages\BikePagesModule::class,
	);

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
		Modules\InstallGallery\GalleryStore::resume_backfill();
		Modules\Recovery\Retention::schedule();
		Modules\Recovery\Scanner::schedule();
		Modules\BikePages\DemandStore::schedule_purge();
		delete_option( Modules\MiGarage\GarageEndpoint::REWRITE_OPT );
		delete_option( Modules\BikePages\PostType::REWRITE_VER_OPT );
		delete_option( Modules\BikePages\SizePages::REWRITE_VER_OPT );
	}

	/**
	 * Deactivation callback: removes our Action Scheduler jobs so they do not fail every run while the
	 * plugin is inactive (activation and admin_init re-create the recurring ones). Data is kept.
	 */
	public static function deactivate(): void {
		Integration\ActionScheduler::unschedule_all();
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
			'2026112001' => \Closure::fromCallable( array( Modules\Compatibility\CompatibilityMigration::class, 'up' ) ),
			'2026112101' => \Closure::fromCallable( array( Modules\Compatibility\CompatibilityMigration::class, 'up_2026112101' ) ),
			'2026112201' => \Closure::fromCallable( array( Modules\Compatibility\CompatibilityMigration::class, 'up' ) ),
			'2026112301' => \Closure::fromCallable( array( Modules\Compatibility\CompatibilityMigration::class, 'up_2026112301' ) ),
			'2026112302' => \Closure::fromCallable( array( Modules\Compatibility\CompatibilityMigration::class, 'up' ) ),
			'2026112401' => \Closure::fromCallable( array( Modules\InstallGallery\GalleryStore::class, 'schedule_backfill' ) ),
			'2026112402' => static function (): bool {
				return Modules\Recovery\RecoveryMigration::up()
					&& Modules\Recovery\SuppressionMigration::up()
					&& Modules\Compatibility\CompatibilityMigration::up();
			},
			// Kit per-axle sheet: front_construction / rear_construction on the product index.
			'2026112403' => \Closure::fromCallable( array( Modules\Compatibility\CompatibilityMigration::class, 'up' ) ),
			// Repairs index rows that stored a term ID as size ID (ensure_size() insert_id bug).
			'2026112404' => \Closure::fromCallable( array( Modules\Compatibility\CompatibilityMigration::class, 'up_2026112404' ) ),
			// SEO 301/302 redirects table.
			'2026112405' => \Closure::fromCallable( array( Modules\Seo\SeoMigration::class, 'up' ) ),
			// BikePages demand capture table.
			'2026112406' => \Closure::fromCallable( array( Modules\BikePages\DemandMigration::class, 'up' ) ),
			// Demand delivery lifecycle and authenticated contact ownership.
			'2026112407' => \Closure::fromCallable( array( Modules\BikePages\DemandMigration::class, 'up' ) ),
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
			add_action( 'admin_enqueue_scripts', array( Admin\AdminAssets::class, 'enqueue' ) );
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
		if ( ! $this->context->is_admin_screen() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		( new Migrations( self::migrations() ) )->run();
		Modules\InstallGallery\GalleryStore::resume_backfill();
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
