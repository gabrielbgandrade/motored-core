<?php
/**
 * WooCommerce Abandoned Cart recovery email.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery\Emails;

use Motored\Core\Integration\WooCommerce\ProductLookup;
use Motored\Core\Modules\Recovery\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * AbandonedCartEmail extends WC_Email.
 * Loaded only inside the woocommerce_email_classes callback.
 */
class AbandonedCartEmail extends \WC_Email {

	/**
	 * Recovery row data.
	 *
	 * @var array<string,mixed>|null
	 */
	public $recovery = null;

	/**
	 * Recovery URL.
	 *
	 * @var string
	 */
	public string $recovery_url = '';

	/**
	 * Opt-out URL.
	 *
	 * @var string
	 */
	public string $optout_url = '';

	/**
	 * Cart item lines with product lookup.
	 *
	 * @var list<array{name:string,qty:int,price:float,line_total:float,stock:string}>
	 */
	public array $items = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'motored_abandoned_cart';
		$this->title          = __( 'Abandoned Cart (Motored)', 'motored-core' );
		$this->description    = __( 'Abandoned cart recovery email sent to shoppers.', 'motored-core' );
		$this->customer_email = true;

		$this->template_html  = 'emails/motored-abandoned-cart.php';
		$this->template_plain = 'emails/plain/motored-abandoned-cart.php';
		$this->template_base  = defined( 'MOTORED_CORE_PATH' ) ? MOTORED_CORE_PATH . 'templates/' : dirname( __DIR__, 4 ) . '/templates/';

		parent::__construct();
	}

	/**
	 * Default email subject.
	 */
	public function get_default_subject() {
		return __( '¿Olvidaste algo en tu carrito de {site_title}?', 'motored-core' );
	}

	/**
	 * Default email heading.
	 */
	public function get_default_heading() {
		return __( 'Tu carrito te está esperando', 'motored-core' );
	}

	/**
	 * Default additional content.
	 */
	public function get_default_additional_content() {
		return __( 'Si tenés alguna consulta sobre tus productos o necesitás ayuda para finalizar tu compra, respondenos este correo.', 'motored-core' );
	}

	/**
	 * Settings fields in WooCommerce > Settings > Emails > Abandoned Cart.
	 */
	public function init_form_fields(): void {
		/* translators: %s: list of placeholders */
		$placeholder_text = sprintf( __( 'Available placeholders: %s', 'motored-core' ), '<code>{site_title}</code>' );

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
	 * Triggers the email.
	 *
	 * @param array<string,mixed> $recovery_row Recovery row from Repository.
	 * @param string              $recovery_url Recovery URL.
	 * @param string              $optout_url   Opt-out URL.
	 * @return bool True if sent.
	 */
	public function trigger_recovery( array $recovery_row, string $recovery_url, string $optout_url ): bool {
		$email = trim( (string) ( $recovery_row['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$this->recovery     = $recovery_row;
		$this->recipient    = $email;
		$this->recovery_url = $recovery_url;
		$this->optout_url   = $optout_url;
		$this->items        = ProductLookup::resolve( Snapshot::decode( (string) ( $recovery_row['items'] ?? '' ) ) );

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return (bool) $this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$this->get_content(),
			$this->get_headers(),
			$this->get_attachments()
		);
	}

	/**
	 * HTML content of the email.
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'recovery'           => $this->recovery,
				'items'              => $this->items,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
				'recovery_url'       => $this->recovery_url,
				'optout_url'         => $this->optout_url,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Plain text content of the email.
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'recovery'           => $this->recovery,
				'items'              => $this->items,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
				'recovery_url'       => $this->recovery_url,
				'optout_url'         => $this->optout_url,
			),
			'',
			$this->template_base
		);
	}
}
