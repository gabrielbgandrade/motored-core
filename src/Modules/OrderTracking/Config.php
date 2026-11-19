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
		'send_delay'    => '3',
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
	 * Delay (seconds) before the dedicated messages go out, so a "completed" message sent in the same
	 * admin click can carry the code first. Minutes 0-60 (default 3).
	 */
	public static function send_delay_seconds(): int {
		$value = Options::get( self::OPTION_KEY, 'send_delay', self::DEFAULTS['send_delay'] );
		$min   = is_scalar( $value ) && '' !== (string) $value ? (int) $value : (int) self::DEFAULTS['send_delay'];
		return max( 0, min( 60, $min ) ) * 60;
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
			$id = (string) preg_replace( '/[^a-z0-9_-]/', '', '' !== $id ? $id : self::slugify( $name ) );
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
	 * Lowercase ASCII slug of a carrier name (a-z, 0-9, "-"; max 30). Pure; never empty.
	 *
	 * @param string $name Carrier name.
	 */
	public static function slugify( string $name ): string {
		$slug = strtr(
			strtolower( $name ),
			array(
				'á' => 'a',
				'é' => 'e',
				'í' => 'i',
				'ó' => 'o',
				'ú' => 'u',
				'ü' => 'u',
				'ñ' => 'n',
			)
		);
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $slug ), '-' );
		$slug = trim( substr( $slug, 0, 30 ), '-' );
		return '' !== $slug ? $slug : 'carrier';
	}

	/**
	 * Save-time processing of the posted carrier table. The id is generated from the name and never taken from the
	 * post unless it is an already stored id (read-only after the first save). A row whose URL template is not https
	 * with {code} is NOT saved (the previous stored version, if any, is kept) and an error names it.
	 *
	 * @param mixed                                                     $raw    Posted rows.
	 * @param list<array{id:string,name:string,url:string,active:bool}> $stored Current carriers.
	 * @return array{rows:list<array{id:string,name:string,url:string,active:bool}>,rejected:list<string>}
	 */
	public static function prepare_carriers( $raw, array $stored ): array {
		$known = array();
		foreach ( $stored as $carrier ) {
			$known[ $carrier['id'] ] = $carrier;
		}
		$rows     = array();
		$rejected = array();
		$seen     = array( self::OTHER => true );
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) || count( $rows ) >= self::MAX_CARRIERS ) {
				continue;
			}
			$name = self::text_field( $row['name'] ?? '' );
			$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 60 ) : substr( $name, 0, 60 );
			if ( '' === $name ) {
				continue;
			}
			$posted = strtolower( self::text_field( $row['id'] ?? '' ) );
			$id     = isset( $known[ $posted ] ) && ! isset( $seen[ $posted ] ) ? $posted : self::unique_id( self::slugify( $name ), $seen );
			$url    = TrackingLink::sanitize_template( self::text_field( $row['url'] ?? '' ) );
			if ( '' === $url ) {
				$rejected[] = $name;
				if ( isset( $known[ $id ] ) ) {
					$seen[ $id ] = true;
					$rows[]      = $known[ $id ];
				}
				continue;
			}
			$seen[ $id ] = true;
			$active      = $row['active'] ?? '';
			$rows[]      = array(
				'id'     => $id,
				'name'   => $name,
				'url'    => $url,
				'active' => true === $active || 'yes' === $active || '1' === $active || 1 === $active,
			);
		}
		return array(
			'rows'     => $rows,
			'rejected' => $rejected,
		);
	}

	/**
	 * First free id: slug, slug-2, slug-3...
	 *
	 * @param string             $slug Base slug.
	 * @param array<string,bool> $seen Ids already taken.
	 */
	private static function unique_id( string $slug, array $seen ): string {
		$id = $slug;
		for ( $n = 2; isset( $seen[ $id ] ); $n++ ) {
			$id = substr( $slug, 0, 27 ) . '-' . $n;
		}
		return $id;
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
