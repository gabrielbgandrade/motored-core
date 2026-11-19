<?php
/**
 * OrderTracking module configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderTracking;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads option `motored_order_tracking` (autoload=false). The carrier list ships EMPTY: the owner
 * fills the URL templates; "other / manual link" always exists and is not stored.
 */
final class Config {

	public const OPTION_KEY   = 'order_tracking';
	public const OTHER        = 'other';
	public const MAX_CARRIERS = 20;
	public const STEP_ADDED   = 'added';
	public const STEP_UPDATED = 'updated';

	public const DEFAULTS = array(
		'carriers'      => array(),
		'send_whatsapp' => 'yes',
		'send_email'    => 'yes',
		'show_customer' => 'yes',
		'text_added'    => "{¡Hola|¡Buenas} {first_name}! Ya podés seguir tu pedido *#{order_number}* de *{store_name}*.\n{tracking_block}\n\nEl detalle del pedido está {acá|en este link}:\n{order_url}",
		'text_updated'  => "{Hola|Buenas} {first_name}, actualizamos el seguimiento de tu pedido *#{order_number}* de *{store_name}*.\n{tracking_block}\n\nEl detalle del pedido está {acá|en este link}:\n{order_url}",
	);

	/**
	 * Whether the dedicated WhatsApp message is on.
	 */
	public static function send_whatsapp(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'send_whatsapp' );
	}

	/**
	 * Whether the dedicated e-mail is on.
	 */
	public static function send_email(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'send_email' );
	}

	/**
	 * Whether the customer order page / customer e-mails show the tracking.
	 */
	public static function show_customer(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'show_customer' );
	}

	/**
	 * Template for a dedicated WhatsApp step (falls back to the default when empty).
	 *
	 * @param string $step self::STEP_ADDED or self::STEP_UPDATED.
	 */
	public static function text( string $step ): string {
		$key   = self::STEP_UPDATED === $step ? 'text_updated' : 'text_added';
		$value = Options::get( self::OPTION_KEY, $key, self::DEFAULTS[ $key ] );
		return is_string( $value ) && '' !== trim( $value ) ? $value : self::DEFAULTS[ $key ];
	}

	/**
	 * Default template of a step.
	 *
	 * @param string $step Step.
	 */
	public static function default_text( string $step ): string {
		return self::DEFAULTS[ self::STEP_UPDATED === $step ? 'text_updated' : 'text_added' ];
	}

	/**
	 * Configured carriers (sanitized, never more than MAX_CARRIERS).
	 *
	 * @return list<array{id:string,name:string,url:string,active:bool}>
	 */
	public static function carriers(): array {
		return self::sanitize_carriers( Options::get( self::OPTION_KEY, 'carriers', array() ) );
	}

	/**
	 * Finds a configured carrier.
	 *
	 * @param string $id Carrier id.
	 * @return array{id:string,name:string,url:string,active:bool}|null
	 */
	public static function carrier( string $id ): ?array {
		foreach ( self::carriers() as $carrier ) {
			if ( $carrier['id'] === $id ) {
				return $carrier;
			}
		}
		return null;
	}

	/**
	 * Sanitizes a raw carrier list (form or stored). Invalid rows and duplicates are dropped; the id is
	 * derived from the name when blank; a template without {code} or without http(s) is emptied.
	 *
	 * @param mixed $raw Raw list.
	 * @return list<array{id:string,name:string,url:string,active:bool}>
	 */
	public static function sanitize_carriers( $raw ): array {
		$clean = array();
		$seen  = array( self::OTHER => true );
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) || count( $clean ) >= self::MAX_CARRIERS ) {
				continue;
			}
			$name = self::text_field( $row['name'] ?? '' );
			$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 60 ) : substr( $name, 0, 60 );
			if ( '' === $name ) {
				continue;
			}
			$id = strtolower( self::text_field( $row['id'] ?? '' ) );
			$id = (string) preg_replace( '/[^a-z0-9_-]/', '', '' !== $id ? $id : str_replace( ' ', '-', strtolower( $name ) ) );
			$id = substr( $id, 0, 30 );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$active      = $row['active'] ?? 'yes';
			$clean[]     = array(
				'id'     => $id,
				'name'   => $name,
				'url'    => TrackingLink::sanitize_template( self::text_field( $row['url'] ?? '' ) ),
				'active' => true === $active || 'yes' === $active || '1' === $active || 1 === $active,
			);
		}
		return $clean;
	}

	/**
	 * Scalar to trimmed single-line text.
	 *
	 * @param mixed $value Value.
	 */
	private static function text_field( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		return trim( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : $value );
	}
}
