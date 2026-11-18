<?php
/**
 * Event listeners, send-time message builder, and result note writer.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderNotifications;

use Motored\Core\Integration\Waha\Result;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Modules\Waha\Message;
use Motored\Core\Modules\Waha\Phone;
use Motored\Core\Modules\Waha\Sender;
use Motored\Core\Support\Logger;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates lightweight enqueueing on WooCommerce hooks and fresh rendering at send time.
 */
final class Notifier {

	/**
	 * Order loader.
	 *
	 * @var \Closure(int):mixed
	 */
	private \Closure $order_loader;

	/**
	 * Job enqueuer.
	 *
	 * @var \Closure(string,int,?string):bool
	 */
	private \Closure $enqueuer;

	/**
	 * Latest customer note loader.
	 *
	 * @var \Closure(int):string
	 */
	private \Closure $note_loader;

	/**
	 * Country ISO -> calling code digits loader.
	 *
	 * @var \Closure(string):string
	 */
	private \Closure $calling_code_loader;

	/**
	 * Price HTML formatter.
	 *
	 * @var \Closure(string,string):string
	 */
	private \Closure $price_formatter;

	/**
	 * Store name loader.
	 *
	 * @var \Closure():string
	 */
	private \Closure $store_name_loader;

	/**
	 * Constructor with optional test doubles.
	 *
	 * @param \Closure|null $order_loader        fn( int $order_id ): mixed.
	 * @param \Closure|null $enqueuer            fn( string $kind, int $ref_id, ?string $step ): bool.
	 * @param \Closure|null $note_loader         fn( int $order_id ): string.
	 * @param \Closure|null $calling_code_loader fn( string $country_iso ): string.
	 * @param \Closure|null $price_formatter     fn( string $total, string $currency ): string.
	 * @param \Closure|null $store_name_loader   fn(): string.
	 */
	public function __construct(
		?\Closure $order_loader = null,
		?\Closure $enqueuer = null,
		?\Closure $note_loader = null,
		?\Closure $calling_code_loader = null,
		?\Closure $price_formatter = null,
		?\Closure $store_name_loader = null
	) {
		$this->order_loader        = $order_loader ?? static fn( int $id ) => function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
		$this->enqueuer            = $enqueuer ?? static fn( string $kind, int $ref_id, ?string $step ): bool => Sender::enqueue( $kind, $ref_id, $step, 0, Sender::HIGH );
		$this->note_loader         = $note_loader ?? static fn( int $id ): string => OrderHooks::latest_customer_note( $id );
		$this->calling_code_loader = $calling_code_loader ?? static fn( string $cc ): string => OrderHooks::country_calling_code( $cc );
		$this->price_formatter     = $price_formatter ?? static fn( string $total, string $currency ): string => OrderHooks::format_price_html( $total, $currency );
		$this->store_name_loader   = $store_name_loader ?? static fn(): string => (string) get_bloginfo( 'name' );
	}

	/**
	 * Lightweight listener on woocommerce_order_status_changed (at most 1 option read + 1 meta check per active event).
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $from_status Previous status.
	 * @param string $to_status   New status.
	 * @param mixed  $order       Optional WC_Order instance passed by WooCommerce.
	 */
	public function on_status_changed( int $order_id, string $from_status, string $to_status, $order = null ): void {
		$customer_event = EventMap::from_status( $to_status );
		$triggers_admin = EventMap::triggers_admin_new_order( $from_status, $to_status );

		if ( null === $customer_event && ! $triggers_admin ) {
			return;
		}

		$all    = Options::all( Config::OPTION_KEY );
		$events = array();

		if ( null !== $customer_event && Config::is_event_enabled_in( $all, $customer_event ) ) {
			$events[] = $customer_event;
		}
		if ( $triggers_admin && Config::is_event_enabled_in( $all, EventMap::ADMIN_NEW_ORDER ) && '' !== Config::admin_phone_in( $all ) ) {
			$events[] = EventMap::ADMIN_NEW_ORDER;
		}

		if ( array() === $events ) {
			return;
		}

		$order = $this->resolve_order( $order_id, $order );
		if ( null === $order ) {
			return;
		}

		if ( 'admin' === (string) $order->get_created_via() && ! Config::include_manual_orders_in( $all ) ) {
			return;
		}

		$dirty = false;
		foreach ( $events as $event ) {
			$meta_key = EventMap::meta_key( $event );
			$existing = $order->get_meta( $meta_key, true );
			if ( is_scalar( $existing ) && '' !== (string) $existing ) {
				continue;
			}
			if ( ( $this->enqueuer )( EventMap::KIND, (int) $order->get_id(), $event ) ) {
				$order->update_meta_data( $meta_key, '1' );
				$dirty = true;
			}
		}

		if ( $dirty ) {
			$order->save_meta_data();
		}
	}

