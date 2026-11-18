<?php
/**
 * Upserts a captured cart and records its history.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\WooCommerce\CartHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the checkout endpoint and the logged-in capture. Budget: at most 3 queries
 * (lookup, insert/update, one event).
 */
final class CaptureService {

	/**
	 * Captures the current server-side cart for a session.
	 *
	 * @param string                                             $session_key Shopper session.
	 * @param int                                                $user_id     User id (0 = guest).
	 * @param array{email:string,phone:string,first_name:string} $contact     Validated contact.
	 * @param string                                             $origin      "checkout" or "account" (history detail).
	 * @return int|null Row id, or null when the cart is empty / nothing was stored.
	 */
	public static function capture( string $session_key, int $user_id, array $contact, string $origin ): ?int {
		if ( '' === $session_key ) {
			return null;
		}
		$snapshot = Snapshot::from_cart( CartHooks::cart_lines() );
		if ( array() === $snapshot['items'] ) {
			Repository::delete_active_by_session( $session_key );
			return null;
		}

		$now      = gmdate( 'Y-m-d H:i:s' );
		$existing = Repository::find_by_session( $session_key );
		if ( null !== $existing && 'order' === $existing['type'] ) {
			if ( Status::ACTIVE === $existing['status'] ) {
				return null; // The order payment sequence owns this session: never capture a second cart.
			}
			Repository::update( $existing['id'], array( 'session_key' => null ) ); // Finished order row: free the session for a new cart.
			$existing = null;
		}
		$fields = array(
			'user_id'           => $user_id,
			'cart_hash'         => $snapshot['hash'],
			'items'             => (string) wp_json_encode( $snapshot['items'] ),
			'currency'          => substr( CartHooks::currency(), 0, 10 ),
			'total'             => number_format( CartHooks::cart_total(), 4, '.', '' ),
			'last_activity_gmt' => $now,
			'updated_gmt'       => $now,
		);
		foreach ( array( 'email', 'phone', 'first_name' ) as $key ) {
			if ( '' !== $contact[ $key ] ) {
				$fields[ $key ] = $contact[ $key ];
			}
		}

		if ( null === $existing ) {
			$next_step       = Config::next_step_num( 0 );
			$next_action_gmt = null !== $next_step
				? gmdate( 'Y-m-d H:i:s', time() + ( Config::step( $next_step )['delay'] * 60 ) )
				: null;

			$id = Repository::insert(
				array_merge(
					$fields,
					array(
						'type'            => 'cart',
						'session_key'     => $session_key,
						'status'          => Status::ACTIVE,
						'next_action_gmt' => $next_action_gmt,
						'created_gmt'     => $now,
						'last_event_gmt'  => $now,
					)
				)
			);
			if ( $id > 0 ) {
				History::log( $id, 'captured', 'none', 'system', 0, 'ok', $origin, false );
			}
			return $id > 0 ? $id : null;
		}

		$id       = $existing['id'];
		$changed  = $existing['cart_hash'] !== $snapshot['hash'];
		$reopened = in_array( $existing['status'], array( Status::CONVERTED, Status::RECOVERED, Status::EXPIRED ), true );
		if ( $reopened ) {
			// A new cart after a finished one: start a fresh cycle (opted_out stays opted_out).
			$next_step                 = Config::next_step_num( 0 );
			$fields['status']          = Status::ACTIVE;
			$fields['step']            = '';
			$fields['order_id']        = 0;
			$fields['created_gmt']     = $now;
			$fields['next_action_gmt'] = null !== $next_step
				? gmdate( 'Y-m-d H:i:s', time() + ( Config::step( $next_step )['delay'] * 60 ) )
				: null;
		} elseif ( Status::ACTIVE === $existing['status'] ) {
			// New activity on an active cart postpones the next action.
			$next_step = Config::next_step_num( (string) ( $existing['step'] ?? '' ) );
			if ( null !== $next_step ) {
				$fields['next_action_gmt'] = gmdate( 'Y-m-d H:i:s', time() + ( Config::step( $next_step )['delay'] * 60 ) );
			}
		}

		if ( $changed || $reopened ) {
			$fields['last_event_gmt'] = $now;
		}
		Repository::update( $id, $fields );
		if ( $changed || $reopened ) {
			History::log( $id, $reopened ? 'captured' : 'cart_updated', 'none', 'system', 0, 'ok', $origin, false );
		}
		return $id;
	}
}
