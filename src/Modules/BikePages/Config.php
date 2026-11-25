<?php
/**
 * Settings accessors for the BikePages module (option motored_bike_pages, autoload=false).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessors. Pages are automatic by default (a published motorcycle creates/updates its page);
 * every automatic behaviour has a switch so the owner can pause it without touching code.
 */
final class Config {

	public const OPTION_KEY = 'bike_pages';

	/**
	 * Option defaults.
	 *
	 * @var array<string,string>
	 */
	public const DEFAULTS = array(
		'enabled'               => 'yes',
		'auto_create'           => 'yes',
		'pages_for_drafts'      => 'no',
		'base_slug'             => 'cubiertas-para',
		'brand_base'            => 'marca',
		'index_min_products'    => '1',
		'faq_enabled'           => 'yes',
		'notify_enabled'        => 'yes',
		'notify_retention_days' => '180',
		'notify_email'          => '',
		'dimension_pages'       => 'no',
	);

	/**
	 * Whether the module renders pages and keeps them in sync.
	 */
	public static function enabled(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'enabled', 'yes' );
	}

	/**
	 * Whether publishing a motorcycle creates/updates its page automatically.
	 */
	public static function auto_create(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'auto_create', 'yes' );
	}

	/**
	 * Whether draft (unverified) versions also get a noindex page for preview.
	 */
	public static function pages_for_drafts(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'pages_for_drafts', 'no' );
	}

	/**
	 * First path segment of the motorcycle page URL.
	 */
	public static function base_slug(): string {
		$slug = sanitize_title( (string) Options::get( self::OPTION_KEY, 'base_slug', 'cubiertas-para' ) );
		return '' !== $slug ? $slug : 'cubiertas-para';
	}

	/**
	 * First path segment of the brand hub URL.
	 */
	public static function brand_base(): string {
		$slug = sanitize_title( (string) Options::get( self::OPTION_KEY, 'brand_base', 'marca' ) );
		return '' !== $slug ? $slug : 'marca';
	}

	/**
	 * Minimum compatible products before a page becomes indexable (0 = index even when empty).
	 */
	public static function index_min_products(): int {
		return max( 0, min( 20, (int) Options::get( self::OPTION_KEY, 'index_min_products', '1' ) ) );
	}

	/**
	 * Whether the generated FAQ block is rendered.
	 */
	public static function faq_enabled(): bool {
		return 'yes' === (string) Options::get( self::OPTION_KEY, 'faq_enabled', 'yes' );
	}

	/**
	 * Whether the "notify me when in stock" block is available.
	 */
	public static function notify_enabled(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'notify_enabled', 'yes' );
	}

	/**
	 * Days a demand request is kept before the purge job removes it.
	 */
	public static function retention_days(): int {
		return max( 7, min( 730, (int) Options::get( self::OPTION_KEY, 'notify_retention_days', '180' ) ) );
	}

	/**
	 * Where internal demand alerts are sent (falls back to the site admin e-mail).
	 */
	public static function notify_email(): string {
		$email = sanitize_email( (string) Options::get( self::OPTION_KEY, 'notify_email', '' ) );
		if ( '' !== $email ) {
			return $email;
		}
		$admin = sanitize_email( (string) get_option( 'admin_email', '' ) );
		return $admin;
	}

	/**
	 * Whether tire-size landing pages (stage 6) are enabled.
	 */
	public static function dimension_pages(): bool {
		return self::enabled() && 'yes' === (string) Options::get( self::OPTION_KEY, 'dimension_pages', 'no' );
	}
}
