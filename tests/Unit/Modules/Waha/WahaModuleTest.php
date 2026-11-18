<?php
namespace Motored\Core\Tests\Unit\Modules\Waha;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Modules\Waha\WahaModule;
use Motored\Core\Support\Context;
use Motored\Core\Tests\Unit\TestCase;

final class WahaModuleTest extends TestCase {

	private function context( bool $admin, bool $ajax = false, bool $cron = false, bool $cli = false, bool $rest = false ): Context {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
		Functions\when( 'wp_doing_cron' )->justReturn( $cron );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_SERVER['REQUEST_URI'] = $rest ? '/wp-json/x' : '/';
		return new Context();
	}

	public function test_id(): void {
		$this->assertSame( 'waha', ( new WahaModule() )->id() );
	}

	public function test_should_load_only_where_needed(): void {
		$module = new WahaModule();
		$this->assertFalse( $module->should_load( $this->context( false ) ), 'public page' );
		$this->assertFalse( $module->should_load( $this->context( false, false, false, false, true ) ), 'REST' );
		$this->assertTrue( $module->should_load( $this->context( true ) ), 'admin' );
		$this->assertTrue( $module->should_load( $this->context( false, false, true ) ), 'cron' );
		$this->assertTrue( $module->should_load( $this->context( false, true ) ), 'ajax (Action Scheduler async)' );
	}

	public function test_job_hook_accepts_every_scheduled_argument_including_priority(): void {
		$this->context( false, false, true );
		Actions\expectAdded( Sender::HOOK )->once()->with( array( Sender::class, 'run' ), 10, 5 );
		( new WahaModule() )->register();
	}

	public function test_register_outside_admin_only_hooks_the_job(): void {
		$this->context( false, false, true );
		( new WahaModule() )->register();

		$this->assertNotFalse( has_action( Sender::HOOK, array( Sender::class, 'run' ) ) );
		$this->assertNull( SectionRegistry::get( 'whatsapp' ) );
		$this->assertFalse( has_filter( 'motored_status_rows' ) );
	}

	public function test_register_in_admin_adds_section_and_tools(): void {
		$this->context( true );
		( new WahaModule() )->register();

		$this->assertNotNull( SectionRegistry::get( 'whatsapp' ) );
		$this->assertNotFalse( has_action( 'admin_post_motored_waha_test_connection' ) );
		$this->assertNotFalse( has_action( 'admin_post_motored_waha_test_message' ) );
		$this->assertNotFalse( has_filter( 'motored_status_rows' ) );
	}
}
