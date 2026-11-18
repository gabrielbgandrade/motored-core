<?php
namespace Motored\Core\Tests\Unit\Modules\Waha;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Integration\Waha\WahaClient;
use Motored\Core\Modules\Waha\Config;
use Motored\Core\Modules\Waha\Message;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Modules\Waha\WahaAdmin;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class DeliveryModeTest extends TestCase {

	private const NOW = 1700000000;

	/** @var list<Result> */
	private array $finished = array();
	private int $built      = 0;

	protected function setUp(): void {
		parent::setUp();
		Sender::reset();
		$this->fake_storage();
		$this->fake_logger();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_timezone' )->alias( fn() => new \DateTimeZone( 'America/Argentina/Buenos_Aires' ) );
		Functions\when( 'wp_date' )->alias( fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( $format ) );
		$this->finished = array();
		$this->built    = 0;
		Actions\expectDone( Sender::RESULT_HOOK )->zeroOrMoreTimes()->whenHappen(
			function ( $kind, $id, $step, $result ): void {
				$this->finished[] = $result;
			}
		);
		Sender::register_handler(
			'order_event',
			function (): Message {
				++$this->built;
				return new Message( '+5491155551234', 'Hola' );
			}
		);
	}

	protected function tearDown(): void {
		Sender::reset();
		parent::tearDown();
	}

	private function set( array $waha ): void {
		Options::reset();
		$this->options['motored_waha'] = array_merge( array( 'typing' => 'no' ), $waha );
	}

	private function client_expecting_send( bool $send ): WahaClient {
		$client = \Mockery::mock( WahaClient::class );
		if ( $send ) {
			$client->shouldReceive( 'sessionStatus' )->andReturn( Result::success( 200, array( 'status' => 'WORKING' ) ) );
			$client->shouldReceive( 'checkExists' )->andReturn( Result::success( 200, array( 'exists' => true, 'chat_id' => '5491155551234@c.us' ) ) );
			$client->shouldReceive( 'sendText' )->once()->andReturn( Result::success( 201 ) );
		} else {
			$client->shouldNotReceive( 'sessionStatus' );
			$client->shouldNotReceive( 'checkExists' );
			$client->shouldNotReceive( 'sendText' );
		}
		return $client;
	}

	private function sender( WahaClient $client ): Sender {
		return new Sender( $client, fn() => self::NOW, fn() => 5, fn() => 30, fn() => null );
	}

	public function test_defaults_are_off_with_test_mode_on(): void {
		$this->assertSame( 'no', Config::DEFAULTS['enabled'] );
		$this->assertSame( 'yes', Config::DEFAULTS['test_mode'] );
		$this->assertSame( Config::MODE_OFF, Config::mode() );
		$this->set( array( 'enabled' => 'yes' ) );
		$this->assertSame( Config::MODE_TEST, Config::mode(), 'First time on: test mode until turned off explicitly.' );
		$this->set( array( 'enabled' => 'yes', 'test_mode' => 'no' ) );
		$this->assertSame( Config::MODE_LIVE, Config::mode() );
		$this->set( array( 'enabled' => 'no', 'test_mode' => 'no' ) );
		$this->assertSame( Config::MODE_OFF, Config::mode(), 'The master switch wins.' );
	}

	public function test_allowed_list_is_normalized_deduplicated_and_ignores_garbage(): void {
		$this->set( array( 'test_numbers' => "011 15 5555-1234\n+54 9 11 5555 1234\r\n\nabc; 54 9 11 4444-0000, 12" ) );
		$this->assertSame( array( '+5491155551234', '+5491144440000' ), Config::test_numbers() );
	}

	public function test_may_send_to_by_mode(): void {
		$this->set( array( 'enabled' => 'no', 'test_mode' => 'no' ) );
		$this->assertFalse( Config::may_send_to( '+5491155551234' ) );
		$this->set( array( 'enabled' => 'yes', 'test_numbers' => '011 15 5555-1234' ) );
		$this->assertTrue( Config::may_send_to( '+5491155551234' ) );
		$this->assertTrue( Config::may_send_to( '011 15 5555-1234' ), 'Any format of an allowed number.' );
		$this->assertFalse( Config::may_send_to( '+5491100000000' ) );
		$this->assertFalse( Config::may_send_to( 'nonsense' ) );
		$this->set( array( 'enabled' => 'yes', 'test_mode' => 'yes', 'test_numbers' => '' ) );
		$this->assertFalse( Config::may_send_to( '+5491155551234' ), 'Empty list: nobody.' );
		$this->set( array( 'enabled' => 'yes', 'test_mode' => 'no' ) );
		$this->assertTrue( Config::may_send_to( '+5491100000000' ) );
	}

	public function test_switch_off_discards_without_building_or_sending(): void {
		$this->set( array() );
		$this->sender( $this->client_expecting_send( false ) )->handle( 'order_event', 1, 'processing' );
		$this->assertSame( 0, $this->built );
		$this->assertCount( 1, $this->finished );
		$this->assertSame( Sender::CODE_DISABLED, $this->finished[0]->code );
		$this->assertFalse( $this->finished[0]->retryable );
	}

	public function test_test_mode_discards_numbers_outside_the_list(): void {
		$this->set( array( 'enabled' => 'yes', 'test_numbers' => '011 4444-0000' ) );
		$this->sender( $this->client_expecting_send( false ) )->handle( 'order_event', 1, 'processing' );
		$this->assertSame( 1, $this->built );
		$this->assertSame( Sender::CODE_TEST_MODE, $this->finished[0]->code );
	}

	public function test_test_mode_sends_to_an_allowed_number(): void {
		$this->set( array( 'enabled' => 'yes', 'test_numbers' => '011 15 5555-1234' ) );
		$this->sender( $this->client_expecting_send( true ) )->handle( 'order_event', 1, 'processing' );
		$this->assertTrue( $this->finished[0]->ok );

	}

	public function test_live_mode_sends_to_anyone(): void {
		$this->set( array( 'enabled' => 'yes', 'test_mode' => 'no' ) );
		$this->sender( $this->client_expecting_send( true ) )->handle( 'order_event', 1, 'processing' );
		$this->assertTrue( $this->finished[0]->ok );
	}

	public function test_discard_notes_exist_only_for_the_two_codes(): void {
		$this->assertStringContainsString( 'turned off', (string) Sender::discard_note( Sender::CODE_DISABLED ) );
		$this->assertStringContainsString( 'test mode', (string) Sender::discard_note( Sender::CODE_TEST_MODE ) );
		$this->assertNull( Sender::discard_note( 'timeout' ) );
	}

	public function test_status_labels_and_notices_cover_the_three_states(): void {
		$this->set( array( 'enabled' => 'yes', 'test_numbers' => "011 4444-0000\n011 4444-0001" ) );
		$this->assertStringContainsString( '2', WahaAdmin::mode_label( Config::MODE_TEST ) );
		$this->assertNotSame( WahaAdmin::mode_label( Config::MODE_OFF ), WahaAdmin::mode_label( Config::MODE_LIVE ) );
		$this->assertStringStartsWith( 'OFF', WahaAdmin::mode_notice( Config::MODE_OFF ) );
		$this->assertStringStartsWith( 'TEST MODE', WahaAdmin::mode_notice( Config::MODE_TEST ) );
		$this->assertStringStartsWith( 'LIVE', WahaAdmin::mode_notice( Config::MODE_LIVE ) );
	}

	public function test_recovery_is_off_by_default_and_other_modules_keep_their_defaults(): void {
		$this->assertSame( 'no', \Motored\Core\Modules\Recovery\Config::DEFAULTS['enabled'] );
		$this->assertSame( 'no', \Motored\Core\Modules\ReviewRequests\Config::DEFAULTS['enabled'] );
		$this->assertSame( 'no', \Motored\Core\Modules\ReviewPhotos\Config::DEFAULTS['enabled'] );
		$this->assertSame( 'yes', \Motored\Core\Modules\OrderNotifications\Config::DEFAULTS['processing']['enabled'] );
	}
}
