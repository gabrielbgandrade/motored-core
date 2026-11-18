<?php
namespace Motored\Core\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class ContextTest extends TestCase {

	/** @var array<string,mixed> */
	private array $server_backup = array();
	/** @var array<string,mixed> */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();
		$this->server_backup = $_SERVER;
		$this->get_backup    = $_GET;
		unset( $_SERVER['REQUEST_URI'] );
		$_GET = array();
		Functions\stubs(
			array(
				'sanitize_text_field' => null,
				'wp_unslash'          => null,
				'rest_get_url_prefix' => 'wp-json',
			)
		);
	}

	private function signals( bool $admin = false, bool $ajax = false, bool $cron = false ): void {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
		Functions\when( 'wp_doing_cron' )->justReturn( $cron );
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		$_GET    = $this->get_backup;
		parent::tearDown();
	}

	public function test_plain_request_is_frontend(): void {
		$_SERVER['REQUEST_URI'] = '/shop/';
		$this->signals();
		$context = new Context();
		$this->assertTrue( $context->is_frontend() );
		$this->assertFalse( $context->is_admin() );
		$this->assertFalse( $context->is_ajax() );
		$this->assertFalse( $context->is_rest() );
		$this->assertFalse( $context->is_cron() );
		$this->assertFalse( $context->is_cli() );
	}

	public function test_admin_is_not_frontend(): void {
		$this->signals( true );
		$context = new Context();
		$this->assertTrue( $context->is_admin() );
		$this->assertFalse( $context->is_frontend() );
	}

	public function test_rest_is_detected_early_from_uri(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/orders';
		$this->signals();
		$context = new Context();
		$this->assertTrue( $context->is_rest() );
		$this->assertFalse( $context->is_frontend() );
	}

	public function test_rest_route_query_arg(): void {
		$_GET['rest_route'] = '/wc/v3/orders';
		$this->signals();
		$this->assertTrue( ( new Context() )->is_rest() );
	}

	public function test_wc_ajax_counts_as_ajax(): void {
		$_GET['wc-ajax'] = 'update_order_review';
		$this->signals();
		$context = new Context();
		$this->assertTrue( $context->is_ajax() );
		$this->assertFalse( $context->is_frontend() );
	}

	public function test_cron(): void {
		$this->signals( false, false, true );
		$context = new Context();
		$this->assertTrue( $context->is_cron() );
		$this->assertFalse( $context->is_frontend() );
	}

	public function test_results_are_memoized(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		$context = new Context();
		$this->assertTrue( $context->is_admin() );
		$this->assertTrue( $context->is_admin() );
	}
}
