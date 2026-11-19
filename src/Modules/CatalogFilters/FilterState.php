<?php
/**
 * Pure parser and native WooCommerce URL builder for active catalog filters.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Modules\Compatibility\TireSpecs;

defined( 'ABSPATH' ) || exit;

/**
 * Reads whitelisted query parameters, keeps every term slug strictly as a string (never int),
 * removes `paged` on filter mutations and preserves unrelated query parameters.
 */
final class FilterState {

	/**
	 * Active attribute slugs keyed by unprefixed attribute slug (e.g. 'ancho' => ['120', '4-10']).
	 *
	 * @var array<string,list<string>>
	 */
	private array $attributes;

	/**
	 * Active product_brand term slugs.
	 *
	 * @var list<string>
	 */
	private array $brands;

	/**
	 * Active motorcycle version slug (?moto=).
	 *
	 * @var string
	 */
	private string $moto;

	/**
	 * Active motorcycle position filter (?pos=front|rear|kit).
	 *
	 * @var string
	 */
	private string $pos;

	/**
	 * Whether ?oferta=1 is active.
	 *
	 * @var bool
	 */
	private bool $on_sale;

	/**
	 * Unknown/unrelated query parameters to preserve across filter toggles (excluding `paged`).
	 *
	 * @var array<string,string>
	 */
	private array $extra;

	/**
	 * Constructor.
	 *
	 * @param array<string,list<string>> $attributes Active pa_* attribute slugs.
	 * @param array<int,string>          $brands     Active product_brand slugs.
	 * @param string                     $moto       Active ?moto= slug.
	 * @param string                     $pos        Active ?pos= key.
	 * @param bool                       $on_sale    Whether ?oferta=1 is set.
	 * @param array<string,string>       $extra      Unrelated scalar query params to preserve.
	 */
	public function __construct(
		array $attributes = array(),
		array $brands = array(),
		string $moto = '',
		string $pos = '',
		bool $on_sale = false,
		array $extra = array()
	) {
		$this->attributes = $attributes;
		$this->brands     = array_values( $brands );
		$this->moto       = $moto;
		$this->pos        = $pos;
		$this->on_sale    = $on_sale;
		$this->extra      = $extra;
	}

	/**
	 * Builds a FilterState from the current HTTP query string (or an injected array for tests).
	 *
	 * @param array<string,mixed>|null $query Query parameters (defaults to $_GET).
	 */
	public static function from_query( ?array $query = null ): self {
		if ( null === $query ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public catalog GET filter parameters.
			$query = $_GET;
		}

		$known_keys = array( 'product_brand', 'oferta', 'moto', 'pos', 'paged', 'product-page' );
		$attrs      = array();

		foreach ( Config::ATTRIBUTE_SLUGS as $attr_slug ) {
			$fk           = 'filter_' . $attr_slug;
			$qk           = 'query_type_' . $attr_slug;
			$known_keys[] = $fk;
			$known_keys[] = $qk;

			if ( isset( $query[ $fk ] ) && is_scalar( $query[ $fk ] ) ) {
				$terms = self::parse_slug_list( (string) wp_unslash( (string) $query[ $fk ] ) );
				if ( ! empty( $terms ) ) {
					$attrs[ $attr_slug ] = $terms;
				}
			}
		}

		$brands = array();
		if ( isset( $query['product_brand'] ) && is_scalar( $query['product_brand'] ) ) {
			$brands = self::parse_slug_list( (string) wp_unslash( (string) $query['product_brand'] ) );
		}

		$moto = '';
		if ( isset( $query['moto'] ) && is_scalar( $query['moto'] ) ) {
			$moto = sanitize_title( (string) wp_unslash( (string) $query['moto'] ) );
		}

		$pos = '';
		if ( isset( $query['pos'] ) && is_scalar( $query['pos'] ) ) {
			$raw_pos = sanitize_key( (string) wp_unslash( (string) $query['pos'] ) );
			if ( in_array( $raw_pos, array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR, TireSpecs::POS_KIT ), true ) ) {
				$pos = $raw_pos;
			}
		}

		$on_sale = isset( $query['oferta'] ) && is_scalar( $query['oferta'] )
			&& in_array( strtolower( trim( (string) wp_unslash( (string) $query['oferta'] ) ) ), array( '1', 'true', 'yes' ), true );

