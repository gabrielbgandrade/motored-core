<?php
/**
 * Save-time dispatch (WhatsApp + e-mail), send-time WhatsApp builder and result note writer.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderTracking;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\OrderNotifications\Config as NotificationsConfig;
use Motored\Core\Modules\OrderNotifications\Notifier;
use Motored\Core\Modules\OrderNotifications\Renderer;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Modules\Waha\Message;
use Motored\Core\Modules\Waha\Phone;
use Motored\Core\Modules\Waha\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * A new or changed code queues ONE high-priority WhatsApp and sends ONE dedicated e-mail, each guarded by a
 * per-channel hash flag on the order. Private notes never contain a phone number or message text.
 */
final class TrackingNotifier {

	public const KIND       = 'order_tracking';
	public const EMAIL_HOOK = 'motored_tracking_email';

	/**
	 * Order loader.
	 *
	 * @var \Closure(int):mixed
	 */
	private \Closure $order_loader;

	/**
	 * Job enqueuer.
	 *
	 * @var \Closure(string,int,?string,int):bool
	 */
	private \Closure $enqueuer;

	/**
	 * Delayed e-mail scheduler.
	 *
	 * @var \Closure(int,int,string):bool
	 */
	private \Closure $email_scheduler;

	/**
	 * Dedicated e-mail sender.
	 *
	 * @var \Closure(\WC_Order):bool
	 */
	private \Closure $emailer;

	/**
	 * Country ISO -> calling code digits loader.
	 *
	 * @var \Closure(string):string
	 */
	private \Closure $calling_code_loader;

	/**
	 * Store name loader.
	 *
	 * @var \Closure():string
	 */
	private \Closure $store_name_loader;

	/**
	 * Constructor with optional test doubles.
	 *
	 * @param \Closure|null $order_loader        fn( int $id ): mixed.
	 * @param \Closure|null $enqueuer            fn( string $kind, int $ref_id, ?string $step, int $delay ): bool.
	 * @param \Closure|null $emailer             fn( \WC_Order $order ): bool.
	 * @param \Closure|null $calling_code_loader fn( string $iso ): string.
	 * @param \Closure|null $store_name_loader   fn(): string.
	 * @param \Closure|null $email_scheduler     fn( int $order_id, int $delay, string $hash ): bool.
	 */
	public function __construct(
		?\Closure $order_loader = null,
		?\Closure $enqueuer = null,
		?\Closure $emailer = null,
		?\Closure $calling_code_loader = null,
		?\Closure $store_name_loader = null,
		?\Closure $email_scheduler = null
	) {
		$this->order_loader        = $order_loader ?? static fn( int $id ) => OrderHooks::load_order( $id );
		$this->enqueuer            = $enqueuer ?? static fn( string $kind, int $ref_id, ?string $step, int $delay ): bool => Sender::enqueue( $kind, $ref_id, $step, $delay, Sender::HIGH );
		$this->email_scheduler     = $email_scheduler ?? static fn( int $order_id, int $delay, string $hash ): bool => ActionScheduler::schedule( time() + $delay, self::EMAIL_HOOK, array( $order_id, $hash ) );
		$this->emailer             = $emailer ?? static fn( \WC_Order $order ): bool => self::send_email_class( $order );
		$this->calling_code_loader = $calling_code_loader ?? static fn( string $iso ): string => OrderHooks::country_calling_code( $iso );
		$this->store_name_loader   = $store_name_loader ?? static fn(): string => (string) get_bloginfo( 'name' );
	}