	/**
	 * Lightweight listener on woocommerce_new_customer_note.
	 *
	 * @param mixed $args Array with 'order_id' and 'customer_note'.
	 */
	public function on_customer_note( $args ): void {
		if ( ! is_array( $args ) ) {
			return;
		}
		$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
		$note     = isset( $args['customer_note'] ) && is_string( $args['customer_note'] ) ? trim( $args['customer_note'] ) : '';

		if ( $order_id <= 0 || '' === $note ) {
			return;
		}

		$all = Options::all( Config::OPTION_KEY );
		if ( ! Config::is_event_enabled_in( $all, EventMap::CUSTOMER_NOTE ) ) {
			return;
		}

		$order = $this->resolve_order( $order_id, null );
		if ( null === $order ) {
			return;
		}

		if ( 'admin' === (string) $order->get_created_via() && ! Config::include_manual_orders_in( $all ) ) {
			return;
		}

		$meta_key = EventMap::meta_key( EventMap::CUSTOMER_NOTE );
		$hash     = md5( $note );
		$existing = $order->get_meta( $meta_key, true );
		if ( is_string( $existing ) && $hash === $existing ) {
			return;
		}

		if ( ( $this->enqueuer )( EventMap::KIND, (int) $order->get_id(), EventMap::CUSTOMER_NOTE ) ) {
			$order->update_meta_data( $meta_key, $hash );
			$order->save_meta_data();
		}
	}

	/**
	 * Send-time handler registered with Waha\Sender for kind "order_event".
	 *
	 * @param int         $order_id Order ID.
	 * @param string|null $step     Event slug.
	 */
	public function build_message( int $order_id, ?string $step ): ?Message {
		if ( $order_id <= 0 || null === $step || ! EventMap::is_valid( $step ) ) {
			return null;
		}
		if ( ! Config::event_enabled( $step ) ) {
			return null;
		}

		$template = Config::event_text( $step );
		if ( '' === trim( $template ) ) {
			return null;
		}

		$order = $this->resolve_order( $order_id, null );
		if ( null === $order ) {
			return null;
		}

		if ( ! EventMap::matches_status( $step, (string) $order->get_status() ) ) {
			return null;
		}

		$phone = $this->resolve_phone( $order, $step );
		if ( null === $phone ) {
			return null;
		}

		$customer_note = '';
		if ( EventMap::CUSTOMER_NOTE === $step ) {
			$customer_note = ( $this->note_loader )( (int) $order->get_id() );
			if ( '' === $customer_note ) {
				return null;
			}
		}

		$text = Renderer::render(
			$template,
			$this->build_vars( $order, $customer_note )
		);

		return '' !== $text ? new Message( $phone, $text ) : null;
	}

	/**
	 * Listener for `motored_waha_result`: adds a private order note without phone or message text.
	 *
	 * @param string      $kind   Job kind.
	 * @param int         $ref_id Order ID.
	 * @param string|null $step   Event slug.
	 * @param Result      $result Send result.
	 */
	public function on_result( string $kind, int $ref_id, ?string $step, Result $result ): void {
		if ( EventMap::KIND !== $kind || $ref_id <= 0 || null === $step || ! EventMap::is_valid( $step ) ) {
			return;
		}
		if ( ! $result->ok && 'skipped' === $result->code ) {
			return;
		}

		$order = $this->resolve_order( $ref_id, null );
		if ( null === $order ) {
			return;
		}

		$discard = $result->ok ? null : Sender::discard_note( $result->code );
		if ( null !== $discard ) {
			$order->add_order_note( $discard, 0 );
			return;
		}
		if ( $result->ok ) {
			$note = sprintf(
				/* translators: %s: order notification event slug (e.g. processing). */
				__( 'WhatsApp sent: %s', 'motored-core' ),
				$step
			);
		} else {
			$note = sprintf(
				/* translators: %s: short failure reason. */
				__( 'WhatsApp failed: %s', 'motored-core' ),
				self::short_reason( $result )
			);
		}

		$order->add_order_note( $note, 0 );
	}

