<?php
namespace Motored\Core\Tests\Unit\Scenarios;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Integration\Waha\WahaClient;
use Motored\Core\Modules\OrderNotifications\Config as OnConfig;
use Motored\Core\Modules\OrderNotifications\Notifier;
use Motored\Core\Modules\OrderNotifications\Renderer;
use Motored\Core\Modules\Recovery\Config as RecoveryConfig;
use Motored\Core\Modules\Recovery\RecoveryResultHandler;
use Motored\Core\Modules\Recovery\Retention;
use Motored\Core\Modules\Recovery\Scanner;
use Motored\Core\Modules\ReviewRequests\Config as ReviewConfig;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Plugin;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

/**
 * End-to-end scenarios chaining the real classes (no WordPress): scheduling -> Sender -> handler -> result.
 */
final class FlowsTest extends TestCase {

	private const NOW = 1700000000;

	/** @var list<array<int,mixed>> Args of every as_schedule_single_action call. */
	private array $scheduled = array();
	/** @var list<string> */
	private array $notes = array();
	/** @var list<array{string,mixed}> */
	private array $updates = array();
	/** @var array<string,mixed>|null */
	private ?array $row = null;

	protected function setUp(): void {
		parent::setUp();
		Sender::reset();
		$this->fake_storage();
		$this->fake_logger();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_timezone' )->alias( fn() => new \DateTimeZone( 'America/Argentina/Buenos_Aires' ) );
		Functions\when( 'wp_date' )->alias( fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( $format ) );
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $text ) => strip_tags( (string) $text ) );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( $when, $hook, $args, $group ) {
				$this->scheduled[] = array( $when, $hook, $args, $group );
				return 99;
			}
		);
		$this->scheduled = array();
		$this->notes     = array();
		$this->updates   = array();
		$this->row       = null;
		$this->options['motored_waha'] = array( 'typing' => 'no', 'enabled' => 'yes', 'test_mode' => 'no' );
	}

	protected function tearDown(): void {
		Sender::reset();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function sender( WahaClient $client ): Sender {
		return new Sender( $client, fn() => self::NOW, fn() => 5, fn() => 30, fn() => null );
	}

	private function order(): \Mockery\MockInterface {
		$meta  = array();
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'get_order_number' )->andReturn( '1042' );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_created_via' )->andReturn( 'checkout' );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( 'Ana' );
		$order->shouldReceive( 'get_billing_phone' )->andReturn( '011 15 5555-1234' );
		$order->shouldReceive( 'get_billing_country' )->andReturn( 'AR' );
		$order->shouldReceive( 'get_total' )->andReturn( '25000' );
		$order->shouldReceive( 'get_currency' )->andReturn( 'ARS' );
		$order->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'https://example.test/o/42' );
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( $k ) => $meta[ $k ] ?? '' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			function ( $k, $v ) use ( &$meta ): void {
				$meta[ $k ] = $v;
			}
		);
		$order->shouldReceive( 'save_meta_data' );
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( $note ): int {
				$this->notes[] = $note;
				return 1;
			}
		);
		return $order;
	}

	/** Flow a: status change -> Notifier -> Sender queue (HIGH) -> job -> handler -> WAHA -> note. */
	public function test_order_status_change_reaches_whatsapp_and_leaves_an_order_note(): void {
		Options::register_defaults( OnConfig::OPTION_KEY, OnConfig::DEFAULTS );
		$order  = $this->order();
		$client = \Mockery::mock( WahaClient::class );
		$client->shouldReceive( 'sessionStatus' )->andReturn( Result::success( 200, array( 'status' => 'WORKING' ) ) );
		$client->shouldReceive( 'checkExists' )->andReturn( Result::success( 200, array( 'exists' => true, 'chat_id' => '5491155551234@c.us' ) ) );
		$client->shouldReceive( 'sendText' )->once()->andReturn( Result::success( 201 ) );

		$sender = $this->sender( $client );
		$notifier = new Notifier(
			fn( int $id ) => $order,
			fn( string $kind, int $id, ?string $step ): bool => $sender->queue( $kind, $id, $step, 0, Sender::HIGH ),
			fn() => '',
			fn( string $cc ): string => '54',
			fn( string $t, string $c ): string => $t,
			fn(): string => 'Motored'
		);
		Sender::register_handler( 'order_event', array( $notifier, 'build_message' ) );
		Actions\expectDone( Sender::RESULT_HOOK )->once()->whenHappen(
			function ( $kind, $id, $step, $result ) use ( $notifier ): void {
				$notifier->on_result( $kind, $id, $step, $result );
			}
		);

		$notifier->on_status_changed( 42, 'pending', 'processing', $order );

		$this->assertCount( 1, $this->scheduled );
		$args = $this->scheduled[0][2];
		$this->assertSame( array( 'order_event', 42, 'processing', 1, 'high' ), $args, 'Priority travels with the job.' );
		$sender->handle( ...$args );
		$this->assertSame( array( 'WhatsApp sent: processing' ), $this->notes );
	}

	/** Every argument of a scheduled job must reach Sender::run, or priority silently becomes "normal". */
	public function test_scheduled_job_args_match_the_hook_registration(): void {
		$this->sender( \Mockery::mock( WahaClient::class ) )->queue( 'order_event', 1, 'processing', 0, Sender::LOW );
		$this->assertCount( Sender::HOOK_ARGS, $this->scheduled[0][2] );
		$this->assertSame( Sender::HOOK_ARGS, ( new \ReflectionMethod( Sender::class, 'run' ) )->getNumberOfParameters() );
	}

	/** Flow b/c: a skipped WhatsApp step must not leave the claimed row active with no next action. */
	public function test_skipped_recovery_step_is_released_instead_of_stuck(): void {
		Options::register_defaults( RecoveryConfig::OPTION_KEY, RecoveryConfig::DEFAULTS );
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn() => $this->row );
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data ) {
				$this->updates[] = $data;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$wpdb->insert_id = 1;
		$GLOBALS['wpdb'] = $wpdb;

		$base = array(
			'id'                => 7,
			'type'              => 'cart',
			'status'            => 'active',
			'step'              => '',
			'last_activity_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
			'next_action_gmt'   => null, // Cleared by Repository::claim_due().
			'wa_sent_count'     => 0,
			'email_sent_count'  => 0,
		);

		$this->row = $base;
		RecoveryResultHandler::on_result( 'recovery_step', 7, '1', Result::failure( 'skipped', 'x' ) );
		$this->assertNotEmpty( $this->updates, 'Row moved past the skipped step.' );
		$this->assertSame( '1', $this->updates[0]['step'] );
		$this->assertTrue( isset( $this->updates[0]['next_action_gmt'] ) || 'expired' === ( $this->updates[0]['status'] ?? '' ) );

		// Rescheduled by the sending-window check (next action set) or already closed: untouched.
		$this->updates = array();
		$this->row     = array_merge( $base, array( 'next_action_gmt' => '2030-01-01 12:00:00' ) );
		RecoveryResultHandler::on_result( 'recovery_step', 7, '1', Result::failure( 'skipped', 'x' ) );
		$this->row = array_merge( $base, array( 'status' => 'converted' ) );
		RecoveryResultHandler::on_result( 'recovery_step', 7, '1', Result::failure( 'skipped', 'x' ) );
		$this->assertSame( array(), $this->updates );
	}

	/** Consistency: kinds, hooks, jobs and placeholders agree across modules. */
	public function test_action_hooks_are_the_ones_the_modules_register(): void {
		$this->assertEqualsCanonicalizing( array( Sender::HOOK, Retention::HOOK, Scanner::HOOK, \Motored\Core\Modules\PaymentPlans\Reconciler::HOOK ), Plugin::ACTION_HOOKS );
		$uninstall = (string) file_get_contents( dirname( __DIR__, 3 ) . '/uninstall.php' );
		foreach ( Plugin::ACTION_HOOKS as $hook ) {
			$this->assertStringContainsString( "'" . $hook . "'", $uninstall, 'uninstall.php unschedules ' . $hook );
		}
	}

	public function test_every_enqueued_kind_has_a_registered_handler(): void {
		$src   = '';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src/Modules' ) );
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$src .= file_get_contents( $file->getPathname() );
			}
		}
		$kinds = array(
			\Motored\Core\Modules\OrderNotifications\EventMap::KIND,
			\Motored\Core\Modules\Recovery\RecoveryResultHandler::KIND,
			\Motored\Core\Modules\ReviewRequests\Scheduler::KIND,
			\Motored\Core\Modules\OrderTracking\TrackingNotifier::KIND,
		);
		$this->assertSame( 4, count( array_unique( $kinds ) ) );
		preg_match_all( '/^\s*Sender::register_handler\(/m', $src, $m );
		$this->assertCount( 4, $m[0], 'One register_handler per kind.' );
		preg_match_all( "/Sender::enqueue\(\s*'([a-z_]+)'/", $src, $literal );
		foreach ( $literal[1] as $kind ) {
			$this->assertContains( $kind, $kinds );
		}
	}

	public function test_every_placeholder_in_default_texts_is_resolved_by_the_renderer(): void {
		$defaults = array(
			json_encode( OnConfig::DEFAULTS ),
			json_encode( RecoveryConfig::DEFAULTS ),
			json_encode( ReviewConfig::DEFAULTS ),
			json_encode( \Motored\Core\Modules\OrderTracking\Config::DEFAULTS ),
		);
		foreach ( $defaults as $blob ) {
			preg_match_all( '/\{[a-z_]+\}/', (string) $blob, $m );
			foreach ( array_unique( $m[0] ) as $placeholder ) {
				$this->assertContains( $placeholder, Renderer::PLACEHOLDERS, $placeholder . ' is not resolved by Renderer.' );
			}
		}
	}
}
