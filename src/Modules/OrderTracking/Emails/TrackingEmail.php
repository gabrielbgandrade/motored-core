<?php
/**
 * Dedicated tracking e-mail (WC_Email subclass).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderTracking\Emails;

use Motored\Core\Modules\OrderTracking\Presenter;
use Motored\Core\Modules\OrderTracking\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Customer e-mail "motored_tracking": editable in WooCommerce > Settings > Emails, templates overridable from the
 * theme folder (woocommerce/emails/motored-tracking.php). Loaded only inside the woocommerce_email_classes callback.
 */
class TrackingEmail extends \WC_Email {

	public const CLASS_KEY = 'WC_Email_Motored_Tracking';

	/**
	 * Resolved carrier/code/url shown by the templates.
	 *
	 * @var array{carrier:string,code:string,url:string}
	 */
	public array $tracking = array(
		'carrier' => '',
		'code'    => '',
		'url'     => '',
	);

	/**
	 * Sets id, templates and defaults.
	 */
	public function __construct() {
		$this->id             = 'motored_tracking';
		$this->title          = __( 'Order tracking (Motored)', 'motored-core' );
		$this->description    = __( 'Sent to the customer when a tracking code is added or changed on the order.', 'motored-core' );
		$this->customer_email = true;

		$this->template_html  = 'emails/motored-tracking.php';
		$this->template_plain = 'emails/plain/motored-tracking.php';
		$this->template_base  = defined( 'MOTORED_CORE_PATH' ) ? MOTORED_CORE_PATH . 'templates/' : dirname( __DIR__, 4 ) . '/templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
		);

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Track your order #{order_number}', 'motored-core' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Your order is on its way', 'motored-core' );
	}

	/**
	 * Default additional content.
	 */
	public function get_default_additional_content() {
		return __( 'The carrier may take a few hours to show the first movement.', 'motored-core' );
	}

	/**
	 * Standard WooCommerce e-mail form fields.
	 */
	public function init_form_fields(): void {
		/* translators: %s: list of placeholders */
		$placeholder_text = sprintf( __( 'Available placeholders: %s', 'motored-core' ), '<code>{site_title}, {order_number}</code>' );

		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Enable/Disable', 'motored-core' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'motored-core' ),
				'default' => 'yes',
			),
			'subject'            => array(
				'title'       => __( 'Subject', 'motored-core' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text,
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'            => array(
				'title'       => __( 'Email heading', 'motored-core' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text,
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'motored-core' ),
				'type'        => 'textarea',
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => $this->get_default_additional_content(),
				'default'     => $this->get_default_additional_content(),
				'desc_tip'    => true,
				'description' => __( 'Text to appear below the main email content.', 'motored-core' ),
			),
			'email_type'         => array(
				'title'       => __( 'Email type', 'motored-core' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'motored-core' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Sends the e-mail for an order. False when disabled, without data or without a valid recipient.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function trigger_order( \WC_Order $order ): bool {
		$tracking = Tracking::from_order( $order );
		$email    = trim( (string) $order->get_billing_email() );
		if ( null === $tracking || '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$this->object                         = $order;
		$this->recipient                      = $email;
		$this->tracking                       = Presenter::resolve( $tracking );
		$this->placeholders['{order_number}'] = (string) $order->get_order_number();

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	/**
	 * HTML body.
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain body.
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * Template variables.
	 *
	 * @param bool $plain Plain text template.
	 * @return array<string,mixed>
	 */
	private function template_args( bool $plain ): array {
		return array(
			'order'              => $this->object,
			'tracking'           => $this->tracking,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}
}
