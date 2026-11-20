<?php
/**
 * Accent/spacing-insensitive search text helpers.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers shared by server-side search haystacks and mirrored in the storefront JS
 * (`motoredText.norm()` / `motoredText.compact()`), so "cb 500", "CB-500" and "cb500" match.
 */
final class SearchText {

	/**
	 * Fallback accent map used when WordPress' remove_accents() is unavailable (isolated unit tests).
	 */
	private const ACCENTS = array(
		'á' => 'a',
		'à' => 'a',
		'â' => 'a',
		'ä' => 'a',
		'ã' => 'a',
		'é' => 'e',
		'è' => 'e',
		'ê' => 'e',
		'ë' => 'e',
		'í' => 'i',
		'ì' => 'i',
		'î' => 'i',
		'ï' => 'i',
		'ó' => 'o',
		'ò' => 'o',
		'ô' => 'o',
		'ö' => 'o',
		'õ' => 'o',
		'ú' => 'u',
		'ù' => 'u',
		'û' => 'u',
		'ü' => 'u',
		'ñ' => 'n',
		'ç' => 'c',
	);

	/**
	 * Lowercases, strips accents and collapses whitespace.
	 *
	 * @param string $raw Raw text.
	 */
	public static function normalize( string $raw ): string {
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $raw ), 'UTF-8' ) : strtolower( trim( $raw ) );
		$plain = strtr( $lower, self::ACCENTS );
		if ( function_exists( 'remove_accents' ) ) {
			$plain = remove_accents( $plain );
		}
		return (string) preg_replace( '/\s+/', ' ', $plain );
	}

	/**
	 * Normalized text without spaces, hyphens, dots, slashes or underscores ("CB-500 F" -> "cb500f").
	 *
	 * @param string $raw Raw text.
	 */
	public static function compact( string $raw ): string {
		return (string) preg_replace( '/[\s\-\._\/]+/', '', self::normalize( $raw ) );
	}

	/**
	 * Haystack containing the normalized text plus its compact form, so both spellings match.
	 *
	 * @param string $raw Raw text.
	 */
	public static function haystack( string $raw ): string {
		$norm    = self::normalize( $raw );
		$compact = self::compact( $raw );
		return '' === $compact || $compact === $norm ? $norm : $norm . ' ' . $compact;
	}

	/**
	 * Whether every whitespace token of the query appears in the haystack (normalized or compact).
	 *
	 * @param string $haystack Output of haystack().
	 * @param string $query    Raw user query.
	 */
	public static function matches( string $haystack, string $query ): bool {
		$q = self::normalize( $query );
		if ( '' === $q ) {
			return true;
		}
		$whole = self::compact( $query );
		if ( '' !== $whole && str_contains( $haystack, $whole ) ) {
			return true;
		}
		foreach ( explode( ' ', $q ) as $tok ) {
			if ( '' !== $tok && ! str_contains( $haystack, $tok ) && ! str_contains( $haystack, self::compact( $tok ) ) ) {
				return false;
			}
		}
		return true;
	}
}
