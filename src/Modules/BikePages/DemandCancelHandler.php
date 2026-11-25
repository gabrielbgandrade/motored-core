<?php
/**
 * Human-facing cancellation page for stock alerts (?motored_alert_cancel=ID&token=...).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

defined( 'ABSPATH' ) || exit;

/**
 * GET only shows a confirmation; the deletion needs a POST. Mail scanners and link previews fetch URLs with GET,
 * so a GET-deletes link would silently cancel alerts the customer never touched.
 */
final class DemandCancelHandler {

	public const QUERY_ARG = 'motored_alert_cancel';

	/** Registers the frontend listener (an isset() check only on unrelated requests: 0 queries). */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 0 );
	}

	/** Handles the cancellation page when the query arg is present. */
	public function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- Authorization is the per-subscription HMAC token.
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) {
			return;
		}
		$id    = absint( wp_unslash( $_GET[ self::QUERY_ARG ] ) );
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification
		$post   = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		$result = self::process( $id, $token, $post );
		nocache_headers();
		if ( 'confirm' === $result ) {
			$form = '<p>' . esc_html__( 'Do you want to cancel this availability alert?', 'motored-core' ) . '</p>'
				. '<form method="post" action="' . esc_url( DemandStore::cancel_url( $id, $token ) ) . '">'
				. '<p><button type="submit" class="button">' . esc_html__( 'Yes, cancel the alert', 'motored-core' ) . '</button></p></form>';
			wp_die( $form, esc_html__( 'Cancel alert', 'motored-core' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		}
		$messages = array(
			'canceled' => __( 'Alert canceled. We will not contact you about it.', 'motored-core' ),
			'invalid'  => __( 'This link is not valid.', 'motored-core' ),
			'error'    => __( 'We could not cancel the alert. Please try again.', 'motored-core' ),
		);
		wp_die( esc_html( $messages[ $result ] ), esc_html__( 'Cancel alert', 'motored-core' ), array( 'response' => 'invalid' === $result ? 403 : ( 'error' === $result ? 503 : 200 ) ) );
	}

	/**
	 * Pure decision (testable): confirm on GET, delete on a valid POST. A missing row reads as already canceled.
	 *
	 * @param int    $id    Subscription ID.
	 * @param string $token HMAC token.
	 * @param bool   $post  Whether the request is the confirmation POST.
	 * @return 'confirm'|'canceled'|'invalid'|'error'
	 */
	public static function process( int $id, string $token, bool $post ): string {
		$row = $id > 0 ? DemandStore::find( $id ) : null;
		if ( null === $row ) {
			return 'canceled';
		}
		if ( ! hash_equals( DemandStore::token( $row ), $token ) ) {
			return 'invalid';
		}
		if ( ! $post ) {
			return 'confirm';
		}
		return DemandStore::delete( $id ) ? 'canceled' : 'error';
	}
}
