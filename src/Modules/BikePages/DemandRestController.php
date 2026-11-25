<?php
/**
 * Transactional stock subscriptions using account contacts or a guest contact.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Modules\Compatibility\RestController as CompatibilityRest;
use Motored\Core\Support\Cache;
use Motored\Core\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Records consent only; transport is handled asynchronously by notification consumers.
 */
final class DemandRestController {

	public const RATE_LIMIT_MAX    = 5;
	public const RATE_LIMIT_WINDOW = 600;

	/** Registers the existing public subscription route. */
	public function register_routes(): void {
		register_rest_route(
			CompatibilityRest::NAMESPACE,
			'/compat/notify/(?P<id>\\d+)/unsubscribe',
			array(
				// POST only: customers use the frontend confirmation page; a GET must never delete (link scanners).
				'methods'             => 'POST',
				'callback'            => array( $this, 'unsubscribe' ),
				'permission_callback' => '__return_true',
			)
		);
		if ( ! Config::notify_enabled() ) {
			return;
		}
		register_rest_route(
			CompatibilityRest::NAMESPACE,
			'/compat/notify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_notify' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Cancels exactly one subscription using an unguessable signed token, even while delivery is paused.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public function unsubscribe( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = absint( $request->get_param( 'id' ) );
		$row = DemandStore::find( $id );
		if ( null === $row ) {
			return new \WP_REST_Response( array( 'message' => __( 'Alert canceled.', 'motored-core' ) ), 200 );
		}
		if ( ! hash_equals( DemandStore::token( $row ), (string) $request->get_param( 'token' ) ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$ok = DemandStore::delete( $id );
		return new \WP_REST_Response(
			array(
				'ok'      => $ok,
				'message' => __( 'Alert canceled.', 'motored-core' ),
			),
			$ok ? 200 : 503
		);
	}

	/**
	 * Uses the authenticated customer's saved contacts, never another account's submitted ID.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public function handle_notify( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Config::notify_enabled() ) {
			return new \WP_REST_Response( array( 'ok' => false ), 404 );
		}
		// Accounts are protected against CSRF by the core wp_rest nonce. Guests have no session to forge, and the
		// bike pages are page-cache candidates: a cached guest nonce older than 24 h must not break the button.
		// Guests are limited by the honeypot + rate limit below instead.
		if ( is_user_logged_in() && (
			! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' )
			|| ! wp_verify_nonce( (string) $request->get_header( 'X-Motored-Nonce' ), 'motored_notify' )
		) ) {
			return new \WP_REST_Response( array( 'error' => __( 'Your session expired. Reload the page and try again.', 'motored-core' ) ), 403 );
		}
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( ! $this->check_rate_limit() ) {
			return new \WP_REST_Response( array( 'error' => __( 'Too many requests. Please wait a few minutes and try again.', 'motored-core' ) ), 429 );
		}

		$contact   = (string) $request->get_param( 'contact' );
		$secondary = '';
		if ( is_user_logged_in() ) {
			$customer  = new \WC_Customer( get_current_user_id() );
			$submitted = $contact;
			$contact   = (string) $customer->get_billing_phone();
			$secondary = (string) $customer->get_email();
			// No phone on the account: accept a WhatsApp number typed in the dialog (never another e-mail).
			if ( '' === DemandStore::parse_contacts( $contact )['phone'] && '' !== trim( $submitted ) ) {
				$typed = DemandStore::parse_contacts( $submitted )['phone'];
				if ( '' === $typed ) {
					return self::error( 'invalid_contact', 422 );
				}
				$contact = $typed;
			}
			if ( '' === $contact && '' === $secondary ) {
				return new \WP_REST_Response(
					array(
						'code'  => 'contact_required',
						'error' => __( 'Add a WhatsApp number or e-mail to your account before requesting an alert.', 'motored-core' ),
					),
					422
				);
			}
		}
		$res = DemandStore::record(
			array(
				'moto'              => (string) $request->get_param( 'moto' ),
				'position'          => (string) $request->get_param( 'position' ),
				'contact'           => $contact,
				'secondary_contact' => $secondary,
				'consent'           => filter_var( $request->get_param( 'consent' ), FILTER_VALIDATE_BOOLEAN ),
			)
		);
		if ( ! $res['ok'] ) {
			$storage = in_array( $res['error'], array( 'storage_unavailable', 'insert_failed' ), true );
			return self::error( $res['error'], $storage ? 503 : ( 'unknown_bike' === $res['error'] ? 404 : 422 ) );
		}
		// Tell the customer exactly which channel will be used, so "we will notify you" is verifiable.
		$whatsapp = '' !== DemandStore::parse_contacts( $contact, $secondary )['phone'];
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'channel' => $whatsapp ? 'whatsapp' : 'email',
				'message' => $whatsapp
					? __( 'Done. We will message you on WhatsApp when compatible tires are available.', 'motored-core' )
					: __( 'Done. We will e-mail you when compatible tires are available.', 'motored-core' ),
			),
			200
		);
	}

	/**
	 * Customer-facing error with a specific, actionable message.
	 *
	 * @param string $code   Machine error code from DemandStore::record().
	 * @param int    $status HTTP status.
	 */
	private static function error( string $code, int $status ): \WP_REST_Response {
		switch ( $code ) {
			case 'consent_required':
				$message = __( 'Accept receiving the alert to continue.', 'motored-core' );
				break;
			case 'invalid_contact':
				$message = __( 'Check the WhatsApp number (with area code) or the e-mail address.', 'motored-core' );
				break;
			case 'unknown_bike':
				$message = __( 'This motorcycle is no longer in our catalog. Reload the page and try again.', 'motored-core' );
				break;
			default:
				$message = __( 'We could not save your alert. Please try again.', 'motored-core' );
		}
		return new \WP_REST_Response(
			array(
				'ok'    => false,
				'code'  => $code,
				'error' => $message,
			),
			$status
		);
	}

	/** Serializes the fixed-window counter even without a persistent object cache. */
	private function check_rate_limit(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Hashed immediately; never logged.
		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'anon';
		$identity = is_user_logged_in() ? 'u' . get_current_user_id() : 'ip' . $ip;
		$key      = 'notify_rl_' . hash( 'sha256', $identity . ':' . (int) floor( time() / self::RATE_LIMIT_WINDOW ) );
		$lock     = 'motored_' . $key;
		if ( ! Db::acquire_option_lock( $lock ) ) {
			return false;
		}
		try {
			$count = (int) Cache::get( $key, 'bike_demand' );
			if ( $count >= self::RATE_LIMIT_MAX ) {
				return false;
			}
			return Cache::set( $key, $count + 1, self::RATE_LIMIT_WINDOW, 'bike_demand' );
		} finally {
			delete_option( $lock );
		}
	}
}
