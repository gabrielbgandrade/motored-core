<?php
/**
 * Demand capture persistence, deduplication, admin summary, CSV export and retention purge.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Integration\ActionScheduler;
use Motored\Core\Integration\BikeDemandHooks;
use Motored\Core\Modules\Compatibility\CatalogRepository;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Modules\Waha\Phone;
use Motored\Core\Support\Db;
use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

// Table names come from Db::table(); scalar parameters use $wpdb->prepare().
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Stores notify-me requests without ever logging raw PII or calling wp_mail() / Waha\Sender directly.
 */
final class DemandStore {

	public const PURGE_HOOK = 'motored_bike_demand_purge';

	/**
	 * Table name.
	 */
	public static function table(): string {
		return Db::table( 'bike_demand' );
	}

	/**
	 * Schedules the daily retention purge job (call on activation / admin_init only — Rule 7).
	 */
	public static function schedule_purge(): void {
		$day = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
		if ( ! ActionScheduler::has_scheduled( self::PURGE_HOOK ) ) {
			ActionScheduler::schedule_recurring( time() + $day, $day, self::PURGE_HOOK );
		}
	}

	/**
	 * Auto-detects whether a contact string is an e-mail address or phone number.
	 *
	 * @param string $primary   Primary contact field.
	 * @param string $secondary Optional second contact field.
	 * @return array{email:string,phone:string,hash:string}
	 */
	public static function parse_contacts( string $primary, string $secondary = '' ): array {
		$email = '';
		$phone = '';
		foreach ( array( $primary, $secondary ) as $raw ) {
			$val = trim( $raw );
			if ( '' === $val ) {
				continue;
			}
			if ( false !== strpos( $val, '@' ) ) {
				$clean_email = sanitize_email( $val );
				if ( '' !== $clean_email && '' === $email ) {
					$email = strtolower( $clean_email );
				}
			} elseif ( '' === $phone ) {
				// Same normalization WAHA uses at send time: a number it cannot deliver to is rejected now,
				// while the customer can still fix it, and "011 15..." / "+54 9 11..." dedupe to one E.164.
				$e164 = Phone::normalize( $val, WahaConfig::country_code() );
				if ( null !== $e164 ) {
					$phone = substr( $e164, 0, 40 );
				}
			}
		}
		$hash_source = '' !== $email ? 'e:' . $email : ( '' !== $phone ? 'p:' . $phone : '' );
		return array(
			'email' => $email,
			'phone' => $phone,
			'hash'  => '' !== $hash_source ? hash( 'sha256', $hash_source ) : '',
		);
	}

	/**
	 * Records a deduplicated notify-me request and emits `motored_bike_demand_created`.
	 *
	 * @param array{moto?:string,position?:string,contact?:string,secondary_contact?:string,consent?:bool} $input Request payload.
	 * @return array{ok:bool,id:int,duplicate:bool,error:string}
	 */
	public static function record( array $input ): array {
		if ( empty( $input['consent'] ) ) {
			return array(
				'ok'        => false,
				'id'        => 0,
				'duplicate' => false,
				'error'     => 'consent_required',
			);
		}
		$parsed = self::parse_contacts(
			(string) ( $input['contact'] ?? '' ),
			(string) ( $input['secondary_contact'] ?? '' )
		);
		if ( '' === $parsed['hash'] ) {
			return array(
				'ok'        => false,
				'id'        => 0,
				'duplicate' => false,
				'error'     => 'invalid_contact',
			);
		}

		$slug = sanitize_title( (string) ( $input['moto'] ?? '' ) );
		$pos  = sanitize_key( (string) ( $input['position'] ?? 'all' ) );
		if ( ! in_array( $pos, array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR, TireSpecs::POS_KIT, 'all' ), true ) ) {
			$pos = 'all';
		}

		$meta = self::resolve_bike_context( $slug );
		if ( '' === $meta['signature'] ) {
			return array(
				'ok'        => false,
				'id'        => 0,
				'duplicate' => false,
				'error'     => 'unknown_bike',
			);
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array(
				'ok'        => false,
				'id'        => 0,
				'duplicate' => false,
				'error'     => 'storage_unavailable',
			);
		}

