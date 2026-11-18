<?php
/**
 * Pure message template renderer for OrderNotifications.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\OrderNotifications;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces order placeholders and guarantees plain text (WhatsApp formatting allowed, never HTML).
 */
final class Renderer {

	public const MAX_ITEMS = 5;

	/**
	 * Supported template placeholders.
	 *
	 * @var list<string>
	 */
	public const PLACEHOLDERS = array(
		'{order_number}',
		'{first_name}',
		'{order_total}',
		'{order_items}',
		'{order_url}',
		'{store_name}',
		'{customer_note}',
		'{cart_items}',
		'{cart_total}',
		'{recovery_url}',
		'{optout_url}',
		'{pay_url}',
	);

	/**
	 * Renders a template with the provided values. Returns '' if template or output is empty.
	 *
	 * @param string              $template Template string.
	 * @param array<string,mixed> $vars     Keyed without braces (e.g. 'order_number' => '1001').
	 */
	public static function render( string $template, array $vars ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}

		$replacements = array(
			'{order_number}'  => self::strip_html( self::string_var( $vars, 'order_number' ) ),
			'{first_name}'    => self::strip_html( self::string_var( $vars, 'first_name' ) ),
			'{order_total}'   => self::format_price( self::string_var( $vars, 'order_total' ) ),
			'{order_items}'   => self::strip_html( self::string_var( $vars, 'order_items' ) ),
			'{order_url}'     => self::clean_url( self::string_var( $vars, 'order_url' ) ),
			'{store_name}'    => self::strip_html( self::string_var( $vars, 'store_name' ) ),
			'{customer_note}' => self::strip_html( self::string_var( $vars, 'customer_note' ) ),
			'{cart_items}'    => self::strip_html( self::string_var( $vars, 'cart_items' ) ),
			'{cart_total}'    => self::format_price( self::string_var( $vars, 'cart_total' ) ),
			'{recovery_url}'  => self::clean_url( self::string_var( $vars, 'recovery_url' ) ),
			'{optout_url}'    => self::clean_url( self::string_var( $vars, 'optout_url' ) ),
			'{pay_url}'       => self::clean_url( self::string_var( $vars, 'pay_url' ) ),
		);

		$rendered = strtr( $template, $replacements );
		// Collapse 3+ consecutive newlines (e.g. when an optional block is empty) to at most 2.
		$collapsed = preg_replace( "/\r\n|\r/", "\n", $rendered );
		$collapsed = is_string( $collapsed ) ? preg_replace( "/\n{3,}/", "\n\n", $collapsed ) : $rendered;
		$result    = trim( is_string( $collapsed ) ? $collapsed : $rendered );

		return $result;
	}

	/**
	 * Formats line items into up to $limit lines plus "y N más" when truncated.
	 *
	 * @param list<array{name:string,qty:int}> $items Items list.
	 * @param int                              $limit Maximum items shown before summarizing.
	 */
	public static function format_items( array $items, int $limit = self::MAX_ITEMS ): string {
		$limit = max( 1, $limit );
		$clean = array();

		foreach ( $items as $item ) {
			$name = self::strip_html( (string) ( $item['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$qty     = max( 1, (int) ( $item['qty'] ?? 1 ) );
			$clean[] = '- ' . $qty . 'x ' . $name;
		}

		$total = count( $clean );
		if ( 0 === $total ) {
			return '';
		}

		$lines = array_slice( $clean, 0, $limit );
		if ( $total > $limit ) {
			$remaining = $total - $limit;
			$lines[]   = 'y ' . $remaining . ' más';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Converts WooCommerce wc_price() HTML output into plain text (e.g. "$12.345,67").
	 *
	 * @param string $wc_price_html Raw price string or HTML from wc_price().
	 */
	public static function format_price( string $wc_price_html ): string {
		$plain = self::strip_html( $wc_price_html );
		$plain = preg_replace( '/[ \t]+/', ' ', $plain );
		return is_string( $plain ) ? trim( $plain ) : '';
	}

	/**
	 * Strips HTML tags and decodes entities to plain UTF-8 text while keeping newlines and WhatsApp *bold*.
	 *
	 * @param string $value Input text.
	 */
	public static function strip_html( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$stripped = wp_strip_all_tags( $value );
		} else {
			$without_scripts = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $value );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback for isolated unit tests without WordPress.
			$stripped = strip_tags( is_string( $without_scripts ) ? $without_scripts : $value );
		}
		$decoded = html_entity_decode( $stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$decoded = str_replace( "\xc2\xa0", ' ', $decoded );
		return trim( $decoded );
	}

	/**
	 * Reads a string variable from the render context.
	 *
	 * @param array<string,mixed> $vars Variables.
	 * @param string              $key  Key.
	 */
	private static function string_var( array $vars, string $key ): string {
		if ( ! isset( $vars[ $key ] ) || ! is_scalar( $vars[ $key ] ) ) {
			return '';
		}
		return (string) $vars[ $key ];
	}

	/**
	 * Keeps URL safe for plain text WhatsApp messages (no HTML tags or encoded &amp;).
	 *
	 * @param string $url URL.
	 */
	private static function clean_url( string $url ): string {
		$url = self::strip_html( $url );
		return trim( $url );
	}
}
