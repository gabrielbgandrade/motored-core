<?php
namespace Motored\Core\Tests\Unit\Modules\OrderNotifications;

use Brain\Monkey\Functions;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Modules\BikePages\DemandStore;
use Motored\Core\Modules\OrderNotifications\BikeDemandNotifier;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Tests\Unit\TestCase;

/** Tests delivery safety without sending mail or making external requests. */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
final class BikeDemandNotifierTest extends TestCase {

	private array $row;
	private array $jobs;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		$this->options['motored_waha'] = array( 'enabled' => 'yes', 'test_mode' => 'no' );
		$this->row = array(
			'id' => 7, 'status' => 'sending', 'email' => 'rider@example.test',
			'phone' => '5491112345678', 'channel' => 'whatsapp', 'version_slug' => '',
		);
		$this->jobs = array();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'as_schedule_single_action' )->alias( function ( $timestamp, $hook, $args, $group, $unique ) {
			$this->jobs[] = array( $hook, $args, $group, $unique );
			return 10;
		} );
		$db = \Mockery::mock( 'wpdb_fake' );
		$db->prefix = 'wp_';
		$db->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$db->shouldReceive( 'get_row' )->andReturnUsing( fn() => $this->row );
		$db->shouldReceive( 'update' )->andReturnUsing( function ( $table, $data, $where ) {
			if ( $where['status'] !== $this->row['status'] ) return 0;
			$this->row = array_merge( $this->row, $data );
			return 1;
		} );
		$GLOBALS['wpdb'] = $db;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_success_is_terminal_and_duplicate_results_do_not_enqueue_fallback(): void {
		$notifier = new BikeDemandNotifier();
		$notifier->on_result( BikeDemandNotifier::KIND, 7, null, Result::success( 200 ) );
		$this->assertSame( 'notified', $this->row['status'] );
		$this->assertNotEmpty( $this->row['notified_gmt'] );
		$notifier->on_result( BikeDemandNotifier::KIND, 7, null, Result::failure( 'timeout', 'Failed' ) );
		$this->assertSame( 'notified', $this->row['status'] );
		$this->assertSame( array(), $this->jobs );
	}

	public function test_unreachable_whatsapp_queues_email_only_after_terminal_failure(): void {
		( new BikeDemandNotifier() )->on_result( BikeDemandNotifier::KIND, 7, null, Result::failure( 'no_whatsapp', 'Unavailable' ) );
		$this->assertSame( 'queued', $this->row['status'] );
		$this->assertSame( 'email', $this->row['channel'] );
		$this->assertSame( array( BikeDemandNotifier::MAIL_HOOK, array( 7, 1 ), 'motored-core', true ), $this->jobs[0] );
		$this->assertStringNotContainsString( 'rider@', json_encode( $this->jobs ) );
		$this->assertStringNotContainsString( '54911', json_encode( $this->jobs ) );
	}

	public function test_whatsapp_only_contact_never_invents_an_email_recipient(): void {
		$this->row['email'] = '';
		( new BikeDemandNotifier() )->on_result( BikeDemandNotifier::KIND, 7, null, Result::failure( 'no_whatsapp', 'Unavailable' ) );
		$this->assertSame( 'failed', $this->row['status'] );
		$this->assertSame( array(), $this->jobs );
	}

	public function test_test_mode_and_disabled_mode_are_never_bypassed_with_email(): void {
		foreach ( array( Sender::CODE_DISABLED, Sender::CODE_TEST_MODE, 'auth', 'not_configured' ) as $code ) {
			$this->row['status'] = 'queued';
			( new BikeDemandNotifier() )->on_result( BikeDemandNotifier::KIND, 7, null, Result::failure( $code, 'Paused' ) );
			$this->assertSame( 'paused', $this->row['status'] );
		}
		$this->assertSame( array(), $this->jobs );
	}

	public function test_email_worker_honors_the_kill_switch(): void {
		$this->options['motored_waha'] = array( 'enabled' => 'no' );
		$this->row['status'] = 'queued';
		$this->row['channel'] = 'email';
		Functions\expect( 'wc_mail' )->never();
		( new BikeDemandNotifier() )->send_mail( 7 );
		$this->assertSame( 'paused', $this->row['status'] );
	}

	public function test_compare_and_swap_prevents_a_second_claim(): void {
		$this->assertTrue( DemandStore::transition( 7, 'sending', 'notified' ) );
		$this->assertFalse( DemandStore::transition( 7, 'sending', 'notified' ) );
	}

	public function test_unresolved_legacy_version_does_not_guess_a_motorcycle(): void {
		$this->assertFalse( BikeDemandNotifier::available( $this->row ) );
		$this->assertNull( ( new BikeDemandNotifier() )->build_message( 7 ) );
		$this->assertSame( 'pending', $this->row['status'] );
	}

	/**
	 * Fakes WC()->mailer()->get_emails() with a native stock-alert e-mail in the given state.
	 */
	private function fake_mailer( bool $enabled ): void {
		if ( ! class_exists( 'WC_Email' ) ) {
			eval( 'class WC_Email { public $id; public $title; public $description; public $customer_email; public $template_html; public $template_plain; public $template_base; public $placeholders = array(); public function __construct() {} }' );
		}
		$email = \Mockery::mock( \Motored\Core\Modules\OrderNotifications\Emails\StockAlertEmail::class );
		$email->shouldReceive( 'is_enabled' )->andReturn( $enabled );
		$mailer = new class( $email ) {
			public function __construct( private object $email ) {}
			public function get_emails(): array {
				return array( \Motored\Core\Modules\OrderNotifications\Emails\StockAlertEmail::CLASS_KEY => $this->email );
			}
		};
		$wc = new class( $mailer ) {
			public function __construct( private object $mailer ) {}
			public function mailer(): object {
				return $this->mailer;
			}
		};
		Functions\when( 'WC' )->justReturn( $wc );
	}

	public function test_native_email_is_registered_with_woocommerce(): void {
		$this->fake_mailer( true );
		Functions\when( '__' )->returnArg();
		$emails = BikeDemandNotifier::register_email_class( array() );
		$this->assertArrayHasKey( \Motored\Core\Modules\OrderNotifications\Emails\StockAlertEmail::CLASS_KEY, $emails );
	}

	public function test_whatsapp_failure_does_not_queue_an_email_the_owner_disabled(): void {
		$this->fake_mailer( false );
		( new BikeDemandNotifier() )->on_result( BikeDemandNotifier::KIND, 7, null, Result::failure( 'no_whatsapp', 'Unavailable' ) );
		$this->assertSame( 'failed', $this->row['status'] );
		$this->assertSame( array(), $this->jobs );
	}

	public function test_whatsapp_failure_falls_back_to_an_enabled_native_email(): void {
		$this->fake_mailer( true );
		( new BikeDemandNotifier() )->on_result( BikeDemandNotifier::KIND, 7, null, Result::failure( 'no_whatsapp', 'Unavailable' ) );
		$this->assertSame( 'queued', $this->row['status'] );
		$this->assertSame( BikeDemandNotifier::MAIL_HOOK, $this->jobs[0][0] );
	}

	public function test_other_message_kinds_are_ignored(): void {
		( new BikeDemandNotifier() )->on_result( 'order', 7, null, Result::success( 200 ) );
		$this->assertSame( 'sending', $this->row['status'] );
		$this->assertSame( array(), $this->jobs );
	}
}