	/**
	 * Short translated reason for a failed send (never includes phone or message text).
	 *
	 * @param Result $result Send result.
	 */
	public static function short_reason( Result $result ): string {
		switch ( $result->code ) {
			case 'not_configured':
				return __( 'WAHA is not configured.', 'motored-core' );
			case 'invalid_phone':
				return __( 'invalid phone number.', 'motored-core' );
			case 'no_whatsapp':
				return __( 'number is not on WhatsApp.', 'motored-core' );
			case 'timeout':
				return __( 'server timed out.', 'motored-core' );
			case 'transport':
				return __( 'could not reach WAHA.', 'motored-core' );
			case 'auth':
				return __( 'API key rejected.', 'motored-core' );
			case 'not_found':
				return __( 'WAHA session not found.', 'motored-core' );
			case 'rate_limited':
				return __( 'rate limit reached.', 'motored-core' );
			case 'server_error':
				return __( 'WAHA server error.', 'motored-core' );
			case 'invalid_response':
				return __( 'invalid WAHA response.', 'motored-core' );
			case 'session_down':
				return __( 'WAHA session is not working.', 'motored-core' );
			case 'http_error':
				return __( 'WAHA returned an error.', 'motored-core' );
			case 'schedule_failed':
				return __( 'could not reschedule job.', 'motored-core' );
			case 'handler_error':
				return __( 'message build failed.', 'motored-core' );
			default:
				return __( 'unexpected error.', 'motored-core' );
		}
	}

	/**
	 * Resolves a WC_Order from the argument or loader.
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $order    Optional order object.
	 */
	private function resolve_order( int $order_id, $order ): ?\WC_Order {
		if ( $order instanceof \WC_Order ) {
			return $order;
		}
		if ( $order_id <= 0 ) {
			return null;
		}
		$loaded = ( $this->order_loader )( $order_id );
		return $loaded instanceof \WC_Order ? $loaded : null;
	}

	/**
	 * Resolves E.164 candidate phone for the event, recording a single private note on invalid customer phone.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $step  Event slug.
	 */
	private function resolve_phone( \WC_Order $order, string $step ): ?string {
		if ( EventMap::ADMIN_NEW_ORDER === $step ) {
			$raw = Config::admin_phone();
			if ( '' === $raw ) {
				return null;
			}
			$normalized = Phone::normalize( $raw, WahaConfig::country_code() );
			if ( null === $normalized ) {
				Logger::warning( 'Admin new-order phone number is invalid.', array( 'order_id' => (int) $order->get_id() ) );
			}
			return $normalized;
		}

		$raw          = trim( (string) $order->get_billing_phone() );
		$calling_code = ( $this->calling_code_loader )( (string) $order->get_billing_country() );
		if ( '' === $calling_code ) {
			$calling_code = WahaConfig::country_code();
		}

		$normalized = '' !== $raw ? Phone::normalize( $raw, $calling_code ) : null;
		if ( null === $normalized ) {
			$noted = $order->get_meta( EventMap::NO_PHONE_META, true );
			if ( ! is_scalar( $noted ) || '' === (string) $noted ) {
				$order->add_order_note( __( 'WhatsApp not sent: missing or invalid billing phone.', 'motored-core' ), 0 );
				$order->update_meta_data( EventMap::NO_PHONE_META, '1' );
				$order->save_meta_data();
			}
			return null;
		}

		return $normalized;
	}

	/**
	 * Extracts template placeholder values from a WooCommerce order.
	 *
	 * @param \WC_Order $order         Order.
	 * @param string    $customer_note Customer note text (for customer_note event).
	 * @return array<string,string>
	 */
	private function build_vars( \WC_Order $order, string $customer_note ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name' => (string) $item->get_name(),
				'qty'  => (int) $item->get_quantity(),
			);
		}

		return array(
			'order_number'  => (string) $order->get_order_number(),
			'first_name'    => (string) $order->get_billing_first_name(),
			'order_total'   => ( $this->price_formatter )( (string) $order->get_total(), (string) $order->get_currency() ),
			'order_items'   => Renderer::format_items( $items ),
			'order_url'     => (string) $order->get_checkout_order_received_url(),
			'store_name'    => ( $this->store_name_loader )(),
			'customer_note' => $customer_note,
		);
	}
}
