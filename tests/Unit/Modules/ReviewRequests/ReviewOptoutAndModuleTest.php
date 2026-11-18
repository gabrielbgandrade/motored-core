<?php
namespace Motored\Core\Tests\Unit\Modules\ReviewRequests;

use Brain\Monkey\Functions;
use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Modules\ReviewRequests\Config;
use Motored\Core\Modules\ReviewRequests\RequestResult;
use Motored\Core\Modules\ReviewRequests\ReviewOptout;
use Motored\Core\Modules\ReviewRequests\ReviewRequestsModule;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class ReviewOptoutAndModuleTest extends TestCase {

	private function context( bool $admin, bool $ajax = false, bool $cron = false, string $method = 'GET' ): Context {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
		Functions\when( 'wp_doing_cron' )->justReturn( $cron );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_SERVER['REQUEST_URI']    = '/';
		$_SERVER['REQUEST_METHOD'] = $method;
		unset( $_GET['wc-api'] );
		return new Context();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
		Sender::reset();
		parent::tearDown();
	}

	public function test_optout_token_is_per_order_and_verified(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		$token = ReviewOptout::token( 7 );
		$this->assertSame( 32, strlen( $token ) );
		$this->assertTrue( ReviewOptout::verify( 7, strtoupper( $token ) ) );
		$this->assertFalse( ReviewOptout::verify( 8, $token ) );
		$this->assertFalse( ReviewOptout::verify( 7, 'abc' ) );
		$this->assertSame( array( 7, $token ), ReviewOptout::parse( '7.' . $token ) );
		$this->assertNull( ReviewOptout::parse( '7.xyz' ) );
	}

	public function test_config_defaults_and_clamps(): void {
		$this->fake_storage();
		$this->assertFalse( Config::enabled() );
		$this->assertSame( 'completed', Config::trigger_status() );
		$this->assertSame( 7, Config::delay_days() );
		$this->assertSame( 3, Config::max_products() );
		\Motored\Core\Support\Options::reset();
		$this->options['motored_review_requests'] = array( 'trigger_status' => 'WC-Despachado', 'delay_days' => '999', 'max_products' => '0', 'store_review_url' => 'javascript:x' );
		$this->assertSame( 'despachado', Config::trigger_status() );
		$this->assertSame( 60, Config::delay_days() );
		$this->assertSame( 3, Config::max_products() );
		$this->assertSame( '', Config::store_review_url() );
		$this->assertStringContainsString( '{review_links}', Config::text_template() );
	}

	public function test_result_note_has_no_phone_or_text_and_skips_are_silent(): void {
		Functions\when( '__' )->returnArg();
		$this->assertStringNotContainsString( '5491', RequestResult::note( true, false ) );
		Functions\expect( 'wc_get_order' )->never();
		RequestResult::on_result( 'review_request', 7, null, Result::failure( 'skipped', 'x' ) );
		RequestResult::on_result( 'other', 7, null, Result::success( 201 ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_public_get_hooks_only_the_optout_link(): void {
		$this->context( false );
		( new ReviewRequestsModule() )->register();
		$this->assertNotFalse( has_action( 'template_redirect' ) );
		$this->assertFalse( has_action( 'woocommerce_order_status_changed' ) );
		$this->assertFalse( has_action( Sender::RESULT_HOOK ) );
	}

	public function test_cron_hooks_status_listener_and_worker(): void {
		$this->context( false, false, true );
		( new ReviewRequestsModule() )->register();
		$this->assertNotFalse( has_action( 'woocommerce_order_status_changed' ) );
		$this->assertNotFalse( has_action( Sender::RESULT_HOOK ) );
		$this->assertFalse( has_action( 'template_redirect' ) );
	}

	public function test_admin_adds_the_shared_reviews_section_and_order_box(): void {
		$this->context( true );
		( new ReviewRequestsModule() )->register();
		$this->assertNotNull( SectionRegistry::get( 'reviews' ) );
		$this->assertNotFalse( has_filter( 'motored_reviews_section_fields' ) );
		$this->assertNotFalse( has_action( 'add_meta_boxes' ) );
		$this->assertNotFalse( has_action( 'admin_post_motored_review_request_send' ) );
	}

	public function test_post_requests_also_hook_status_changes(): void {
		$this->context( false, false, false, 'POST' );
		( new ReviewRequestsModule() )->register();
		$this->assertNotFalse( has_action( 'woocommerce_order_status_changed' ) );
	}

	public function test_registering_reads_no_options(): void {
		Functions\expect( 'get_option' )->never();
		$this->context( false );
		( new ReviewRequestsModule() )->register();
		$this->addToAssertionCount( 1 );
	}
}
