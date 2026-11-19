<?php
/**
 * Admin side: settings fields and the "send now" box on the order screen.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewRequests;

use Motored\Core\Admin\ReviewsSection;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Modules\OrderNotifications\Renderer;
use Motored\Core\Modules\Waha\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Only instantiated in admin requests.
 */
final class ReviewRequestsAdmin {

	public const ACTION       = 'motored_review_request_send';
	public const NONCE_ACTION = 'motored_review_request';
	public const NONCE_FIELD  = 'motored_review_request_nonce';
	public const ORDER_FIELD  = 'motored_review_request_order';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		ReviewsSection::ensure();
		add_filter( 'motored_reviews_section_fields', array( $this, 'add_fields' ), 20 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_send' ) );
	}

	/**
	 * Adds this module's fields to the shared Reviews section.
	 *
	 * @param mixed $fields Fields of other modules.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_fields( $fields ): array {
		return array_merge( is_array( $fields ) ? $fields : array(), $this->settings() );
	}

	/**
	 * Fields of this module.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		$placeholders = '{first_name} {order_number} {review_links} {store_review_url} {optout_url} {store_name}';
		return array(
			array(
				'title' => __( 'Review requests', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Sends ONE WhatsApp message some days after the order, asking for a review of the store and the products.', 'motored-core' ),
				'id'    => 'motored_review_requests_title',
			),
			array(
				'title'    => __( 'Enable review requests', 'motored-core' ),
				'desc'     => __( 'Send the review request automatically.', 'motored-core' ),
				'id'       => 'motored_review_requests[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Trigger order status', 'motored-core' ),
				'desc'     => __( 'Status slug without "wc-" (completed, or a custom dispatch status).', 'motored-core' ),
				'desc_tip' => true,
				'id'       => 'motored_review_requests[trigger_status]',
				'type'     => 'text',
				'default'  => Config::DEFAULTS['trigger_status'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Days after the status change', 'motored-core' ),
				'id'                => 'motored_review_requests[delay_days]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['delay_days'],
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '60',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'    => __( 'Store review URL', 'motored-core' ),
				'desc'     => __( 'Optional, for example your Google reviews link.', 'motored-core' ),
				'desc_tip' => true,
				'id'       => 'motored_review_requests[store_review_url]',
				'type'     => 'text',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'             => __( 'Maximum products listed', 'motored-core' ),
				'id'                => 'motored_review_requests[max_products]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['max_products'],
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '5',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'    => __( 'Message text', 'motored-core' ),
				'desc'     => sprintf(
					/* translators: %s: list of placeholders. */
					__( 'Placeholders: %s. Use {option a|option b} for a random variation.', 'motored-core' ),
					$placeholders
				),
				'id'       => 'motored_review_requests[text]',
				'type'     => 'textarea',
				'class'    => 'motored-input-wide motored-textarea',
				'default'  => Config::DEFAULTS['text'],
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_review_requests_title',
			),
		);
	}

	/**
	 * Adds the box to the order edit screen (HPOS or legacy).
	 */
	public function add_meta_box(): void {
		add_meta_box( 'motored-review-request', __( 'Review request', 'motored-core' ), array( $this, 'render_meta_box' ), OrderHooks::order_screen_id(), 'side' );
	}

	/**
	 * Prints the state and the "send now" button.
	 *
	 * @param mixed $post_or_order WP_Post (legacy) or WC_Order (HPOS).
	 */
	public function render_meta_box( $post_or_order ): void {
		$order_id = self::order_id( $post_or_order );
		if ( $order_id < 1 ) {
			return;
		}
		$order = OrderHooks::load_order( $order_id );
		$state = null === $order ? '' : (string) $order->get_meta( Scheduler::META, true );
		if ( 'sent' === $state ) {
			$label = __( 'Sent.', 'motored-core' );
		} elseif ( ctype_digit( $state ) ) {
			/* translators: %s: date and time. */
			$label = sprintf( __( 'Scheduled for %s.', 'motored-core' ), (string) wp_date( 'Y-m-d H:i', (int) $state ) );
		} else {
			$label = __( 'Not scheduled.', 'motored-core' );
		}
		echo '<div class="motored-box motored-box--flush"><p>' . esc_html( $label ) . '</p>';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );
		printf(
			'<input type="hidden" name="%1$s" value="%2$d" /><p><button type="submit" class="button" name="action" value="%3$s" formmethod="post" formaction="%4$s">%5$s</button></p>',
			esc_attr( self::ORDER_FIELD ),
			(int) $order_id,
			esc_attr( self::ACTION ),
			esc_url( admin_url( 'admin-post.php?action=' . self::ACTION ) ),
			esc_html__( 'Send now', 'motored-core' )
		);
		echo '</div>';
	}

	/**
	 * Handler of admin-post: queues a manual request (nonce + manage_woocommerce).
	 */
	public function handle_send(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'motored-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
		$order_id = isset( $_POST[ self::ORDER_FIELD ] ) ? absint( wp_unslash( $_POST[ self::ORDER_FIELD ] ) ) : 0;
		if ( $order_id > 0 && null !== OrderHooks::load_order( $order_id ) && Sender::enqueue( Scheduler::KIND, $order_id, RequestHandler::MANUAL, 0, Sender::NORMAL ) ) {
			OrderHooks::add_private_note( $order_id, __( 'Motored: review request queued manually.', 'motored-core' ) );
		}
		$back = wp_get_referer();
		wp_safe_redirect( false !== $back ? $back : admin_url() );
		exit;
	}

	/**
	 * Order id from the meta box argument.
	 *
	 * @param mixed $arg WP_Post or WC_Order.
	 */
	public static function order_id( $arg ): int {
		if ( $arg instanceof \WC_Order ) {
			return (int) $arg->get_id();
		}
		return is_object( $arg ) && isset( $arg->ID ) ? (int) $arg->ID : 0;
	}
}
