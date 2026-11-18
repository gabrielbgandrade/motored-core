<?php
/**
 * Registry of sections for the "Motored" settings tab.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Lets modules add sections to WooCommerce > Settings > Motored.
 *
 * Modules call SectionRegistry::add() from Module::register(), and only when
 * Context::is_admin() is true. Labels are callables so translations are resolved
 * lazily (after init), never while hooks are being registered.
 *
 * A section provides either a `settings` callable returning WooCommerce settings
 * fields (use ids like "motored_{module}[key]" with 'autoload' => false), or a
 * `render` callable that prints read-only HTML (the Save button is hidden).
 */
final class SectionRegistry {

	/**
	 * Registered sections keyed by id ('' = default section).
	 *
	 * @var array<string,array{label:callable,settings:?callable,render:?callable,priority:int}>
	 */
	private static array $sections = array();

	/**
	 * Adds (or replaces) a section.
	 *
	 * @param string        $id       Section id: '' for the default one, otherwise [a-z0-9_-]+.
	 * @param callable      $label    Returns the translated label.
	 * @param callable|null $settings Returns WooCommerce settings fields.
	 * @param callable|null $render   Prints read-only content instead of fields.
	 * @param int           $priority Sort order (lower first).
	 * @throws \InvalidArgumentException On an invalid id.
	 */
	public static function add( string $id, callable $label, ?callable $settings = null, ?callable $render = null, int $priority = 10 ): void {
		if ( '' !== $id && 1 !== preg_match( '/^[a-z0-9_-]+$/', $id ) ) {
			throw new \InvalidArgumentException( 'Invalid section id.' );
		}
		self::$sections[ $id ] = array(
			'label'    => $label,
			'settings' => $settings,
			'render'   => $render,
			'priority' => $priority,
		);
	}

	/**
	 * A single section, or null.
	 *
	 * @param string $id Section id.
	 * @return array{label:callable,settings:?callable,render:?callable,priority:int}|null
	 */
	public static function get( string $id ): ?array {
		return self::$sections[ $id ] ?? null;
	}

	/**
	 * All sections sorted by priority (stable for equal priorities).
	 *
	 * @return array<string,array{label:callable,settings:?callable,render:?callable,priority:int}>
	 */
	public static function all(): array {
		$sections = self::$sections;
		$order    = array_flip( array_map( 'strval', array_keys( $sections ) ) );
		uksort(
			$sections,
			static function ( $a, $b ) use ( $sections, $order ): int {
				$by_priority = $sections[ $a ]['priority'] <=> $sections[ $b ]['priority'];
				return 0 !== $by_priority ? $by_priority : $order[ (string) $a ] <=> $order[ (string) $b ];
			}
		);
		return $sections;
	}

	/**
	 * Clears the registry (tests).
	 */
	public static function reset(): void {
		self::$sections = array();
	}
}
