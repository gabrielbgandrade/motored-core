<?php
/**
 * OrderTracking admin: Motored > Tracking section and the order edit meta box (no JS).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderTracking;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Admin\Ui;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Settings (carrier list, switches, texts) and the meta box (carrier, code, manual link, preview).
 */
final class OrderTrackingAdmin {

	public const SECTION        = 'tracking';
	public const SETTINGS_NONCE = 'motored_tracking_settings';
	public const SETTINGS_FIELD = 'motored_tracking_settings_nonce';
	public const CARRIERS_KEY   = 'motored_tracking_carriers';
	public const BOX_NONCE      = 'motored_tracking_box';
	public const BOX_FIELD      = 'motored_tracking_box_nonce';
	public const BOX_KEY        = 'motored_tracking';
	public const BLANK_ROWS     = 3;

	/**
	 * Save-time dispatcher.
	 *
	 * @var TrackingNotifier
	 */
	private TrackingNotifier $notifier;

	/**
	 * Constructor.
	 *
	 * @param TrackingNotifier|null $notifier Dispatcher (tests).
	 */
	public function __construct( ?TrackingNotifier $notifier = null ) {
		$this->notifier = $notifier ?? new TrackingNotifier();
	}

	/**
	 * Hooks (admin only).
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'Tracking', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			32
		);
		add_action( 'woocommerce_admin_field_motored_tracking_carriers', array( $this, 'render_carriers_field' ) );
		add_action( 'woocommerce_update_options_motored_' . self::SECTION, array( $this, 'save_carriers' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		// Fires for legacy and HPOS order edit screens (VERIFY.md).
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_meta_box' ), 50, 2 );
	}

	/**
	 * Settings fields of the section.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function settings(): array {
		$placeholders = '{first_name} {order_number} {order_url} {store_name} {tracking_carrier} {tracking_code} {tracking_url} {tracking_block}';
		$help         = sprintf(
			/* translators: %s: list of placeholders. */
			__( 'Placeholders: %s. Use {option a|option b} for a random variation.', 'motored-core' ),
			$placeholders
		);
		return array(
			array(
				'title' => __( 'Order tracking', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Tracking code on each order, shown to the customer and sent by WhatsApp and e-mail. No carrier API is queried.', 'motored-core' ),
				'id'    => 'motored_order_tracking_title',
			),
			array(
				'title'    => __( 'WhatsApp on save', 'motored-core' ),
				'desc'     => __( 'Send a WhatsApp when a tracking code is added or changed.', 'motored-core' ),
				'id'       => 'motored_order_tracking[send_whatsapp]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['send_whatsapp'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'E-mail on save', 'motored-core' ),
				'desc'     => __( 'Send an e-mail when a tracking code is added or changed.', 'motored-core' ),
				'id'       => 'motored_order_tracking[send_email]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['send_email'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Show to the customer', 'motored-core' ),
				'desc'     => __( 'Show the tracking on the customer order page and in the processing and completed e-mails.', 'motored-core' ),
				'id'       => 'motored_order_tracking[show_customer]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['show_customer'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Delay before sending (minutes)', 'motored-core' ),
				'desc'              => __( 'The WhatsApp and e-mail wait this long, so a "completed" message sent at the same time can carry the code instead.', 'motored-core' ),
				'desc_tip'          => true,
				'id'                => 'motored_order_tracking[send_delay]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['send_delay'],
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '60',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'type'  => 'motored_tracking_carriers',
				'title' => __( 'Carriers', 'motored-core' ),
			),
			array(
				'title'    => __( 'WhatsApp text: tracking added', 'motored-core' ),
				'desc'     => $help,
				'id'       => 'motored_order_tracking[text_added]',
				'type'     => 'textarea',
				'class'    => 'motored-input-wide motored-textarea',
				'default'  => Config::DEFAULTS['text_added'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'WhatsApp text: tracking updated', 'motored-core' ),
				'desc'     => $help,
				'id'       => 'motored_order_tracking[text_updated]',
				'type'     => 'textarea',
				'class'    => 'motored-input-wide motored-textarea',
				'default'  => Config::DEFAULTS['text_updated'],
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_order_tracking_title',
			),
		);
	}

	/**
	 * Renders the editable carrier table (stored rows + blank rows).
	 *
	 * @param array<string,mixed> $field Field definition.
	 */
	public function render_carriers_field( array $field ): void {
		$title = isset( $field['title'] ) && is_string( $field['title'] ) ? $field['title'] : '';
		$rows  = Config::carriers();
		$blank = max( 0, min( self::BLANK_ROWS, Config::MAX_CARRIERS - count( $rows ) ) );
		for ( $i = 0; $i < $blank; $i++ ) {
			$rows[] = array(
				'id'     => '',
				'name'   => '',
				'url'    => '',
				'active' => true,
			);
		}

		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $title ) . '</th><td class="forminp">';
		wp_nonce_field( self::SETTINGS_NONCE, self::SETTINGS_FIELD, false );
		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Name', 'motored-core' )    => 'md',
				__( 'Id', 'motored-core' )      => 'md',
				__( 'URL template with {code}', 'motored-core' ) => 'grow',
				__( 'Preview', 'motored-core' ) => 'grow',
				__( 'Active', 'motored-core' )  => 'xs',
			)
		);
		echo '<tbody>';
		foreach ( array_values( $rows ) as $index => $row ) {
			$base    = self::CARRIERS_KEY . '[' . $index . ']';
			$preview = '' !== $row['url'] ? TrackingLink::preview( $row['url'] ) : '';
			printf(
				'<tr><td><input type="text" name="%1$s[name]" value="%2$s" maxlength="60" /></td><td class="motored-mono">%3$s</td><td><input type="text" name="%1$s[url]" value="%4$s" maxlength="300" placeholder="%5$s" /></td><td class="motored-mono">%6$s</td><td class="motored-cell-center"><input type="checkbox" name="%1$s[active]" value="yes" %7$s /></td></tr>',
				esc_attr( $base ),
				esc_attr( $row['name'] ),
				'' !== $row['id'] ? '<code>' . esc_html( $row['id'] ) . '</code><input type="hidden" name="' . esc_attr( $base ) . '[id]" value="' . esc_attr( $row['id'] ) . '" />' : '&mdash;', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped here.
				esc_attr( $row['url'] ),
				esc_attr( TrackingLink::EXAMPLE_TEMPLATE ),
				'' !== $preview ? esc_html( $preview ) : '&mdash;', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped here.
				checked( $row['active'], true, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core helper.
			);
		}
		echo '</tbody>';
		Ui::table_close();
		echo '<p class="description">'
			. esc_html(
				sprintf(
					/* translators: %s: example URL template. */
					__( 'Add your carriers with their tracking URL: it must be https and contain {code}. Example: %s. The id is generated from the name and cannot be changed. "Other / manual link" is always available on the order. Leave the name empty to remove a row.', 'motored-core' ),
					TrackingLink::EXAMPLE_TEMPLATE
				)
			)
			. '</p></td></tr>';
	}

	/**
	 * Saves the carrier list (after WooCommerce saved the other fields).
	 */
	public function save_carriers(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified on the next line.
		if ( ! isset( $_POST[ self::SETTINGS_FIELD ] ) ) {
			return;
		}
		check_admin_referer( self::SETTINGS_NONCE, self::SETTINGS_FIELD );

		$raw = isset( $_POST[ self::CARRIERS_KEY ] ) && is_array( $_POST[ self::CARRIERS_KEY ] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Config::prepare_carriers().
			? wp_unslash( $_POST[ self::CARRIERS_KEY ] )
			: array();
		$result = Config::prepare_carriers( $raw, Config::carriers() );
		Options::update( Config::OPTION_KEY, array( 'carriers' => $result['rows'] ) );
		if ( class_exists( 'WC_Admin_Settings' ) ) {
			foreach ( $result['rejected'] as $name ) {
				\WC_Admin_Settings::add_error(
					sprintf(
						/* translators: 1: carrier name, 2: example URL template. */
						__( 'Carrier "%1$s" was not saved: the URL template must start with https:// and contain {code}. Example: %2$s', 'motored-core' ),
						$name,
						TrackingLink::EXAMPLE_TEMPLATE
					)
				);
			}
		}
	}

	/**
	 * Registers the meta box on the order edit screen (HPOS or legacy screen id).
	 */
	public function add_meta_box(): void {
		add_meta_box( 'motored-tracking', __( 'Tracking', 'motored-core' ), array( $this, 'render_meta_box' ), OrderHooks::order_screen_id(), 'side' );
	}

	/**
	 * Renders the meta box.
	 *
	 * @param mixed $post_or_order WP_Post (legacy) or WC_Order (HPOS).
	 */
	public function render_meta_box( $post_or_order ): void {
		$order_id = self::order_id( $post_or_order );
		$order    = $order_id > 0 ? OrderHooks::load_order( $order_id ) : null;
		if ( null === $order ) {
			return;
		}
		$current = Tracking::from_array( $order->get_meta( Tracking::META, true ) );

		$options = array( Config::OTHER => __( 'Other / manual link', 'motored-core' ) );
		foreach ( Config::carriers() as $carrier ) {
			if ( $carrier['active'] || $carrier['id'] === $current->carrier_id ) {
				$options[ $carrier['id'] ] = $carrier['name'];
			}
		}
		$selected = '' === $current->carrier_id ? Config::OTHER : $current->carrier_id;
		if ( ! isset( $options[ $selected ] ) ) {
			$options[ $selected ] = $selected;
		}

		wp_nonce_field( self::BOX_NONCE, self::BOX_FIELD, false );
		$name   = self::BOX_KEY;
		$select = '<select id="motored-tracking-carrier" name="' . esc_attr( $name ) . '[carrier_id]">';
		foreach ( $options as $id => $label ) {
			$select .= sprintf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( (string) $id ), selected( $selected, (string) $id, false ), esc_html( $label ) );
		}
		$select .= '</select>';

		Ui::box_open( true );
		Ui::field( __( 'Carrier', 'motored-core' ), 'motored-tracking-carrier', $select );
		Ui::field(
			__( 'Tracking code', 'motored-core' ),
			'motored-tracking-code',
			sprintf(
				'<input type="text" id="motored-tracking-code" name="%1$s[code]" value="%2$s" maxlength="%3$d" />',
				esc_attr( $name ),
				esc_attr( $current->code ),
				(int) TrackingLink::CODE_MAX
			)
		);
		Ui::field(
			__( 'Manual link (optional)', 'motored-core' ),
			'motored-tracking-url',
			sprintf(
				'<input type="url" id="motored-tracking-url" name="%1$s[url_override]" value="%2$s" maxlength="%3$d" placeholder="https://" />',
				esc_attr( $name ),
				esc_attr( $current->url_override ),
				(int) TrackingLink::URL_MAX
			),
			__( 'https only. It replaces the carrier link.', 'motored-core' )
		);

		$data = Presenter::resolve( $current );
		if ( '' !== $data['url'] ) {
			printf(
				'<p>%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p>',
				esc_html__( 'Preview of the saved link:', 'motored-core' ),
				esc_url( $data['url'] ),
				esc_html__( 'Open', 'motored-core' )
			);
		}
		Ui::box_close();
	}

	/**
	 * Saves the meta box: nonce + edit_shop_orders, CRUD meta, then the notification events.
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $order    Order passed by WooCommerce (or null).
	 */
	public function save_meta_box( $order_id, $order = null ): void {
		if ( ! isset( $_POST[ self::BOX_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::BOX_FIELD ] ) );
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
		$allowed = current_user_can( 'edit_shop_orders' );
		if ( ! $allowed || ! wp_verify_nonce( $nonce, self::BOX_NONCE ) ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : OrderHooks::load_order( (int) $order_id );
		if ( null === $order ) {
			return;
		}

		$raw = isset( $_POST[ self::BOX_KEY ] ) && is_array( $_POST[ self::BOX_KEY ] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Tracking::from_array().
			? wp_unslash( $_POST[ self::BOX_KEY ] )
			: array();
		$old = Tracking::from_array( $order->get_meta( Tracking::META, true ) );
		$new = self::merge( $old, $raw );

		if ( $new->is_empty() ) {
			$order->delete_meta_data( Tracking::META );
			$order->save_meta_data();
			return;
		}
		if ( $new->to_array() === $old->to_array() ) {
			return;
		}
		$order->update_meta_data( Tracking::META, $new->to_array() );
		$order->save_meta_data();
		$this->notifier->on_saved( $order, $old->code );
	}

	/**
	 * Builds the new entry from the posted values (validated carrier id, fresh timestamp when something changed).
	 *
	 * @param Tracking            $old Stored entry.
	 * @param array<string,mixed> $raw Posted values.
	 */
	public static function merge( Tracking $old, array $raw ): Tracking {
		$posted  = Tracking::from_array( $raw );
		$id      = '' === $posted->carrier_id ? Config::OTHER : $posted->carrier_id;
		$allowed = Config::OTHER === $id || null !== Config::carrier( $id ) || $id === $old->carrier_id;
		$new     = new Tracking( $allowed ? $id : Config::OTHER, $posted->code, $posted->url_override, $old->updated_gmt );
		if ( $new->to_array() !== $old->to_array() ) {
			$new = new Tracking( $new->carrier_id, $new->code, $new->url_override, time() );
		}
		return $new;
	}

	/**
	 * Order id from a WP_Post or WC_Order.
	 *
	 * @param mixed $arg Meta box argument.
	 */
	public static function order_id( $arg ): int {
		if ( $arg instanceof \WC_Order ) {
			return (int) $arg->get_id();
		}
		return is_object( $arg ) && isset( $arg->ID ) ? (int) $arg->ID : 0;
	}
}
