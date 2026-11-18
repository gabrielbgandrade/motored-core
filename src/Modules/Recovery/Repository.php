<?php
/**
 * Data access for motored_recoveries.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

// Table names come from Db::table() (validated) and ORDER BY from a whitelist; values are always prepared.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Explicit column lists (no SELECT *), prepared statements, paginated reads.
 */
final class Repository {

	/**
	 * Columns read from motored_recoveries.
	 */
	public const COLUMNS = 'id, type, session_key, user_id, order_id, email, phone, first_name, cart_hash, items, currency, total, status, step, last_activity_gmt, next_action_gmt, clicked_gmt, recovered_order_id, wa_sent_count, email_sent_count, last_event_gmt, created_gmt, updated_gmt';

	public const ORDERABLE = array( 'id', 'created_gmt', 'total', 'status', 'last_activity_gmt' );

	/**
	 * Write formats by column.
	 *
	 * @var array<string,string>
	 */
	private const FORMATS = array(
		'type'               => '%s',
		'session_key'        => '%s',
		'user_id'            => '%d',
		'order_id'           => '%d',
		'email'              => '%s',
		'phone'              => '%s',
		'first_name'         => '%s',
		'cart_hash'          => '%s',
		'items'              => '%s',
		'currency'           => '%s',
		'total'              => '%s',
		'status'             => '%s',
		'step'               => '%s',
		'last_activity_gmt'  => '%s',
		'next_action_gmt'    => '%s',
		'token_hash'         => '%s',
		'token_expires_gmt'  => '%s',
		'clicked_gmt'        => '%s',
		'recovered_order_id' => '%d',
		'wa_sent_count'      => '%d',
		'email_sent_count'   => '%d',
		'last_event_gmt'     => '%s',
		'created_gmt'        => '%s',
		'updated_gmt'        => '%s',
	);

	/**
	 * Table name.
	 */
	public static function table(): string {
		return Db::table( 'recoveries' );
	}

	/**
	 * Events table name.
	 */
	public static function events_table(): string {
		return Db::table( 'recovery_events' );
	}

