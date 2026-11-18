<?php
/**
 * Recovery statuses and the derived "abandoned" state.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Stored statuses are active|converted|expired|opted_out|recovered. "abandoned" is NEVER stored:
 * it is derived on read (active + inactive longer than the configured limit).
 */
final class Status {

	public const ACTIVE    = 'active';
	public const CONVERTED = 'converted';
	public const EXPIRED   = 'expired';
	public const OPTED_OUT = 'opted_out';
	public const RECOVERED = 'recovered';
	public const ABANDONED = 'abandoned';

	public const STORED = array( self::ACTIVE, self::CONVERTED, self::EXPIRED, self::OPTED_OUT, self::RECOVERED );

	/**
	 * Derives the display status.
	 *
	 * @param string $status           Stored status.
	 * @param string $last_activity    Last activity (GMT, "Y-m-d H:i:s").
	 * @param int    $now              Unix time.
	 * @param int    $abandon_minutes  Inactivity limit in minutes.
	 */
	public static function derive( string $status, string $last_activity, int $now, int $abandon_minutes ): string {
		if ( self::ACTIVE !== $status ) {
			return $status;
		}
		$last = self::timestamp( $last_activity );
		return ( null !== $last && $last < $now - ( $abandon_minutes * 60 ) ) ? self::ABANDONED : self::ACTIVE;
	}

	/**
	 * Display status of a row: only carts become "abandoned"; orders keep their stored status.
	 *
	 * @param array<string,mixed> $row             Recovery row.
	 * @param int                 $now             Unix time.
	 * @param int                 $abandon_minutes Inactivity limit in minutes.
	 */
	public static function derive_row( array $row, int $now, int $abandon_minutes ): string {
		$status = (string) ( $row['status'] ?? '' );
		if ( 'order' === ( $row['type'] ?? 'cart' ) ) {
			return $status;
		}
		return self::derive( $status, (string) ( $row['last_activity_gmt'] ?? '' ), $now, $abandon_minutes );
	}

	/**
	 * GMT datetime before which an active cart counts as abandoned.
	 *
	 * @param int $now             Unix time.
	 * @param int $abandon_minutes Inactivity limit.
	 */
	public static function abandon_cutoff( int $now, int $abandon_minutes ): string {
		return gmdate( 'Y-m-d H:i:s', $now - ( $abandon_minutes * 60 ) );
	}

	/**
	 * Parses a GMT "Y-m-d H:i:s" string.
	 *
	 * @param string $gmt Datetime.
	 */
	public static function timestamp( string $gmt ): ?int {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $gmt, new \DateTimeZone( 'UTC' ) );
		return false === $date ? null : $date->getTimestamp();
	}

	/**
	 * Translated label of a (stored or derived) status.
	 *
	 * @param string $status Status.
	 */
	public static function label( string $status ): string {
		switch ( $status ) {
			case self::ACTIVE:
				return __( 'Active', 'motored-core' );
			case self::ABANDONED:
				return __( 'Abandoned', 'motored-core' );
			case self::CONVERTED:
				return __( 'Converted', 'motored-core' );
			case self::RECOVERED:
				return __( 'Recovered', 'motored-core' );
			case self::EXPIRED:
				return __( 'Expired', 'motored-core' );
			case self::OPTED_OUT:
				return __( 'Opted out', 'motored-core' );
			default:
				return $status;
		}
	}
}
