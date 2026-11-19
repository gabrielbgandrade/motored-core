<?php
/**
 * Admin side of Recovery: settings section, "Abandoned carts" screen, detail and actions.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Admin\Ui;
use Motored\Core\Integration\Waha\WahaClient;
use Motored\Core\Integration\WooCommerce\OrderHooks;
use Motored\Core\Integration\WooCommerce\ProductLookup;
use Motored\Core\Modules\OrderNotifications\Renderer;
use Motored\Core\Modules\Waha\ChatResolver;
use Motored\Core\Modules\Waha\Config as WahaConfig;
use Motored\Core\Modules\Waha\Phone;
use Motored\Core\Support\Cache;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Only instantiated in admin requests. The list table class is loaded only on its own screen.
 * Actions (delete, manual send, note) check manage_woocommerce and nonces.
 */
final class RecoveryAdmin {

	public const SECTION     = 'recovery';
	public const PAGE        = 'motored-recovery';
	public const NOTE_ACTION = 'motored_recovery_note';
	public const SUMMARY_TTL = 300;
	public const STEPS_NONCE = 'motored_recovery_steps_save';
	public const STEPS_FIELD = 'motored_recovery_steps_nonce';
	public const POST_STEPS  = 'motored_recovery_steps';
	public const POST_ORDER  = 'motored_recovery_order_steps';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'Recovery', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			40
		);
		add_action( 'woocommerce_admin_field_motored_recovery_steps', array( $this, 'render_steps_field' ) );
		add_action( 'woocommerce_admin_field_motored_recovery_order_steps', array( $this, 'render_order_steps_field' ) );
		add_action( 'woocommerce_update_options_motored_' . self::SECTION, array( $this, 'save_steps' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::NOTE_ACTION, array( $this, 'handle_note' ) );
	}

	/**
	 * Settings fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		return array(
			array(
				'title' => __( 'Cart recovery', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Captures carts of visitors and customers who leave an e-mail or phone at checkout, and coordinates automated recovery messages.', 'motored-core' ),
				'id'    => 'motored_recovery_title',
			),
			array(
				'title'    => __( 'Capture carts', 'motored-core' ),
				'desc'     => __( 'Save the cart when the shopper types an e-mail or phone at checkout.', 'motored-core' ),
				'id'       => 'motored_recovery[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Minutes to consider a cart abandoned', 'motored-core' ),
				'desc'              => __( 'Active carts without activity for this long are shown as abandoned.', 'motored-core' ),
				'desc_tip'          => true,
				'id'                => 'motored_recovery[abandon_minutes]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['abandon_minutes'],
				'custom_attributes' => array(
					'min'  => '5',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'             => __( 'Retention (days)', 'motored-core' ),
				'desc'              => __( 'Carts are deleted after this many days (converted and recovered ones after 7 days).', 'motored-core' ),
				'desc_tip'          => true,
				'id'                => 'motored_recovery[retention_days]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['retention_days'],
				'custom_attributes' => array(
					'min'  => '7',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'type' => 'motored_recovery_steps',
			),
			array(
				'title'    => __( 'Recover unpaid orders', 'motored-core' ),
				'desc'     => __( 'Follow up orders left pending, failed or on hold. Step 1 of pending/failed orders should go out before WooCommerce cancels unpaid orders (WooCommerce > Settings > Products > Inventory > Hold stock, 60 minutes by default).', 'motored-core' ),
				'id'       => 'motored_recovery[order_enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['order_enabled'],
				'autoload' => false,
			),
			array(
				'type' => 'motored_recovery_order_steps',
			),
			array(
				'title'    => __( 'Cancelled orders', 'motored-core' ),
				'desc'     => __( 'What to do when the order was cancelled automatically before the next step.', 'motored-core' ),
				'desc_tip' => true,
				'id'       => 'motored_recovery[order_cancelled_fallback]',
				'type'     => 'select',
				'default'  => Config::DEFAULTS['order_cancelled_fallback'],
				'options'  => array(
					'restore' => __( 'Restore the items in a new cart through the recovery link', 'motored-core' ),
					'expire'  => __( 'Stop the sequence', 'motored-core' ),
				),
				'autoload' => false,
			),
			array(
				'title'    => __( 'Cancelled order message', 'motored-core' ),
				'desc'     => __( 'WhatsApp text for the "restore" option. Placeholders: {first_name}, {order_number}, {order_items}, {recovery_url}, {optout_url}, {store_name}', 'motored-core' ),
				'desc_tip' => true,
				'id'       => 'motored_recovery[order_cancelled_text]',
				'type'     => 'textarea',
				'class'    => 'motored-input-wide motored-textarea',
				'default'  => Config::DEFAULTS['order_cancelled_text'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Sending window start', 'motored-core' ),
				'desc'              => __( 'Time of day when recovery messages start sending (HH:MM in site timezone).', 'motored-core' ),
				'desc_tip'          => true,
				'id'                => 'motored_recovery[window_start]',
				'type'              => 'text',
				'default'           => Config::DEFAULTS['window_start'],
				'custom_attributes' => array( 'placeholder' => '09:00' ),
				'autoload'          => false,
			),
			array(
				'title'             => __( 'Sending window end', 'motored-core' ),
				'desc'              => __( 'Time of day after which messages are paused until the next window (HH:MM).', 'motored-core' ),
				'desc_tip'          => true,
				'id'                => 'motored_recovery[window_end]',
				'type'              => 'text',
				'default'           => Config::DEFAULTS['window_end'],
				'custom_attributes' => array( 'placeholder' => '21:00' ),
				'autoload'          => false,
			),
			array(
				'title'    => __( 'Recovery coupon code', 'motored-core' ),
				'desc'     => __( 'Optional WooCommerce coupon code automatically applied when a recovery link is clicked.', 'motored-core' ),
				'desc_tip' => true,
				'id'       => 'motored_recovery[coupon_code]',
				'type'     => 'text',
				'default'  => Config::DEFAULTS['coupon_code'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Token validity (days)', 'motored-core' ),
				'desc'              => __( 'Number of days recovery links remain valid.', 'motored-core' ),
				'desc_tip'          => true,
				'id'                => 'motored_recovery[token_validity_days]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['token_validity_days'],
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'    => __( 'Fallback to email', 'motored-core' ),
				'desc'     => __( 'Try sending an email when WhatsApp number does not exist or delivery fails permanently.', 'motored-core' ),
				'id'       => 'motored_recovery[fallback_email]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['fallback_email'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Maximum messages per 24 hours', 'motored-core' ),
				'desc'              => __( 'Soft limit: maximum recovery messages sent to the same phone number within 24 hours.', 'motored-core' ),
				'desc_tip'          => true,
				'id'                => 'motored_recovery[limit_24h]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['limit_24h'],
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'             => __( 'Maximum messages per week', 'motored-core' ),
				'desc'              => __( 'Soft limit: maximum recovery messages sent to the same phone number in 7 days.', 'motored-core' ),
				'desc_tip'          => true,
				'id'                => 'motored_recovery[limit_week]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['limit_week'],
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_recovery_title',
			),
		);
	}

	/**
	 * Custom field renderer for the cart sequence steps table.
	 */
	public function render_steps_field(): void {
		wp_nonce_field( self::STEPS_NONCE, self::STEPS_FIELD );
		$rows = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$rows[] = array(
				'label' => __( 'Step', 'motored-core' ) . ' ' . $i,
				'name'  => self::POST_STEPS . '[' . $i . ']',
				'cfg'   => Config::step( $i ),
			);
		}
		$this->render_steps_table(
			__( 'Recovery sequence', 'motored-core' ),
			__( 'Configure the 3-step sequence. Inactive / off steps are skipped.', 'motored-core' ),
			$rows,
			__( 'Available placeholders: {first_name}, {cart_items}, {cart_total}, {recovery_url}, {optout_url}, {store_name}', 'motored-core' )
		);
	}

	/**
	 * Custom field renderer for the order sequences (2 steps per case).
	 */
	public function render_order_steps_field(): void {
		$rows = array();
		foreach ( OrderCase::OPEN as $variant ) {
			for ( $i = 1; $i <= OrderCase::STEPS; $i++ ) {
				$rows[] = array(
					'label' => OrderCase::label( $variant ) . ' · ' . __( 'Step', 'motored-core' ) . ' ' . $i,
					'name'  => self::POST_ORDER . '[' . $variant . '][' . $i . ']',
					'cfg'   => Config::order_step( $variant, $i ),
				);
			}
		}
		$this->render_steps_table(
			__( 'Unpaid order sequences', 'motored-core' ),
			__( 'Delays count from the moment the order entered that status. The e-mail channel uses the "Pending payment reminder" e-mail in WooCommerce > Settings > Emails.', 'motored-core' ),
			$rows,
			__( 'Available placeholders: {first_name}, {order_number}, {order_total}, {order_items}, {pay_url}, {optout_url}, {store_name}', 'motored-core' )
		);
	}

	/**
	 * Prints a table of editable steps.
	 *
	 * @param string                                                                                $title       Row title.
	 * @param string                                                                                $description Help text.
	 * @param list<array{label:string,name:string,cfg:array{channel:string,delay:int,text:string}}> $rows        Rows.
	 * @param string                                                                                $footer      Placeholders help.
	 */
	private function render_steps_table( string $title, string $description, array $rows, string $footer ): void {
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $title ) . '</th><td class="forminp">';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Step', 'motored-core' )            => 'xs',
				__( 'Channel', 'motored-core' )         => 'md',
				__( 'Delay (minutes)', 'motored-core' ) => 'md',
				__( 'Message text', 'motored-core' )    => 'grow',
			)
		);
		echo '<tbody>';

		foreach ( $rows as $row ) {
			$cfg = $row['cfg'];
			echo '<tr>';
			echo '<td><strong>' . esc_html( $row['label'] ) . '</strong></td>';
			echo '<td><select name="' . esc_attr( $row['name'] . '[channel]' ) . '">';
			printf( '<option value="whatsapp"%s>%s</option>', selected( $cfg['channel'], 'whatsapp', false ), esc_html__( 'WhatsApp', 'motored-core' ) );
			printf( '<option value="email"%s>%s</option>', selected( $cfg['channel'], 'email', false ), esc_html__( 'E-mail', 'motored-core' ) );
			printf( '<option value="off"%s>%s</option>', selected( $cfg['channel'], 'off', false ), esc_html__( 'Off', 'motored-core' ) );
			echo '</select></td>';
			echo '<td><input type="number" name="' . esc_attr( $row['name'] . '[delay]' ) . '" value="' . absint( $cfg['delay'] ) . '" min="1" step="1" /></td>';
			echo '<td><textarea name="' . esc_attr( $row['name'] . '[text]' ) . '" rows="4">' . esc_textarea( $cfg['text'] ) . '</textarea></td>';
			echo '</tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		echo '<p class="description">' . esc_html( $footer ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Saves the cart and order sequence settings.
	 */
	public function save_steps(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified on next line.
		if ( ! isset( $_POST[ self::STEPS_FIELD ] ) ) {
			return;
		}
		check_admin_referer( self::STEPS_NONCE, self::STEPS_FIELD );

		$raw_cart  = isset( $_POST[ self::POST_STEPS ] ) && is_array( $_POST[ self::POST_STEPS ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? wp_unslash( $_POST[ self::POST_STEPS ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();
		$raw_order = isset( $_POST[ self::POST_ORDER ] ) && is_array( $_POST[ self::POST_ORDER ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? wp_unslash( $_POST[ self::POST_ORDER ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$steps = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$steps[ 'step_' . $i ] = self::sanitize_step( $raw_cart[ $i ] ?? array(), Config::DEFAULTS[ 'step_' . $i ] );
		}
		foreach ( OrderCase::OPEN as $variant ) {
			for ( $i = 1; $i <= OrderCase::STEPS; $i++ ) {
				$key           = 'order_' . $variant . '_step_' . $i;
				$steps[ $key ] = self::sanitize_step( $raw_order[ $variant ][ $i ] ?? array(), Config::DEFAULTS[ $key ] );
			}
		}

		Options::update( Config::OPTION_KEY, $steps );
	}

	/**
	 * Sanitizes one submitted step (invalid values fall back to the defaults).
	 *
	 * @param mixed                                          $entry    Submitted entry.
	 * @param array{channel:string,delay:string,text:string} $defaults Defaults.
	 * @return array{channel:string,delay:string,text:string}
	 */
	public static function sanitize_step( $entry, array $defaults ): array {
		$entry   = is_array( $entry ) ? $entry : array();
		$channel = isset( $entry['channel'] ) && in_array( $entry['channel'], array( 'whatsapp', 'email', 'off' ), true )
			? (string) $entry['channel']
			: $defaults['channel'];
		$delay   = isset( $entry['delay'] ) && is_numeric( $entry['delay'] ) && (int) $entry['delay'] > 0
			? (string) (int) $entry['delay']
			: $defaults['delay'];
		$text    = isset( $entry['text'] ) && is_string( $entry['text'] )
			? sanitize_textarea_field( $entry['text'] )
			: $defaults['text'];

		return array(
			'channel' => $channel,
			'delay'   => $delay,
			'text'    => $text,
		);
	}

	/**
	 * Adds WooCommerce > Abandoned carts.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Abandoned carts', 'motored-core' ),
			__( 'Abandoned carts', 'motored-core' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render' )
		);
		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( $this, 'process_request' ) );
		}
	}

	/**
	 * Handles delete and manual send actions before any output (load-{page}).
	 */
	public function process_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified per action below.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( '' === $action || '-1' === $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}
		$single = isset( $_GET['motored_action'] ) ? sanitize_key( wp_unslash( $_GET['motored_action'] ) ) : '';
		// phpcs:enable

		if ( 'delete' === $action && isset( $_REQUEST['recovery'] ) && is_array( $_REQUEST['recovery'] ) ) {
			$this->require_cap();
			check_admin_referer( 'bulk-recoveries' );
			$ids     = array_map( 'absint', wp_unslash( $_REQUEST['recovery'] ) );
			$deleted = Repository::delete_ids( $ids );
			Cache::delete( 'summary', CaptureLimiter::BUCKET );
			wp_safe_redirect(
				add_query_arg(
					array(
						'motored_notice' => 'deleted',
						'motored_count'  => $deleted,
					),
					$this->url()
				)
			);
			exit;
		}

		if ( '' === $single || ! isset( $_GET['id'] ) ) {
			return;
		}

		$id = absint( wp_unslash( $_GET['id'] ) );
		$this->require_cap();

		switch ( $single ) {
			case 'delete':
				check_admin_referer( 'motored_recovery_delete_' . $id );
				$deleted = Repository::delete_ids( array( $id ) );
				Cache::delete( 'summary', CaptureLimiter::BUCKET );
				wp_safe_redirect(
					add_query_arg(
						array(
							'motored_notice' => 'deleted',
							'motored_count'  => $deleted,
						),
						$this->url()
					)
				);
				exit;

			case 'send_wa':
				check_admin_referer( 'motored_recovery_send_wa_' . $id );
				$this->handle_manual_send_wa( $id );
				break;

			case 'send_email':
				check_admin_referer( 'motored_recovery_send_email_' . $id );
				$this->handle_manual_send_email( $id );
				break;

			case 'open_wa':
				check_admin_referer( 'motored_recovery_open_wa_' . $id );
				$this->handle_manual_open_wa( $id );
				break;

			case 'copy_link':
				check_admin_referer( 'motored_recovery_copy_link_' . $id );
				$this->handle_manual_copy_link( $id );
				break;
		}
	}

	/**
	 * Manual WhatsApp send action.
	 *
	 * @param int $id Row ID.
	 */
	private function handle_manual_send_wa( int $id ): void {
		$row = Repository::get( $id );
		if ( null === $row ) {
			wp_safe_redirect( add_query_arg( 'motored_notice', 'not_found', $this->url() ) );
			exit;
		}

		$phone = (string) $row['phone'];
		if ( '' === $phone ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'view'           => $id,
						'motored_notice' => 'missing_phone',
					),
					$this->url()
				)
			);
			exit;
		}

		if ( Suppression::is_suppressed( $phone, 'whatsapp' ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'view'           => $id,
						'motored_notice' => 'opted_out',
					),
					$this->url()
				)
			);
			exit;
		}

		$norm = Phone::normalize( $phone, WahaConfig::country_code() );
		if ( null === $norm ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'view'           => $id,
						'motored_notice' => 'invalid_phone',
					),
					$this->url()
				)
			);
			exit;
		}

		$token        = RecoveryToken::get_or_generate( $id, Config::token_validity_days() );
		$recovery_url = RecoveryToken::recovery_url( $id, $token );
		$optout_url   = RecoveryToken::optout_url( $id );

		$text = $this->manual_text( $row, $recovery_url, $optout_url );
		if ( null === $text ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'view'           => $id,
						'motored_notice' => 'order_unavailable',
					),
					$this->url()
				)
			);
			exit;
		}

		$client   = new WahaClient();
		$resolver = new ChatResolver( $client, WahaConfig::country_code() );
		$resolved = $resolver->resolve( $norm );
		$result   = $resolved->ok
			? $client->sendText( (string) ( $resolved->data['chat_id'] ?? '' ), $text )
			: $resolved;

		OrderNote::record( $id, 'whatsapp', 'manual', $result->ok );
		if ( $result->ok ) {
			History::log( $id, 'sent', 'whatsapp', 'manual', get_current_user_id(), 'ok', 'manual_send' );
			Repository::update(
				$id,
				array(
					'wa_sent_count'  => (int) $row['wa_sent_count'] + 1,
					'last_event_gmt' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'view'           => $id,
						'motored_notice' => 'wa_sent',
					),
					$this->url()
				)
			);
			exit;
		}

		History::log( $id, 'failed', 'whatsapp', 'manual', get_current_user_id(), 'failed', $result->code );
		wp_safe_redirect(
			add_query_arg(
				array(
					'view'           => $id,
					'motored_notice' => 'wa_failed',
					'motored_code'   => $result->code,
				),
				$this->url()
			)
		);
		exit;
	}

	/**
	 * Manual Email send action.
	 *
	 * @param int $id Row ID.
	 */
	private function handle_manual_send_email( int $id ): void {
		$row = Repository::get( $id );
		if ( null === $row ) {
			wp_safe_redirect( add_query_arg( 'motored_notice', 'not_found', $this->url() ) );
			exit;
		}

		$email = (string) $row['email'];
		if ( '' === $email ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'view'           => $id,
						'motored_notice' => 'missing_email',
					),
					$this->url()
				)
			);
			exit;
		}

		if ( Suppression::is_suppressed( $email, 'email' ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'view'           => $id,
						'motored_notice' => 'opted_out',
					),
					$this->url()
				)
			);
			exit;
		}

		$token        = RecoveryToken::get_or_generate( $id, Config::token_validity_days() );
		$recovery_url = RecoveryToken::recovery_url( $id, $token );
		$optout_url   = RecoveryToken::optout_url( $id );

		$sent = $this->manual_email( $row, $recovery_url, $optout_url );
		OrderNote::record( $id, 'email', 'manual', $sent );

		if ( $sent ) {
			History::log( $id, 'sent', 'email', 'manual', get_current_user_id(), 'ok', 'manual_send' );
			Repository::update(
				$id,
				array(
					'email_sent_count' => (int) $row['email_sent_count'] + 1,
					'last_event_gmt'   => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'view'           => $id,
						'motored_notice' => 'email_sent',
					),
					$this->url()
				)
			);
			exit;
		}

		History::log( $id, 'failed', 'email', 'manual', get_current_user_id(), 'failed', 'mail_failed' );
		wp_safe_redirect(
			add_query_arg(
				array(
					'view'           => $id,
					'motored_notice' => 'email_failed',
				),
				$this->url()
			)
		);
		exit;
	}

	/**
	 * Manual copy-link action.
	 *
	 * @param int $id Row ID.
	 */
	private function handle_manual_copy_link( int $id ): void {
		$row = Repository::get( $id );
		if ( null === $row ) {
			wp_safe_redirect( add_query_arg( 'motored_notice', 'not_found', $this->url() ) );
			exit;
		}

		RecoveryToken::get_or_generate( $id, Config::token_validity_days() );
		History::log( $id, 'note', 'none', 'manual', get_current_user_id(), 'ok', 'recovery_link_copied' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'view'           => $id,
					'motored_notice' => 'link_ready',
				),
				$this->url()
			)
		);
		exit;
	}

	/**
	 * Manual open-in-WhatsApp-Web action.
	 *
	 * @param int $id Row ID.
	 */
	private function handle_manual_open_wa( int $id ): void {
		$row = Repository::get( $id );
		if ( null === $row ) {
			wp_safe_redirect( add_query_arg( 'motored_notice', 'not_found', $this->url() ) );
			exit;
		}

		$phone = (string) $row['phone'];
		$norm  = Phone::normalize( $phone, WahaConfig::country_code() );
		if ( null === $norm ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'view'           => $id,
						'motored_notice' => 'invalid_phone',
					),
					$this->url()
				)
			);
			exit;
		}

		$token        = RecoveryToken::get_or_generate( $id, Config::token_validity_days() );
		$recovery_url = RecoveryToken::recovery_url( $id, $token );
		$optout_url   = RecoveryToken::optout_url( $id );

		$text = $this->manual_text( $row, $recovery_url, $optout_url );
		if ( null === $text ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'view'           => $id,
						'motored_notice' => 'order_unavailable',
					),
					$this->url()
				)
			);
			exit;
		}

		// Register note: "abierto manualmente, no confirmado", never as sent.
		History::log( $id, 'note', 'whatsapp', 'manual', get_current_user_id(), 'ok', 'abierto manualmente, no confirmado' );

		$digits = preg_replace( '/\D+/', '', $norm );
		$wa_url = 'https://wa.me/' . $digits . '?text=' . rawurlencode( $text );
		wp_redirect( $wa_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External redirect to WhatsApp Web.
		exit;
	}

	/**
	 * WhatsApp text of a manual action: cart rows use cart step 1, order rows the current case (fresh order).
	 *
	 * @param array<string,mixed> $row          Recovery row.
	 * @param string              $recovery_url Recovery URL.
	 * @param string              $optout_url   Opt-out URL.
	 */
	private function manual_text( array $row, string $recovery_url, string $optout_url ): ?string {
		if ( 'order' === (string) $row['type'] ) {
			return OrderStepHandler::manual_text( $row );
		}
		$vars = array(
			'first_name'   => (string) $row['first_name'],
			'cart_items'   => Renderer::format_items( ProductLookup::resolve( Snapshot::decode( (string) $row['items'] ) ), 3 ),
			'cart_total'   => Renderer::format_price( (string) $row['total'] ),
			'recovery_url' => $recovery_url,
			'optout_url'   => $optout_url,
			'store_name'   => get_bloginfo( 'name' ),
		);
		return Renderer::render( Config::step( 1 )['text'], $vars );
	}

	/**
	 * Sends the e-mail of a manual action (pending-payment e-mail for orders, abandoned-cart e-mail otherwise).
	 *
	 * @param array<string,mixed> $row          Recovery row.
	 * @param string              $recovery_url Recovery URL.
	 * @param string              $optout_url   Opt-out URL.
	 */
	private function manual_email( array $row, string $recovery_url, string $optout_url ): bool {
		if ( 'order' === (string) $row['type'] ) {
			$order = OrderHooks::load_order( (int) $row['order_id'] );
			if ( null === $order ) {
				return false;
			}
			$variant = OrderCase::of_status( (string) $order->get_status(), OrderHooks::paid_statuses() );
			if ( OrderCase::CANCELLED !== $variant ) {
				$mailer = new Emails\PendingPaymentEmail();
				return $mailer->trigger_order( $row, $order, OrderHooks::pay_url( $order, OrderCase::ON_HOLD === $variant ), $optout_url );
			}
		}
		$mailer = new Emails\AbandonedCartEmail();
		return $mailer->trigger_recovery( $row, $recovery_url, $optout_url );
	}

	/**
	 * Adds a manual note (admin-post).
	 */
	public function handle_note(): void {
		$this->require_cap();
		check_admin_referer( self::NOTE_ACTION, 'motored_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$id   = isset( $_POST['recovery_id'] ) ? absint( wp_unslash( $_POST['recovery_id'] ) ) : 0;
		$note = isset( $_POST['motored_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['motored_note'] ) ) : '';
		// phpcs:enable
		if ( $id > 0 && '' !== $note && null !== Repository::get( $id ) ) {
			History::log( $id, 'note', 'none', 'manual', get_current_user_id(), 'ok', $note );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'view'           => $id,
					'motored_notice' => 'noted',
				),
				$this->url()
			)
		);
		exit;
	}

	/**
	 * Screen callback.
	 */
	public function render(): void {
		$this->require_cap();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		$view = isset( $_GET['view'] ) ? absint( wp_unslash( $_GET['view'] ) ) : 0;

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Abandoned carts', 'motored-core' ) . '</h1><hr class="wp-header-end" />';
		$this->render_notice();
		if ( $view > 0 ) {
			$this->render_detail( $view );
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	/**
	 * Summary cards + filterable list.
	 */
	private function render_list(): void {
		if ( ! class_exists( 'WP_List_Table', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		$summary = $this->summary();
		printf(
			'<p><strong>%1$s</strong> %2$d &nbsp;|&nbsp; <strong>%3$s</strong> %4$d (%5$s: %6$d · %7$s: %8$d) &nbsp;|&nbsp; <strong>%9$s</strong> %10$s</p>',
			esc_html__( 'Abandoned:', 'motored-core' ),
			(int) $summary['abandoned'],
			esc_html__( 'Recovered:', 'motored-core' ),
			(int) $summary['recovered'],
			esc_html__( 'via link', 'motored-core' ),
			(int) $summary['recovered_link'],
			esc_html__( 'bought after message', 'motored-core' ),
			(int) $summary['recovered_message'],
			esc_html__( 'Recovered value:', 'motored-core' ),
			wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $summary['recovered_value'] ) : (string) $summary['recovered_value'] )
		);

		$table = new RecoveryListTable();
		$table->prepare_items();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		$table->search_box( __( 'Search e-mail or phone', 'motored-core' ), 'motored-recovery' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Item detail: contact, fresh products, actions, timeline, note form.
	 *
	 * @param int $id Row id.
	 */
	private function render_detail( int $id ): void {
		$row = Repository::get( $id );
		echo '<p><a href="' . esc_url( $this->url() ) . '">&larr; ' . esc_html__( 'Back to the list', 'motored-core' ) . '</a></p>';
		if ( null === $row ) {
			echo '<p>' . esc_html__( 'This item no longer exists.', 'motored-core' ) . '</p>';
			return;
		}

		$status = Status::derive_row( $row, time(), Config::abandon_minutes() );
		$user   = (int) $row['user_id'] > 0 ? '<a href="' . esc_url( get_edit_user_link( (int) $row['user_id'] ) ) . '">#' . (int) $row['user_id'] . '</a>' : esc_html__( 'Guest', 'motored-core' );

		echo '<h2>' . esc_html( sprintf( /* translators: %d: item number. */ __( 'Item #%d', 'motored-core' ), $id ) ) . '</h2>';

		$base          = admin_url( 'admin.php?page=' . self::PAGE );
		$token         = RecoveryToken::get_or_generate( $id, Config::token_validity_days() );
		$rec_url       = RecoveryToken::recovery_url( $id, $token );
		$is_order      = 'order' === (string) $row['type'];
		$order_details = $is_order ? OrderHooks::admin_details( (int) $row['order_id'] ) : null;
		$link_url      = $rec_url;
		if ( $is_order ) {
			$order      = OrderHooks::load_order( (int) $row['order_id'] );
			$order_case = null === $order ? null : OrderCase::of_status( (string) $order->get_status(), OrderHooks::paid_statuses() );
			$link_url   = null === $order || OrderCase::CANCELLED === $order_case ? $rec_url : OrderHooks::pay_url( $order, OrderCase::ON_HOLD === $order_case );
		}
		$send_wa_url    = wp_nonce_url(
			add_query_arg(
				array(
					'motored_action' => 'send_wa',
					'id'             => $id,
				),
				$base
			),
			'motored_recovery_send_wa_' . $id
		);
		$send_email_url = wp_nonce_url(
			add_query_arg(
				array(
					'motored_action' => 'send_email',
					'id'             => $id,
				),
				$base
			),
			'motored_recovery_send_email_' . $id
		);
		$open_wa_url    = wp_nonce_url(
			add_query_arg(
				array(
					'motored_action' => 'open_wa',
					'id'             => $id,
				),
				$base
			),
			'motored_recovery_open_wa_' . $id
		);

		echo '<div class="motored-actions">';
		echo '<a href="' . esc_url( $send_wa_url ) . '" class="button button-primary">' . esc_html__( 'Send WhatsApp now', 'motored-core' ) . '</a>';
		echo '<a href="' . esc_url( $send_email_url ) . '" class="button button-secondary">' . esc_html__( 'Send email now', 'motored-core' ) . '</a>';
		echo '<a href="' . esc_url( $open_wa_url ) . '" class="button button-secondary" target="_blank">' . esc_html__( 'Open in WhatsApp Web', 'motored-core' ) . '</a>';
		echo '</div>';

		echo '<div class="motored-box motored-narrow"><div class="motored-field"><label class="motored-field__label" for="motored-recovery-link-input">' . esc_html( $is_order ? __( 'Payment link:', 'motored-core' ) : __( 'Recovery link:', 'motored-core' ) ) . '</label>';
		echo '<div class="motored-actions motored-field__control">';
		echo '<input id="motored-recovery-link-input" type="text" readonly value="' . esc_attr( $link_url ) . '" class="motored-mono" onfocus="this.select()" />';
		echo '<button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\'motored-recovery-link-input\').value); alert(\'' . esc_js( __( 'Link copied to clipboard!', 'motored-core' ) ) . '\');">' . esc_html__( 'Copy link', 'motored-core' ) . '</button>';
		echo '</div></div></div>';

		Ui::table_open( true );
		echo '<tbody>';
		$this->detail_row( __( 'Status', 'motored-core' ), esc_html( Status::label( $status ) ) );
		$this->detail_row( __( 'Type', 'motored-core' ), esc_html( 'order' === $row['type'] ? __( 'Order', 'motored-core' ) : __( 'Cart', 'motored-core' ) ) );
		$this->detail_row( __( 'Customer', 'motored-core' ), wp_kses_post( $user ) );
		$this->detail_row( __( 'First name', 'motored-core' ), esc_html( (string) $row['first_name'] ) );
		$this->detail_row( __( 'E-mail', 'motored-core' ), esc_html( (string) $row['email'] ) );
		$this->detail_row( __( 'Phone', 'motored-core' ), esc_html( (string) $row['phone'] ) );
		$this->detail_row( __( 'WhatsApp sent count', 'motored-core' ), esc_html( (string) (int) $row['wa_sent_count'] ) );
		$this->detail_row( __( 'Email sent count', 'motored-core' ), esc_html( (string) (int) $row['email_sent_count'] ) );
		$this->detail_row( __( 'Created', 'motored-core' ), esc_html( RecoveryListTable::local_date( (string) $row['created_gmt'] ) ) );
		$this->detail_row( __( 'Last activity', 'motored-core' ), esc_html( RecoveryListTable::local_date( (string) $row['last_activity_gmt'] ) ) );
		if ( $is_order ) {
			$this->render_order_rows( $row, $order_details );
		}
		echo '</tbody>';
		Ui::table_close();

		if ( $is_order && null !== $order_details && array() !== $order_details['notes'] ) {
			echo '<h3>' . esc_html__( 'Latest order notes', 'motored-core' ) . '</h3><ul class="motored-notes">';
			foreach ( $order_details['notes'] as $note ) {
				echo '<li>' . esc_html( $note ) . '</li>';
			}
			echo '</ul>';
		}

		$lines = ProductLookup::resolve( Snapshot::decode( (string) $row['items'] ) );
		echo '<h3>' . esc_html__( 'Products (current data)', 'motored-core' ) . '</h3>';
		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Product', 'motored-core' )  => 'grow',
				__( 'Qty', 'motored-core' )      => 'xs',
				__( 'Subtotal', 'motored-core' ) => 'md',
				__( 'Stock', 'motored-core' )    => 'md',
			)
		);
		echo '<tbody>';
		foreach ( $lines as $line ) {
			printf(
				'<tr><td>%1$s</td><td>%2$d</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( $line['name'] ),
				(int) $line['qty'],
				wp_kses_post( self::money( $line['line_total'], (string) $row['currency'] ) ),
				esc_html( self::stock_label( $line['stock'] ) )
			);
		}
		echo '</tbody>';
		Ui::table_close();
		echo '<p><strong>' . esc_html__( 'Current total:', 'motored-core' ) . '</strong> ' . wp_kses_post( self::money( ProductLookup::total( $lines ), (string) $row['currency'] ) ) . '</p>';

		$this->render_timeline( $id );
		$this->render_note_form( $id );
	}

	/**
	 * Extra detail rows of an order recovery: order link, current status, payment method, total.
	 *
	 * @param array<string,mixed>                                                                                                   $row     Recovery row.
	 * @param array{edit_url:string,status_label:string,payment_method:string,total:string,currency:string,notes:list<string>}|null $details Order details.
	 */
	private function render_order_rows( array $row, ?array $details ): void {
		if ( null === $details ) {
			$this->detail_row( __( 'Order', 'motored-core' ), esc_html( '#' . (int) $row['order_id'] . ' — ' . __( 'This order no longer exists.', 'motored-core' ) ) );
			return;
		}
		$this->detail_row( __( 'Order', 'motored-core' ), '<a href="' . esc_url( $details['edit_url'] ) . '">#' . (int) $row['order_id'] . '</a>' );
		$this->detail_row( __( 'Current order status', 'motored-core' ), esc_html( $details['status_label'] ) );
		$this->detail_row( __( 'Payment method', 'motored-core' ), esc_html( '' !== $details['payment_method'] ? $details['payment_method'] : '—' ) );
		$this->detail_row( __( 'Order total', 'motored-core' ), wp_kses_post( self::money( (float) $details['total'], $details['currency'] ) ) );
	}

	/**
	 * Event timeline.
	 *
	 * @param int $id Row id.
	 */
	private function render_timeline( int $id ): void {
		$events = History::for_recovery( $id );
		echo '<h3>' . esc_html__( 'History', 'motored-core' ) . '</h3>';
		if ( array() === $events['rows'] ) {
			echo '<p>' . esc_html__( 'No events yet.', 'motored-core' ) . '</p>';
			return;
		}
		Ui::table_open();
		echo '<tbody>';
		foreach ( $events['rows'] as $event ) {
			$line = History::format( $event );
			printf(
				'<tr><td class="motored-col-md">%1$s</td><td><strong>%2$s</strong>%3$s%4$s</td></tr>',
				esc_html( RecoveryListTable::local_date( $line['date'] ) ),
				esc_html( $line['label'] ),
				'' !== $line['meta'] ? ' <span class="description">(' . esc_html( $line['meta'] ) . ')</span>' : '',
				'' !== $line['detail'] ? '<br />' . esc_html( $line['detail'] ) : ''
			);
		}
		echo '</tbody>';
		Ui::table_close();
	}

	/**
	 * Manual note form.
	 *
	 * @param int $id Row id.
	 */
	private function render_note_form( int $id ): void {
		echo '<h3>' . esc_html__( 'Add a note', 'motored-core' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NOTE_ACTION, 'motored_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NOTE_ACTION ) . '" />';
		echo '<input type="hidden" name="recovery_id" value="' . esc_attr( (string) $id ) . '" />';
		echo '<p><textarea name="motored_note" rows="3" cols="60" maxlength="190"></textarea></p>';
		echo '<p class="description">' . esc_html__( 'Do not type phone numbers or e-mails: they are removed from notes.', 'motored-core' ) . '</p>';
		submit_button( __( 'Add note', 'motored-core' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Table row helper (value is already escaped).
	 *
	 * @param string $label Label.
	 * @param string $html  Escaped HTML.
	 */
	private function detail_row( string $label, string $html ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
	}

	/**
	 * Notice after an action.
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = isset( $_GET['motored_notice'] ) ? sanitize_key( wp_unslash( $_GET['motored_notice'] ) ) : '';
		$count  = isset( $_GET['motored_count'] ) ? absint( wp_unslash( $_GET['motored_count'] ) ) : 0;
		$code   = isset( $_GET['motored_code'] ) ? sanitize_text_field( wp_unslash( $_GET['motored_code'] ) ) : '';
		// phpcs:enable
		if ( '' === $notice ) {
			return;
		}

		$class = 'notice-success';
		switch ( $notice ) {
			case 'deleted':
				/* translators: %d: number of deleted items. */
				$text = sprintf( __( '%d item(s) deleted.', 'motored-core' ), $count );
				break;
			case 'noted':
				$text = __( 'Note added.', 'motored-core' );
				break;
			case 'wa_sent':
				$text = __( 'WhatsApp message sent.', 'motored-core' );
				break;
			case 'wa_failed':
				$class = 'notice-error';
				/* translators: %s: failure code. */
				$text = sprintf( __( 'WhatsApp message could not be sent: %s', 'motored-core' ), $code );
				break;
			case 'email_sent':
				$text = __( 'Recovery email sent.', 'motored-core' );
				break;
			case 'email_failed':
				$class = 'notice-error';
				$text  = __( 'Recovery email could not be sent.', 'motored-core' );
				break;
			case 'link_ready':
				$text = __( 'Recovery link is ready.', 'motored-core' );
				break;
			case 'opted_out':
				$class = 'notice-warning';
				$text  = __( 'Cannot send: this contact has opted out of messages.', 'motored-core' );
				break;
			case 'missing_phone':
				$class = 'notice-warning';
				$text  = __( 'Cannot send: no phone number recorded.', 'motored-core' );
				break;
			case 'invalid_phone':
				$class = 'notice-error';
				$text  = __( 'Cannot send: phone number is invalid.', 'motored-core' );
				break;
			case 'missing_email':
				$class = 'notice-warning';
				$text  = __( 'Cannot send: no email address recorded.', 'motored-core' );
				break;
			case 'order_unavailable':
				$class = 'notice-error';
				$text  = __( 'Cannot send: the order no longer exists.', 'motored-core' );
				break;
			case 'not_found':
				$class = 'notice-error';
				$text  = __( 'Cart item not found.', 'motored-core' );
				break;
			default:
				return;
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Summary counts, cached 5 minutes.
	 *
	 * @return array{abandoned:int,recovered:int,recovered_link:int,recovered_message:int,recovered_value:float}
	 */
	private function summary(): array {
		$summary = Cache::remember(
			'summary',
			static fn(): array => Repository::summary( Status::abandon_cutoff( time(), Config::abandon_minutes() ) ),
			self::SUMMARY_TTL,
			CaptureLimiter::BUCKET
		);
		return is_array( $summary ) ? array_merge(
			array(
				'abandoned'         => 0,
				'recovered'         => 0,
				'recovered_link'    => 0,
				'recovered_message' => 0,
				'recovered_value'   => 0.0,
			),
			$summary
		) : array(
			'abandoned'         => 0,
			'recovered'         => 0,
			'recovered_link'    => 0,
			'recovered_message' => 0,
			'recovered_value'   => 0.0,
		);
	}

	/**
	 * Formats money (HTML from wc_price()).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 */
	public static function money( float $amount, string $currency ): string {
		return function_exists( 'wc_price' ) ? (string) wc_price( $amount, array( 'currency' => $currency ) ) : number_format( $amount, 2 );
	}

	/**
	 * Translated stock label.
	 *
	 * @param string $stock ProductLookup constant.
	 */
	public static function stock_label( string $stock ): string {
		switch ( $stock ) {
			case ProductLookup::IN_STOCK:
				return __( 'In stock', 'motored-core' );
			case ProductLookup::OUT_OF_STOCK:
				return __( 'Out of stock', 'motored-core' );
			default:
				return __( 'Unavailable', 'motored-core' );
		}
	}

	/**
	 * URL of the list screen.
	 */
	private function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Stops the request without the capability.
	 */
	private function require_cap(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'motored-core' ), '', array( 'response' => 403 ) );
		}
	}
}
