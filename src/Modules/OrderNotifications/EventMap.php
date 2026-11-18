<?php
/**
 * Mapping between WooCommerce order statuses and notification events.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderNotifications;

defined( 'ABSPATH' ) || exit;

/**
 * Pure map of WooCommerce order statuses to notification event slugs.
 */
final class EventMap {

	public const KIND          = 'order_event';
	public const ON_HOLD       = 'on_hold';
	public const PROCESSING    = 'processing';
	public const COMPLETED     = 'completed';
	public const REFUNDED      = 'refunded';
	public const CUSTOMER_NOTE = 'customer_note';
	public const META_PREFIX   = '_motored_wa_';
	public const NO_PHONE_META = '_motored_wa_no_phone_noted';

	/**
	 * WooCommerce status slug (without "wc-") to customer notification event.
	 *
	 * @var array<string,string>
	 */
	private const STATUS_TO_EVENT = array(
		'on-hold'    => self::ON_HOLD,
		'processing' => self::PROCESSING,
		'completed'  => self::COMPLETED,
		'refunded'   => self::REFUNDED,
	);

	/**
	 * Inverse map: customer status event to expected WooCommerce status slug.
	 *
	 * @var array<string,string>
	 */
	private const EVENT_TO_STATUS = array(
		self::ON_HOLD    => 'on-hold',
		self::PROCESSING => 'processing',
		self::COMPLETED  => 'completed',
		self::REFUNDED   => 'refunded',
	);

	/**
	 * All supported event slugs in settings display order.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::ON_HOLD,
			self::PROCESSING,
			self::COMPLETED,
			self::REFUNDED,
			self::CUSTOMER_NOTE,
		);
	}

	/**
	 * Whether an event slug is supported by this module.
	 *
	 * @param string $event Event slug.
	 */
	public static function is_valid( string $event ): bool {
		return in_array( $event, self::all(), true );
	}

	/**
	 * Maps a WooCommerce order status to a customer notification event, or null when unmapped.
	 *
	 * @param string $status WooCommerce status (with or without "wc-").
	 */
	public static function from_status( string $status ): ?string {
		$normalized = self::normalize_status( $status );
		return self::STATUS_TO_EVENT[ $normalized ] ?? null;
	}

	/**
	 * Whether an order's current status still matches the queued event at send time.
	 *
	 * @param string $event        Event slug.
	 * @param string $order_status Current WooCommerce order status.
	 */
	public static function matches_status( string $event, string $order_status ): bool {
		$normalized = self::normalize_status( $order_status );
		if ( isset( self::EVENT_TO_STATUS[ $event ] ) ) {
			return self::EVENT_TO_STATUS[ $event ] === $normalized;
		}
		if ( self::CUSTOMER_NOTE === $event ) {
			return '' !== $normalized && 'trash' !== $normalized;
		}
		return false;
	}

	/**
	 * Order meta key used for per-order, per-event idempotency.
	 *
	 * @param string $event Event slug.
	 */
	public static function meta_key( string $event ): string {
		return self::META_PREFIX . $event;
	}

	/**
	 * Normalizes a WooCommerce status slug (lowercase, trimmed, without "wc-" prefix).
	 *
	 * @param string $status Raw status.
	 */
	public static function normalize_status( string $status ): string {
		$status = strtolower( trim( $status ) );
		if ( str_starts_with( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}
		return $status;
	}
}
