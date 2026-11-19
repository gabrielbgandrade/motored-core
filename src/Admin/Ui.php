<?php
/**
 * Shared admin markup helpers (classes of assets/admin/motored-admin.css).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Every admin table, box and field goes through these helpers or uses the same classes:
 * ad hoc per-module CSS and inline styles are not allowed (AGENTS.md).
 */
final class Ui {

	public const TABLE = 'motored-table';
	public const BOX   = 'motored-box';
	public const FIELD = 'motored-field';

	/**
	 * Prints the opening of a scrollable table. Close it with table_close().
	 *
	 * @param bool $compact Two-column read-only table (no minimum width).
	 */
	public static function table_open( bool $compact = false ): void {
		echo '<div class="motored-table-wrap"><table class="widefat striped ' . esc_attr( self::TABLE . ( $compact ? ' motored-table--compact' : '' ) ) . '">';
	}

	/**
	 * Prints the end of table_open().
	 */
	public static function table_close(): void {
		echo '</table></div>';
	}

	/**
	 * Prints the header row. Width classes: xs, sm, md, lg, grow ('' = automatic).
	 *
	 * @param array<string,string> $columns Translated label => width class.
	 */
	public static function table_head( array $columns ): void {
		echo '<thead><tr>';
		foreach ( $columns as $label => $width ) {
			$class = in_array( $width, array( 'xs', 'sm', 'md', 'lg', 'grow' ), true ) ? ' class="motored-col-' . esc_attr( $width ) . '"' : '';
			echo '<th' . $class . '>' . esc_html( (string) $label ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Class built from a fixed list.
		}
		echo '</tr></thead>';
	}

	/**
	 * Prints the opening of a box. Close it with box_close().
	 *
	 * @param bool $flush Without border/padding (inside a WordPress meta box).
	 */
	public static function box_open( bool $flush = false ): void {
		echo '<div class="' . esc_attr( self::BOX . ( $flush ? ' motored-box--flush' : '' ) ) . '">';
	}

	/**
	 * Prints the end of box_open().
	 */
	public static function box_close(): void {
		echo '</div>';
	}

	/**
	 * Prints label + control + description.
	 *
	 * @param string $label   Already translated label (escaped here).
	 * @param string $control_id Control id.
	 * @param string $control Control HTML, already escaped by the caller.
	 * @param string $desc    Already translated description (escaped here), optional.
	 */
	public static function field( string $label, string $control_id, string $control, string $desc = '' ): void {
		echo '<div class="' . esc_attr( self::FIELD ) . '"><label class="motored-field__label" for="' . esc_attr( $control_id ) . '">' . esc_html( $label ) . '</label>';
		echo '<div class="motored-field__control">' . $control . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		if ( '' !== $desc ) {
			echo '<p class="description motored-field__desc">' . esc_html( $desc ) . '</p>';
		}
		echo '</div>';
	}
}