	/**
	 * Called by the admin save AFTER the meta was stored.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $old_code Code before the save ('' when none).
	 */
	public function on_saved( \WC_Order $order, string $old_code ): void {
		$tracking = Tracking::from_order( $order );
		if ( null === $tracking || '' === $tracking->code || $tracking->code === $old_code ) {
			return;
		}
		$hash = $tracking->hash();
		$step = '' === $old_code ? Config::STEP_ADDED : Config::STEP_UPDATED;

		$send_wa    = Config::send_whatsapp() && Tracking::flag( $order, Tracking::SENT_WA ) !== $hash && Tracking::flag( $order, Tracking::CARRIED_WA ) !== $hash;
		$send_email = Config::send_email() && Tracking::flag( $order, Tracking::SENT_EMAIL ) !== $hash && Tracking::flag( $order, Tracking::CARRIED_EMAIL ) !== $hash;
		if ( ! $send_wa && ! $send_email ) {
			return;
		}

		if ( 'admin' === (string) $order->get_created_via() && ! NotificationsConfig::include_manual_orders() ) {
			$order->add_order_note( __( 'Tracking not sent: manual order.', 'motored-core' ), 0 );
			return;
		}

		if ( $send_wa ) {
			$this->queue_whatsapp( $order, $step, $hash );
		}
		if ( $send_email ) {
			$this->schedule_email( $order, $hash );
		}
	}

	/**
	 * Action Scheduler callback: sends the dedicated e-mail unless a completed/processing e-mail already carried the code.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $hash     Code hash the job was scheduled for (a newer code supersedes it).
	 */
	public function send_scheduled_email( int $order_id, string $hash = '' ): void {
		$order = $order_id > 0 ? $this->resolve_order( $order_id ) : null;
		if ( null === $order || ! Config::send_email() ) {
			return;
		}
		$tracking = Tracking::from_order( $order );
		if ( null === $tracking || '' === $tracking->code || ( '' !== $hash && $hash !== $tracking->hash() ) || Tracking::flag( $order, Tracking::CARRIED_EMAIL ) === $tracking->hash() ) {
			return;
		}
		$this->send_email( $order, $tracking->hash() );
	}

	/**
	 * Send-time handler registered with Waha\Sender for kind "order_tracking".
	 *
	 * @param int         $order_id Order ID.
	 * @param string|null $step     added|updated.
	 */
	public function build_message( int $order_id, ?string $step ): ?Message {
		if ( $order_id <= 0 || ! in_array( $step, array( Config::STEP_ADDED, Config::STEP_UPDATED ), true ) || ! Config::send_whatsapp() ) {
			return null;
		}
		$order = $this->resolve_order( $order_id );
		if ( null === $order ) {
			return null;
		}
		$tracking = Tracking::from_order( $order );
		if ( null === $tracking || '' === $tracking->code || Tracking::flag( $order, Tracking::CARRIED_WA ) === $tracking->hash() ) {
			return null;
		}
		$phone = $this->resolve_phone( $order );
		if ( null === $phone ) {
			return null;
		}
		$text = Renderer::render(
			Config::text( (string) $step ),
			array_merge(
				Presenter::vars( $order ),
				array(
					'order_number' => (string) $order->get_order_number(),
					'first_name'   => (string) $order->get_billing_first_name(),
					'order_url'    => (string) $order->get_checkout_order_received_url(),
					'store_name'   => ( $this->store_name_loader )(),
				)
			)
		);
		return '' !== $text ? new Message( $phone, $text ) : null;
	}

	/**
	 * Listener for `motored_waha_result`: private note without phone or message text.
	 *
	 * @param string      $kind   Job kind.
	 * @param int         $ref_id Order ID.
	 * @param string|null $step   Step.
	 * @param Result      $result Result.
	 */
	public function on_result( string $kind, int $ref_id, ?string $step, Result $result ): void {
		if ( self::KIND !== $kind || $ref_id <= 0 || ( ! $result->ok && 'skipped' === $result->code ) ) {
			return;
		}
		$order = $this->resolve_order( $ref_id );
		if ( null === $order ) {
			return;
		}
		$discard = $result->ok ? null : Sender::discard_note( $result->code );
		if ( null !== $discard ) {
			$order->add_order_note( $discard, 0 );
			return;
		}
		if ( $result->ok ) {
			$note = __( 'Tracking WhatsApp sent.', 'motored-core' );
		} else {
			/* translators: %s: short failure reason. */
			$note = sprintf( __( 'Tracking WhatsApp failed: %s', 'motored-core' ), Notifier::short_reason( $result ) );
		}
		$order->add_order_note( $note, 0 );
	}

