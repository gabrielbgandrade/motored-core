<?php
/**
 * Recovery cases of an order (pure).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Maps WooCommerce order statuses to the recovery case that decides the sequence and the texts.
 */
final class OrderCase {

	public const PENDING   = 'pending';
	public const FAILED    = 'failed';
	public const ON_HOLD   = 'on_hold';
	public const PAID      = 'paid';
	public const CANCELLED = 'cancelled';

	public const OPEN  = array( self::PENDING, self::FAILED, self::ON_HOLD );
	public const STEPS = 2;

	/**
	 * Case of a status, or null when the status is irrelevant (refunded, draft, ...).
	 *
	 * @param string            $status Order status, with or without the "wc-" prefix.
	 * @param array<int,string> $paid   Statuses WooCommerce treats as paid.
	 */
	public static function of_status( string $status, array $paid = array( 'processing', 'completed' ) ): ?string {
		$status = strtolower( (string) preg_replace( '/^wc-/i', '', trim( $status ) ) );
		switch ( $status ) {
			case 'pending':
				return self::PENDING;
			case 'failed':
				return self::FAILED;
			case 'on-hold':
				return self::ON_HOLD;
			case 'cancelled':
			case 'trash':
				return self::CANCELLED;
		}
		return in_array( $status, $paid, true ) ? self::PAID : null;
	}

	/**
	 * Whether the case belongs to an order still waiting for payment.
	 *
	 * @param string|null $variant Case.
	 */
	public static function is_open( ?string $variant ): bool {
		return null !== $variant && in_array( $variant, self::OPEN, true );
	}

	/**
	 * Translated label.
	 *
	 * @param string $variant Case.
	 */
	public static function label( string $variant ): string {
		switch ( $variant ) {
			case self::FAILED:
				return __( 'Failed payment', 'motored-core' );
			case self::ON_HOLD:
				return __( 'Waiting for payment', 'motored-core' );
			case self::CANCELLED:
				return __( 'Cancelled order', 'motored-core' );
			default:
				return __( 'Pending payment', 'motored-core' );
		}
	}
}
