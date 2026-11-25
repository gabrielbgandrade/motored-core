<?php
/**
 * WordPress personal data exporter and eraser for motored_bike_demand.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

defined( 'ABSPATH' ) || exit;

// Personal data export/erase queries motored_bike_demand directly via $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Exports and erases demand-notification entries matching a customer e-mail address.
 */
final class DemandPrivacy {

	/**
	 * Registers WordPress privacy exporter and eraser callbacks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Registers the demand exporter.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array<string,mixed>
	 */
	public function add_exporter( $exporters ): array {
		$exporters                        = is_array( $exporters ) ? $exporters : array();
		$exporters['motored-bike-demand'] = array(
			'exporter_friendly_name' => __( 'Motored Motorcycle Tire Alerts', 'motored-core' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the demand eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array<string,mixed>
	 */
	public function add_eraser( $erasers ): array {
		$erasers                        = is_array( $erasers ) ? $erasers : array();
		$erasers['motored-bike-demand'] = array(
			'eraser_friendly_name' => __( 'Motored Motorcycle Tire Alerts', 'motored-core' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email Customer e-mail address.
	 * @param int    $page  Page number.
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public function export( $email, $page = 1 ): array {
		unset( $page );
		$clean = strtolower( sanitize_email( (string) $email ) );
		if ( '' === $clean ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$table = DemandStore::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, brand_name, model_name, years_label, position, email, phone, consent_gmt, created_gmt
				FROM {$table}
				WHERE email = %s
				ORDER BY id ASC",
				$clean
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$export_items = array();
		foreach ( $rows as $row ) {
			$export_items[] = array(
				'group_id'    => 'motored-bike-demand',
				'group_label' => __( 'Motored Motorcycle Tire Alerts', 'motored-core' ),
				'item_id'     => 'motored-bike-demand-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Motorcycle', 'motored-core' ),
						'value' => trim( (string) $row['brand_name'] . ' ' . (string) $row['model_name'] . ' ' . (string) $row['years_label'] ),
					),
					array(
						'name'  => __( 'Position', 'motored-core' ),
						'value' => (string) $row['position'],
					),
					array(
						'name'  => __( 'E-mail', 'motored-core' ),
						'value' => (string) $row['email'],
					),
					array(
						'name'  => __( 'Phone', 'motored-core' ),
						'value' => (string) $row['phone'],
					),
					array(
						'name'  => __( 'Consent timestamp (UTC)', 'motored-core' ),
						'value' => (string) $row['consent_gmt'],
					),
				),
			);
		}
		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email Customer e-mail address.
	 * @param int    $page  Page number.
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$clean = strtolower( sanitize_email( (string) $email ) );
		if ( '' === $clean ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$deleted = $wpdb->delete( DemandStore::table(), array( 'email' => $clean ), array( '%s' ) );
		return array(
			'items_removed'  => is_int( $deleted ) && $deleted > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
