<?php
/**
 * Admin side of the Waha module: settings section, test buttons, status rows.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Waha;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Integration\Waha\Result;
use Motored\Core\Integration\Waha\WahaClient;
use Motored\Core\Support\Cache;
use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Only instantiated in admin requests. The two test buttons are the ONLY synchronous HTTP calls
 * of the module (admin-post.php, nonce + manage_woocommerce; never admin-ajax).
 */
final class WahaAdmin {

	public const ACTION_CONNECTION = 'motored_waha_test_connection';
	public const ACTION_MESSAGE    = 'motored_waha_test_message';
	public const NONCE_ACTION      = 'motored_waha_tools';
	public const NONCE_FIELD       = 'motored_waha_nonce';
	public const PHONE_FIELD       = 'motored_waha_test_phone';
	public const SECTION           = 'whatsapp';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'WhatsApp', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			20
		);
		// Custom field type rendered by WooCommerce through woocommerce_admin_field_{type} (VERIFY.md).
		add_action( 'woocommerce_admin_field_motored_waha_tools', array( $this, 'render_tools' ) );
		add_action( 'admin_post_' . self::ACTION_CONNECTION, array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_' . self::ACTION_MESSAGE, array( $this, 'handle_test_message' ) );
		add_filter( 'motored_status_rows', array( $this, 'status_rows' ) );
	}

	/**
	 * Fields of the WhatsApp section. Fields overridden by constants are not rendered as inputs
	 * (so saving can never overwrite them) and are listed read-only in the description.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		$locked = array();
		$fields = array(
			array(
				'title'    => __( 'Send messages', 'motored-core' ),
				'desc'     => __( 'Master switch. Off: no WhatsApp message leaves the store; queued messages are discarded.', 'motored-core' ),
				'id'       => 'motored_waha[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Test mode', 'motored-core' ),
				'desc'     => __( 'Only send to the numbers listed below. Turn it off to message real customers.', 'motored-core' ),
				'id'       => 'motored_waha[test_mode]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['test_mode'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Allowed numbers (test mode)', 'motored-core' ),
				'desc'     => __( 'One number per line, with or without country code.', 'motored-core' ),
				'desc_tip' => true,
				'id'       => 'motored_waha[test_numbers]',
				'type'     => 'textarea',
				'css'      => 'min-width:300px;height:80px;',
				'default'  => Config::DEFAULTS['test_numbers'],
				'autoload' => false,
			),
		);

		$definitions = array(
			'url'     => array(
				'title' => __( 'WAHA URL', 'motored-core' ),
				'desc'  => __( 'Base URL of your WAHA instance. It must use https.', 'motored-core' ),
				'type'  => 'text',
				'extra' => array( 'placeholder' => 'https://waha.example.com' ),
			),
			'session' => array(
				'title' => __( 'Session', 'motored-core' ),
				'desc'  => __( 'Name of the WAHA session that sends the messages.', 'motored-core' ),
				'type'  => 'text',
				'extra' => array(),
			),
			'api_key' => array(
				'title' => __( 'API key', 'motored-core' ),
				'desc'  => __( 'Sent in the X-Api-Key header. Prefer defining it in wp-config.php.', 'motored-core' ),
				'type'  => 'password',
				'extra' => array( 'autocomplete' => 'new-password' ),
			),
		);

		foreach ( $definitions as $key => $definition ) {
			if ( Config::is_locked( $key ) ) {
				$locked[ $key ] = $definition['title'];
				continue;
			}
			$fields[] = array(
				'title'             => $definition['title'],
				'desc'              => $definition['desc'],
				'desc_tip'          => true,
				'id'                => 'motored_waha[' . $key . ']',
				'type'              => $definition['type'],
				'default'           => Config::DEFAULTS[ $key ],
				'custom_attributes' => $definition['extra'],
				'autoload'          => false,
			);
		}

		$fields[] = $this->number_field( 'country_code', __( 'Default country code', 'motored-core' ), __( 'Calling code used for numbers without one (54 = Argentina).', 'motored-core' ), 'text' );
		$fields[] = $this->number_field( 'rate_per_minute', __( 'Messages per minute', 'motored-core' ), __( 'Over this limit, messages wait for the next minute.', 'motored-core' ) );
		$fields[] = $this->number_field( 'rate_per_hour', __( 'Messages per hour', 'motored-core' ), __( 'Over this limit, messages wait for the next hour.', 'motored-core' ) );
		$fields[] = $this->number_field( 'breaker_threshold', __( 'Failures to pause sending', 'motored-core' ), __( 'Consecutive failures that pause all sending.', 'motored-core' ) );
		$fields[] = $this->number_field( 'breaker_minutes', __( 'Pause length (minutes)', 'motored-core' ), __( 'How long sending stays paused after too many failures.', 'motored-core' ) );
		$fields[] = $this->number_field( 'interval_min', __( 'Minimum gap between messages (seconds)', 'motored-core' ), __( 'Shortest random wait between any two messages.', 'motored-core' ), 'number', '0' );
		$fields[] = $this->number_field( 'interval_max', __( 'Maximum gap between messages (seconds)', 'motored-core' ), __( 'Longest random wait between any two messages.', 'motored-core' ) );
		$fields[] = $this->number_field( 'daily_cap', __( 'Daily message limit', 'motored-core' ), __( 'Over this limit, messages wait for the next day.', 'motored-core' ) );
		$fields[] = $this->number_field( 'low_percent', __( 'Share of the daily limit for low priority (%)', 'motored-core' ), __( 'Recovery messages only use this share; the rest is kept for order notices.', 'motored-core' ) );
		$fields[] = array(
			'title'    => __( 'Typing simulation', 'motored-core' ),
			'desc'     => __( 'Show "typing..." for a moment before each message (up to 3 seconds).', 'motored-core' ),
			'id'       => 'motored_waha[typing]',
			'type'     => 'checkbox',
			'default'  => Config::DEFAULTS['typing'],
			'autoload' => false,
		);

		$description = '<strong>' . esc_html( self::mode_notice( Config::mode() ) ) . '</strong>';
		if ( array() !== $locked ) {
			$description .= '<br />';
			$items        = array();
			foreach ( $locked as $key => $label ) {
				$items[] = esc_html( $label ) . ' (<code>' . esc_html( Config::constant_name( $key ) ) . '</code>)';
			}
			$description .= esc_html__( 'Read-only, defined in wp-config.php:', 'motored-core' ) . ' ' . implode( ', ', $items ) . '.';
		}

		return array_merge(
			array(
				array(
					'title' => __( 'WhatsApp (WAHA)', 'motored-core' ),
					'type'  => 'title',
					'desc'  => $description,
					'id'    => 'motored_waha_title',
				),
			),
			$fields,
			array(
				// No id on purpose: WooCommerce skips id-less fields when saving.
				array( 'type' => 'motored_waha_tools' ),
				array(
					'type' => 'sectionend',
					'id'   => 'motored_waha_title',
				),
			)
		);
	}

	/**
	 * Prints the test tools row (WooCommerce calls this for the custom field type).
	 */
	public function render_tools(): void {
		$notice = $this->pull_notice();
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Tests', 'motored-core' ) . '</th><td class="forminp">';
		if ( null !== $notice ) {
			printf(
				'<p><strong style="color:%1$s">%2$s</strong></p>',
				esc_attr( $notice['ok'] ? '#008a20' : '#b32d2e' ),
				esc_html( $notice['text'] )
			);
		}
		echo '<p class="description">' . esc_html__( 'Tests use the saved settings: save before testing.', 'motored-core' ) . '</p>';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );
		$action = esc_url( admin_url( 'admin-post.php' ) );
		printf(
			'<p><button type="submit" class="button" name="action" value="%1$s" formmethod="post" formaction="%2$s">%3$s</button></p>',
			esc_attr( self::ACTION_CONNECTION ),
			$action, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_html__( 'Test connection', 'motored-core' )
		);
		printf(
			'<p><input type="tel" name="%1$s" placeholder="%2$s" autocomplete="off" /> <button type="submit" class="button" name="action" value="%3$s" formmethod="post" formaction="%4$s">%5$s</button></p>',
			esc_attr( self::PHONE_FIELD ),
			esc_attr( '011 5555-1234' ),
			esc_attr( self::ACTION_MESSAGE ),
			$action, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_html__( 'Send test message', 'motored-core' )
		);
		echo '</td></tr>';
	}

	/**
	 * "Test connection": shows the session state.
	 */
	public function handle_test_connection(): void {
		$this->guard();
		$result = ( new WahaClient() )->sessionStatus();
		if ( $result->ok ) {
			$this->finish_test(
				true,
				sprintf(
					/* translators: %s: WAHA session state, e.g. WORKING. */
					__( 'Connection OK. Session status: %s.', 'motored-core' ),
					(string) ( $result->data['status'] ?? '' )
				)
			);
		}
		$this->finish_test(
			false,
			/* translators: %s: reason of the failure. */
			sprintf( __( 'Connection failed: %s', 'motored-core' ), self::describe( $result ) )
		);
	}

	/**
	 * "Send test message": sends a fixed text to the typed number.
	 */
	public function handle_test_message(): void {
		$this->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$phone = isset( $_POST[ self::PHONE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::PHONE_FIELD ] ) ) : '';
		if ( '' === $phone ) {
			$this->finish_test( false, __( 'Type a phone number first.', 'motored-core' ) );
		}

		$client   = new WahaClient();
		$resolved = ( new ChatResolver( $client, Config::country_code() ) )->resolve( $phone );
		$result   = $resolved->ok
			? $client->sendText( (string) ( $resolved->data['chat_id'] ?? '' ), __( 'Test message from Motored Core.', 'motored-core' ) )
			: $resolved;

		Logger::info(
			'WhatsApp test message attempted.',
			array(
				'to'   => Logger::mask_phone( $phone ),
				'code' => $result->code,
			)
		);
		if ( $result->ok ) {
			$this->finish_test( true, __( 'Test message sent.', 'motored-core' ) );
		}
		$this->finish_test(
			false,
			/* translators: %s: reason of the failure. */
			sprintf( __( 'The message was not sent: %s', 'motored-core' ), self::describe( $result ) )
		);
	}

	/**
	 * Adds WhatsApp rows to the Status section.
	 *
	 * @param mixed $rows Label => value rows.
	 * @return array<string,string>
	 */
	public function status_rows( $rows ): array {
		$rows  = is_array( $rows ) ? $rows : array();
		$until = CircuitBreaker::until();

		$rows[ __( 'WhatsApp delivery mode', 'motored-core' ) ] = self::mode_label( Config::mode() );
		$rows[ __( 'WAHA configured', 'motored-core' ) ]        = Config::is_configured() ? __( 'Yes', 'motored-core' ) : __( 'No', 'motored-core' );
		$rows[ __( 'WhatsApp sending', 'motored-core' ) ]       = $until > time()
			/* translators: %s: time when sending resumes. */
			? sprintf( __( 'Paused by circuit breaker until %s', 'motored-core' ), (string) wp_date( 'H:i', $until ) )
			: __( 'Active', 'motored-core' );
		$rows[ __( 'Consecutive WhatsApp failures', 'motored-core' ) ] = (string) CircuitBreaker::failures();
		/* translators: 1: messages sent today, 2: daily limit. */
		$rows[ __( 'WhatsApp messages sent today', 'motored-core' ) ] = sprintf( __( '%1$d of %2$d', 'motored-core' ), RateLimiter::daily_count( time() ), Config::daily_cap() );
		return $rows;
	}

	/**
	 * Short state label (Status section).
	 *
	 * @param string $mode Config::MODE_*.
	 */
	public static function mode_label( string $mode ): string {
		if ( Config::MODE_LIVE === $mode ) {
			return __( 'Live (real customers)', 'motored-core' );
		}
		if ( Config::MODE_TEST === $mode ) {
			/* translators: %d: number of allowed numbers. */
			return sprintf( __( 'Test mode (%d allowed numbers)', 'motored-core' ), count( Config::test_numbers() ) );
		}
		return __( 'Off (nothing is sent)', 'motored-core' );
	}

	/**
	 * Visible warning at the top of the WhatsApp section.
	 *
	 * @param string $mode Config::MODE_*.
	 */
	public static function mode_notice( string $mode ): string {
		if ( Config::MODE_LIVE === $mode ) {
			return __( 'LIVE: messages are sent to real customers.', 'motored-core' );
		}
		if ( Config::MODE_TEST === $mode ) {
			return __( 'TEST MODE: messages only go to the allowed numbers; all others are discarded.', 'motored-core' );
		}
		return __( 'OFF: no WhatsApp message is sent. Turn on "Send messages" to start (test mode first).', 'motored-core' );
	}

	/**
	 * Translated, short reason for a failed Result.
	 *
	 * @param Result $result Result.
	 */
	public static function describe( Result $result ): string {
		switch ( $result->code ) {
			case 'not_configured':
				return __( 'URL or API key missing.', 'motored-core' );
			case 'invalid_url':
				return __( 'the URL is not valid.', 'motored-core' );
			case 'insecure_url':
				return __( 'the URL must use https.', 'motored-core' );
			case 'timeout':
				return __( 'the server did not answer in time.', 'motored-core' );
			case 'transport':
				return __( 'could not connect to the server.', 'motored-core' );
			case 'auth':
				return __( 'the API key was rejected.', 'motored-core' );
			case 'not_found':
				return __( 'session or endpoint not found.', 'motored-core' );
			case 'rate_limited':
				return __( 'too many requests.', 'motored-core' );
			case 'server_error':
				return __( 'WAHA server error.', 'motored-core' );
			case 'invalid_response':
				return __( 'unexpected response from WAHA.', 'motored-core' );
			case 'invalid_phone':
				return __( 'the phone number is not valid.', 'motored-core' );
			case 'no_whatsapp':
				return __( 'that number is not on WhatsApp.', 'motored-core' );
			default:
				return __( 'unexpected error.', 'motored-core' );
		}
	}

	/**
	 * Number field helper.
	 *
	 * @param string $key   Config key.
	 * @param string $title Label.
	 * @param string $desc  Help text.
	 * @param string $type  Field type.
	 * @param string $min   Minimum value for number fields.
	 * @return array<string,mixed>
	 */
	private function number_field( string $key, string $title, string $desc, string $type = 'number', string $min = '1' ): array {
		return array(
			'title'             => $title,
			'desc'              => $desc,
			'desc_tip'          => true,
			'id'                => 'motored_waha[' . $key . ']',
			'type'              => $type,
			'default'           => Config::DEFAULTS[ $key ],
			'custom_attributes' => 'number' === $type ? array(
				'min'  => $min,
				'step' => '1',
			) : array(),
			'autoload'          => false,
		);
	}

	/**
	 * Capability + nonce check for the admin-post handlers.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'motored-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
	}

	/**
	 * Stores the outcome for the next settings page view and redirects there.
	 *
	 * @param bool   $ok   Whether the test passed.
	 * @param string $text Message shown to the admin.
	 */
	private function finish_test( bool $ok, string $text ): never {
		Cache::set(
			'notice:' . get_current_user_id(),
			array(
				'ok'   => $ok,
				'text' => $text,
			),
			120,
			ChatResolver::BUCKET
		);
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=motored&section=' . self::SECTION ) );
		exit;
	}

	/**
	 * Reads and clears the pending notice of the current user.
	 *
	 * @return array{ok:bool,text:string}|null
	 */
	private function pull_notice(): ?array {
		$key    = 'notice:' . get_current_user_id();
		$found  = false;
		$notice = Cache::get( $key, ChatResolver::BUCKET, $found );
		if ( ! $found || ! is_array( $notice ) ) {
			return null;
		}
		Cache::delete( $key, ChatResolver::BUCKET );
		return array(
			'ok'   => ! empty( $notice['ok'] ),
			'text' => isset( $notice['text'] ) && is_string( $notice['text'] ) ? $notice['text'] : '',
		);
	}
}
