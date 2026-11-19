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

	public const ADVANCED            = 'advanced';
	public const ADVANCED_CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'motored';
		$this->label = __( 'Motored', 'motored-core' );

		SectionRegistry::add(
			'status',
			static fn(): string => __( 'Status', 'motored-core' ),
			null,
			array( $this, 'render_status' ),
			1000
		);
		// Always the last section of the menu (after Status).
		SectionRegistry::add(
			self::ADVANCED,
			static fn(): string => __( 'Advanced', 'motored-core' ),
			array( $this, 'advanced_settings' ),
			null,
			2000,
			self::ADVANCED_CAPABILITY
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
		foreach ( SectionRegistry::visible() as $id => $definition ) {
			// The first section is the tab's default one (no "section" in the URL).
			$sections[ array() === $sections ? '' : (string) $id ] = (string) ( $definition['label'] )();
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
		$section    = $this->canonical( (string) $section_id );
		$definition = SectionRegistry::allowed( $section ) ? SectionRegistry::get( $section ) : null;
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

		$section    = $this->canonical( is_string( $current_section ) ? $current_section : '' );
		$definition = SectionRegistry::get( $section );

		// Scope for assets/admin/motored-admin.css.
		echo '<div class="motored-admin motored-section-' . esc_attr( sanitize_html_class( $section ) ) . '">';
		if ( null !== $definition && ! SectionRegistry::allowed( $section ) ) {
			// Direct URL without the capability: nothing is rendered or read.
			$GLOBALS['hide_save_button'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			echo '<div class="notice notice-error inline"><p>' . esc_html( self::denied_message() ) . '</p></div>';
		} elseif ( null !== $definition && null !== $definition['render'] ) {
			// WooCommerce's own global flag, read by its settings view.
			$GLOBALS['hide_save_button'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			( $definition['render'] )();
		} else {
			parent::output();
		}
		echo '</div>';
	}

	/**
	 * Saves the section; a section whose capability the user lacks is ignored with a notice (nonce + capability).
	 */
	public function save(): void {
		global $current_section;
		check_admin_referer( 'woocommerce-settings' );

		$section = $this->canonical( is_string( $current_section ) ? $current_section : '' );
		if ( null !== SectionRegistry::get( $section ) && ! SectionRegistry::allowed( $section ) ) {
			\WC_Admin_Settings::add_error( self::denied_message() );
			return;
		}
		parent::save();
	}

	/**
	 * Message shown when the user lacks the capability of a section.
	 */
	public static function denied_message(): string {
		return __( 'You do not have permission to view or change the Advanced settings.', 'motored-core' );
	}

	/**
	 * Real id of a requested section: '' (the tab's default) is the first registered section.
	 *
	 * @param string $section Requested section.
	 */
	private function canonical( string $section ): string {
		if ( '' !== $section ) {
			return $section;
		}
		$ids = array_keys( SectionRegistry::visible() );
		return array() === $ids ? '' : (string) $ids[0];
	}

	/**
	 * Fields of the "Advanced" section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function advanced_settings(): array {
		if ( ! current_user_can( self::ADVANCED_CAPABILITY ) ) {
			return array();
		}
		return array(
			array(
				'title' => __( 'Advanced', 'motored-core' ),
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
		Ui::table_open( true );
		echo '<tbody>';
		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html( (string) $label ),
				esc_html( (string) $value )
			);
		}
		echo '</tbody>';
		Ui::table_close();
	}
}
