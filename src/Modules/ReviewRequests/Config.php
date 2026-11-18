<?php
/**
 * ReviewRequests configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewRequests;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads option `motored_review_requests` (autoload=false). Default text is es_AR (voseo), plain text.
 */
final class Config {

	public const OPTION_KEY = 'review_requests';

	public const DEFAULTS = array(
		'enabled'          => 'no',
		'trigger_status'   => 'completed',
		'delay_days'       => '7',
		'store_review_url' => '',
		'max_products'     => '3',
		'text'             => "{¡Hola|Buenas} {first_name}! Gracias por tu compra en *{store_name}* (pedido *#{order_number}*). {¿Nos contás qué te pareció?|¿Nos dejás tu opinión?} Ayudás a otros clientes a elegir:\n{review_links}\n\nTambién podés calificar la tienda: {store_review_url}\n\nSi no querés recibir más mensajes: {optout_url}",
	);

	/**
	 * Whether review requests are active.
	 */
	public static function enabled(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'enabled' );
	}

	/**
	 * Order status (slug without "wc-") that schedules the request.
	 */
	public static function trigger_status(): string {
		$value = strtolower( trim( (string) self::text( 'trigger_status' ) ) );
		$value = (string) preg_replace( '/^wc-/', '', $value );
		return 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', $value ) ? $value : self::DEFAULTS['trigger_status'];
	}

	/**
	 * Days between the status change and the message (1-60).
	 */
	public static function delay_days(): int {
		return self::clamp( 'delay_days', 1, 60 );
	}

	/**
	 * Maximum products listed (1-5).
	 */
	public static function max_products(): int {
		return self::clamp( 'max_products', 1, 5 );
	}

	/**
	 * Store review URL (http/https only), '' when not set.
	 */
	public static function store_review_url(): string {
		$url = trim( (string) self::text( 'store_review_url' ) );
		return 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	/**
	 * Message template.
	 */
	public static function text_template(): string {
		$text = (string) self::text( 'text' );
		return '' !== trim( $text ) ? $text : self::DEFAULTS['text'];
	}

	/**
	 * Scalar option as string.
	 *
	 * @param string $key Key.
	 */
	private static function text( string $key ): string {
		$value = Options::get( self::OPTION_KEY, $key, self::DEFAULTS[ $key ] );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Integer within bounds (default when invalid).
	 *
	 * @param string $key Key.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 */
	private static function clamp( string $key, int $min, int $max ): int {
		$value = (int) self::text( $key );
		return $value < $min ? (int) self::DEFAULTS[ $key ] : min( $max, $value );
	}
}
