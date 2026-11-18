<?php
/**
 * "Motored" tab in WooCommerce > Settings.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Admin;

use Motored\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page. Only autoloaded from the woocommerce_get_settings_pages callback,
 * so it never loads outside WooCommerce settings screens.
 */
final class SettingsTab extends \WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'motored';
		$this->label = __( 'Motored', 'motored-core' );

		SectionRegistry::add(
			'',
			static fn(): string => __( 'General', 'motored-core' ),
			array( $this, 'general_settings' ),
			null,
			0
		);
		SectionRegistry::add(
			'status',
			static fn(): string => __( 'Status', 'motored-core' ),
			null,
			array( $this, 'render_status' ),
			1000
		);

		parent::__construct();
	}

	/**
	 * Sections shown as sub-navigation.
	 *
	 * @return array<string,string>
	 */
	protected function get_own_sections() {
		$sections = array();
		foreach ( SectionRegistry::all() as $id => $definition ) {
			$sections[ (string) $id ] = (string) ( $definition['label'] )();
		}
		return $sections;
	}

	/**
	 * Settings fields for any registered section.
	 *
	 * @param string $section_id Section id.
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_section_core( $section_id ) {
		$definition = SectionRegistry::get( (string) $section_id );
		if ( null === $definition || null === $definition['settings'] ) {
			return array();
		}
		return (array) ( $definition['settings'] )();
	}

	/**
	 * Prints the section: read-only sections render themselves, others use WooCommerce.
	 */
	public function output(): void {
		global $current_section;

		$definition = SectionRegistry::get( is_string( $current_section ) ? $current_section : '' );
		if ( null !== $definition && null !== $definition['render'] ) {
			// WooCommerce's own global flag, read by its settings view.
			$GLOBALS['hide_save_button'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			( $definition['render'] )();
			return;
		}
		parent::output();
	}

	/**
	 * Fields of the "General" section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function general_settings(): array {
		return array(
			array(
				'title' => __( 'General', 'motored-core' ),
				'type'  => 'title',
				'id'    => 'motored_general_title',
			),
			array(
				'title'    => __( 'Delete data on uninstall', 'motored-core' ),
				'desc'     => __( 'Remove all Motored options and cached data when the plugin is deleted.', 'motored-core' ),
				'id'       => 'motored_general[delete_data_on_uninstall]',
				'type'     => 'checkbox',
				'default'  => Plugin::GENERAL_DEFAULTS['delete_data_on_uninstall'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Log level', 'motored-core' ),
				'desc'     => __( 'Minimum severity written to WooCommerce > Status > Logs (source: motored-core).', 'motored-core' ),
				'id'       => 'motored_general[log_level]',
				'type'     => 'select',
				'default'  => Plugin::GENERAL_DEFAULTS['log_level'],
				'options'  => array(
					'error'   => __( 'Errors only', 'motored-core' ),
					'warning' => __( 'Warnings and errors', 'motored-core' ),
					'info'    => __( 'Information', 'motored-core' ),
					'debug'   => __( 'Debug (verbose)', 'motored-core' ),
				),
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_general_title',
			),
		);
	}

	/**
	 * Prints the read-only "Status" section.
	 */
	public function render_status(): void {
		$yes = __( 'Yes', 'motored-core' );
		$no  = __( 'No', 'motored-core' );

		$wc_version = defined( 'WC_VERSION' ) ? (string) WC_VERSION : $no;
		$timezone   = function_exists( 'wc_timezone_string' ) ? wc_timezone_string() : '';
		$locale     = get_locale();

		$rows = array(
			__( 'Plugin version', 'motored-core' )      => MOTORED_CORE_VERSION,
			__( 'PHP version', 'motored-core' )         => PHP_VERSION,
			__( 'WordPress version', 'motored-core' )   => get_bloginfo( 'version' ),
			__( 'WooCommerce version', 'motored-core' ) => $wc_version,
			__( 'Persistent object cache', 'motored-core' ) => wp_using_ext_object_cache() ? $yes : $no,
			__( 'Action Scheduler available', 'motored-core' ) => function_exists( 'as_enqueue_async_action' ) ? $yes : $no,
			__( 'Store timezone', 'motored-core' )      => '' !== $timezone ? $timezone : $no,
			__( 'Site locale', 'motored-core' )         => $locale,
			__( 'Locale is es_AR', 'motored-core' )     => 'es_AR' === $locale ? $yes : $no,
		);

		// Modules add their own rows (label => value, already translated).
		$rows = apply_filters( 'motored_status_rows', $rows );

		echo '<h2>' . esc_html__( 'Status', 'motored-core' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html( (string) $label ),
				esc_html( (string) $value )
			);
		}
		echo '</tbody></table>';
	}
}
