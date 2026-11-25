<?php
/**
 * Domain event contract between BikePages demand capture and notification modules (tracked in docs/VERIFY.md).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Decouples BikePages from e-mail / WhatsApp delivery modules (§10.15).
 */
final class BikeDemandHooks {

	/**
	 * Fired after a new demand request is persisted.
	 *
	 * @param int                 $demand_id Row ID in motored_bike_demand.
	 * @param array<string,mixed> $context   Structured context (`signature`, `position`, `brand`, `model`, `years`, `sizes`, `has_email`, `has_phone`).
	 */
	public static function created( int $demand_id, array $context ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'motored_bike_demand_created', $demand_id, $context );
		}
	}

	/**
	 * Allows notification modules to customize the stock-arrival message text.
	 *
	 * @param string              $text    Default message text.
	 * @param array<string,mixed> $context Demand context.
	 */
	public static function filter_message( string $text, array $context ): string {
		if ( function_exists( 'apply_filters' ) ) {
			return (string) apply_filters( 'motored_bike_demand_message', $text, $context );
		}
		return $text;
	}

	/**
	 * Fired by a notification consumer after attempting delivery for a demand entry.
	 *
	 * @param int    $demand_id Row ID.
	 * @param string $channel   'email'|'whatsapp'.
	 * @param bool   $ok        Whether delivery succeeded.
	 */
	public static function notified( int $demand_id, string $channel, bool $ok ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'motored_bike_demand_notified', $demand_id, $channel, $ok );
		}
	}
}
