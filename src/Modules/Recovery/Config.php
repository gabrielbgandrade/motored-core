<?php
/**
 * Recovery module configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads option `motored_recovery` (autoload=false).
 */
final class Config {

	public const OPTION_KEY = 'recovery';

	public const DEFAULTS = array(
		'enabled'                  => 'yes',
		'abandon_minutes'          => '60',
		'retention_days'           => '30',
		'window_start'             => '09:00',
		'window_end'               => '21:00',
		'coupon_code'              => '',
		'token_validity_days'      => '7',
		'fallback_email'           => 'yes',
		'limit_24h'                => '1',
		'limit_week'               => '3',
		'order_enabled'            => 'yes',
		'order_cancelled_fallback' => 'restore',
		'order_cancelled_text'     => "Hola {first_name}, tu pedido *#{order_number}* en *{store_name}* se canceló porque no se completó el pago. Guardamos tus productos para que puedas retomar la compra:\n{order_items}\n\nRecuperá tu carrito acá:\n{recovery_url}\n\nPara no recibir más avisos: {optout_url}",
		'order_pending_step_1'     => array(
			'channel' => 'whatsapp',
			'delay'   => '30',
			'text'    => "¡Hola {first_name}! Vimos que no completaste el pago de tu pedido *#{order_number}* en *{store_name}* por *{order_total}*:\n{order_items}\n\nPodés terminarlo ahora desde acá:\n{pay_url}\n\nSi ya pagaste, ignorá este mensaje. Para no recibir más avisos: {optout_url}",
		),
		'order_pending_step_2'     => array(
			'channel' => 'email',
			'delay'   => '1440',
			'text'    => "Hola {first_name}, tu pedido *#{order_number}* en *{store_name}* sigue sin pagarse. Todavía podés completarlo acá:\n{pay_url}\n\nPara cancelar avisos: {optout_url}",
		),
		'order_failed_step_1'      => array(
			'channel' => 'whatsapp',
			'delay'   => '30',
			'text'    => "Hola {first_name}, no se pudo procesar tu pago del pedido *#{order_number}* en *{store_name}* por *{order_total}*:\n{order_items}\n\nPodés reintentarlo con otro medio de pago desde acá:\n{pay_url}\n\nSi ya lo resolviste, ignorá este mensaje. Para no recibir más avisos: {optout_url}",
		),
		'order_failed_step_2'      => array(
			'channel' => 'email',
			'delay'   => '1440',
			'text'    => "Hola {first_name}, el pago de tu pedido *#{order_number}* en *{store_name}* sigue sin completarse. Podés volver a intentarlo acá:\n{pay_url}\n\nPara cancelar avisos: {optout_url}",
		),
		'order_on_hold_step_1'     => array(
			'channel' => 'whatsapp',
			'delay'   => '1440',
			'text'    => "Hola {first_name}, seguimos esperando el pago de tu pedido *#{order_number}* en *{store_name}* por *{order_total}*. Apenas se acredite, lo preparamos.\n\nPodés ver cómo completar el pago acá:\n{pay_url}\n\nSi ya pagaste, ignorá este mensaje. Para no recibir más avisos: {optout_url}",
		),
		'order_on_hold_step_2'     => array(
			'channel' => 'off',
			'delay'   => '2880',
			'text'    => "Hola {first_name}, ¿pudiste completar el pago de tu pedido *#{order_number}* en *{store_name}*? Mirá cómo hacerlo acá:\n{pay_url}\n\nPara cancelar avisos: {optout_url}",
		),
		'step_1'                   => array(
			'channel' => 'whatsapp',
			'delay'   => '60',
			'text'    => "¡Hola {first_name}! Notamos que dejaste productos en tu carrito en *{store_name}* ({cart_total}):\n{cart_items}\n\nPodés recuperarlo y finalizar tu compra acá:\n{recovery_url}\n\nSi no querés recibir más estos avisos: {optout_url}",
		),
		'step_2'                   => array(
			'channel' => 'email',
			'delay'   => '1440',
			'text'    => "Hola {first_name}, ¿todavía estás pensando en tu compra en *{store_name}*? Tu carrito por *{cart_total}* te está esperando:\n{cart_items}\n\nCompletá tu pedido acá:\n{recovery_url}\n\nPara cancelar avisos: {optout_url}",
		),
		'step_3'                   => array(
			'channel' => 'off',
			'delay'   => '4320',
			'text'    => "Hola {first_name}, es tu última oportunidad para recuperar tu carrito en *{store_name}* por *{cart_total}*:\n{cart_items}\n\nAccedé a tu compra acá:\n{recovery_url}\n\nCancelar avisos: {optout_url}",
		),
	);

	/**
	 * Whether cart capture is enabled.
	 */
	public static function enabled(): bool {
		return 'yes' === Options::get( self::OPTION_KEY, 'enabled', self::DEFAULTS['enabled'] );
	}

	/**
	 * Minutes of inactivity after which an active cart is considered abandoned.
	 */
	public static function abandon_minutes(): int {
		return self::positive_int( 'abandon_minutes', 5 );
	}

	/**
	 * Days to keep non-converted rows.
	 */
	public static function retention_days(): int {
		return self::positive_int( 'retention_days', 7 );
	}

	/**
	 * Window start time (HH:MM).
	 */
	public static function window_start(): string {
		$time = (string) Options::get( self::OPTION_KEY, 'window_start', self::DEFAULTS['window_start'] );
		return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : self::DEFAULTS['window_start'];
	}

