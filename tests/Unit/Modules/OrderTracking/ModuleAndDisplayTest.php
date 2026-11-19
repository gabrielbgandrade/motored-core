<?php
namespace Motored\Core\Tests\Unit\Modules\OrderTracking;

use Brain\Monkey\Functions;
use Motored\Core\Modules\OrderTracking\Config;
use Motored\Core\Modules\OrderTracking\CustomerDisplay;
use Motored\Core\Modules\OrderTracking\OrderTrackingAdmin;
use Motored\Core\Modules\OrderTracking\OrderTrackingModule;
use Motored\Core\Modules\OrderTracking\Tracking;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class ModuleAndDisplayTest extends TestCase {

	/** @var array<string,mixed> */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$this->options['motored_order_tracking'] = array(
			'carriers' => array( array( 'id' => 'andreani', 'name' => 'Andreani', 'url' => 'https://a.com/t/{code}', 'active' => 'yes' ) ),
		);
		$this->meta = array( Tracking::META => array( 'carrier_id' => 'andreani', 'code' => 'AB123', 'url_override' => '', 'updated_gmt' => 1 ) );
	}

	protected function tearDown(): void {
		Sender::reset();
		parent::tearDown();
	}

	private function order( int $id = 42 ): \WC_Order {
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( string $k ) => $this->meta[ $k ] ?? '' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( string $k, $v ): void {
				$this->meta[ $k ] = $v;
			}
		);
		$order->shouldReceive( 'save_meta_data' );
		return $order;
	}

	private function context( bool $admin ): Context {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'wp_unslash' )->returnArg();
		return new Context();
	}

	public function test_module_registers_hooks_by_context_and_always_loads(): void {
		$module = new OrderTrackingModule();
		$this->assertSame( 'order_tracking', $module->id() );
		$this->assertTrue( $module->should_load( $this->context( false ) ) );

		$module->register();
		$this->assertNotFalse( has_filter( 'woocommerce_email_classes', array( OrderTrackingModule::class, 'register_email_class' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_email_order_meta', array( CustomerDisplay::class, 'render_email' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_details_after_order_table', array( CustomerDisplay::class, 'render_order_page' ) ) );
		$this->assertFalse( has_action( 'add_meta_boxes' ), 'no admin hooks on the frontend' );
	}

	public function test_order_page_shows_escaped_data_only_when_enabled_and_present(): void {
		ob_start();
		CustomerDisplay::render_order_page( $this->order() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Andreani', $html );
		$this->assertStringContainsString( 'AB123', $html );
		$this->assertStringContainsString( 'href="https://a.com/t/AB123"', $html );

		$this->options['motored_order_tracking']['show_customer'] = 'no';
		Options::reset();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		ob_start();
		CustomerDisplay::render_order_page( $this->order() );
		$this->assertSame( '', ob_get_clean() );

		$this->meta = array();
		ob_start();
		CustomerDisplay::render_order_page( $this->order() );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_email_block_only_in_customer_processing_and_completed_emails(): void {
		$completed = (object) array( 'id' => 'customer_completed_order' );
		$order     = $this->order();

		ob_start();
		CustomerDisplay::render_email( $order, false, true, $completed );
		$plain = (string) ob_get_clean();
		$this->assertStringContainsString( 'AB123', $plain );
		$this->assertSame( md5( 'AB123' ), $this->meta[ Tracking::CARRIED_EMAIL ], 'completed e-mail marks the code as carried' );

		foreach ( array( (object) array( 'id' => 'new_order' ), (object) array( 'id' => 'motored_tracking' ), null ) as $other ) {
			ob_start();
			CustomerDisplay::render_email( $order, false, false, $other );
			$this->assertSame( '', ob_get_clean() );
		}
		ob_start();
		CustomerDisplay::render_email( $order, true, false, $completed );
		$this->assertSame( '', ob_get_clean(), 'never for the admin copy' );
	}

	public function test_admin_merge_validates_carrier_and_stamps_time_only_on_change(): void {
		$old = Tracking::from_array( $this->meta[ Tracking::META ] );

		$same = OrderTrackingAdmin::merge( $old, array( 'carrier_id' => 'andreani', 'code' => 'AB123', 'url_override' => '' ) );
		$this->assertSame( 1, $same->updated_gmt );

		$new = OrderTrackingAdmin::merge( $old, array( 'carrier_id' => 'ghost', 'code' => 'x 9<', 'url_override' => 'http://bad' ) );
		$this->assertSame( 'other', $new->carrier_id, 'unknown carrier falls back to manual' );
		$this->assertSame( 'x9', $new->code );
		$this->assertSame( '', $new->url_override );
		$this->assertGreaterThan( 1, $new->updated_gmt );
	}

	public function test_meta_box_save_requires_nonce_and_capability(): void {
		$order = $this->order();
		$_POST = array( OrderTrackingAdmin::BOX_KEY => array( 'carrier_id' => 'andreani', 'code' => 'NEW1' ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		( new OrderTrackingAdmin() )->save_meta_box( 42, $order );
		$this->assertSame( 'AB123', $this->meta[ Tracking::META ]['code'], 'no nonce field: untouched' );

		$_POST[ OrderTrackingAdmin::BOX_FIELD ] = 'bad';
		( new OrderTrackingAdmin() )->save_meta_box( 42, $order );
		$this->assertSame( 'AB123', $this->meta[ Tracking::META ]['code'], 'bad nonce: untouched' );

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );
		( new OrderTrackingAdmin() )->save_meta_box( 42, $order );
		$this->assertSame( 'AB123', $this->meta[ Tracking::META ]['code'], 'no capability: untouched' );
		$_POST = array();
	}
}
