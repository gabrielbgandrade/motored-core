<?php
/**
 * Insert-only event history of a recovery row.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

// Table names come from Db::table() (validated); values are always prepared.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Events are only inserted (and removed together with their recovery row). `detail` is short and
 * stripped of e-mails and long digit sequences, so it never carries PII.
 */
final class History {

	public const EVENTS   = array( 'captured', 'cart_updated', 'order_created', 'sent', 'failed', 'clicked', 'restored', 'recovered', 'opted_out', 'note', 'expired' );
	public const CHANNELS = array( 'whatsapp', 'email', 'none' );
	public const SOURCES  = array( 'auto', 'manual', 'system' );
	public const RESULTS  = array( 'ok', 'failed', 'queued' );

	public const DETAIL_MAX = 190;

	/**
	 * Records an event.
	 *
	 * @param int    $recovery_id Row id.
	 * @param string $event       One of EVENTS.
	 * @param string $channel     One of CHANNELS.
	 * @param string $source      One of SOURCES.
	 * @param int    $user_id     Acting admin (0 = none).
	 * @param string $result      One of RESULTS.
	 * @param string $detail      Short text without PII (cleaned here anyway).
	 * @param bool   $touch       Also update last_event_gmt of the row (extra query).
	 * @return bool Whether the event was stored.
	 */
	public static function log( int $recovery_id, string $event, string $channel = 'none', string $source = 'system', int $user_id = 0, string $result = 'ok', string $detail = '', bool $touch = true ): bool {
		global $wpdb;
		if ( $recovery_id <= 0 || ! in_array( $event, self::EVENTS, true ) || ! in_array( $channel, self::CHANNELS, true ) || ! in_array( $source, self::SOURCES, true ) || ! in_array( $result, self::RESULTS, true ) ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			Db::table( 'recovery_events' ),
			array(
				'recovery_id' => $recovery_id,
				'event'       => $event,
				'channel'     => $channel,
				'source'      => $source,
				'user_id'     => max( 0, $user_id ),
				'result'      => $result,
				'detail'      => self::clean_detail( $detail ),
				'created_gmt' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( false !== $ok && $touch ) {
			$table = Db::table( 'recoveries' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_event_gmt = %s WHERE id = %d", $now, $recovery_id ) );
		}
		return false !== $ok;
	}

	/**
	 * Events of a row, newest first.
	 *
	 * @param int $recovery_id Row id.
	 * @param int $page        1-based page.
	 * @param int $per_page    Page size (max 100).
	 * @return array{rows:list<array<string,mixed>>,total:int}
	 */
	public static function for_recovery( int $recovery_id, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$table    = Db::table( 'recovery_events' );
		$per_page = max( 1, min( 100, $per_page ) );
		$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE recovery_id = %d", $recovery_id ) );
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, recovery_id, event, channel, source, user_id, result, detail, created_gmt FROM {$table} WHERE recovery_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
				$recovery_id,
				$per_page,
				max( 0, $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		return array(
			'rows'  => is_array( $rows ) ? array_values( $rows ) : array(),
			'total' => $total,
		);
	}

	/**
	 * Removes e-mails and digit runs of 6+ and cuts the text (pure).
	 *
	 * @param string $detail Free text.
	 */
	public static function clean_detail( string $detail ): string {
		$detail = preg_replace( '/[^\s@]+@[^\s@]+/u', '[email]', $detail ) ?? '';
		$detail = preg_replace( '/\+?\d[\d\s().-]{4,}\d/u', '[number]', $detail ) ?? '';
		$detail = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $detail ) ) ?? '' );
		return mb_substr( $detail, 0, self::DETAIL_MAX );
	}

	/**
	 * Translated label of an event.
	 *
	 * @param string $event Event.
	 */
	public static function event_label( string $event ): string {
		$labels = array(
			'captured'      => __( 'Cart captured', 'motored-core' ),
			'cart_updated'  => __( 'Cart updated', 'motored-core' ),
			'order_created' => __( 'Order created', 'motored-core' ),
			'sent'          => __( 'Message sent', 'motored-core' ),
			'failed'        => __( 'Send failed', 'motored-core' ),
			'clicked'       => __( 'Link clicked', 'motored-core' ),
			'restored'      => __( 'Cart restored', 'motored-core' ),
			'recovered'     => __( 'Cart recovered', 'motored-core' ),
			'opted_out'     => __( 'Opted out', 'motored-core' ),
			'note'          => __( 'Note', 'motored-core' ),
			'expired'       => __( 'Expired', 'motored-core' ),
		);
		return $labels[ $event ] ?? $event;
	}

	/**
	 * Formats an event row for the timeline (pure; no escaping, callers escape).
	 *
	 * @param array<string,mixed> $row Event row.
	 * @return array{label:string,meta:string,detail:string,date:string}
	 */
	public static function format( array $row ): array {
		$event  = (string) ( $row['event'] ?? '' );
		$meta   = array();
		$source = (string) ( $row['source'] ?? '' );
		if ( 'manual' === $source ) {
			$meta[] = __( 'manual', 'motored-core' );
		} elseif ( 'auto' === $source ) {
			$meta[] = __( 'automatic', 'motored-core' );
		}
		$channel = (string) ( $row['channel'] ?? 'none' );
		if ( 'whatsapp' === $channel ) {
			$meta[] = 'WhatsApp';
		} elseif ( 'email' === $channel ) {
			$meta[] = __( 'e-mail', 'motored-core' );
		}
		if ( 'failed' === ( $row['result'] ?? '' ) && 'failed' !== $event ) {
			$meta[] = __( 'failed', 'motored-core' );
		}
		return array(
			'label'  => self::event_label( $event ),
			'meta'   => implode( ' · ', $meta ),
			'detail' => (string) ( $row['detail'] ?? '' ),
			'date'   => (string) ( $row['created_gmt'] ?? '' ),
		);
	}
}
