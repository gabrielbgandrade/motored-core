<?php
/**
 * OrderNotifications module configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderNotifications;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads option `motored_order_notifications` (one option for the module, autoload=false).
 * Default message texts are in es_AR (voseo) plain text with WhatsApp formatting.
 */
final class Config {

	public const OPTION_KEY = 'order_notifications';

	/**
	 * Option defaults (no translation calls here so it is safe before init).
	 *
	 * @var array<string,mixed>
	 */
	public const DEFAULTS = array(
		'include_manual_orders' => 'no',
		EventMap::ON_HOLD       => array(
			'enabled' => 'yes',
			'text'    => "{¡Hola|¡Buenas} {first_name}! Recibimos tu pedido *#{order_number}* en *{store_name}* por *{order_total}*.\n{order_items}\n\nQuedó *en espera* de confirmación del pago. Podés ver el detalle {acá|en este link}:\n{order_url}",
		),
		EventMap::PROCESSING    => array(
			'enabled' => 'yes',
			'text'    => "{¡Hola|¡Buenas} {first_name}! Ya confirmamos tu pago y estamos preparando tu pedido *#{order_number}* en *{store_name}* (*{order_total}*).\n{order_items}\n\nSeguí el estado de tu compra {acá|en este link}:\n{order_url}",
		),
		EventMap::COMPLETED     => array(
			'enabled' => 'yes',
			'text'    => "{¡Hola|¡Buenas} {first_name}! Tu pedido *#{order_number}* en *{store_name}* ya está *completado*.\n{order_items}\n\nPodés consultar el resumen {acá|en este link}:\n{order_url}",
		),
		EventMap::REFUNDED      => array(
			'enabled' => 'yes',
			'text'    => "{Hola|Buenas} {first_name}, te confirmamos que tu pedido *#{order_number}* en *{store_name}* por *{order_total}* fue *reembolsado*.\n\nPodés revisar el detalle {acá|en este link}:\n{order_url}",
		),
		EventMap::CUSTOMER_NOTE => array(
			'enabled' => 'yes',
			'text'    => "{Hola|Buenas} {first_name}, tenés una nueva nota sobre tu pedido *#{order_number}* en *{store_name}*:\n\n{customer_note}\n\nMirá tu pedido {acá|en este link}:\n{order_url}",
		),
	);

	/**
	 * Whether an event is enabled.
	 *
	 * @param string $event Event slug.
	 */
	public static function event_enabled( string $event ): bool {
		return self::is_event_enabled_in( Options::all( self::OPTION_KEY ), $event );
	}

	/**
	 * Checks whether an event is enabled inside an already-fetched Options::all() array.
	 *
	 * @param array<string,mixed> $all   Module options array.
	 * @param string              $event Event slug.
	 */
	public static function is_event_enabled_in( array $all, string $event ): bool {
		if ( ! EventMap::is_valid( $event ) ) {
			return false;
		}
		$default = self::default_entry( $event );
		$entry   = isset( $all[ $event ] ) && is_array( $all[ $event ] ) ? $all[ $event ] : array();
		$enabled = $entry['enabled'] ?? $default['enabled'];
		return true === $enabled || 'yes' === $enabled || '1' === $enabled || 1 === $enabled;
	}

	/**
	 * Configured template text for an event (may be empty if cleared by admin).
	 *
	 * @param string $event Event slug.
	 */
	public static function event_text( string $event ): string {
		if ( ! EventMap::is_valid( $event ) ) {
			return '';
		}
		$default = self::default_entry( $event );
		$stored  = Options::get( self::OPTION_KEY, $event, array() );
		if ( is_array( $stored ) && array_key_exists( 'text', $stored ) && is_string( $stored['text'] ) ) {
			return $stored['text'];
		}
		return $default['text'];
	}

	/**
	 * Default es_AR template text for an event.
	 *
	 * @param string $event Event slug.
	 */
	public static function default_text( string $event ): string {
		return self::default_entry( $event )['text'];
	}

	/**
	 * Whether orders created manually in wp-admin (created_via = 'admin') also trigger notifications.
	 */
	public static function include_manual_orders(): bool {
		return self::include_manual_orders_in( Options::all( self::OPTION_KEY ) );
	}

	/**
	 * Checks manual-order setting from an already-fetched Options::all() array.
	 *
	 * @param array<string,mixed> $all Module options array.
	 */
	public static function include_manual_orders_in( array $all ): bool {
		$value = $all['include_manual_orders'] ?? self::DEFAULTS['include_manual_orders'];
		return true === $value || 'yes' === $value;
	}

	/**
	 * Default {enabled, text} entry for an event.
	 *
	 * @param string $event Event slug.
	 * @return array{enabled:string,text:string}
	 */
	private static function default_entry( string $event ): array {
		$entry = self::DEFAULTS[ $event ] ?? null;
		if ( ! is_array( $entry ) ) {
			return array(
				'enabled' => 'no',
				'text'    => '',
			);
		}
		return array(
			'enabled' => (string) $entry['enabled'],
			'text'    => (string) $entry['text'],
		);
	}
}