	/**
	 * Queues the WhatsApp (marks the hash at enqueue time, like the status events do).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $step  added|updated.
	 * @param string    $hash  Code hash.
	 */
	private function queue_whatsapp( \WC_Order $order, string $step, string $hash ): void {
		if ( null === $this->resolve_phone( $order ) ) {
			$order->add_order_note( __( 'Tracking WhatsApp not sent: missing or invalid billing phone.', 'motored-core' ), 0 );
			return;
		}
		if ( ( $this->enqueuer )( self::KIND, (int) $order->get_id(), $step, Config::send_delay_seconds() ) ) {
			Tracking::set_flag( $order, Tracking::SENT_WA, $hash );
		} else {
			$order->add_order_note( __( 'Tracking WhatsApp could not be queued.', 'motored-core' ), 0 );
		}
	}

	/**
	 * Schedules the dedicated e-mail (marks the hash at schedule time, like the WhatsApp).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $hash  Code hash.
	 */
	private function schedule_email( \WC_Order $order, string $hash ): void {
		if ( ! is_email( (string) $order->get_billing_email() ) ) {
			$order->add_order_note( __( 'Tracking e-mail not sent: missing or invalid billing e-mail.', 'motored-core' ), 0 );
			return;
		}
		if ( ( $this->email_scheduler )( (int) $order->get_id(), Config::send_delay_seconds(), $hash ) ) {
			Tracking::set_flag( $order, Tracking::SENT_EMAIL, $hash );
		} else {
			$order->add_order_note( __( 'Tracking e-mail could not be scheduled.', 'motored-core' ), 0 );
		}
	}

	/**
	 * Sends the dedicated e-mail.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $hash  Code hash.
	 */
	private function send_email( \WC_Order $order, string $hash ): void {
		if ( ! is_email( (string) $order->get_billing_email() ) ) {
			$order->add_order_note( __( 'Tracking e-mail not sent: missing or invalid billing e-mail.', 'motored-core' ), 0 );
			return;
		}
		if ( ( $this->emailer )( $order ) ) {
			$order->add_order_note( __( 'Tracking e-mail sent.', 'motored-core' ), 0 );
		} else {
			if ( Tracking::flag( $order, Tracking::SENT_EMAIL ) === $hash ) {
				Tracking::set_flag( $order, Tracking::SENT_EMAIL, '' );
			}
			$order->add_order_note( __( 'Tracking e-mail not sent (disabled or failed).', 'motored-core' ), 0 );
		}
	}

	/**
	 * Triggers the WC_Email subclass registered by the module.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function send_email_class( \WC_Order $order ): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ Emails\TrackingEmail::CLASS_KEY ] ?? null;
		return $email instanceof Emails\TrackingEmail && $email->trigger_order( $order );
	}

	/**
	 * Resolves a WC_Order.
	 *
	 * @param int $order_id Order ID.
	 */
	private function resolve_order( int $order_id ): ?\WC_Order {
		$loaded = ( $this->order_loader )( $order_id );
		return $loaded instanceof \WC_Order ? $loaded : null;
	}

	/**
	 * Normalized billing phone (null when missing/invalid).
	 *
	 * @param \WC_Order $order Order.
	 */
	private function resolve_phone( \WC_Order $order ): ?string {
		$raw  = trim( (string) $order->get_billing_phone() );
		$code = ( $this->calling_code_loader )( (string) $order->get_billing_country() );
		if ( '' === $code ) {
			$code = WahaConfig::country_code();
		}
		return '' !== $raw ? Phone::normalize( $raw, $code ) : null;
	}
}
