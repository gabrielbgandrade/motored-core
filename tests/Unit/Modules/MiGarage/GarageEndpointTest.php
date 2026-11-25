<?php
namespace Motored\Core\Tests\Unit\Modules\MiGarage;

use Brain\Monkey\Functions;
use Motored\Core\Modules\MiGarage\GarageEndpoint;
use Motored\Core\Modules\MiGarage\MiGarageModule;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class GarageEndpointTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'EP_ROOT' ) ) {
			define( 'EP_ROOT', 64 );
		}
		if ( ! defined( 'EP_PAGES' ) ) {
			define( 'EP_PAGES', 4096 );
		}
		$this->fake_storage();
	}

	public function test_registers_wc_and_wp_query_vars_and_title(): void {
		Functions\when( '__' )->returnArg();
		Functions\expect( 'add_shortcode' )->once()->with( GarageEndpoint::SHORTCODE, \Mockery::type( 'array' ) );
		$this->options['motored_compatibility'] = array( 'enabled' => 'yes' );

		$endpoint = new GarageEndpoint();
		$endpoint->register();

		$this->assertSame( 0, has_action( 'init', array( $endpoint, 'add_endpoint' ) ) );
		$this->assertSame( 0, has_filter( 'query_vars', array( $endpoint, 'add_query_vars' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_get_query_vars', array( $endpoint, 'add_wc_query_vars' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_endpoint_mi-garage_title', array( $endpoint, 'endpoint_title' ) ) );

		$this->assertSame( array( 'orders', 'mi-garage' ), $endpoint->add_query_vars( array( 'orders' ) ) );
		$this->assertSame( array( 'mi-garage' ), $endpoint->add_query_vars( array( 'mi-garage' ) ) );
		$this->assertSame(
			array(
				'orders'    => 'orders',
				'mi-garage' => 'mi-garage',
			),
			$endpoint->add_wc_query_vars( array( 'orders' => 'orders' ) )
		);
		$this->assertSame( 'My Garage', $endpoint->endpoint_title( 'My Account' ) );
	}

	public function test_flushes_when_version_changes_and_skips_when_rule_present(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\expect( 'add_rewrite_endpoint' )->once()->with( 'mi-garage', EP_ROOT | EP_PAGES );
		Functions\expect( 'flush_rewrite_rules' )->once()->with( false );

		$this->options[ GarageEndpoint::REWRITE_OPT ] = '1';
		$this->options['rewrite_rules']               = array(
			'(.?.+?)/mi-garage(/(.*))?/?$' => 'index.php?pagename=$matches[1]&mi-garage=$matches[3]',
		);

		$endpoint = new GarageEndpoint();
		$endpoint->maybe_flush_rewrite_rules();

		$this->assertSame( GarageEndpoint::REWRITE_VERSION, $this->options[ GarageEndpoint::REWRITE_OPT ] );
		$this->assertFalse( $this->autoload[ GarageEndpoint::REWRITE_OPT ] );

		// Second call sees current version and existing rule -> no flush.
		$endpoint->maybe_flush_rewrite_rules();
	}

	public function test_self_heals_when_pretty_rules_lack_mi_garage_and_respects_lock(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\expect( 'add_rewrite_endpoint' )->once()->with( 'mi-garage', EP_ROOT | EP_PAGES );
		Functions\expect( 'flush_rewrite_rules' )->once()->with( false );

		$this->options[ GarageEndpoint::REWRITE_OPT ] = GarageEndpoint::REWRITE_VERSION;
		$this->options['rewrite_rules']               = array(
			'shop/?$' => 'index.php?post_type=product',
		);

		$endpoint = new GarageEndpoint();
		$this->assertTrue( $endpoint->is_missing_from_rewrite_rules() );

		$endpoint->maybe_flush_rewrite_rules();
		$this->assertArrayHasKey( GarageEndpoint::REWRITE_HEAL_LOCK, $this->transients );

		// Lock prevents repeated flushes within 5 minutes even if rules remain unchanged.
		$endpoint->maybe_flush_rewrite_rules();
	}

	public function test_does_not_treat_plain_permalinks_as_missing_rules(): void {
		$this->options['rewrite_rules'] = '';
		$endpoint                       = new GarageEndpoint();
		$this->assertFalse( $endpoint->is_missing_from_rewrite_rules() );
	}

	public function test_module_registers_rewrite_hooks_in_cron_without_rest_or_menu(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );

		$module = new MiGarageModule();
		$this->assertTrue( $module->should_load( new Context() ) );
		$module->register();

		$this->assertNotFalse( has_action( 'init' ) );
		$this->assertNotFalse( has_filter( 'woocommerce_get_query_vars' ) );
		$this->assertFalse( has_action( 'rest_api_init' ) );
		$this->assertFalse( has_filter( 'woocommerce_account_menu_items' ) );
	}
}
