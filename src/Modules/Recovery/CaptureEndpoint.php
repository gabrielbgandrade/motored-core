<?php
/**
 * Endpoint wc-ajax "motored_capture".
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\WooCommerce\CartHooks;
use Motored\Core\Modules\Waha\Config as WahaConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Receives e-mail / phone / first name from the checkout script. The cart is ALWAYS read on the
 * server. Nonce + per-session/IP rate limit; empty cart removes the active row.
 */
final class CaptureEndpoint {

	public const NONCE_ACTION = 'motored_capture';

	/**
	 * Handles the request and ends it with a JSON response.
	 */
	public function handle(): void {
		if ( ! Config::enabled() ) {
			$this->respond( 404 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified right here.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->respond( 403 );
		}

		$session_key = CartHooks::session_key();
		if ( '' === $session_key ) {
			$this->respond( 400 );
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! CaptureLimiter::allow( $session_key, $ip, time() ) ) {
			$this->respond( 429 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above; values are validated by CaptureValidator.
		$input = array(
			'email' => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '',
			'phone' => isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '',
			'name'  => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
		);
		// phpcs:enable
		$contact = CaptureValidator::validate( $input, WahaConfig::country_code() );
		if ( null === $contact ) {
			$this->respond( 422 );
		}

		CaptureService::capture( $session_key, get_current_user_id(), $contact, 'checkout' );
		$this->respond( 200 );
	}

	/**
	 * Sends the JSON response and stops.
	 *
	 * @param int $status HTTP status.
	 */
	private function respond( int $status ): never {
		wp_send_json( array( 'ok' => 200 === $status ), $status );
	}
}
