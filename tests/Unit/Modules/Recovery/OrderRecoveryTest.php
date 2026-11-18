<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\OrderNotifications\Config as NotificationsConfig;
use Motored\Core\Modules\OrderNotifications\EventMap;
use Motored\Core\Modules\Recovery\Config;
use Motored\Core\Modules\Recovery\OrderCase;
use Motored\Core\Modules\Recovery\OrderNote;
use Motored\Core\Modules\Recovery\OrderRecovery;
use Motored\Core\Tests\Unit\TestCase;

final class OrderRecoveryTest extends TestCase {

	private const NOW = 1700000000;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Functions\stubTranslationFunctions();
	}

	public function test_default_sequences_by_case(): void {
		// pending / failed: WhatsApp at 30 min (before WooCommerce cancels unpaid orders), e-mail at 24 h.
		foreach ( array( OrderCase::PENDING, OrderCase::FAILED ) as $case ) {
			$this->assertSame( 'whatsapp', Config::order_step( $case, 1 )['channel'] );
			$this->assertSame( 30, Config::order_step( $case, 1 )['delay'] );
			$this->assertSame( 'email', Config::order_step( $case, 2 )['channel'] );
			$this->assertSame( 1440, Config::order_step( $case, 2 )['delay'] );
		}
		// on-hold: WhatsApp at 24 h, no second step.
		$this->assertSame( 'whatsapp', Config::order_step( OrderCase::ON_HOLD, 1 )['channel'] );
		$this->assertSame( 1440, Config::order_step( OrderCase::ON_HOLD, 1 )['delay'] );
		$this->assertSame( 'off', Config::order_step( OrderCase::ON_HOLD, 2 )['channel'] );
	}

	public function test_next_action_uses_the_case_delay(): void {
		$this->assertSame( self::NOW + 30 * 60, OrderRecovery::next_action_ts( OrderCase::PENDING, 0, self::NOW ) );
		$this->assertSame( self::NOW + 30 * 60, OrderRecovery::next_action_ts( OrderCase::FAILED, 0, self::NOW ) );
		$this->assertSame( self::NOW + 1440 * 60, OrderRecovery::next_action_ts( OrderCase::ON_HOLD, 0, self::NOW ) );
		$this->assertSame( self::NOW + 1440 * 60, OrderRecovery::next_action_ts( OrderCase::PENDING, 1, self::NOW ), 'Step 2 after step 1.' );
	}

	public function test_sequence_ends_when_no_enabled_step_is_left(): void {
		$this->assertNull( OrderRecovery::next_action_ts( OrderCase::PENDING, 2, self::NOW ) );
		$this->assertNull( OrderRecovery::next_action_ts( OrderCase::ON_HOLD, 1, self::NOW ), 'On-hold step 2 is off by default.' );
	}

	public function test_configured_steps_override_defaults_and_off_steps_are_skipped(): void {
		$this->options['motored_recovery'] = array(
			'order_pending_step_1' => array( 'channel' => 'off', 'delay' => '5', 'text' => 'x' ),
			'order_pending_step_2' => array( 'channel' => 'whatsapp', 'delay' => '90', 'text' => 'y' ),
		);
		$this->assertSame( 2, Config::order_next_step_num( OrderCase::PENDING, 0 ) );
		$this->assertSame( self::NOW + 90 * 60, OrderRecovery::next_action_ts( OrderCase::PENDING, 0, self::NOW ) );
	}

	public function test_texts_are_distinct_per_case_and_carry_the_pay_link(): void {
		$failed  = Config::order_step( OrderCase::FAILED, 1 )['text'];
		$pending = Config::order_step( OrderCase::PENDING, 1 )['text'];
		$on_hold = Config::order_step( OrderCase::ON_HOLD, 1 )['text'];

		$this->assertStringContainsString( 'no se pudo procesar tu pago', $failed );
		$this->assertStringContainsString( 'no completaste el pago', $pending );
		$this->assertStringContainsString( 'esperando el pago', $on_hold );
		$this->assertCount( 3, array_unique( array( $failed, $pending, $on_hold ) ) );
		foreach ( array( $failed, $pending, $on_hold ) as $text ) {
			$this->assertStringContainsString( '{pay_url}', $text );
			$this->assertStringContainsString( '{optout_url}', $text );
			$this->assertStringContainsString( '{order_number}', $text );
		}
	}

	public function test_on_hold_recovery_text_does_not_repeat_the_immediate_notification(): void {
		$immediate = (string) NotificationsConfig::DEFAULTS[ EventMap::ON_HOLD ]['text'];
		$this->assertNotSame( $immediate, Config::order_step( OrderCase::ON_HOLD, 1 )['text'] );
		$this->assertStringNotContainsString( 'Quedó *en espera*', Config::order_step( OrderCase::ON_HOLD, 1 )['text'] );
	}

	public function test_template_choice_per_case(): void {
		foreach ( OrderCase::OPEN as $case ) {
			$this->assertSame( OrderRecovery::EMAIL_PENDING_PAYMENT, OrderRecovery::email_kind( $case ) );
		}
		$this->assertSame( OrderRecovery::EMAIL_ABANDONED_CART, OrderRecovery::email_kind( OrderCase::CANCELLED ) );

		$cancelled = OrderRecovery::step_config( OrderCase::CANCELLED, 1 );
		$this->assertSame( Config::order_step( OrderCase::PENDING, 1 )['channel'], $cancelled['channel'] );
		$this->assertSame( Config::order_cancelled_text(), $cancelled['text'] );
		$this->assertStringContainsString( '{recovery_url}', $cancelled['text'] );
		$this->assertSame( Config::order_step( OrderCase::FAILED, 2 ), OrderRecovery::step_config( OrderCase::FAILED, 2 ) );
	}

	public function test_attribution_requires_a_delivered_message(): void {
		$sent = OrderRecovery::paid_resolution( array( 'step' => '1', 'wa_sent_count' => 1, 'email_sent_count' => 0 ), 50, '2026-11-18 10:00:00' );
		$this->assertTrue( $sent['recovered'] );
		$this->assertSame( 'recovered', $sent['fields']['status'] );
		$this->assertSame( 50, $sent['fields']['recovered_order_id'] );
		$this->assertNull( $sent['fields']['next_action_gmt'], 'Pending sends are cancelled.' );

		$email = OrderRecovery::paid_resolution( array( 'step' => '2', 'wa_sent_count' => 0, 'email_sent_count' => 1 ), 50, '2026-11-18 10:00:00' );
		$this->assertTrue( $email['recovered'] );

		$failed_only = OrderRecovery::paid_resolution( array( 'step' => '1', 'wa_sent_count' => 0, 'email_sent_count' => 0 ), 50, '2026-11-18 10:00:00' );
		$this->assertFalse( $failed_only['recovered'] );
		$this->assertSame( 'converted', $failed_only['fields']['status'] );
		$this->assertArrayNotHasKey( 'recovered_order_id', $failed_only['fields'] );

		$plain = OrderRecovery::paid_resolution( array( 'step' => '', 'wa_sent_count' => 0, 'email_sent_count' => 0 ), 50, '2026-11-18 10:00:00' );
		$this->assertSame( 'converted', $plain['fields']['status'] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row( string $items = '[{"product_id":1}]' ): array {
		return array(
			'id'    => 9,
			'items' => $items,
		);
	}

	public function test_skips_when_already_paid(): void {
		$facts = array( 'status' => 'processing', 'needs_payment' => false );
		$this->assertSame( 'paid', OrderRecovery::decide( $this->row(), $facts, array( 'processing', 'completed' ), 'restore' )['action'] );
		$this->assertSame( 'paid', OrderRecovery::decide( $this->row(), array( 'status' => 'shipped', 'needs_payment' => false ), array( 'processing', 'shipped' ), 'restore' )['action'] );
	}

	public function test_skips_when_payment_is_not_needed_any_more(): void {
		foreach ( array( 'pending', 'failed' ) as $status ) {
			$decision = OrderRecovery::decide( $this->row(), array( 'status' => $status, 'needs_payment' => false ), array( 'processing' ), 'restore' );
			$this->assertSame( 'expire', $decision['action'], $status );
			$this->assertSame( 'no_payment_needed', $decision['reason'] );
		}
		$this->assertSame( 'send', OrderRecovery::decide( $this->row(), array( 'status' => 'pending', 'needs_payment' => true ), array( 'processing' ), 'restore' )['action'] );
		$this->assertSame( 'send', OrderRecovery::decide( $this->row(), array( 'status' => 'failed', 'needs_payment' => true ), array( 'processing' ), 'restore' )['action'] );
	}

	public function test_on_hold_is_sent_even_though_woocommerce_never_asks_for_payment_on_it(): void {
		$decision = OrderRecovery::decide( $this->row(), array( 'status' => 'on-hold', 'needs_payment' => false ), array( 'processing' ), 'restore' );
		$this->assertSame( 'send', $decision['action'] );
		$this->assertSame( OrderCase::ON_HOLD, $decision['case'] );
	}

	public function test_status_changed_to_something_else_stops_the_sequence(): void {
		$this->assertSame( 'expire', OrderRecovery::decide( $this->row(), array( 'status' => 'refunded', 'needs_payment' => false ), array( 'processing' ), 'restore' )['action'] );
	}

	public function test_cancelled_order_fallback(): void {
		$facts = array( 'status' => 'cancelled', 'needs_payment' => false );
		$this->assertSame( 'restore', OrderRecovery::decide( $this->row(), $facts, array( 'processing' ), 'restore' )['action'] );
		$this->assertSame( 'expire', OrderRecovery::decide( $this->row(), $facts, array( 'processing' ), 'expire' )['action'] );
		$this->assertSame( 'expire', OrderRecovery::decide( $this->row( '' ), $facts, array( 'processing' ), 'restore' )['action'], 'Nothing to restore.' );
		$this->assertSame( 'cancelled', OrderRecovery::decide( $this->row(), $facts, array( 'processing' ), 'restore' )['reason'] );
	}

	public function test_deleted_or_trashed_order_expires(): void {
		$this->assertSame( 'expire', OrderRecovery::decide( $this->row(), null, array( 'processing' ), 'restore' )['action'] );
		$this->assertSame( 'order_missing', OrderRecovery::decide( $this->row(), null, array( 'processing' ), 'restore' )['reason'] );
		$trashed = OrderRecovery::decide( $this->row(), array( 'status' => 'trash', 'needs_payment' => false ), array( 'processing' ), 'restore' );
		$this->assertSame( 'expire', $trashed['action'], 'A trashed order is never restored.' );
		$this->assertSame( 'order_trashed', $trashed['reason'] );
	}

	public function test_cancelled_fallback_option(): void {
		$this->assertSame( 'restore', Config::order_cancelled_fallback() );
		$this->options['motored_recovery'] = array( 'order_cancelled_fallback' => 'expire' );
		\Motored\Core\Support\Options::reset();
		$this->assertSame( 'expire', Config::order_cancelled_fallback() );
	}

	public function test_private_order_note_has_no_contact_data(): void {
		$text = OrderNote::text( 'whatsapp', 'auto', true );
		$this->assertStringContainsString( 'WhatsApp', $text );
		$this->assertDoesNotMatchRegularExpression( '/\d{6,}|@/', $text );
		$this->assertStringContainsString( 'could not be sent', OrderNote::text( 'email', 'manual', false ) );
	}
}
