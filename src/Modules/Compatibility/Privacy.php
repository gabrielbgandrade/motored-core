<?php
/**
 * WordPress personal data exporter and eraser for Mi Garage user meta.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Exports and erases _motored_garage by customer e-mail address.
 */
final class Privacy {

	/**
	 * Registers privacy hooks (admin / ajax contexts).
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array<string,mixed>
	 */
	public function add_exporter( $exporters ): array {
		$exporters                          = is_array( $exporters ) ? $exporters : array();
		$exporters['motored-compatibility'] = array(
			'exporter_friendly_name' => __( 'Motored My Garage', 'motored-core' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array<string,mixed>
	 */
	public function add_eraser( $erasers ): array {
		$erasers                          = is_array( $erasers ) ? $erasers : array();
		$erasers['motored-compatibility'] = array(
			'eraser_friendly_name' => __( 'Motored My Garage', 'motored-core' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email User e-mail address.
	 * @param int    $page  Page (single user lookup).
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public function export( $email, $page = 1 ): array {
		unset( $page );
		$user = '' !== trim( (string) $email ) ? get_user_by( 'email', (string) $email ) : false;
		if ( ! $user instanceof \WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$garage = GarageRepository::get( (int) $user->ID );
		if ( empty( $garage['bikes'] ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$items = array(
			array(
				'name'  => __( 'Active motorcycle', 'motored-core' ),
				'value' => $garage['active'],
			),
		);
		foreach ( $garage['bikes'] as $bike ) {
			$items[] = array(
				'name'  => __( 'Saved motorcycle', 'motored-core' ),
				'value' => $bike['label'] . ' (' . $bike['slug'] . ')',
			);
		}
		return array(
			'data' => array(
				array(
					'group_id'    => 'motored-garage',
					'group_label' => __( 'Motored My Garage', 'motored-core' ),
					'item_id'     => 'motored-garage-' . (int) $user->ID,
					'data'        => $items,
				),
			),
			'done' => true,
		);
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email User e-mail address.
	 * @param int    $page  Page.
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$user = '' !== trim( (string) $email ) ? get_user_by( 'email', (string) $email ) : false;
		if ( ! $user instanceof \WP_User ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$existing = GarageRepository::get( (int) $user->ID );
		$had      = ! empty( $existing['bikes'] );
		if ( $had ) {
			delete_user_meta( (int) $user->ID, GarageRepository::META_KEY );
		}
		return array(
			'items_removed'  => $had,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
