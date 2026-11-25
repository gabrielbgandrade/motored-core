<?php
/**
 * Settings accessors for the Seo module (option motored_seo, autoload=false).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Strongly typed accessors for lightweight core SEO behavior.
 */
final class Config {

	public const OPTION_KEY = 'seo';

	/**
	 * Option defaults.
	 *
	 * @var array<string,string>
	 */
	public const DEFAULTS = array(
		'enabled'          => 'yes',
		'noindex_paged'    => 'no',
		'noindex_search'   => 'yes',
		'og_enabled'       => 'yes',
		'schema_enabled'   => 'yes',
		'default_og_image' => '',
	);

	/**
	 * Whether the Seo module is enabled.
	 */
	public static function enabled(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'enabled', 'yes' );
	}

	/**
	 * Whether paginated archive pages (?paged=2+ / /page/2/) emit noindex, follow.
	 */
	public static function noindex_paged(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'noindex_paged', 'no' );
	}

	/**
	 * Whether WordPress search result pages emit noindex, follow.
	 */
	public static function noindex_search(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'noindex_search', 'yes' );
	}

	/**
	 * Whether Open Graph and Twitter meta tags are emitted on owned/configured pages.
	 */
	public static function og_enabled(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'og_enabled', 'yes' );
	}

	/**
	 * Whether JSON-LD structured data is emitted on owned/configured pages.
	 */
	public static function schema_enabled(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'schema_enabled', 'yes' );
	}

	/**
	 * Fallback Open Graph image URL when a page does not supply its own image.
	 */
	public static function default_og_image(): string {
		return esc_url_raw( trim( (string) Options::get( self::OPTION_KEY, 'default_og_image', '' ) ) );
	}
}
