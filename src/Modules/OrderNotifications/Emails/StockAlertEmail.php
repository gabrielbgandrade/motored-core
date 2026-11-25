<?php
/**
 * Native WooCommerce e-mail for the Avisame stock alert (last-resort channel after WhatsApp).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderNotifications\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Listed in WooCommerce > Settings > Emails, so the owner can enable it, edit subject/heading/text,
 * choose HTML or plain text and preview it, with the store's own e-mail styles.
 * Loaded only inside the woocommerce_email_classes callback (WC_Email must exist).
 */
class StockAlertEmail extends \WC_Email {

	public const ID = 'motored_stock_alert';

	/**
	 * Key in WC_Emails::get_emails().
	 */
	public const CLASS_KEY = 'WC_Email_Motored_Stock_Alert';

	/**
	 * Motorcycle label (brand, model, years).
	 *
	 * @var string
	 */
	public string $bike = '';

	/**
	 * Axle label (front, rear, kit, both).
	 *
	 * @var string
	 */
	public string $axle = '';

	/**
	 * Compatible tires URL.
	 *
	 * @var string
	 */
	public string $catalog_url = '';

	/**
	 * Signed cancellation page URL.
	 *
	 * @var string
	 */
	public string $cancel_url = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = self::ID;
		$this->title          = __( 'Stock alert (Motored)', 'motored-core' );
		$this->description    = __( 'Sent when compatible tires are available for a motorcycle a customer asked about (Avisame). WhatsApp is used first; this e-mail is the fallback or the only channel when the customer left only an e-mail.', 'motored-core' );
		$this->customer_email = true;

		$this->template_html  = 'emails/motored-stock-alert.php';
		$this->template_plain = 'emails/plain/motored-stock-alert.php';
		$this->template_base  = defined( 'MOTORED_CORE_PATH' ) ? MOTORED_CORE_PATH . 'templates/' : dirname( __DIR__, 4 ) . '/templates/';
		$this->placeholders   = array(
			'{bike}' => '',
		);

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Tires for your {bike} are available at {site_title}', 'motored-core' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Compatible tires are available', 'motored-core' );
	}

	/**
	 * Default additional content.
	 */
	public function get_default_additional_content() {
		return __( 'Stock can change quickly. If you have questions about which tire to choose, reply to this e-mail.', 'motored-core' );
	}

	/**
	 * Settings in WooCommerce > Settings > Emails > Stock alert.
	 */
	public function init_form_fields(): void {
		/* translators: %s: list of placeholders */
		$placeholder_text = sprintf( __( 'Available placeholders: %s', 'motored-core' ), '<code>{site_title}</code>, <code>{bike}</code>' );

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
	 * Sends the alert. Returns false when disabled or the address is invalid (caller decides the state).
	 *
	 * @param string $recipient   Customer e-mail.
	 * @param string $bike        Motorcycle label.
	 * @param string $axle        Axle label.
	 * @param string $catalog_url Compatible tires URL.
	 * @param string $cancel_url  Signed cancellation URL.
	 */
	public function trigger_alert( string $recipient, string $bike, string $axle, string $catalog_url, string $cancel_url ): bool {
		$recipient = trim( $recipient );
		if ( '' === $recipient || ! is_email( $recipient ) || ! $this->is_enabled() ) {
			return false;
		}
		$this->setup_locale();
		$this->recipient              = $recipient;
		$this->bike                   = $bike;
		$this->axle                   = $axle;
		$this->catalog_url            = $catalog_url;
		$this->cancel_url             = $cancel_url;
		$this->placeholders['{bike}'] = $bike;

		$sent = (bool) $this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);
		$this->restore_locale();
		return $sent;
	}

	/**
	 * Template variables shared by both formats.
	 *
	 * @param bool $plain Plain text.
	 * @return array<string,mixed>
	 */
	private function template_args( bool $plain ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
			'bike'               => '' !== $this->bike ? $this->bike : __( 'your motorcycle', 'motored-core' ),
			'axle'               => $this->axle,
			'catalog_url'        => '' !== $this->catalog_url ? $this->catalog_url : home_url( '/' ),
			'cancel_url'         => $this->cancel_url,
		);
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
}