	/**
	 * Light lookup used by the capture path: id, cart_hash, status, step of a session's row.
	 *
	 * @param string $session_key Session key.
	 * @return array{id:int,cart_hash:string,status:string,step:string,type:string}|null
	 */
	public static function find_by_session( string $session_key ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, cart_hash, status, step, type FROM {$table} WHERE session_key = %s LIMIT 1", $session_key ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'        => (int) $row['id'],
			'cart_hash' => (string) $row['cart_hash'],
			'status'    => (string) $row['status'],
			'step'      => (string) ( $row['step'] ?? '' ),
			'type'      => (string) ( $row['type'] ?? 'cart' ),
		);
	}

	/**
	 * One full row.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		$cols  = self::COLUMNS . ', token_hash, token_expires_gmt';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Inserts a row.
	 *
	 * @param array<string,mixed> $data Column => value (whitelisted columns only).
	 * @return int New id, 0 on failure.
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$data = self::only_known( $data );
		$ok   = $wpdb->insert( self::table(), $data, self::formats( $data ) );
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Updates a row.
	 *
	 * @param int                 $id   Row id.
	 * @param array<string,mixed> $data Column => value.
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$data = self::only_known( $data );
		return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ), self::formats( $data ), array( '%d' ) );
	}

	/**
	 * Updates a row only while it still has the given status (compare-and-set against concurrent requests).
	 *
	 * @param int                 $id     Row id.
	 * @param string              $status Expected current status.
	 * @param array<string,mixed> $data   Column => value.
	 * @return bool True when this call changed the row.
	 */
	public static function update_if_status( int $id, string $status, array $data ): bool {
		global $wpdb;
		$data   = self::only_known( $data );
		$result = $wpdb->update(
			self::table(),
			$data,
			array(
				'id'     => $id,
				'status' => $status,
			),
			self::formats( $data ),
			array( '%d', '%s' )
		);
		return is_int( $result ) && $result > 0;
	}

	/**
	 * Claims a due row for the scanner: clears next_action_gmt only if nobody else did it first.
	 *
	 * @param int    $id       Row id.
	 * @param string $expected next_action_gmt read by the scanner.
	 * @return bool True when this scanner run owns the row.
	 */
	public static function claim_due( int $id, string $expected ): bool {
		global $wpdb;
		$result = $wpdb->update(
			self::table(),
			array( 'next_action_gmt' => null ),
			array(
				'id'              => $id,
				'status'          => Status::ACTIVE,
				'next_action_gmt' => $expected,
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);
		return is_int( $result ) && $result > 0;
	}

	/**
	 * Marks the active row of a session as converted (or recovered if step > 0).
	 *
	 * @param string $session_key Session key.
	 * @param int    $order_id    Created order.
	 * @param string $now         GMT datetime.
	 * @return array{id:int,recovered:bool}|null Result, or null when there was nothing to convert.
	 */
	public static function mark_converted( string $session_key, int $order_id, string $now ): ?array {
		$row = self::find_by_session( $session_key );
		if ( null === $row || Status::ACTIVE !== $row['status'] ) {
			return null;
		}
		return self::mark_converted_by_id( $row['id'], $order_id, $now );
	}

	/**
	 * Marks an active row by ID as converted (or recovered if step > 0).
	 *
	 * @param int    $id       Row ID.
	 * @param int    $order_id Created order.
	 * @param string $now      GMT datetime.
	 * @return array{id:int,recovered:bool}|null Result, or null on failure.
	 */
	public static function mark_converted_by_id( int $id, int $order_id, string $now ): ?array {
		$row = self::get( $id );
		if ( null === $row || Status::ACTIVE !== $row['status'] ) {
			return null;
		}
		$step_num     = (int) ( $row['step'] ?? 0 );
		$is_recovered = $step_num > 0;
		$fields       = array(
			'status'          => $is_recovered ? Status::RECOVERED : Status::CONVERTED,
			'order_id'        => $order_id,
			'next_action_gmt' => null,
			'last_event_gmt'  => $now,
			'updated_gmt'     => $now,
		);
		if ( $is_recovered ) {
			$fields['recovered_order_id'] = $order_id;
		}
		$done = self::update( $id, $fields );
		return $done ? array(
			'id'        => $id,
			'recovered' => $is_recovered,
		) : null;
	}

	/**
	 * Single-query lookup for order recovery: the row of this order, else the active cart row of the
	 * same session or of the same e-mail with recent activity (to convert it into the order row).
	 *
	 * @param int    $order_id    Order ID.
	 * @param string $session_key Shopper session ('' when unknown, e.g. gateway callbacks).
	 * @param string $email       Billing e-mail ('' when unknown).
	 * @param string $since_gmt   Oldest last_activity_gmt accepted for the e-mail match.
	 * @return array{id:int,type:string,status:string,step:string,order_id:int,wa_sent_count:int,email_sent_count:int,has_items:bool}|null
	 */
	public static function find_for_order( int $order_id, string $session_key, string $email, string $since_gmt ): ?array {
		global $wpdb;
		$table = self::table();
		$where = array( 'order_id = %d' );
		$args  = array( $order_id );

		$cart = array();
		if ( '' !== $session_key ) {
			$cart[] = 'session_key = %s';
			$args[] = $session_key;
		}
		if ( '' !== $email ) {
			$cart[] = '(email = %s AND last_activity_gmt >= %s)';
			$args[] = strtolower( $email );
			$args[] = $since_gmt;
		}
		if ( array() !== $cart ) {
			$where[] = "(type = 'cart' AND status = 'active' AND (" . implode( ' OR ', $cart ) . '))';
		}
		$args[] = $order_id;

		$sql = "SELECT id, type, status, step, order_id, wa_sent_count, email_sent_count, (items IS NOT NULL AND items <> '') AS has_items FROM {$table} WHERE " . implode( ' OR ', $where ) . ' ORDER BY (order_id = %d) DESC, id DESC LIMIT 1';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'               => (int) $row['id'],
			'type'             => (string) $row['type'],
			'status'           => (string) $row['status'],
			'step'             => (string) $row['step'],
			'order_id'         => (int) $row['order_id'],
			'wa_sent_count'    => (int) $row['wa_sent_count'],
			'email_sent_count' => (int) $row['email_sent_count'],
			'has_items'        => (bool) (int) $row['has_items'],
		);
	}

	/**
	 * Removes the ACTIVE row of a session (the cart became empty).
	 *
	 * @param string $session_key Session key.
	 */
	public static function delete_active_by_session( string $session_key ): int {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE session_key = %s AND status = %s AND type = %s LIMIT 1", $session_key, Status::ACTIVE, 'cart' ), ARRAY_A );
		return is_array( $row ) ? self::delete_ids( array( (int) $row['id'] ) ) : 0;
	}

	/**
	 * Deletes rows and their events.
	 *
	 * @param array<int,int> $ids Row ids.
	 * @return int Rows deleted.
	 */
	public static function delete_ids( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) );
		if ( array() === $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$ev = self::events_table();
		$tb = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$ev} WHERE recovery_id IN ({$in})", $ids ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$tb} WHERE id IN ({$in})", $ids ) );
	}

	/**
	 * Builds the WHERE clause (pure).
	 *
	 * Filters: status (stored status, "abandoned" or "active" = not yet abandoned), type, date_from /
	 * date_to ("Y-m-d", on created_gmt), search (e-mail or phone fragment).
	 *
	 * @param array<string,string> $filters        Filters.
	 * @param string               $abandon_cutoff GMT datetime (see Status::abandon_cutoff()).
	 * @return array{0:string,1:list<string>} SQL (with leading " WHERE " or empty) and its arguments.
	 */
	public static function where( array $filters, string $abandon_cutoff ): array {
		$sql  = array();
		$args = array();

		$status = $filters['status'] ?? '';
		if ( Status::ABANDONED === $status ) {
			// Only carts are ever "abandoned"; orders waiting for payment stay active.
			$sql[]  = 'status = %s AND type = %s AND last_activity_gmt < %s';
			$args[] = Status::ACTIVE;
			$args[] = 'cart';
			$args[] = $abandon_cutoff;
		} elseif ( Status::ACTIVE === $status ) {
			$sql[]  = 'status = %s AND (type <> %s OR last_activity_gmt >= %s)';
			$args[] = Status::ACTIVE;
			$args[] = 'cart';
			$args[] = $abandon_cutoff;
		} elseif ( in_array( $status, Status::STORED, true ) ) {
			$sql[]  = 'status = %s';
			$args[] = $status;
		}

		$type = $filters['type'] ?? '';
		if ( in_array( $type, array( 'cart', 'order' ), true ) ) {
			$sql[]  = 'type = %s';
			$args[] = $type;
		}

		$from = $filters['date_from'] ?? '';
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$sql[]  = 'created_gmt >= %s';
			$args[] = $from . ' 00:00:00';
		}
		$to = $filters['date_to'] ?? '';
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$sql[]  = 'created_gmt <= %s';
			$args[] = $to . ' 23:59:59';
		}

		$search = trim( $filters['search'] ?? '' );
		if ( '' !== $search ) {
			$like   = '%' . addcslashes( substr( $search, 0, 100 ), '_%\\' ) . '%';
			$sql[]  = '(email LIKE %s OR phone LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}

		return array( array() === $sql ? '' : ' WHERE ' . implode( ' AND ', $sql ), $args );
	}

	/**
	 * Paginated list.
	 *
	 * @param array<string,string> $filters        Filters (see where()).
	 * @param string               $abandon_cutoff GMT datetime.
	 * @param int                  $page           1-based page.
	 * @param int                  $per_page       Rows per page (max 100).
	 * @param string               $orderby        One of ORDERABLE.
	 * @param string               $order          ASC|DESC.
	 * @return array{rows:list<array<string,mixed>>,total:int}
	 */
	public static function query( array $filters, string $abandon_cutoff, int $page, int $per_page, string $orderby = 'id', string $order = 'DESC' ): array {
		global $wpdb;
		$table                = self::table();
		list( $where, $args ) = self::where( $filters, $abandon_cutoff );
		$per_page             = max( 1, min( 100, $per_page ) );
		$offset               = max( 0, $page - 1 ) * $per_page;
		$orderby              = in_array( $orderby, self::ORDERABLE, true ) ? $orderby : 'id';
		$order                = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		$count_sql = "SELECT COUNT(*) FROM {$table}{$where}";
		$total     = (int) ( array() === $args ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) );

		$list_sql = 'SELECT ' . self::COLUMNS . " FROM {$table}{$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A );

		return array(
			'rows'  => is_array( $rows ) ? array_values( $rows ) : array(),
			'total' => $total,
		);
	}

	/**
	 * Counts for the summary: abandoned, recovered (via link vs after message) and recovered value.
	 *
	 * @param string $abandon_cutoff GMT datetime.
	 * @return array{abandoned:int,recovered:int,recovered_link:int,recovered_message:int,recovered_value:float}
	 */
	public static function summary( string $abandon_cutoff ): array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(status = %s AND type = %s AND last_activity_gmt < %s) AS abandoned, SUM(status = %s) AS recovered, SUM(status = %s AND clicked_gmt IS NOT NULL) AS recovered_link, SUM(status = %s AND clicked_gmt IS NULL) AS recovered_message, SUM(CASE WHEN status = %s THEN total ELSE 0 END) AS recovered_value FROM {$table}",
				Status::ACTIVE,
				'cart',
				$abandon_cutoff,
				Status::RECOVERED,
				Status::RECOVERED,
				Status::RECOVERED,
				Status::RECOVERED
			),
			ARRAY_A
		);
		return array(
			'abandoned'         => is_array( $row ) ? (int) $row['abandoned'] : 0,
			'recovered'         => is_array( $row ) ? (int) $row['recovered'] : 0,
			'recovered_link'    => is_array( $row ) ? (int) $row['recovered_link'] : 0,
			'recovered_message' => is_array( $row ) ? (int) $row['recovered_message'] : 0,
			'recovered_value'   => is_array( $row ) ? (float) $row['recovered_value'] : 0.0,
		);
	}

	/**
	 * Finds active rows whose next_action_gmt has passed.
	 *
	 * @param string $now_gmt GMT datetime.
	 * @param int    $limit   Limit.
	 * @return list<array{id:int,step:string,type:string,order_id:int,last_activity_gmt:string,next_action_gmt:string}>
	 */
	public static function find_due_for_action( string $now_gmt, int $limit = 50 ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, step, type, order_id, last_activity_gmt, next_action_gmt FROM {$table} WHERE status = %s AND next_action_gmt IS NOT NULL AND next_action_gmt <= %s ORDER BY next_action_gmt ASC LIMIT %d",
				Status::ACTIVE,
				$now_gmt,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Deletes expired rows (and their events), at most $limit per call.
	 *
	 * @param array{terminal:string,other:string} $cutoffs GMT cutoffs (see Retention::cutoffs()).
	 * @param int                                 $limit   Batch size.
	 * @return int Rows deleted.
	 */
	public static function purge( array $cutoffs, int $limit ): int {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE (status IN (%s, %s) AND updated_gmt < %s) OR (status NOT IN (%s, %s) AND updated_gmt < %s) ORDER BY id ASC LIMIT %d",
				Status::CONVERTED,
				Status::RECOVERED,
				$cutoffs['terminal'],
				Status::CONVERTED,
				Status::RECOVERED,
				$cutoffs['other'],
				$limit
			)
		);
		return self::delete_ids( is_array( $ids ) ? array_map( 'intval', $ids ) : array() );
	}

	/**
	 * Rows of an e-mail (privacy exporter), paginated.
	 *
	 * @param string $email    E-mail.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Page size.
	 * @return list<array<string,mixed>>
	 */
	public static function by_email( string $email, int $page, int $per_page = 50 ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", strtolower( $email ), $per_page, max( 0, $page - 1 ) * $per_page ),
			ARRAY_A
		);
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Deletes everything stored for an e-mail (privacy eraser).
	 *
	 * @param string $email E-mail.
	 * @return int Rows deleted.
	 */
	public static function delete_by_email( string $email ): int {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s LIMIT 500", strtolower( $email ) ) );
		return self::delete_ids( is_array( $ids ) ? array_map( 'intval', $ids ) : array() );
	}

	/**
	 * Keeps only known columns.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return array<string,mixed>
	 */
	private static function only_known( array $data ): array {
		return array_intersect_key( $data, self::FORMATS );
	}

	/**
	 * Formats for the given data keys.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return list<string>
	 */
	private static function formats( array $data ): array {
		return array_map( static fn( string $key ): string => self::FORMATS[ $key ], array_keys( $data ) );
	}
}
