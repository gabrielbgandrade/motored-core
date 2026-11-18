<?php
/**
 * Admin settings section "Messages" for OrderNotifications.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderNotifications;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Messages" section under WooCommerce > Settings > Motored (admin only).
 */
final class OrderNotificationsAdmin {

	public const SECTION      = 'messages';
	public const NONCE_ACTION = 'motored_order_notifications_save';
	public const NONCE_FIELD  = 'motored_order_notifications_nonce';
	public const POST_KEY     = 'motored_order_notifications_events';

	/**
	 * Tracks whether the nonce field was already printed for the current section render.
	 *
	 * @var bool
	 */
	private bool $nonce_printed = false;

	/**
	 * Tracks whether the inline restore-default helper script was already printed.
	 *
	 * @var bool
	 */
	private bool $script_printed = false;

	/**
	 * Registers SectionRegistry section and WooCommerce settings hooks.
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'Messages', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			30
		);
		// Custom field type rendered by WooCommerce through woocommerce_admin_field_{type} (VERIFY.md).
		add_action( 'woocommerce_admin_field_motored_order_event', array( $this, 'render_event_field' ) );
		add_action( 'woocommerce_update_options_motored_' . self::SECTION, array( $this, 'save_events' ) );
	}

	/**
	 * Fields for the Messages section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		$fields = array(
			array(
				'title' => __( 'Order messages', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Automatic WhatsApp text notifications equivalent to standard WooCommerce order emails.', 'motored-core' ),
				'id'    => 'motored_order_notifications_title',
			),
			array(
				'title'    => __( 'Manual admin orders', 'motored-core' ),
				'desc'     => __( 'Also send notifications for orders created manually from wp-admin.', 'motored-core' ),
				'id'       => 'motored_order_notifications[include_manual_orders]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['include_manual_orders'],
				'autoload' => false,
			),
		);

		$labels = $this->event_definitions();
		foreach ( EventMap::all() as $event ) {
			$meta     = $labels[ $event ];
			$fields[] = array(
				// No id on purpose: WooCommerce skips id-less fields on save; save_events() persists {enabled, text}.
				'type'         => 'motored_order_event',
				'event'        => $event,
				'title'        => $meta['title'],
				'desc'         => $meta['desc'],
				'placeholders' => $meta['placeholders'],
			);
		}

		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'motored_order_notifications_title',
		);

		return $fields;
	}

	/**
	 * Renders one event row (toggle + textarea + available variables + restore default button).
	 *
	 * @param array<string,mixed> $field Field definition.
	 */
	public function render_event_field( array $field ): void {
		$event = isset( $field['event'] ) && is_string( $field['event'] ) ? $field['event'] : '';
		if ( ! EventMap::is_valid( $event ) ) {
			return;
		}

		$title        = isset( $field['title'] ) && is_string( $field['title'] ) ? $field['title'] : $event;
		$desc         = isset( $field['desc'] ) && is_string( $field['desc'] ) ? $field['desc'] : '';
		$placeholders = isset( $field['placeholders'] ) && is_array( $field['placeholders'] )
			? $field['placeholders']
			: Renderer::PLACEHOLDERS;

		$enabled      = Config::event_enabled( $event );
		$text         = Config::event_text( $event );
		$default_text = Config::default_text( $event );
		$textarea_id  = 'motored_on_text_' . $event;
		$toggle_name  = self::POST_KEY . '[' . $event . '][enabled]';
		$text_name    = self::POST_KEY . '[' . $event . '][text]';

		$code_tags = array();
		foreach ( $placeholders as $placeholder ) {
			$code_tags[] = '<code>' . esc_html( (string) $placeholder ) . '</code>';
		}

		echo '<tr valign="top">';
		printf(
			'<th scope="row" class="titledesc"><label for="%1$s">%2$s</label></th>',
			esc_attr( $textarea_id ),
			esc_html( $title )
		);
		echo '<td class="forminp">';

		if ( ! $this->nonce_printed ) {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );
			$this->nonce_printed = true;
		}

		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></p>',
			esc_attr( $toggle_name ),
			checked( $enabled, true, false ),
			esc_html__( 'Enable this message', 'motored-core' )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}

		printf(
			'<p><textarea id="%1$s" name="%2$s" rows="5" class="large-text" style="max-width:560px" data-default="%3$s">%4$s</textarea></p>',
			esc_attr( $textarea_id ),
			esc_attr( $text_name ),
			esc_attr( $default_text ),
			esc_textarea( $text )
		);

		echo '<p class="description">'
			. esc_html__( 'Available variables:', 'motored-core' )
			. ' '
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each placeholder is escaped above inside <code>.
			. implode( ', ', $code_tags )
			. '</p>';

		printf(
			'<p><button type="button" class="button motored-on-restore" data-target="%1$s">%2$s</button></p>',
			esc_attr( $textarea_id ),
			esc_html__( 'Restore default text', 'motored-core' )
		);

		if ( ! $this->script_printed ) {
			$this->script_printed = true;
			echo '<script>(function(){document.addEventListener("click",function(e){var b=e.target&&e.target.closest?e.target.closest(".motored-on-restore"):null;if(!b){return;}var t=document.getElementById(b.getAttribute("data-target")||"");if(t&&t.hasAttribute("data-default")){t.value=t.getAttribute("data-default")||"";}});})();</script>';
		}

		echo '</td></tr>';
	}

	/**
	 * Saves per-event {enabled, text} settings when the Messages section is submitted.
	 */
	public function save_events(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified on the next line.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$raw_events = isset( $_POST[ self::POST_KEY ] ) && is_array( $_POST[ self::POST_KEY ] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
			? wp_unslash( $_POST[ self::POST_KEY ] )
			: array();

		$updated = array();
		foreach ( EventMap::all() as $event ) {
			$entry             = isset( $raw_events[ $event ] ) && is_array( $raw_events[ $event ] ) ? $raw_events[ $event ] : array();
			$text              = isset( $entry['text'] ) && is_string( $entry['text'] )
				? sanitize_textarea_field( $entry['text'] )
				: Config::default_text( $event );
			$updated[ $event ] = array(
				'enabled' => ! empty( $entry['enabled'] ) ? 'yes' : 'no',
				'text'    => $text,
			);
		}

		Options::update( Config::OPTION_KEY, $updated );
	}

	/**
	 * Translated titles, descriptions, and available placeholders per event.
	 *
	 * @return array<string,array{title:string,desc:string,placeholders:list<string>}>
	 */
	private function event_definitions(): array {
		$common = array(
			'{order_number}',
			'{first_name}',
			'{order_total}',
			'{order_items}',
			'{order_url}',
			'{store_name}',
		);

		return array(
			EventMap::ON_HOLD       => array(
				'title'        => __( 'Order on hold', 'motored-core' ),
				'desc'         => __( 'Sent when an order enters "on-hold" status (e.g. bank transfer or offline Mercado Pago ticket).', 'motored-core' ),
				'placeholders' => $common,
			),
			EventMap::PROCESSING    => array(
				'title'        => __( 'Order processing', 'motored-core' ),
				'desc'         => __( 'Sent when payment is confirmed and the order enters "processing" status.', 'motored-core' ),
				'placeholders' => $common,
			),
			EventMap::COMPLETED     => array(
				'title'        => __( 'Order completed', 'motored-core' ),
				'desc'         => __( 'Sent when the order is marked as "completed".', 'motored-core' ),
				'placeholders' => $common,
			),
			EventMap::REFUNDED      => array(
				'title'        => __( 'Order refunded', 'motored-core' ),
				'desc'         => __( 'Sent when the order is marked as "refunded".', 'motored-core' ),
				'placeholders' => $common,
			),
			EventMap::CUSTOMER_NOTE => array(
				'title'        => __( 'Customer note', 'motored-core' ),
				'desc'         => __( 'Sent when a public customer note is added to the order.', 'motored-core' ),
				'placeholders' => array_merge( $common, array( '{customer_note}' ) ),
			),
		);
	}
}
