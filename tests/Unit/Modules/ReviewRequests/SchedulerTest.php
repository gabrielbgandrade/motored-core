<?php
namespace Motored\Core\Tests\Unit\Modules\ReviewRequests;

use Brain\Monkey\Functions;
use Motored\Core\Modules\ReviewRequests\Scheduler;
use Motored\Core\Tests\Unit\TestCase;

final class SchedulerTest extends TestCase {

	private const NOW = 1700000000;

	/** @var list<array{int,int}> */
	private array $queued = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Functions\when( 'wp_timezone' )->alias( fn() => new \DateTimeZone( 'America/Argentina/Buenos_Aires' ) );
		$this->options['motored_review_requests'] = array( 'enabled' => 'yes', 'trigger_status' => 'wc-Completed', 'delay_days' => '7' );
		$this->queued                             = array();
	}

	private function scheduler( bool $ok = true ): Scheduler {
		return new Scheduler(
			function ( int $id, int $delay ) use ( $ok ): bool {
				$this->queued[] = array( $id, $delay );
				return $ok;
			},
			fn(): int => self::NOW
		);
	}

	private function order( string $meta = '' ) {
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_motored_review_req', true )->andReturn( $meta );
		return $order;
	}

	public function test_schedules_one_job_with_the_delay_and_stores_the_meta(): void {
		$order = $this->order();
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_motored_review_req', (string) ( self::NOW + 7 * 86400 ) );
		$order->shouldReceive( 'save_meta_data' )->once();

		$this->scheduler()->on_status_changed( 12, 'processing', 'completed', $order );

		$this->assertSame( array( array( 12, 7 * 86400 ) ), $this->queued );
	}

	public function test_is_idempotent_by_order_meta(): void {
		$order = $this->order( '1700600000' );
		$order->shouldNotReceive( 'update_meta_data' );
		$this->scheduler()->on_status_changed( 12, 'processing', 'completed', $order );
		$this->scheduler()->on_status_changed( 12, 'processing', 'completed', $this->order( 'sent' ) );
		$this->assertSame( array(), $this->queued );
	}

	public function test_ignores_other_statuses_disabled_module_and_bad_arguments(): void {
		$order = $this->order();
		$order->shouldNotReceive( 'update_meta_data' );
		$this->scheduler()->on_status_changed( 12, 'pending', 'processing', $order );
		$this->scheduler()->on_status_changed( 12, 'pending', 'completed', 'not an order' );
		\Motored\Core\Support\Options::reset();
		$this->options['motored_review_requests']['enabled'] = 'no';
		$this->scheduler()->on_status_changed( 12, 'processing', 'completed', $order );
		$this->assertSame( array(), $this->queued );
	}

	public function test_custom_trigger_status(): void {
		$this->options['motored_review_requests']['trigger_status'] = 'despachado';
		$order = $this->order();
		$order->shouldReceive( 'update_meta_data' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();
		$this->scheduler()->on_status_changed( 5, 'processing', 'despachado', $order );
		$this->assertCount( 1, $this->queued );
	}

	public function test_failed_scheduling_does_not_mark_the_order(): void {
		$order = $this->order();
		$order->shouldNotReceive( 'update_meta_data' );
		$this->scheduler( false )->on_status_changed( 12, 'processing', 'completed', $order );
		$this->assertCount( 1, $this->queued );
	}
}
