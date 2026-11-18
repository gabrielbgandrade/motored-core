<?php
/**
 * List table of captured carts.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Recovery;

use Motored\Core\Integration\WooCommerce\ProductLookup;

defined( 'ABSPATH' ) || exit;

/**
 * Loaded only on WooCommerce > Abandoned carts (the screen requires WP_List_Table first).
 * Total and products are read fresh by ID, never from stored prices.
 */
final class RecoveryListTable extends \WP_List_Table {

	public const PER_PAGE = 20;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'recovery',
				'plural'   => 'recoveries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'id'       => __( 'No.', 'motored-core' ),
			'created'  => __( 'Date', 'motored-core' ),
			'customer' => __( 'Customer', 'motored-core' ),
			'total'    => __( 'Total', 'motored-core' ),
			'status'   => __( 'Status', 'motored-core' ),
			'steps'    => __( 'Steps / sends', 'motored-core' ),
			'activity' => __( 'Last activity', 'motored-core' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array<int,string|bool>>
	 */
	protected function get_sortable_columns() {
		return array(
			'id'       => array( 'id', true ),
			'created'  => array( 'created_gmt', true ),
			'total'    => array( 'total', false ),
			'status'   => array( 'status', false ),
			'activity' => array( 'last_activity_gmt', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'motored-core' ) );
	}

	/**
	 * Loads the current page.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters (GET).
		$filters = array();
		foreach ( array( 'status', 'type', 'date_from', 'date_to' ) as $key ) {
			$filters[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}
		$filters['search'] = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby           = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$order             = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$result                = Repository::query(
			$filters,
			Status::abandon_cutoff( time(), Config::abandon_minutes() ),
			$this->get_pagenum(),
			self::PER_PAGE,
			$orderby,
			$order
		);
		$this->items           = $result['rows'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters (GET).
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		// phpcs:enable

		echo '<div class="alignleft actions"><select name="status"><option value="">' . esc_html__( 'All statuses', 'motored-core' ) . '</option>';
		foreach ( array( Status::ABANDONED, Status::ACTIVE, Status::CONVERTED, Status::RECOVERED, Status::EXPIRED, Status::OPTED_OUT ) as $value ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $status, $value, false ), esc_html( Status::label( $value ) ) );
		}
		echo '</select><select name="type"><option value="">' . esc_html__( 'All types', 'motored-core' ) . '</option>';
		printf( '<option value="cart"%1$s>%2$s</option>', selected( $type, 'cart', false ), esc_html__( 'Cart', 'motored-core' ) );
		printf( '<option value="order"%1$s>%2$s</option>', selected( $type, 'order', false ), esc_html__( 'Order', 'motored-core' ) );
		echo '</select>';
		printf( '<input type="date" name="date_from" value="%s" aria-label="%s" />', esc_attr( $from ), esc_attr__( 'From', 'motored-core' ) );
		printf( '<input type="date" name="date_to" value="%s" aria-label="%s" />', esc_attr( $to ), esc_attr__( 'To', 'motored-core' ) );
		submit_button( __( 'Filter', 'motored-core' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Message when empty.
	 */
	public function no_items(): void {
		esc_html_e( 'No carts found.', 'motored-core' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="recovery[]" value="' . esc_attr( (string) $item['id'] ) . '" />';
	}

	/**
	 * Number column with row actions.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_id( $item ): string {
		$id             = (int) $item['id'];
		$base           = admin_url( 'admin.php?page=' . RecoveryAdmin::PAGE );
		$view_url       = add_query_arg( 'view', $id, $base );
		$delete_url     = wp_nonce_url(
			add_query_arg(
				array(
					'motored_action' => 'delete',
					'id'             => $id,
				),
				$base
			),
			'motored_recovery_delete_' . $id
		);
		$send_wa_url    = wp_nonce_url(
			add_query_arg(
				array(
					'motored_action' => 'send_wa',
					'id'             => $id,
				),
				$base
			),
			'motored_recovery_send_wa_' . $id
		);
		$send_email_url = wp_nonce_url(
			add_query_arg(
				array(
					'motored_action' => 'send_email',
					'id'             => $id,
				),
				$base
			),
			'motored_recovery_send_email_' . $id
		);
		$open_wa_url    = wp_nonce_url(
			add_query_arg(
				array(
					'motored_action' => 'open_wa',
					'id'             => $id,
				),
				$base
			),
			'motored_recovery_open_wa_' . $id
		);
		$copy_url       = wp_nonce_url(
			add_query_arg(
				array(
					'motored_action' => 'copy_link',
					'id'             => $id,
				),
				$base
			),
			'motored_recovery_copy_link_' . $id
		);

		$actions = array(
			'view'       => '<a href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'motored-core' ) . '</a>',
			'send_wa'    => '<a href="' . esc_url( $send_wa_url ) . '">' . esc_html__( 'Send WhatsApp now', 'motored-core' ) . '</a>',
			'send_email' => '<a href="' . esc_url( $send_email_url ) . '">' . esc_html__( 'Send email now', 'motored-core' ) . '</a>',
			'open_wa'    => '<a href="' . esc_url( $open_wa_url ) . '" target="_blank">' . esc_html__( 'Open in WhatsApp Web', 'motored-core' ) . '</a>',
			'copy_link'  => '<a href="' . esc_url( $copy_url ) . '">' . esc_html__( 'Copy link', 'motored-core' ) . '</a>',
			'delete'     => '<a href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete', 'motored-core' ) . '</a>',
		);
		return '<strong><a href="' . esc_url( $view_url ) . '">#' . $id . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Date column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_created( $item ): string {
		return esc_html( self::local_date( (string) $item['created_gmt'] ) );
	}

	/**
	 * Customer column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_customer( $item ): string {
		$parts = array_filter( array( (string) $item['first_name'], (string) $item['email'], (string) $item['phone'] ), static fn( string $v ): bool => '' !== $v );
		return array() === $parts ? '&mdash;' : implode( '<br />', array_map( 'esc_html', $parts ) );
	}

	/**
	 * Total column (fresh prices).
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_total( $item ): string {
		if ( 'order' === (string) $item['type'] ) {
			// Orders: the order total stored at the last status event (the detail screen reads it fresh).
			return wp_kses_post( RecoveryAdmin::money( (float) $item['total'], (string) $item['currency'] ) );
		}
		$lines = ProductLookup::resolve( Snapshot::decode( (string) $item['items'] ) );
		return wp_kses_post( RecoveryAdmin::money( ProductLookup::total( $lines ), (string) $item['currency'] ) );
	}

	/**
	 * Status column (derived).
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_status( $item ): string {
		return esc_html( Status::label( Status::derive_row( $item, time(), Config::abandon_minutes() ) ) );
	}

	/**
	 * Steps / sends column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_steps( $item ): string {
		$step = '' !== (string) $item['step'] ? (string) $item['step'] : '—';
		return esc_html( sprintf( 'WhatsApp: %1$d · E-mail: %2$d · %3$s', (int) $item['wa_sent_count'], (int) $item['email_sent_count'], $step ) );
	}

	/**
	 * Last activity column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_activity( $item ): string {
		$ts = Status::timestamp( (string) $item['last_activity_gmt'] );
		/* translators: %s: human readable time difference, e.g. "2 hours". */
		return esc_html( null === $ts ? '' : sprintf( __( '%s ago', 'motored-core' ), human_time_diff( $ts, time() ) ) );
	}

	/**
	 * Fallback column.
	 *
	 * @param array<string,mixed> $item        Row.
	 * @param string              $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * GMT datetime to the site timezone.
	 *
	 * @param string $gmt GMT datetime.
	 */
	public static function local_date( string $gmt ): string {
		return '' === $gmt ? '' : (string) get_date_from_gmt( $gmt, 'Y-m-d H:i' );
	}
}
