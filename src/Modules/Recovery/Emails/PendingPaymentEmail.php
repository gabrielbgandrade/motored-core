<?php
/**
 * WooCommerce e-mail: reminder for an order waiting for payment.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery\Emails;

use Motored\Core\Integration\WooCommerce\OrderHooks;

defined( 'ABSPATH' ) || exit;

/**
 * PendingPaymentEmail extends WC_Email (id "motored_pending_payment"): subject, heading and type
 * are editable in WooCommerce > Settings > Emails. Loaded only inside the woocommerce_email_classes callback.
 */
class PendingPaymentEmail extends \WC_Email {

	/**
	 * Recovery row.
	 *
	 * @var array<string,mixed>|null
	 */
	public $recovery = null;

	/**
	 * Payment URL (payment page, or order-received page for on-hold orders).
	 *
	 * @var string
	 */
	public string $pay_url = '';

	/**
	 * Opt-out URL.
	 *
	 * @var string
	 */
	public string $optout_url = '';

	/**
	 * Order lines (name and quantity).
	 *
	 * @var list<array{name:string,qty:int}>
	 */
	public array $items = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'motored_pending_payment';
		$this->title          = __( 'Pending payment reminder (Motored)', 'motored-core' );
		$this->description    = __( 'Reminder sent to customers whose order is still waiting for payment.', 'motored-core' );
		$this->customer_email = true;

		$this->template_html  = 'emails/motored-pending-payment.php';
		$this->template_plain = 'emails/plain/motored-pending-payment.php';
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
		return __( 'Your order #{order_number} is waiting for payment', 'motored-core' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Complete the payment of your order', 'motored-core' );
	}

	/**
	 * Default additional content.
	 */
	public function get_default_additional_content() {
		return __( 'If you already paid, ignore this e-mail. If you need help, reply to this message.', 'motored-core' );
	}

	/**
	 * Settings fields (native WooCommerce e-mail screen).
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
	 * Sends the reminder.
	 *
	 * @param array<string,mixed> $recovery_row Recovery row.
	 * @param \WC_Order           $order        Fresh order.
	 * @param string              $pay_url      Payment URL.
	 * @param string              $optout_url   Opt-out URL.
	 * @return bool True when sent.
	 */
	public function trigger_order( array $recovery_row, \WC_Order $order, string $pay_url, string $optout_url ): bool {
		$email = trim( (string) ( $recovery_row['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$this->object                         = $order;
		$this->recovery                       = $recovery_row;
		$this->recipient                      = $email;
		$this->pay_url                        = $pay_url;
		$this->optout_url                     = $optout_url;
		$this->items                          = OrderHooks::item_names( $order );
		$this->placeholders['{order_number}'] = (string) $order->get_order_number();

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	/**
	 * HTML content.
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain text content.
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * Variables passed to the templates.
	 *
	 * @param bool $plain Whether the plain template is rendered.
	 * @return array<string,mixed>
	 */
	private function template_args( bool $plain ): array {
		return array(
			'recovery'           => $this->recovery,
			'order'              => $this->object,
			'items'              => $this->items,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
			'pay_url'            => $this->pay_url,
			'optout_url'         => $this->optout_url,
		);
	}
}
