<?php
/**
 * Admin settings and Compatibility subtabs for BikePages and Demand capture.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Admin\Ui;
use Motored\Core\Modules\Compatibility\CompatibilityAdmin;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Renders settings and admin tables strictly via Admin\Ui and shared motored-admin.css classes.
 */
final class BikePagesAdmin {

	public const SECTION        = 'bike_pages';
	public const REBUILD_ACTION = 'motored_bike_pages_rebuild';
	public const EXPORT_ACTION  = 'motored_bike_demand_export';

	/**
	 * Registers settings section, Compatibility subtabs and admin-post handlers.
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'Motorcycle pages', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			62
		);
		add_filter( 'motored_compat_admin_tabs', array( $this, 'add_compat_tabs' ) );
		add_action( 'admin_post_' . self::REBUILD_ACTION, array( $this, 'handle_rebuild' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_demand_export' ) );
	}

	/**
	 * WooCommerce > Settings > Motored > Motorcycle pages fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		return array(
			array(
				'title' => __( 'Motorcycle landing pages & demand capture', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Automatic indexable landing pages per model + tire-size signature, brand hubs and out-of-stock demand alerts.', 'motored-core' ),
				'id'    => 'motored_bike_pages_title',
			),
			array(
				'title'    => __( 'Enable motorcycle pages', 'motored-core' ),
				'desc'     => __( 'Register motorcycle landing pages (/cubiertas-para/) and brand hubs.', 'motored-core' ),
				'id'       => 'motored_bike_pages[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'title'    => __( 'Create and update pages automatically', 'motored-core' ),
				'desc'     => __( 'Generate or update motorcycle pages in the background whenever the compatibility catalog or JSON import changes.', 'motored-core' ),
				'id'       => 'motored_bike_pages[auto_create]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['auto_create'],
				'autoload' => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'title'    => __( 'Generate preview pages for draft motorcycles', 'motored-core' ),
				'desc'     => __( 'Also create pages for unverified/draft versions (always marked noindex until published).', 'motored-core' ),
				'id'       => 'motored_bike_pages[pages_for_drafts]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['pages_for_drafts'],
				'autoload' => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'title'    => __( 'URL base slug', 'motored-core' ),
				'id'       => 'motored_bike_pages[base_slug]',
				'type'     => 'text',
				'default'  => Config::DEFAULTS['base_slug'],
				'autoload' => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'title'    => __( 'Brand hub slug', 'motored-core' ),
				'id'       => 'motored_bike_pages[brand_base]',
				'type'     => 'text',
				'default'  => Config::DEFAULTS['brand_base'],
				'autoload' => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'title'             => __( 'Minimum compatible products to index', 'motored-core' ),
				'desc'              => __( 'Pages with fewer compatible products stay reachable for visitors and demand capture, with noindex.', 'motored-core' ),
				'id'                => 'motored_bike_pages[index_min_products]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['index_min_products'],
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '20',
					'step' => '1',
				),
				'autoload'          => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'title'    => __( 'Enable data-driven FAQ', 'motored-core' ),
				'desc'     => __( 'Show factory-size FAQs and emit matching FAQPage structured data.', 'motored-core' ),
				'id'       => 'motored_bike_pages[faq_enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['faq_enabled'],
				'autoload' => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'title'    => __( 'Enable "Notify me when in stock"', 'motored-core' ),
				'desc'     => __( 'Show the demand capture form on empty motorcycle sections, empty ?moto= grids and missing pair axles.', 'motored-core' ),
				'id'       => 'motored_bike_pages[notify_enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['notify_enabled'],
				'autoload' => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'title'             => __( 'Demand retention (days)', 'motored-core' ),
				'id'                => 'motored_bike_pages[notify_retention_days]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['notify_retention_days'],
				'custom_attributes' => array(
					'min'  => '7',
					'max'  => '730',
					'step' => '1',
				),
				'autoload'          => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'title'    => __( 'Internal demand alert e-mail', 'motored-core' ),
				'desc'     => __( 'Optional destination passed to notification consumers (defaults to site admin e-mail).', 'motored-core' ),
				'id'       => 'motored_bike_pages[notify_email]',
				'type'     => 'email',
				'default'  => '',
				'autoload' => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'title'    => __( 'Enable tire-size landing pages (/medidas/)', 'motored-core' ),
				'desc'     => __( 'Generate landing pages per canonical tire size (e.g. /medidas/160-60-17/) showing matching products and motorcycles.', 'motored-core' ),
				'id'       => 'motored_bike_pages[dimension_pages]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['dimension_pages'],
				'autoload' => Options::autoload( Config::OPTION_KEY ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_bike_pages_end',
			),
		);
	}

	/**
	 * Adds "Motorcycle pages" and "Demand" tabs to Productos > Compatibilidad.
	 *
	 * @param mixed $tabs Existing extra tabs.
	 * @return array<string,array{label:string,callback:callable}>
	 */
	public function add_compat_tabs( $tabs ): array {
		$tabs               = is_array( $tabs ) ? $tabs : array();
		$tabs['bike_pages'] = array(
			'label'    => __( 'Motorcycle pages', 'motored-core' ),
			'callback' => array( $this, 'render_pages_tab' ),
		);
		$tabs['demand']     = array(
			'label'    => __( 'Demand', 'motored-core' ),
			'callback' => array( $this, 'render_demand_tab' ),
		);
		return $tabs;
	}

