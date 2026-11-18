<?php
/**
 * WordPress personal data exporter and eraser.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Exports and erases captured carts by e-mail address.
 */
final class Privacy {

	/**
	 * Registers the exporter and eraser (admin / ajax contexts, where WordPress runs privacy tools).
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array<string,mixed>
	 */
	public function add_exporter( $exporters ): array {
		$exporters                     = is_array( $exporters ) ? $exporters : array();
		$exporters['motored-recovery'] = array(
			'exporter_friendly_name' => __( 'Motored carts', 'motored-core' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array<string,mixed>
	 */
	public function add_eraser( $erasers ): array {
		$erasers                     = is_array( $erasers ) ? $erasers : array();
		$erasers['motored-recovery'] = array(
			'eraser_friendly_name' => __( 'Motored carts', 'motored-core' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  1-based page.
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public function export( $email, $page = 1 ): array {
		$per_page = 50;
		$rows     = Repository::by_email( (string) $email, max( 1, (int) $page ), $per_page );
		$data     = array();
		foreach ( $rows as $row ) {
			$fields = array(
				__( 'E-mail', 'motored-core' )        => (string) $row['email'],
				__( 'Phone', 'motored-core' )         => (string) $row['phone'],
				__( 'First name', 'motored-core' )    => (string) $row['first_name'],
				__( 'Status', 'motored-core' )        => Status::label( (string) $row['status'] ),
				__( 'Items in cart', 'motored-core' ) => (string) count( Snapshot::decode( (string) $row['items'] ) ),
				__( 'Last activity (GMT)', 'motored-core' ) => (string) $row['last_activity_gmt'],
			);
			$items  = array();
			foreach ( $fields as $name => $value ) {
				$items[] = array(
					'name'  => (string) $name,
					'value' => $value,
				);
			}
			$data[] = array(
				'group_id'    => 'motored-carts',
				'group_label' => __( 'Motored carts', 'motored-core' ),
				'item_id'     => 'motored-cart-' . (int) $row['id'],
				'data'        => $items,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < $per_page,
		);
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  1-based page (unused: rows are deleted in batches until none is left).
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$deleted = Repository::delete_by_email( (string) $email );
		return array(
			'items_removed'  => $deleted > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $deleted < 500,
		);
	}
}
