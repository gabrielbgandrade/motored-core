<?php
/**
 * Capture for logged-in customers on cart changes.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\WooCommerce\CartHooks;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Listens ONLY to add to cart, remove item and quantity change (never to page views). Per-user
 * throttle of 5 minutes in Cache; the write is deferred to shutdown.
 */
final class CartChangeCapture {

	public const THROTTLE_SECONDS = 300;

	/**
	 * Whether the shutdown flush is already queued in this request.
	 *
	 * @var bool
	 */
	private bool $queued = false;

	/**
	 * Registers the cart-change hooks.
	 */
	public function register(): void {
		CartHooks::register_cart_changes( array( $this, 'on_change' ) );
	}

	/**
	 * Cart changed.
	 */
	public function on_change(): void {
		if ( $this->queued ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! Config::enabled() ) {
			return;
		}
		if ( ! Cache::add( 'li:' . $user_id, 1, self::THROTTLE_SECONDS, CaptureLimiter::BUCKET ) ) {
			return;
		}
		$this->queued = true;
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Deferred write (shutdown).
	 */
	public function flush(): void {
		$user_id = get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$first = (string) get_user_meta( $user_id, 'billing_first_name', true );
		if ( '' === $first ) {
			$first = (string) get_user_meta( $user_id, 'first_name', true );
		}
		$email   = (string) get_user_meta( $user_id, 'billing_email', true );
		$contact = CaptureValidator::validate(
			array(
				'email' => '' !== $email ? $email : $user->user_email,
				'phone' => (string) get_user_meta( $user_id, 'billing_phone', true ),
				'name'  => $first,
			),
			WahaConfig::country_code()
		);
		if ( null === $contact ) {
			return;
		}
		CaptureService::capture( CartHooks::session_key(), $user_id, $contact, 'account' );
	}
}