	/**
	 * Renders the Motorcycle pages management tab.
	 */
	public function render_pages_tab(): void {
		$counts      = BikePageSync::preview_counts();
		$archive_url = function_exists( 'get_post_type_archive_link' ) ? (string) get_post_type_archive_link( PostType::POST_TYPE ) : '';
		$list_url    = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE );

		Ui::box_open();
		echo '<div class="motored-box__head"><h2 class="motored-box__title">' . esc_html__( 'Motorcycle landing pages', 'motored-core' ) . '</h2></div>';
		echo '<div class="motored-box__stats">';
		echo '<span class="motored-box__stat"><strong>' . esc_html( (string) $counts['pages'] ) . '</strong> ' . esc_html__( 'Total pages', 'motored-core' ) . '</span>';
		echo '<span class="motored-box__stat"><strong>' . esc_html( (string) $counts['indexable'] ) . '</strong> ' . esc_html__( 'Indexable (with stock)', 'motored-core' ) . '</span>';
		echo '<span class="motored-box__stat"><strong>' . esc_html( (string) $counts['noindex'] ) . '</strong> ' . esc_html__( 'Noindex (waiting for products)', 'motored-core' ) . '</span>';
		echo '</div>';

		echo '<div class="motored-actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::REBUILD_ACTION ) ) . '">';
		wp_nonce_field( self::REBUILD_ACTION );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Regenerate all pages', 'motored-core' ) . '</button>';
		echo '</form>';
		echo '<a class="button" href="' . esc_url( $list_url ) . '">' . esc_html__( 'Open pages list', 'motored-core' ) . '</a>';
		if ( '' !== $archive_url ) {
			echo '<a class="button" href="' . esc_url( $archive_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View public hub', 'motored-core' ) . '</a>';
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Pages are grouped automatically by model and OEM tire-size signature. Manual title and SEO overrides in the page editor are preserved during sync.', 'motored-core' ) . '</p>';
		Ui::box_close();
	}

	/**
	 * Renders the Demand capture summary tab (`motored_bike_demand`).
	 */
	public function render_demand_tab(): void {
		$rows       = DemandStore::grouped_summary( 100 );
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ), self::EXPORT_ACTION );

		Ui::box_open();
		echo '<div class="motored-box__head"><h2 class="motored-box__title">' . esc_html__( 'Customer demand (Notify me)', 'motored-core' ) . '</h2>';
		echo '<div class="motored-actions"><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export demand CSV', 'motored-core' ) . '</a></div>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Requests left by riders when a motorcycle or axle has no compatible stock. Sorted by demand count.', 'motored-core' ) . '</p>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No demand requests recorded yet.', 'motored-core' ) . '</p>';
			Ui::box_close();
			return;
		}

		Ui::table_open( true );
		Ui::table_head(
			array(
				__( 'Motorcycle', 'motored-core' )     => 'grow',
				__( 'Position', 'motored-core' )       => 'sm',
				__( 'Requests', 'motored-core' )       => 'xs',
				__( 'Oldest request', 'motored-core' ) => 'md',
			)
		);
		echo '<tbody>';
		foreach ( $rows as $row ) {
			$bike = trim( $row['brand_name'] . ' ' . $row['model_name'] . ( '' !== $row['years_label'] ? ' (' . $row['years_label'] . ')' : '' ) );
			$pos  = 'all' === $row['position'] ? __( 'All', 'motored-core' ) : TireSpecs::position_label( $row['position'] );
			echo '<tr>';
			echo '<td><strong>' . esc_html( '' !== $bike ? $bike : $row['signature'] ) . '</strong></td>';
			echo '<td>' . esc_html( '' !== $pos ? $pos : $row['position'] ) . '</td>';
			echo '<td class="motored-cell-center"><strong>' . esc_html( (string) $row['total'] ) . '</strong></td>';
			echo '<td class="motored-mono">' . esc_html( $row['oldest_gmt'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		Ui::box_close();
	}

	/**
	 * Queues full BikePageSync rebuild (and SizePages sync if enabled).
	 */
	public function handle_rebuild(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'motored-core' ), 403 );
		}
		check_admin_referer( self::REBUILD_ACTION );
		$ok = BikePageSync::schedule_full_rebuild();
		if ( Config::dimension_pages() ) {
			SizePages::schedule_sync();
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => 'product',
					'page'      => CompatibilityAdmin::PAGE,
					'tab'       => 'bike_pages',
					'msg'       => $ok ? 'bulk_done' : 'error',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Streams demand requests CSV for store managers (formula-injection hardened).
	 */
	public function handle_demand_export(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'motored-core' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );
		$rows = DemandStore::export_rows( 2000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="motored-bike-demand.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false !== $out ) {
			fputcsv( $out, array( 'id', 'signature', 'brand', 'model', 'years', 'position', 'email', 'phone', 'consent_gmt', 'created_gmt', 'status' ) );
			foreach ( $rows as $row ) {
				fputcsv(
					$out,
					array_map(
						static fn( $val ): string => self::csv_safe( (string) $val ),
						array(
							$row['id'] ?? '',
							$row['signature'] ?? '',
							$row['brand_name'] ?? '',
							$row['model_name'] ?? '',
							$row['years_label'] ?? '',
							$row['position'] ?? '',
							$row['email'] ?? '',
							$row['phone'] ?? '',
							$row['consent_gmt'] ?? '',
							$row['created_gmt'] ?? '',
							$row['status'] ?? '',
						)
					)
				);
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming php://output CSV response.
		}
		exit;
	}

	/**
	 * Escapes leading spreadsheet formula characters in exported CSV cells.
	 *
	 * @param string $value Raw cell string.
	 */
	private static function csv_safe( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