		$table    = self::table();
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE contact_hash = %s AND signature = %s AND position = %s LIMIT 1",
				$parsed['hash'],
				$meta['signature'],
				$pos
			)
		);
		if ( $existing > 0 ) {
			$row = self::find( $existing );
			// An explicit new request may renew a completed/failed one-shot alert. In-flight jobs stay untouched.
			if ( null !== $row && in_array( $row['status'], array( 'notified', 'failed' ), true ) ) {
				$wpdb->update(
					$table,
					array(
						'status'       => 'pending',
						'version_slug' => $slug,
						'email'        => $parsed['email'],
						'phone'        => $parsed['phone'],
						'consent_gmt'  => gmdate( 'Y-m-d H:i:s' ),
						'created_gmt'  => gmdate( 'Y-m-d H:i:s' ),
						'updated_gmt'  => gmdate( 'Y-m-d H:i:s' ),
						'notified_gmt' => null,
						'last_error'   => '',
					),
					array(
						'id'     => $existing,
						'status' => $row['status'],
					)
				);
			} elseif ( null !== $row && '' === (string) $row['version_slug'] && in_array( $row['status'], array( 'pending', 'paused' ), true ) ) {
				$wpdb->update(
					$table,
					array( 'version_slug' => $slug ),
					array(
						'id'     => $existing,
						'status' => $row['status'],
					)
				);
			}
			BikeDemandHooks::created( $existing, array() );
			return array(
				'ok'        => true,
				'id'        => $existing,
				'duplicate' => true,
				'error'     => '',
			);
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			$table,
			array(
				'signature'    => substr( $meta['signature'], 0, 80 ),
				'model_id'     => (int) $meta['model_id'],
				'brand_name'   => substr( $meta['brand'], 0, 120 ),
				'model_name'   => substr( $meta['model'], 0, 160 ),
				'years_label'  => substr( $meta['years'], 0, 60 ),
				'position'     => $pos,
				'contact_hash' => $parsed['hash'],
				'email'        => $parsed['email'],
				'phone'        => $parsed['phone'],
				'version_slug' => $slug,
				'user_id'      => get_current_user_id(),
				'updated_gmt'  => $now,
				'consent_gmt'  => $now,
				'created_gmt'  => $now,
				'status'       => 'pending',
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			return array(
				'ok'        => false,
				'id'        => 0,
				'duplicate' => false,
				'error'     => 'insert_failed',
			);
		}
		$id = (int) $wpdb->insert_id;

		Logger::info(
			'Recorded motorcycle tire demand request.',
			array(
				'id'        => $id,
				'signature' => $meta['signature'],
				'position'  => $pos,
				'email'     => '' !== $parsed['email'] ? Logger::mask_email( $parsed['email'] ) : '',
				'phone'     => '' !== $parsed['phone'] ? Logger::mask_phone( $parsed['phone'] ) : '',
			)
		);

		BikeDemandHooks::created(
			$id,
			array(
				'signature' => $meta['signature'],
				'position'  => $pos,
				'brand'     => $meta['brand'],
				'model'     => $meta['model'],
				'years'     => $meta['years'],
				'sizes'     => $meta['sizes'],
				'has_email' => '' !== $parsed['email'],
				'has_phone' => '' !== $parsed['phone'],
			)
		);

		return array(
			'ok'        => true,
			'id'        => $id,
			'duplicate' => false,
			'error'     => '',
		);
	}

	/**
	 * Signed cancellation link; possession authorizes only deletion of this subscription.
	 *
	 * @param array<string,mixed> $row Subscription.
	 */
	public static function unsubscribe_url( array $row ): string {
		return self::cancel_url( (int) $row['id'], self::token( $row ) );
	}

	/**
	 * Frontend confirmation page URL (GET shows a button; only the POST deletes).
	 *
	 * @param int    $id    Subscription ID.
	 * @param string $token HMAC token.
	 */
	public static function cancel_url( int $id, string $token ): string {
		return add_query_arg(
			array(
				DemandCancelHandler::QUERY_ARG => $id,
				'token'                        => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * HMAC bound to the row and its consent time (a renewed subscription invalidates older links).
	 *
	 * @param array<string,mixed> $row Subscription.
	 */
	public static function token( array $row ): string {
		return hash_hmac( 'sha256', $row['id'] . '|' . $row['consent_gmt'], wp_salt( 'nonce' ) );
	}

	/**
	 * Deletes one subscription (signed customer cancellation).
	 *
	 * @param int $id Subscription ID.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Loads one subscription. Contacts never enter scheduler arguments.
	 *
	 * @param int $id Subscription ID.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Compare-and-swap delivery state; concurrent workers cannot claim the same subscription.
	 *
	 * @param int    $id      Subscription ID.
	 * @param string $from    Expected state.
	 * @param string $to      New state.
	 * @param string $channel Delivery channel.
	 * @param string $error   Machine code only (never service response/message text).
	 */
	public static function transition( int $id, string $from, string $to, string $channel = '', string $error = '' ): bool {
		global $wpdb;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$data  = array(
			'status'      => $to,
			'channel'     => $channel,
			'last_error'  => substr( sanitize_key( $error ), 0, 60 ),
			'updated_gmt' => $now,
		);
		if ( 'notified' === $to ) {
			$data['notified_gmt'] = $now;
		}
		return 1 === $wpdb->update(
			$table,
			$data,
			array(
				'id'     => $id,
				'status' => $from,
			)
		);
	}

	/**
	 * Cursor page of pending/paused subscriptions, retried only by stock events or the daily sweep.
	 *
	 * @param int $cursor Last processed ID.
	 * @return list<int>
	 */
	public static function pending_ids( int $cursor = 0 ): array {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id > %d AND status IN ('pending','paused') ORDER BY id ASC LIMIT 50", $cursor ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Grouped demand summary for Productos > Compatibilidad > Demanda.
	 *
	 * @param int $limit Max grouped rows.
	 * @return list<array{signature:string,brand_name:string,model_name:string,years_label:string,position:string,total:int,oldest_gmt:string}>
	 */
	public static function grouped_summary( int $limit = 100 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT signature, brand_name, model_name, years_label, position,
					COUNT(*) AS total, MIN(created_gmt) AS oldest_gmt
				FROM {$table}
				GROUP BY signature, brand_name, model_name, years_label, position
				ORDER BY total DESC, oldest_gmt ASC
				LIMIT %d",
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'signature'   => (string) $row['signature'],
				'brand_name'  => (string) $row['brand_name'],
				'model_name'  => (string) $row['model_name'],
				'years_label' => (string) $row['years_label'],
				'position'    => (string) $row['position'],
				'total'       => (int) $row['total'],
				'oldest_gmt'  => (string) $row['oldest_gmt'],
			);
		}
		return $out;
	}

	/**
	 * Exports raw demand rows for admin CSV download.
	 *
	 * @param int $limit Max rows.
	 * @return list<array<string,mixed>>
	 */
	public static function export_rows( int $limit = 2000 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, signature, brand_name, model_name, years_label, position, email, phone, consent_gmt, created_gmt, status
				FROM {$table}
				ORDER BY created_gmt DESC
				LIMIT %d",
				max( 1, min( 5000, $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes demand rows older than the configured retention period.
	 */
	public static function purge_expired(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( Config::retention_days() * DAY_IN_SECONDS ) );
		$table   = self::table();
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_gmt < %s", $cutoff ) );
		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Resolves signature, bike names and OEM sizes from a version slug.
	 *
	 * @param string $slug Version slug.
	 * @return array{signature:string,model_id:int,brand:string,model:string,years:string,sizes:array{front:list<string>,rear:list<string>}}
	 */
	private static function resolve_bike_context( string $slug ): array {
		$empty = array(
			'signature' => '',
			'model_id'  => 0,
			'brand'     => '',
			'model'     => '',
			'years'     => '',
			'sizes'     => array(
				'front' => array(),
				'rear'  => array(),
			),
		);
		if ( '' === $slug ) {
			return $empty;
		}
		$ver = CatalogRepository::find_version_by_slug( $slug, true );
		if ( null === $ver ) {
			return $empty;
		}
		$fitments = CatalogRepository::fitments_for_version( (int) $ver['id'] );
		$sig      = Signature::key( (int) $ver['model_id'], Signature::compute_for_fitments( $fitments ) );
		$sizes    = array(
			'front' => array(),
			'rear'  => array(),
		);
		foreach ( $fitments as $f ) {
			if ( TireSpecs::FIT_OEM === (string) $f['fit_type'] && isset( $sizes[ $f['position'] ] ) ) {
				$sizes[ $f['position'] ][] = (string) ( $f['designation'] ?? $f['canonical'] );
			}
		}
		return array(
			'signature' => $sig,
			'model_id'  => (int) $ver['model_id'],
			'brand'     => (string) $ver['brand_name'],
			'model'     => (string) $ver['model_name'],
			'years'     => MatchEngine::format_years( (int) $ver['year_from'], (int) $ver['year_to'] ),
			'sizes'     => $sizes,
		);
	}
}