		$extra = array();
		foreach ( $query as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( '' === $key || in_array( $key, $known_keys, true ) || ! is_scalar( $v ) ) {
				continue;
			}
			$val = sanitize_text_field( (string) wp_unslash( (string) $v ) );
			if ( '' !== $val ) {
				$extra[ $key ] = $val;
			}
		}

		return new self( $attrs, $brands, $moto, $pos, $on_sale, $extra );
	}

	/**
	 * Parses a comma-separated list of term slugs, preserving numeric strings as strings.
	 *
	 * @param string $raw Raw query value.
	 * @return list<string>
	 */
	public static function parse_slug_list( string $raw ): array {
		$parts = explode( ',', $raw );
		$out   = array();
		$seen  = array();
		foreach ( $parts as $part ) {
			$slug = self::sanitize_term_slug( $part );
			if ( '' !== $slug && ! isset( $seen[ $slug ] ) ) {
				$seen[ $slug ] = true;
				$out[]         = $slug;
			}
		}
		return $out;
	}

	/**
	 * Sanitizes a single attribute/brand term slug while keeping strings like "4-10" or "120" strictly string.
	 *
	 * @param string $raw Raw slug.
	 */
	public static function sanitize_term_slug( string $raw ): string {
		$trimmed = trim( $raw );
		if ( '' === $trimmed ) {
			return '';
		}
		return (string) sanitize_title( str_replace( '.', '-', $trimmed ) );
	}

	/**
	 * Returns all active attribute filters.
	 *
	 * @return array<string,list<string>>
	 */
	public function attributes(): array {
		return $this->attributes;
	}

	/**
	 * Returns active term slugs for one attribute.
	 *
	 * @param string $attr_slug Unprefixed attribute slug.
	 * @return list<string>
	 */
	public function attribute_terms( string $attr_slug ): array {
		return $this->attributes[ $attr_slug ] ?? array();
	}

	/**
	 * Returns active product_brand slugs.
	 *
	 * @return list<string>
	 */
	public function brands(): array {
		return $this->brands;
	}

	/**
	 * Returns active motorcycle slug (?moto=).
	 */
	public function moto(): string {
		return $this->moto;
	}

	/**
	 * Returns active motorcycle position (?pos=).
	 */
	public function pos(): string {
		return $this->pos;
	}

	/**
	 * Whether ?oferta=1 is active.
	 */
	public function on_sale(): bool {
		return $this->on_sale;
	}

	/**
	 * Preserved unrelated query parameters.
	 *
	 * @return array<string,string>
	 */
	public function extra(): array {
		return $this->extra;
	}

	/**
	 * Whether any filter controlled by CatalogFilters or Compatibility is active.
	 */
	public function has_any_filter(): bool {
		return ! empty( $this->attributes )
			|| ! empty( $this->brands )
			|| $this->on_sale
			|| '' !== $this->moto;
	}

	/**
	 * Whether any CatalogFilters-specific filter (attributes, brand, sale) is active.
	 */
	public function has_catalog_filter(): bool {
		return ! empty( $this->attributes )
			|| ! empty( $this->brands )
			|| $this->on_sale;
	}

	/**
	 * Checks if a specific facet value is currently active.
	 *
	 * @param string $facet Facet key (attribute slug, 'product_brand', 'oferta', or 'moto').
	 * @param string $slug  Term slug (ignored for 'oferta' / 'moto').
	 */
	public function is_active( string $facet, string $slug = '' ): bool {
		if ( 'oferta' === $facet ) {
			return $this->on_sale;
		}
		if ( 'moto' === $facet ) {
			return '' !== $this->moto;
		}
		if ( 'product_brand' === $facet ) {
			return in_array( $slug, $this->brands, true );
		}
		return in_array( $slug, $this->attributes[ $facet ] ?? array(), true );
	}

	/**
	 * Returns a new FilterState with a single facet option toggled (OR within group).
	 *
	 * @param string $facet Facet key (attribute slug, 'product_brand', 'oferta', or 'moto').
	 * @param string $slug  Term slug.
	 */
	public function with_toggled( string $facet, string $slug = '' ): self {
		$attrs   = $this->attributes;
		$brands  = $this->brands;
		$moto    = $this->moto;
		$pos     = $this->pos;
		$on_sale = $this->on_sale;

		if ( 'oferta' === $facet ) {
			$on_sale = ! $on_sale;
		} elseif ( 'moto' === $facet ) {
			$moto = '';
			$pos  = '';
		} elseif ( 'product_brand' === $facet ) {
			$clean  = self::sanitize_term_slug( $slug );
			$brands = self::toggle_in_list( $brands, $clean );
		} elseif ( in_array( $facet, Config::ATTRIBUTE_SLUGS, true ) ) {
			$clean   = self::sanitize_term_slug( $slug );
			$updated = self::toggle_in_list( $attrs[ $facet ] ?? array(), $clean );
			if ( empty( $updated ) ) {
				unset( $attrs[ $facet ] );
			} else {
				$attrs[ $facet ] = $updated;
			}
		}

		return new self( $attrs, $brands, $moto, $pos, $on_sale, $this->extra );
	}

	/**
	 * Returns a new FilterState with all CatalogFilters + Compatibility filters cleared (preserving extra params).
	 */
	public function cleared(): self {
		return new self( array(), array(), '', '', false, $this->extra );
	}

	/**
	 * Builds the query parameter map for this state (native Woo format, no `paged`).
	 *
	 * @return array<string,string>
	 */
	public function to_query_args(): array {
		$args = $this->extra;

		if ( '' !== $this->moto ) {
			$args['moto'] = $this->moto;
			if ( '' !== $this->pos ) {
				$args['pos'] = $this->pos;
			}
		}

		foreach ( Config::ATTRIBUTE_SLUGS as $attr_slug ) {
			if ( ! empty( $this->attributes[ $attr_slug ] ) ) {
				$args[ 'filter_' . $attr_slug ]     = implode( ',', $this->attributes[ $attr_slug ] );
				$args[ 'query_type_' . $attr_slug ] = 'or';
			}
		}

		if ( ! empty( $this->brands ) ) {
			$args['product_brand'] = implode( ',', $this->brands );
		}

		if ( $this->on_sale ) {
			$args['oferta'] = '1';
		}

		return $args;
	}

	/**
	 * Builds a full URL from a base path/URL, stripping pagination (/page/N/ and ?paged=).
	 *
	 * @param string $base_url Base shop/archive URL (without query string or with existing query).
	 */
	public function build_url( string $base_url ): string {
		$clean_base = self::strip_query_and_pagination( $base_url );
		$args       = $this->to_query_args();
		if ( empty( $args ) ) {
			return $clean_base;
		}
		return add_query_arg( $args, $clean_base );
	}

	/**
	 * Convenience helper: URL with one option toggled.
	 *
	 * @param string $base_url Base URL.
	 * @param string $facet    Facet key.
	 * @param string $slug     Term slug.
	 */
	public function toggle_url( string $base_url, string $facet, string $slug = '' ): string {
		return $this->with_toggled( $facet, $slug )->build_url( $base_url );
	}

	/**
	 * Convenience helper: URL with all filters cleared.
	 *
	 * @param string $base_url Base URL.
	 */
	public function clear_url( string $base_url ): string {
		return $this->cleared()->build_url( $base_url );
	}

	/**
	 * Strips query string and `/page/N/` suffix from a base archive URL.
	 *
	 * @param string $url Raw URL.
	 */
	public static function strip_query_and_pagination( string $url ): string {
		$no_query = (string) strtok( $url, '?' );
		$stripped = (string) preg_replace( '#/page/\d+/?$#', '/', $no_query );
		return '' !== $stripped ? $stripped : '/';
	}

	/**
	 * Toggles a string value inside a deduplicated list.
	 *
	 * @param array<int,string> $items Current list.
	 * @param string            $value Value to toggle.
	 * @return list<string>
	 */
	private static function toggle_in_list( array $items, string $value ): array {
		if ( '' === $value ) {
			return array_values( $items );
		}
		if ( in_array( $value, $items, true ) ) {
			return array_values( array_filter( $items, static fn( string $v ): bool => $v !== $value ) );
		}
		$out   = array_values( $items );
		$out[] = $value;
		return $out;
	}
}