	/**
	 * Window end time (HH:MM).
	 */
	public static function window_end(): string {
		$time = (string) Options::get( self::OPTION_KEY, 'window_end', self::DEFAULTS['window_end'] );
		return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : self::DEFAULTS['window_end'];
	}

	/**
	 * Coupon code applied on recovery (optional).
	 */
	public static function coupon_code(): string {
		$code = Options::get( self::OPTION_KEY, 'coupon_code', '' );
		return is_scalar( $code ) ? sanitize_text_field( trim( (string) $code ) ) : '';
	}

	/**
	 * Token validity in days.
	 */
	public static function token_validity_days(): int {
		return self::positive_int( 'token_validity_days', 1 );
	}

	/**
	 * Whether to fallback to email if WhatsApp is unavailable or fails.
	 */
	public static function fallback_email(): bool {
		$val = Options::get( self::OPTION_KEY, 'fallback_email', self::DEFAULTS['fallback_email'] );
		return true === $val || 'yes' === $val || '1' === $val;
	}

	/**
	 * Maximum messages allowed per phone number in 24 hours.
	 */
	public static function limit_24h(): int {
		return self::positive_int( 'limit_24h', 1 );
	}

	/**
	 * Maximum messages allowed per phone number per week.
	 */
	public static function limit_week(): int {
		return self::positive_int( 'limit_week', 1 );
	}

	/**
	 * Configuration of a cart step.
	 *
	 * @param int $num Step number (1, 2, 3).
	 * @return array{channel:string,delay:int,text:string}
	 */
	public static function step( int $num ): array {
		return self::parse_step( 'step_' . $num );
	}

	/**
	 * Whether pending/failed/on-hold order recovery is enabled.
	 */
	public static function order_enabled(): bool {
		return 'yes' === Options::get( self::OPTION_KEY, 'order_enabled', self::DEFAULTS['order_enabled'] );
	}

	/**
	 * Configuration of an order step.
	 *
	 * @param string $variant Case: pending, failed or on_hold.
	 * @param int    $num  Step number (1 or 2).
	 * @return array{channel:string,delay:int,text:string}
	 */
	public static function order_step( string $variant, int $num ): array {
		return self::parse_step( 'order_' . $variant . '_step_' . $num );
	}

	/**
	 * Next enabled order step after the current one (the sequence has 2 steps per case).
	 *
	 * @param string     $variant         Case: pending, failed or on_hold.
	 * @param string|int $current_step Current step ('' or 0 before any send).
	 * @return int|null Step number (1 or 2), or null when the sequence is finished.
	 */
	public static function order_next_step_num( string $variant, string|int $current_step ): ?int {
		for ( $i = (int) $current_step + 1; $i <= 2; $i++ ) {
			if ( 'off' !== self::order_step( $variant, $i )['channel'] ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * What to do with a cancelled order at send time: "restore" (new cart from the recovery link) or "expire".
	 */
	public static function order_cancelled_fallback(): string {
		$value = Options::get( self::OPTION_KEY, 'order_cancelled_fallback', self::DEFAULTS['order_cancelled_fallback'] );
		return 'expire' === $value ? 'expire' : 'restore';
	}

	/**
	 * Message text used when restoring a cancelled order.
	 */
	public static function order_cancelled_text(): string {
		$text = Options::get( self::OPTION_KEY, 'order_cancelled_text', self::DEFAULTS['order_cancelled_text'] );
		return is_string( $text ) ? $text : (string) self::DEFAULTS['order_cancelled_text'];
	}

	/**
	 * Reads one step definition (stored value over default).
	 *
	 * @param string $key Option key, e.g. "step_1" or "order_failed_step_2".
	 * @return array{channel:string,delay:int,text:string}
	 */
	private static function parse_step( string $key ): array {
		$defaults = self::DEFAULTS[ $key ] ?? array(
			'channel' => 'off',
			'delay'   => 60,
			'text'    => '',
		);
		$stored   = Options::get( self::OPTION_KEY, $key, array() );
		$stored   = is_array( $stored ) ? $stored : array();

		$channel = isset( $stored['channel'] ) && in_array( $stored['channel'], array( 'whatsapp', 'email', 'off' ), true )
			? (string) $stored['channel']
			: (string) $defaults['channel'];

		$delay = isset( $stored['delay'] ) && is_numeric( $stored['delay'] ) && (int) $stored['delay'] > 0
			? (int) $stored['delay']
			: (int) $defaults['delay'];

		$text = isset( $stored['text'] ) && is_string( $stored['text'] )
			? $stored['text']
			: (string) $defaults['text'];

		return array(
			'channel' => $channel,
			'delay'   => $delay,
			'text'    => $text,
		);
	}

	/**
	 * Finds the next enabled step number after the current step.
	 *
	 * @param string|int $current_step Current step ('' or 0 before any send, 1 after step 1, etc.).
	 * @return int|null Next step number (1, 2, 3), or null if sequence finished.
	 */
	public static function next_step_num( string|int $current_step ): ?int {
		$cur = (int) $current_step;
		for ( $i = $cur + 1; $i <= 3; $i++ ) {
			$cfg = self::step( $i );
			if ( 'off' !== $cfg['channel'] ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Integer with a lower bound (falls back to the default when invalid).
	 *
	 * @param string $key Key.
	 * @param int    $min Minimum accepted.
	 */
	private static function positive_int( string $key, int $min ): int {
		$default = (int) ( self::DEFAULTS[ $key ] ?? $min );
		$value   = Options::get( self::OPTION_KEY, $key, $default );
		$value   = is_scalar( $value ) ? (int) $value : 0;
		return $value >= $min ? $value : $default;
	}
}
