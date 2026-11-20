<?php
/**
 * Admin interface for Compatibility: Motored settings section, Productos > Compatibilidad and product meta field.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Compatibility;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Admin\Ui;
use Motored\Core\Integration\WooCommerce\AttributeHooks;

defined( 'ABSPATH' ) || exit;

/**
 * All admin tables, boxes and fields use Admin\Ui and the shared motored-admin.css stylesheet.
 */
final class CompatibilityAdmin {

	public const SECTION            = 'compatibility';
	public const PAGE               = 'motored-compatibility';
	public const TOOL_ACTION        = 'motored_compat_tool';
	private const TOOL_NONCE        = '_motored_compat_nonce';
	private const PREFILL_TRANSIENT = 'motored_compat_prefill_';
	public const EXPORT_ACTION      = 'motored_compat_export';
	public const CRUD_ACTION        = 'motored_compat_crud';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'Compatibility', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			55
		);
		add_action( 'woocommerce_admin_field_motored_compat_tools', array( $this, 'render_tools_field' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ) );
		add_action( 'admin_notices', array( $this, 'render_settings_notice' ) );
		add_action( 'admin_post_' . self::TOOL_ACTION, array( $this, 'handle_tool_action' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_action( 'admin_post_' . self::CRUD_ACTION, array( $this, 'handle_crud_post' ) );

		( new ProductTireAdmin() )->register();
	}

	/**
	 * Flushes rewrite rules once when Mi Garage endpoint version changes (admin_init only).
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( GarageEndpoint::REWRITE_VERSION === (string) get_option( GarageEndpoint::REWRITE_OPT, '' ) ) {
			return;
		}
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		update_option( GarageEndpoint::REWRITE_OPT, GarageEndpoint::REWRITE_VERSION, false );
	}

	/**
	 * WooCommerce > Settings > Motored > Compatibility fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		$brand_note = AttributeHooks::has_native_brand_taxonomy()
			? __( 'Native WooCommerce product brands taxonomy (product_brand) detected.', 'motored-core' )
			: __( 'Warning: product_brand taxonomy was not detected in this WooCommerce version.', 'motored-core' );

		return array(
			array(
				'title' => __( 'Motorcycle compatibility & My Garage', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Matches motorcycles to tires by size without linking bikes inside every product.', 'motored-core' ) . ' ' . $brand_note,
				'id'    => 'motored_compatibility_title',
			),
			array(
				'title'    => __( 'Enable compatibility', 'motored-core' ),
				'desc'     => __( 'Enable selector shortcode, ?moto= filter, My Garage, compatibility badges and product tabs.', 'motored-core' ),
				'id'       => 'motored_compatibility[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Max bikes in My Garage', 'motored-core' ),
				'id'                => 'motored_compatibility[max_garage_bikes]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['max_garage_bikes'],
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '20',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'    => __( 'Strict load/speed matching', 'motored-core' ),
				'desc'     => __( 'Hide tires with missing load/speed attributes when a motorcycle specifies minimum ratings (off by default to protect catalog reach).', 'motored-core' ),
				'id'       => 'motored_compatibility[strict_specs]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['strict_specs'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Show incompatible badge on catalog cards', 'motored-core' ),
				'desc'     => __( 'Display "Does not match your bike" on shop cards when a tire does not fit the active garage bike. The product page always tells the truth about the active bike.', 'motored-core' ),
				'id'       => 'motored_compatibility[show_incompatible_badge]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['show_incompatible_badge'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Suggest filtering by the active motorcycle', 'motored-core' ),
				'desc'     => __( 'On shop pages without a motorcycle filter, show "View only tires for my …" to shoppers with a bike in My Garage.', 'motored-core' ),
				'id'       => 'motored_compatibility[garage_cta]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['garage_cta'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Best fit first', 'motored-core' ),
				'desc'     => __( 'When filtering by motorcycle and the shopper did not pick a sorting, list OEM sizes first, then alternatives, then equivalents.', 'motored-core' ),
				'id'       => 'motored_compatibility[oem_first_sort]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['oem_first_sort'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Analytics events', 'motored-core' ),
				'desc'     => __( 'Push anonymous events (motorcycle chosen, filters applied, suggestion clicked) to window.dataLayer when it exists. No personal data is sent.', 'motored-core' ),
				'id'       => 'motored_compatibility[analytics_events]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['analytics_events'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Rename tab to "Technical information"', 'motored-core' ),
				'desc'     => __( 'Rename WooCommerce Additional Information tab to Technical information and include Full size.', 'motored-core' ),
				'id'       => 'motored_compatibility[rename_tech_tab]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['rename_tech_tab'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Show "Compatibility" product tab', 'motored-core' ),
				'desc'     => __( 'Add a lazy-loaded Compatibility tab after Technical information on indexed tire products.', 'motored-core' ),
				'id'       => 'motored_compatibility[show_compat_tab]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['show_compat_tab'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Auto-fill width, profile and rim', 'motored-core' ),
				'desc'     => __( 'When saving a single tire (never kits), fill empty Width, Aspect ratio and Rim attributes from its canonical size.', 'motored-core' ),
				'id'       => 'motored_compatibility[auto_fill_dimensions]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['auto_fill_dimensions'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Verification disclaimer', 'motored-core' ),
				'desc'     => __( 'Leave blank to use the default message advising shoppers to check their manual.', 'motored-core' ),
				'id'       => 'motored_compatibility[disclaimer]',
				'type'     => 'text',
				'class'    => 'motored-input-wide',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'    => __( 'Catalog base URL', 'motored-core' ),
				'desc'     => __( 'Optional URL used when redirecting ?moto= searches (defaults to the WooCommerce shop page).', 'motored-core' ),
				'id'       => 'motored_compatibility[shop_url]',
				'type'     => 'url',
				'class'    => 'motored-input-wide',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'    => __( 'Motorcycle search page URL', 'motored-core' ),
				'desc'     => __( 'Page with the motorcycle search ([motored_buscador]). Product pages send shoppers there when no motorcycle is selected. Leave blank to use the home page.', 'motored-core' ),
				'id'       => 'motored_compatibility[selector_url]',
				'type'     => 'url',
				'class'    => 'motored-input-wide',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'type' => 'motored_compat_tools',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_compatibility_end',
			),
		);
	}

	/**
	 * Renders attribute installation, term seeding and catalog reindexing buttons inside Settings.
	 */
	public function render_tools_field(): void {
		$manage_url = admin_url( 'edit.php?post_type=product&page=' . self::PAGE );
		$post_base  = admin_url( 'admin-post.php?action=' . self::TOOL_ACTION );
		$nonce      = wp_create_nonce( self::TOOL_ACTION );
		$counts     = CatalogRepository::summary_counts();
		$indexed    = ProductIndexRepository::count_indexed();
		echo '<tr valign="top"><th scope="row">' . esc_html__( 'Catalog & attributes setup', 'motored-core' ) . '</th><td>';
		Ui::box_open();
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: published motorcycles, 2: draft motorcycles, 3: indexed tire products. */
				__( 'Catalog status: %1$d published · %2$d draft motorcycles · %3$d indexed tire products.', 'motored-core' ),
				$counts['versions_published'],
				$counts['versions_draft'],
				$indexed
			)
		) . '</p>';
		$btn = static function ( string $tool, string $label, string $nonce, string $post_base, string $css = 'button' ): void {
			echo '<button type="submit" class="' . esc_attr( $css ) . '" formnovalidate formmethod="post" formaction="' . esc_url(
				add_query_arg(
					array(
						'tool'           => $tool,
						self::TOOL_NONCE => $nonce,
					),
					$post_base
				)
			) . '">' . esc_html( $label ) . '</button>';
		};
		echo '<div class="motored-actions">';
		$btn( 'setup_attributes', __( '1. Install attributes & terms', 'motored-core' ), $nonce, $post_base );
		$btn( 'prefill_base_data', __( '2. Load base catalog (Argentina)', 'motored-core' ), $nonce, $post_base, 'button button-primary' );
		$btn( 'reindex', __( '3. Reindex tire products', 'motored-core' ), $nonce, $post_base );
		echo '<a class="button" href="' . esc_url( $manage_url ) . '">' . esc_html__( 'Open Compatibility catalog', 'motored-core' ) . '</a>';
		echo '</div>';
		echo '<p><label class="motored-field__check"><input type="checkbox" name="force_draft" value="1" /> ' . esc_html__( 'Import every motorcycle as draft (review before publishing)', 'motored-core' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Do step 1 once, then step 2 (imports immediately, safe to repeat). Saving tire products auto-indexes them; step 3 is only needed after bulk imports.', 'motored-core' ) . '</p>';
		Ui::box_close();
		echo '</td></tr>';
	}

	/**
	 * Handles admin-post setup/maintenance buttons.
	 */
	public function handle_tool_action(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'motored-core' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below per tool.
		$tool = isset( $_REQUEST['tool'] ) && is_string( $_REQUEST['tool'] ) ? sanitize_key( wp_unslash( $_REQUEST['tool'] ) ) : '';
		$msg  = '';

		// The outer WooCommerce settings form also posts `_wpnonce`; use an isolated nonce key.
		check_admin_referer( self::TOOL_ACTION, self::TOOL_NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below.
		$return_to = isset( $_REQUEST['return_to'] ) && is_string( $_REQUEST['return_to'] ) ? sanitize_key( wp_unslash( $_REQUEST['return_to'] ) ) : 'settings';
		$back      = 'import' === $return_to
			? admin_url( 'edit.php?post_type=product&page=' . self::PAGE . '&tab=import' )
			: admin_url( 'admin.php?page=wc-settings&tab=motored&section=' . self::SECTION );

		if ( 'setup_attributes' === $tool ) {
			$res  = AttributeInstaller::install_attributes();
			$seed = AttributeInstaller::seed_fixed_terms();
			$msg  = sprintf( 'setup:%d:%d:%d', $res['created'], $res['existing'], $seed['seeded'] );
		} elseif ( 'install_attributes' === $tool ) {
			$res = AttributeInstaller::install_attributes();
			$msg = sprintf( 'attrs:%d:%d', $res['created'], $res['existing'] );
		} elseif ( 'seed_terms' === $tool ) {
			$res = AttributeInstaller::seed_fixed_terms();
			$msg = sprintf( 'terms:%d:%d', $res['seeded'], $res['skipped'] );
		} elseif ( 'reindex' === $tool ) {
			$queued = ProductIndexSync::schedule_full_reindex();
			if ( ! $queued ) {
				$cursor = 0;
				$loops  = 0;
				do {
					$cursor = ProductIndexSync::reindex_batch( (int) $cursor, ProductIndexSync::REINDEX_BATCH );
					++$loops;
				} while ( null !== $cursor && $loops < 50 );
			}
			$msg = $queued ? 'reindex_queued' : 'reindex_sync';
		} elseif ( 'prefill_base_data' === $tool ) {
			$raw_json = self::load_base_json();
			if ( '' === $raw_json ) {
				set_transient( self::PREFILL_TRANSIENT . 'err_' . get_current_user_id(), __( 'Bundled base catalog file could not be read or contains invalid JSON.', 'motored-core' ), 5 * MINUTE_IN_SECONDS );
				wp_safe_redirect( add_query_arg( 'motored_compat_msg', 'prefill_error', $back ) );
				exit;
			}
			$force_draft = isset( $_POST['force_draft'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
			$result      = ImportRunner::start(
				$raw_json,
				array(
					'default_status'     => 'draft',
					'import_constraints' => false,
					'force_draft'        => $force_draft,
					'sync'               => true,
				)
			);
			if ( 'busy' === ( $result['mode'] ?? '' ) ) {
				wp_safe_redirect( add_query_arg( 'motored_compat_msg', 'prefill_busy', $back ) );
				exit;
			}
			if ( empty( $result['ok'] ) ) {
				$err_msg = ! empty( $result['message'] ) ? (string) $result['message'] : __( 'Could not start base catalog import.', 'motored-core' );
				set_transient( self::PREFILL_TRANSIENT . 'err_' . get_current_user_id(), $err_msg, 5 * MINUTE_IN_SECONDS );
				wp_safe_redirect( add_query_arg( 'motored_compat_msg', 'prefill_error', $back ) );
				exit;
			}
			if ( 'import' === $return_to ) {
				$back = admin_url( 'edit.php?post_type=product&page=' . self::PAGE . '&tab=versions' );
			}
			if ( 'queued' === ( $result['mode'] ?? '' ) ) {
				$msg = 'prefill_queued';
			} else {
				$stats = $result['stats'];
				$msg   = sprintf(
					'prefill:%d:%d:%d:%d',
					(int) ( $stats['versions'] ?? 0 ),
					(int) ( $stats['fitments'] ?? 0 ),
					(int) ( $stats['equivalences'] ?? 0 ),
					(int) ( $stats['rejected'] ?? 0 )
				);
			}
		}

		if ( '' !== $msg ) {
			$back = add_query_arg( 'motored_compat_msg', rawurlencode( $msg ), $back );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Loads the bundled base catalog JSON for the Argentine market.
	 *
	 * @return string Raw JSON string, or empty string on failure.
	 */
	private static function load_base_json(): string {
		$path = __DIR__ . '/data/compat-base-ar.json';
		if ( ! is_file( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled JSON file.
		$raw = (string) file_get_contents( $path );
		if ( '' === $raw || null === json_decode( $raw, true ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * Displays feedback notice on WooCommerce > Settings > Motored > Compatibility after setup tools run.
	 */
	public function render_settings_notice(): void {
		if ( 'wc-settings' !== $this->get_key( 'page' ) || 'motored' !== $this->get_key( 'tab' ) || self::SECTION !== $this->get_key( 'section' ) ) {
			return;
		}
		$this->render_tool_feedback_notice( true );
		$active_job = ImportRunner::active_job();
		if ( null !== $active_job && '' === $this->get_text( 'motored_compat_msg' ) ) {
			$cat_url = admin_url( 'edit.php?post_type=product&page=' . self::PAGE );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'A background compatibility import is currently queued or running.', 'motored-core' ) . ' <a href="' . esc_url( $cat_url ) . '">' . esc_html__( 'Open Compatibility catalog', 'motored-core' ) . '</a></p></div>';
		}
	}

	/**
	 * Renders feedback notices produced by setup/prefill tool actions.
	 *
	 * @param bool $include_catalog_link Whether to append a link to the Compatibility catalog.
	 */
	private function render_tool_feedback_notice( bool $include_catalog_link ): void {
		$raw = $this->get_text( 'motored_compat_msg' );
		if ( '' === $raw ) {
			return;
		}
		$parts       = explode( ':', $raw );
		$text        = '';
		$link_url    = '';
		$link_label  = '';
		$notice_type = 'notice-success';
		if ( 'prefill_error' === $raw ) {
			$notice_type = 'notice-error';
			$err_key     = self::PREFILL_TRANSIENT . 'err_' . get_current_user_id();
			$detail      = get_transient( $err_key );
			delete_transient( $err_key );
			$text = is_string( $detail ) && '' !== $detail
				? $detail
				: __( 'Could not load or import the base catalog.', 'motored-core' );
		} elseif ( 'error' === $raw ) {
			$notice_type = 'notice-error';
			$text        = __( 'Could not load or import the base catalog. Check the Compatibility catalog logs.', 'motored-core' );
		} elseif ( 'prefill_busy' === $raw ) {
			$notice_type = 'notice-warning';
			$text        = __( 'An import is already in progress. Open the Compatibility catalog to run the next batch or cancel it.', 'motored-core' );
			$link_url    = admin_url( 'edit.php?post_type=product&page=' . self::PAGE . '&tab=import' );
			$link_label  = __( 'Open Import / Export', 'motored-core' );
		} elseif ( 'setup' === $parts[0] && 4 === count( $parts ) ) {
			$text = sprintf(
				/* translators: 1: created attributes, 2: existing attributes, 3: seeded terms. */
				__( 'Setup done: %1$d attributes created, %2$d already existed, %3$d fixed terms ready.', 'motored-core' ),
				(int) $parts[1],
				(int) $parts[2],
				(int) $parts[3]
			);
		} elseif ( 'attrs' === $parts[0] && 3 === count( $parts ) ) {
			$text = sprintf(
				/* translators: 1: created count, 2: existing count. */
				__( 'Global attributes setup: %1$d created, %2$d already existed.', 'motored-core' ),
				(int) $parts[1],
				(int) $parts[2]
			);
		} elseif ( 'terms' === $parts[0] && 3 === count( $parts ) ) {
			$text = sprintf(
				/* translators: 1: seeded terms count, 2: skipped count. */
				__( 'Fixed terms setup: %1$d ready, %2$d skipped.', 'motored-core' ),
				(int) $parts[1],
				(int) $parts[2]
			);
		} elseif ( 'reindex_queued' === $raw ) {
			$text = __( 'Catalog compatibility reindex queued in Action Scheduler.', 'motored-core' );
		} elseif ( 'reindex_sync' === $raw ) {
			$text = __( 'Catalog compatibility reindex batch completed.', 'motored-core' );
		} elseif ( 'prefill_queued' === $raw ) {
			$text       = __( 'Base catalog import queued in Action Scheduler.', 'motored-core' );
			$link_url   = admin_url( 'edit.php?post_type=product&page=' . self::PAGE );
			$link_label = __( 'Open Compatibility catalog', 'motored-core' );
		} elseif ( 'prefill' === $parts[0] && 5 === count( $parts ) ) {
			$text = sprintf(
				/* translators: 1: versions, 2: fitments, 3: equivalences, 4: rejected. */
				__( 'Base catalog imported: %1$d versions, %2$d fitments, %3$d equivalences, %4$d rejected.', 'motored-core' ),
				(int) $parts[1],
				(int) $parts[2],
				(int) $parts[3],
				(int) $parts[4]
			);
			if ( $include_catalog_link ) {
				$link_url   = admin_url( 'edit.php?post_type=product&page=' . self::PAGE );
				$link_label = __( 'Open Compatibility catalog', 'motored-core' );
			}
		}
		if ( '' !== $text ) {
			echo '<div class="notice ' . esc_attr( $notice_type ) . ' is-dismissible"><p>' . esc_html( $text );
			if ( '' !== $link_url && '' !== $link_label ) {
				echo ' <a href="' . esc_url( $link_url ) . '">' . esc_html( $link_label ) . '</a>';
			}
			echo '</p></div>';
		}
	}

	/**
	 * Adds Productos > Compatibilidad submenu under WooCommerce Products.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Compatibility', 'motored-core' ),
			__( 'Compatibility', 'motored-core' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Streams schema v1 JSON export download.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'motored-core' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="motored-compatibility.json"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download payload.
		echo JsonExporter::to_json();
		exit;
	}

	/**
	 * Processes CRUD form submissions on Productos > Compatibilidad and redirects back cleanly.
	 */
	public function handle_crud_post(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'motored-core' ), 403 );
		}
		check_admin_referer( self::CRUD_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$op = isset( $_POST['op'] ) && is_string( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$tab    = isset( $_POST['tab'] ) && is_string( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'versions';
		$status = 'ok';
		$extra  = array();

		if ( 'quick_add_moto' === $op ) {
			$brand_name = $this->post_text( 'brand_name' );
			$model_name = $this->post_text( 'model_name' );
			$cc_raw     = $this->post_int( 'cc' );
			$aliases    = $this->post_text( 'aliases' );
			$year_from  = $this->post_int( 'year_from' );
			$year_to    = $this->post_int( 'year_to' );
			$variant    = $this->post_text( 'variant' );
			$front_raw  = $this->post_text( 'front_size' );
			$rear_raw   = $this->post_text( 'rear_size' );
			$ver_stat   = 'published' === $this->post_key( 'status' ) ? 'published' : 'draft';
			$verified   = isset( $_POST['verified'] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$notes      = $this->post_text( 'notes' );

			$brand_slug   = sanitize_title( $brand_name );
			$model_slug   = sanitize_title( $model_name );
			$var_slug     = '' !== $variant ? sanitize_title( $variant ) : '';
			$front_parsed = '' !== $front_raw ? SizeParser::parse( $front_raw ) : null;
			$rear_parsed  = '' !== $rear_raw ? SizeParser::parse( $rear_raw ) : null;

			if ( '' === $brand_name || '' === $brand_slug || '' === $model_name || '' === $model_slug ) {
				$status = 'error';
			} elseif ( $year_from < 1950 || $year_from > 2100 || ( $year_to > 0 && $year_to < $year_from ) ) {
				$status = 'invalid_years';
			} elseif ( ( '' !== $front_raw && null === $front_parsed ) || ( '' !== $rear_raw && null === $rear_parsed ) ) {
				$status = 'invalid_size';
			} else {
				$existing_brand = CatalogRepository::find_brand_by_slug( $brand_slug );
				$brand_id       = null !== $existing_brand
					? $existing_brand['id']
					: CatalogRepository::upsert_brand( $brand_name, $brand_slug );
				if ( null !== $existing_brand ) {
					$brand_slug = $existing_brand['slug'];
				}

				$existing_model = $brand_id > 0 ? CatalogRepository::find_model( $brand_id, $model_slug ) : null;
				$model_id       = null !== $existing_model
					? $existing_model['id']
					: CatalogRepository::upsert_model( $brand_id, $model_name, $model_slug, $cc_raw > 0 ? $cc_raw : null, $aliases );
				if ( null !== $existing_model ) {
					$model_slug = $existing_model['slug'];
				}

				if ( $brand_id <= 0 || $model_id <= 0 ) {
					$status = 'error';
				} elseif ( CatalogRepository::has_year_overlap( $model_id, $var_slug, $year_from, $year_to ) ) {
					$status = 'overlap';
				} else {
					$new_id = CatalogRepository::upsert_version(
						array(
							'model_id'   => $model_id,
							'brand_slug' => $brand_slug,
							'model_slug' => $model_slug,
							'year_from'  => $year_from,
							'year_to'    => $year_to,
							'variant'    => $variant,
							'status'     => $ver_stat,
							'verified'   => $verified,
							'notes'      => $notes,
						)
					);
					if ( $new_id <= 0 ) {
						$status = 'error';
					} else {
						$status                = 'saved';
						$extra['edit_version'] = $new_id;
						$this->replace_oem_fitment( $new_id, TireSpecs::POS_FRONT, $front_raw );
						$this->replace_oem_fitment( $new_id, TireSpecs::POS_REAR, $rear_raw );
					}
				}
			}
			if ( empty( $extra['edit_version'] ) ) {
				$extra = array_merge( $extra, $this->posted_filter_args() );
			}
		} elseif ( 'bulk_versions' === $op ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
			$row_action = isset( $_POST['row_action'] ) && is_string( $_POST['row_action'] ) ? sanitize_text_field( wp_unslash( $_POST['row_action'] ) ) : '';
			$extra      = $this->posted_filter_args();
			if ( '' !== $row_action && str_contains( $row_action, ':' ) ) {
				$parts = explode( ':', $row_action, 2 );
				$act   = sanitize_key( $parts[0] );
				$vid   = absint( $parts[1] );
				if ( $vid > 0 && 'publish' === $act ) {
					CatalogRepository::set_version_status( $vid, 'published' );
					$status = 'saved';
				} elseif ( $vid > 0 && 'draft' === $act ) {
					CatalogRepository::set_version_status( $vid, 'draft' );
					$status = 'saved';
				} elseif ( $vid > 0 && 'delete' === $act ) {
					CatalogRepository::delete_version( $vid );
					$status = 'deleted';
				} else {
					$status = 'error';
				}
			} else {
				$bulk_action = $this->post_key( 'bulk_action' );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
				$raw_ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
				$ids     = array_values( array_filter( array_map( 'absint', $raw_ids ), static fn( int $id ): bool => $id > 0 ) );
				if ( '' === $bulk_action || empty( $ids ) ) {
					$status = 'error';
				} else {
					foreach ( $ids as $vid ) {
						if ( 'publish_verify' === $bulk_action ) {
							CatalogRepository::set_version_status( $vid, 'published', 1 );
						} elseif ( 'publish' === $bulk_action ) {
							CatalogRepository::set_version_status( $vid, 'published' );
						} elseif ( 'draft' === $bulk_action ) {
							CatalogRepository::set_version_status( $vid, 'draft' );
						} elseif ( 'delete' === $bulk_action ) {
							CatalogRepository::delete_version( $vid );
						}
					}
					$status = 'delete' === $bulk_action ? 'deleted' : 'bulk_done';
				}
			}
		} elseif ( 'publish_verify_version' === $op ) {
			$vid = $this->post_int( 'id' );
			if ( $vid > 0 && CatalogRepository::set_version_status( $vid, 'published', 1 ) ) {
				$status                = 'saved';
				$extra['edit_version'] = $vid;
			} else {
				$status = 'error';
			}
		} elseif ( 'save_brand' === $op ) {
			$name   = $this->post_text( 'name' );
			$ok     = CatalogRepository::upsert_brand( $name ) > 0;
			$status = $ok ? 'saved' : 'error';
		} elseif ( 'update_brand' === $op ) {
			$bid    = $this->post_int( 'id' );
			$name   = $this->post_text( 'name' );
			$ok     = CatalogRepository::update_brand( $bid, $name );
			$status = $ok ? 'saved' : 'error';
			if ( ! $ok && $bid > 0 ) {
				$extra['edit_brand'] = $bid;
			}
		} elseif ( 'delete_brand' === $op ) {
			$ok     = CatalogRepository::delete_brand( $this->post_int( 'id' ) );
			$status = $ok ? 'deleted' : 'has_children';
			$extra  = $this->posted_filter_args();
		} elseif ( 'save_model' === $op ) {
			$brand_id = $this->post_int( 'brand_id' );
			$name     = $this->post_text( 'name' );
			$cc_raw   = $this->post_int( 'cc' );
			$aliases  = $this->post_text( 'aliases' );
			$ok       = CatalogRepository::upsert_model( $brand_id, $name, '', $cc_raw > 0 ? $cc_raw : null, $aliases ) > 0;
			$status   = $ok ? 'saved' : 'error';
			$extra    = $this->posted_filter_args();
		} elseif ( 'update_model' === $op ) {
			$mid     = $this->post_int( 'id' );
			$name    = $this->post_text( 'name' );
			$cc_raw  = $this->post_int( 'cc' );
			$aliases = $this->post_text( 'aliases' );
			$ok      = CatalogRepository::update_model( $mid, $name, $cc_raw > 0 ? $cc_raw : null, $aliases );
			$status  = $ok ? 'saved' : 'error';
			$extra   = $this->posted_filter_args();
			if ( ! $ok && $mid > 0 ) {
				$extra['edit_model'] = $mid;
			}
		} elseif ( 'delete_model' === $op ) {
			$ok     = CatalogRepository::delete_model( $this->post_int( 'id' ) );
			$status = $ok ? 'deleted' : 'has_children';
			$extra  = $this->posted_filter_args();
		} elseif ( 'save_version' === $op ) {
			$vid       = $this->post_int( 'id' );
			$model_id  = $this->post_int( 'model_id' );
			$year_from = $this->post_int( 'year_from' );
			$year_to   = $this->post_int( 'year_to' );
			$variant   = $this->post_text( 'variant' );
			$ver_stat  = 'published' === $this->post_key( 'status' ) ? 'published' : 'draft';
			$verified  = isset( $_POST['verified'] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$notes     = $this->post_text( 'notes' );

			$var_slug = '' !== $variant ? sanitize_title( $variant ) : '';
			if ( $year_from < 1950 || $year_from > 2100 || ( $year_to > 0 && $year_to < $year_from ) ) {
				$status = 'invalid_years';
				if ( $vid > 0 ) {
					$extra['edit_version'] = $vid;
				}
			} elseif ( CatalogRepository::has_year_overlap( $model_id, $var_slug, $year_from, $year_to, $vid ) ) {
				$status = 'overlap';
				if ( $vid > 0 ) {
					$extra['edit_version'] = $vid;
				}
			} elseif ( $vid > 0 ) {
				$front_raw = $this->post_text( 'front_size' );
				$rear_raw  = $this->post_text( 'rear_size' );
				if ( ( '' !== $front_raw && null === SizeParser::parse( $front_raw ) ) || ( '' !== $rear_raw && null === SizeParser::parse( $rear_raw ) ) ) {
					$status                = 'invalid_size';
					$extra['edit_version'] = $vid;
				} else {
					CatalogRepository::update_version(
						$vid,
						array(
							'year_from' => $year_from,
							'year_to'   => $year_to,
							'variant'   => $variant,
							'status'    => $ver_stat,
							'verified'  => $verified,
							'notes'     => $notes,
						)
					);
					$this->replace_oem_fitment( $vid, TireSpecs::POS_FRONT, $front_raw );
					$this->replace_oem_fitment( $vid, TireSpecs::POS_REAR, $rear_raw );
					$extra['edit_version'] = $vid;
					$status                = 'saved';
				}
			} else {
				$models = CatalogRepository::models();
				$m_row  = null;
				foreach ( $models as $m ) {
					if ( $m['id'] === $model_id ) {
						$m_row = $m;
						break;
					}
				}
				$b_slug = '';
				if ( null !== $m_row ) {
					foreach ( CatalogRepository::brands() as $b ) {
						if ( $b['id'] === $m_row['brand_id'] ) {
							$b_slug = $b['slug'];
							break;
						}
					}
				}
				if ( null === $m_row || '' === $b_slug ) {
					$status = 'error';
				} else {
					$new_id                = CatalogRepository::upsert_version(
						array(
							'model_id'   => $model_id,
							'brand_slug' => $b_slug,
							'model_slug' => $m_row['slug'],
							'year_from'  => $year_from,
							'year_to'    => $year_to,
							'variant'    => $variant,
							'status'     => $ver_stat,
							'verified'   => $verified,
							'notes'      => $notes,
						)
					);
					$status                = $new_id > 0 ? 'saved' : 'error';
					$extra['edit_version'] = $new_id;
				}
			}
		} elseif ( 'delete_version' === $op ) {
			CatalogRepository::delete_version( $this->post_int( 'id' ) );
			$status = 'deleted';
			$extra  = $this->posted_filter_args();
		} elseif ( 'save_fitment' === $op ) {
			$vid                   = $this->post_int( 'version_id' );
			$extra['edit_version'] = $vid;
			$size                  = CatalogRepository::ensure_size( $this->post_text( 'size' ) );
			$pos                   = TireSpecs::normalize_position( $this->post_key( 'position' ) );
			if ( null === $size || ! in_array( $pos, array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ), true ) ) {
				$status = 'invalid_size';
			} else {
				$min_load  = TireSpecs::parse_load_index( $this->post_text( 'min_load' ) );
				$min_speed = TireSpecs::speed_rank( $this->post_text( 'min_speed' ) );
				$req_const = TireSpecs::normalize_construction( $this->post_text( 'req_construction' ) );
				$desig     = SizeParser::designation( $this->post_text( 'size' ) );
				if ( '' === $req_const && null !== $desig ) {
					$req_const = $desig['construction'];
				}
				CatalogRepository::upsert_fitment(
					array(
						'version_id'       => $vid,
						'position'         => $pos,
						'size_id'          => $size['id'],
						'fit_type'         => $this->post_key( 'fit_type' ),
						'min_load'         => $min_load,
						'min_speed_rank'   => $min_speed,
						'req_construction' => '' !== $req_const ? $req_const : null,
						'designation'      => ( null !== $desig && $desig['designation'] !== $size['canonical'] ) ? $desig['designation'] : null,
						'source'           => $this->post_key( 'source' ),
						'note'             => $this->post_text( 'note' ),
					)
				);
				$status = 'saved';
			}
		} elseif ( 'delete_fitment' === $op ) {
			$vid                   = $this->post_int( 'version_id' );
			$extra['edit_version'] = $vid;
			CatalogRepository::delete_fitment( $this->post_int( 'id' ) );
			$status = 'deleted';
		} elseif ( 'save_size' === $op ) {
			$size   = CatalogRepository::ensure_size( $this->post_text( 'raw_size' ) );
			$status = null !== $size ? 'saved' : 'invalid_size';
		} elseif ( 'save_equivalence' === $op ) {
			$src = CatalogRepository::ensure_size( $this->post_text( 'source_size' ) );
			$dst = CatalogRepository::ensure_size( $this->post_text( 'target_size' ) );
			if ( null === $src || null === $dst || $src['id'] === $dst['id'] ) {
				$status = 'invalid_size';
			} elseif ( $src['rim'] !== $dst['rim'] ) {
				$status = 'rim_mismatch';
			} else {
				CatalogRepository::upsert_equivalence(
					array(
						'source_size_id' => $src['id'],
						'target_size_id' => $dst['id'],
						'position_scope' => $this->post_key( 'position_scope' ),
						'is_approved'    => isset( $_POST['is_approved'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
						'is_approximate' => isset( $_POST['is_approximate'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
						'note'           => $this->post_text( 'note' ),
					),
					isset( $_POST['create_inverse'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				);
				$status = 'saved';
			}
			$extra = $this->posted_filter_args();
		} elseif ( 'toggle_equivalence' === $op ) {
			$eid      = $this->post_int( 'id' );
			$approved = 1 === $this->post_int( 'approved' );
			$ok       = CatalogRepository::set_equivalence_approved( $eid, $approved );
			$status   = $ok ? 'saved' : 'error';
			$extra    = $this->posted_filter_args();
		} elseif ( 'delete_equivalence' === $op ) {
			CatalogRepository::delete_equivalence( $this->post_int( 'id' ) );
			$status = 'deleted';
			$extra  = $this->posted_filter_args();
		} elseif ( 'sync_product' === $op ) {
			ProductIndexSync::sync_product( $this->post_int( 'product_id' ) );
			$status = 'saved';
		} elseif ( 'resume_import' === $op ) {
			$status = ImportRunner::resume_active() ? 'resumed' : 'error';
		} elseif ( 'cancel_import' === $op ) {
			$status = ImportRunner::cancel_active() ? 'cancelled' : 'error';
		}

		$redirect = add_query_arg(
			array_merge(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE,
					'tab'       => $tab,
					'msg'       => $status,
				),
				$extra
			),
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Sets the single OEM tire size of one axle for a motorcycle version (replaces the previous OEM size).
	 * An empty raw size leaves the axle untouched. Alternatives are never removed.
	 *
	 * @param int    $version_id Version ID.
	 * @param string $position   'front' or 'rear'.
	 * @param string $raw_size   Raw size typed by the admin (already validated by the caller).
	 */
	private function replace_oem_fitment( int $version_id, string $position, string $raw_size ): void {
		if ( '' === $raw_size ) {
			return;
		}
		$size = CatalogRepository::ensure_size( $raw_size );
		if ( null === $size ) {
			return;
		}
		foreach ( CatalogRepository::fitments_for_version( $version_id ) as $f ) {
			if ( $f['position'] === $position && TireSpecs::FIT_OEM === $f['fit_type'] && $f['size_id'] !== $size['id'] ) {
				CatalogRepository::delete_fitment( $f['id'] );
			}
		}
		$desig = SizeParser::designation( $raw_size );
		CatalogRepository::upsert_fitment(
			array(
				'version_id'       => $version_id,
				'position'         => $position,
				'size_id'          => $size['id'],
				'fit_type'         => TireSpecs::FIT_OEM,
				'min_load'         => null,
				'min_speed_rank'   => null,
				'req_construction' => ( null !== $desig && '' !== $desig['construction'] ) ? $desig['construction'] : null,
				'designation'      => ( null !== $desig && $desig['designation'] !== $size['canonical'] ) ? $desig['designation'] : null,
				'source'           => 'manual',
				'note'             => '',
			)
		);
	}

	/**
	 * Renders the Productos > Compatibilidad management screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'motored-core' ), 403 );
		}

		$tab = $this->get_key( 'tab', 'versions' );
		// Primary flow: Motorcycles (moto -> years -> front/rear tire size) and Import / Export.
		// Everything else is "Advanced" (catalog maintenance), reachable from one secondary menu.
		$advanced = array(
			'brands'       => __( 'Brands', 'motored-core' ),
			'models'       => __( 'Models', 'motored-core' ),
			'sizes'        => __( 'Sizes', 'motored-core' ),
			'equivalences' => __( 'Equivalences', 'motored-core' ),
			'report'       => __( 'Product Index', 'motored-core' ),
		);
		$tabs     = array(
			'versions' => __( 'Motorcycles', 'motored-core' ),
			'import'   => __( 'Import / Export', 'motored-core' ),
			'brands'   => __( 'Advanced', 'motored-core' ),
		);
		if ( ! isset( $tabs[ $tab ] ) && ! isset( $advanced[ $tab ] ) ) {
			$tab = 'versions';
		}
		$nav_active = isset( $advanced[ $tab ] ) ? 'brands' : $tab;

		echo '<div class="wrap motored-admin">';
		echo '<h1>' . esc_html__( 'Motorcycle Compatibility', 'motored-core' ) . '</h1>';
		$this->render_notice();
		$this->render_tool_feedback_notice( false );

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$url    = add_query_arg(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE,
					'tab'       => $key,
				),
				admin_url( 'edit.php' )
			);
			$active = $nav_active === $key ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( isset( $advanced[ $tab ] ) ) {
			$links = array();
			foreach ( $advanced as $key => $label ) {
				$url     = add_query_arg(
					array(
						'post_type' => 'product',
						'page'      => self::PAGE,
						'tab'       => $key,
					),
					admin_url( 'edit.php' )
				);
				$links[] = $tab === $key
					? '<strong>' . esc_html( $label ) . '</strong>'
					: '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '<p class="motored-actions">' . implode( ' | ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped above.
		}
		echo '<p></p>';

		if ( 'versions' === $tab ) {
			$this->render_versions_tab();
		} elseif ( 'brands' === $tab ) {
			$this->render_brands_tab();
		} elseif ( 'models' === $tab ) {
			$this->render_models_tab();
		} elseif ( 'equivalences' === $tab ) {
			$this->render_equivalences_tab();
		} elseif ( 'sizes' === $tab ) {
			$this->render_sizes_tab();
		} elseif ( 'import' === $tab ) {
			$this->render_import_tab();
		} elseif ( 'report' === $tab ) {
			$this->render_report_tab();
		}

		echo '</div>';
	}

	/**
	 * Renders the overview stats, active import banner and quick-start guide.
	 *
	 * @param array{brands:int,models:int,versions_published:int,versions_draft:int,versions_verified:int,sizes:int,equivalences_approved:int,equivalences_pending:int} $counts Catalog summary counts.
	 */
	private function render_overview_box( array $counts ): void {
		$indexed      = ProductIndexRepository::count_indexed();
		$enabled      = Config::enabled();
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=motored&section=' . self::SECTION );
		$base_url     = admin_url( 'edit.php?post_type=product&page=' . self::PAGE );

		Ui::box_open();
		echo '<div class="motored-box__head">';
		echo '<h2 class="motored-box__title">' . esc_html__( 'Compatibility overview', 'motored-core' ) . '</h2>';
		echo '<div class="motored-actions">';
		echo '<strong>' . esc_html( $enabled ? __( 'Storefront: Active', 'motored-core' ) : __( 'Storefront: Disabled', 'motored-core' ) ) . '</strong>';
		echo '<a class="button" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings & setup tools', 'motored-core' ) . '</a>';
		echo '</div></div>';

		echo '<div class="motored-box__stats">';
		echo '<a class="motored-box__stat" href="' . esc_url(
			add_query_arg(
				array(
					'tab'    => 'versions',
					'status' => 'published',
				),
				$base_url
			)
		) . '"><strong>' . esc_html( (string) $counts['versions_published'] ) . '</strong> ' . esc_html__( 'Published motorcycles', 'motored-core' ) . '</a>';
		echo '<a class="motored-box__stat" href="' . esc_url(
			add_query_arg(
				array(
					'tab'    => 'versions',
					'status' => 'draft',
				),
				$base_url
			)
		) . '"><strong>' . esc_html( (string) $counts['versions_draft'] ) . '</strong> ' . esc_html__( 'Drafts to review', 'motored-core' ) . '</a>';
		echo '<a class="motored-box__stat" href="' . esc_url( add_query_arg( array( 'tab' => 'report' ), $base_url ) ) . '"><strong>' . esc_html( (string) $indexed ) . '</strong> ' . esc_html__( 'Indexed tire products', 'motored-core' ) . '</a>';
		echo '<a class="motored-box__stat" href="' . esc_url( add_query_arg( array( 'tab' => 'equivalences' ), $base_url ) ) . '"><strong>' . esc_html( (string) $counts['equivalences_approved'] ) . '</strong> ' . esc_html__( 'Approved equivalences', 'motored-core' ) . '</a>';
		echo '</div>';

		$active_job = ImportRunner::active_job();
		if ( null !== $active_job ) {
			$last_st = ImportRunner::get_last_status();
			$offset  = (int) ( $last_st['offset'] ?? 0 );
			$total_v = (int) ( $last_st['total_versions'] ?? 0 );
			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: 1: processed versions, 2: total versions. */
					__( 'Background import in progress: %1$d / %2$d versions processed.', 'motored-core' ),
					$offset,
					$total_v
				)
			) . '</strong></p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '" class="motored-actions">';
			wp_nonce_field( self::CRUD_ACTION );
			echo '<input type="hidden" name="tab" value="versions" />';
			echo '<button type="submit" name="op" value="resume_import" class="button button-primary">' . esc_html__( 'Run next batch now', 'motored-core' ) . '</button>';
			echo '<button type="submit" name="op" value="cancel_import" class="button">' . esc_html__( 'Cancel import', 'motored-core' ) . '</button>';
			echo '</form>';
		}

		$guide_open = ( ! $enabled || 0 === $counts['versions_published'] ) ? ' open' : '';
		echo '<details class="motored-box__details"' . $guide_open . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed literal attribute.
		echo '<summary>' . esc_html__( 'How compatibility works (4-step setup)', 'motored-core' ) . '</summary>';
		echo '<ol class="motored-notes">';
		echo '<li>' . esc_html__( 'Install global attributes and fixed terms once in WooCommerce > Settings > Motored > Compatibility.', 'motored-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Load the base catalog or add motorcycles here with their front and rear OEM tire sizes.', 'motored-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Review draft motorcycles and publish them so customers can select them in the store and My Garage.', 'motored-core' ) . '</li>';
		echo '<li>' . esc_html__( 'Assign Position and Front/Rear size attributes on tire products — WooCommerce auto-indexes them on save.', 'motored-core' ) . '</li>';
		echo '</ol></details>';
		Ui::box_close();
	}

	/**
	 * Renders the unified 1-step Quick Add Motorcycle form with HTML5 datalists.
	 *
	 * @param bool $open_by_default Whether the details panel starts expanded.
	 */
	private function render_quick_add_box( bool $open_by_default ): void {
		$open_attr = $open_by_default ? ' open' : '';
		Ui::box_open();
		echo '<details class="motored-box__details"' . $open_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed literal attribute.
		echo '<summary>' . esc_html__( '+ Quick add motorcycle (Brand, Model, Years & Tire Sizes in 1 step)', 'motored-core' ) . '</summary><p></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="quick_add_moto" /><input type="hidden" name="tab" value="versions" />';
		echo '<div class="motored-box__grid">';
		Ui::field( __( 'Brand', 'motored-core' ), 'compat-qa-brand', '<input id="compat-qa-brand" type="text" name="brand_name" list="motored-brands-list" required placeholder="Honda" />' );
		Ui::field( __( 'Model', 'motored-core' ), 'compat-qa-model', '<input id="compat-qa-model" type="text" name="model_name" list="motored-models-list" required placeholder="XR 250 Tornado" />' );
		Ui::field( __( 'Year from', 'motored-core' ), 'compat-qa-yf', '<input id="compat-qa-yf" type="number" name="year_from" min="1950" max="2100" required placeholder="2018" />' );
		Ui::field( __( 'Year to (0 = present)', 'motored-core' ), 'compat-qa-yt', '<input id="compat-qa-yt" type="number" name="year_to" min="0" max="2100" value="0" />' );
		Ui::field( __( 'Front tire size (OEM)', 'motored-core' ), 'compat-qa-front', '<input id="compat-qa-front" type="text" name="front_size" list="motored-sizes-list" placeholder="110/80-19 / 120/70ZR17" />' );
		Ui::field( __( 'Rear tire size (OEM)', 'motored-core' ), 'compat-qa-rear', '<input id="compat-qa-rear" type="text" name="rear_size" list="motored-sizes-list" placeholder="150/70R17" />' );
		Ui::field( __( 'Version (optional)', 'motored-core' ), 'compat-qa-var', '<input id="compat-qa-var" type="text" name="variant" placeholder="ABS, Rally, SC28" />' );
		Ui::field( __( 'Displacement cc (new model)', 'motored-core' ), 'compat-qa-cc', '<input id="compat-qa-cc" type="number" name="cc" min="1" max="3000" placeholder="250" />' );
		Ui::field( __( 'Search aliases (new model)', 'motored-core' ), 'compat-qa-aliases', '<input id="compat-qa-aliases" type="text" name="aliases" placeholder="Tornado, XR250" />' );
		$st_sel = '<select id="compat-qa-status" name="status"><option value="published">' . esc_html__( 'Published (visible now)', 'motored-core' ) . '</option><option value="draft">' . esc_html__( 'Draft (review first)', 'motored-core' ) . '</option></select>';
		Ui::field( __( 'Status', 'motored-core' ), 'compat-qa-status', $st_sel );
		Ui::field( __( 'Notes (optional)', 'motored-core' ), 'compat-qa-notes', '<input id="compat-qa-notes" type="text" name="notes" />' );
		Ui::field( __( 'Verified', 'motored-core' ), 'compat-qa-verified', '<label class="motored-field__check"><input id="compat-qa-verified" type="checkbox" name="verified" value="1" checked="checked" /> ' . esc_html__( 'Checked against manual or OEM catalog', 'motored-core' ) . '</label>' );
		echo '</div>';
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save motorcycle', 'motored-core' ) . '</button></div>';
		echo '<p class="description">' . esc_html__( 'Existing brand and model names are matched automatically. After saving, you can add alternative sizes or inspect matching products.', 'motored-core' ) . '</p>';
		echo '</form></details>';
		Ui::box_close();
	}

	/**
	 * Brands tab: add/edit + paginated list with drill-down links.
	 */
	private function render_brands_tab(): void {
		$search  = $this->get_text( 's' );
		$paged   = max( 1, $this->get_int( 'paged', 1 ) );
		$edit_id = $this->get_int( 'edit_brand' );
		$list    = CatalogRepository::list_brands( $search, $paged, 25 );

		if ( $edit_id > 0 ) {
			$edit_brand = CatalogRepository::find_brand_by_id( $edit_id );
			if ( null !== $edit_brand ) {
				$cancel_url = add_query_arg(
					array(
						'post_type' => 'product',
						'page'      => self::PAGE,
						'tab'       => 'brands',
					),
					admin_url( 'edit.php' )
				);
				Ui::box_open();
				echo '<h2 class="motored-box__title">' . esc_html__( 'Edit brand', 'motored-core' ) . '</h2>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
				wp_nonce_field( self::CRUD_ACTION );
				echo '<input type="hidden" name="op" value="update_brand" /><input type="hidden" name="tab" value="brands" /><input type="hidden" name="id" value="' . esc_attr( (string) $edit_brand['id'] ) . '" />';
				Ui::field( __( 'Brand name', 'motored-core' ), 'compat-eb-name', '<input id="compat-eb-name" type="text" name="name" required value="' . esc_attr( $edit_brand['name'] ) . '" />' );
				echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Update brand', 'motored-core' ) . '</button>';
				echo '<a class="button" href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Cancel', 'motored-core' ) . '</a></div>';
				echo '</form>';
				Ui::box_close();
			}
		}

		Ui::box_open();
		echo '<details class="motored-box__details">';
		echo '<summary>' . esc_html__( '+ Add brand', 'motored-core' ) . '</summary><p></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_brand" /><input type="hidden" name="tab" value="brands" />';
		Ui::field( __( 'Brand name', 'motored-core' ), 'compat-brand-name', '<input id="compat-brand-name" type="text" name="name" required placeholder="Honda" />' );
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save brand', 'motored-core' ) . '</button></div>';
		echo '</form></details>';
		Ui::box_close();

		$this->render_search_bar( 'brands', $search );
		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Brand', 'motored-core' )   => 'grow',
				__( 'Slug', 'motored-core' )    => 'md',
				__( 'Models', 'motored-core' )  => 'xs',
				__( 'Actions', 'motored-core' ) => 'lg',
			)
		);
		echo '<tbody>';
		foreach ( $list['items'] as $row ) {
			$models_url = add_query_arg(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE,
					'tab'       => 'models',
					'brand_id'  => $row['id'],
				),
				admin_url( 'edit.php' )
			);
			$motos_url  = add_query_arg(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE,
					'tab'       => 'versions',
					'brand_id'  => $row['id'],
				),
				admin_url( 'edit.php' )
			);
			$edit_url   = add_query_arg(
				array(
					'post_type'  => 'product',
					'page'       => self::PAGE,
					'tab'        => 'brands',
					'edit_brand' => $row['id'],
				),
				admin_url( 'edit.php' )
			);
			echo '<tr><td><strong>' . esc_html( $row['name'] ) . '</strong></td><td class="motored-mono">' . esc_html( $row['slug'] ) . '</td>';
			echo '<td class="motored-cell-center"><a href="' . esc_url( $models_url ) . '">' . esc_html( (string) $row['models_count'] ) . '</a></td><td>';
			echo '<div class="motored-actions">';
			echo '<a class="button" href="' . esc_url( $motos_url ) . '">' . esc_html__( 'Motorcycles', 'motored-core' ) . '</a>';
			echo '<a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'motored-core' ) . '</a>';
			if ( 0 === $row['models_count'] ) {
				$this->render_delete_btn( 'delete_brand', 'brands', $row['id'], array( 's' => $search ) );
			}
			echo '</div></td></tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		$this->render_pagination( 'brands', $paged, $list['total'], 25, array( 's' => $search ) );
	}

	/**
	 * Models tab: add/edit + brand-filtered paginated list.
	 */
	private function render_models_tab(): void {
		$search   = $this->get_text( 's' );
		$brand_id = $this->get_int( 'brand_id' );
		$edit_id  = $this->get_int( 'edit_model' );
		$paged    = max( 1, $this->get_int( 'paged', 1 ) );
		$brands   = CatalogRepository::brands();
		$list     = CatalogRepository::list_models( $brand_id, $search, $paged, 25 );
		$filters  = array(
			'brand_id' => $brand_id > 0 ? $brand_id : '',
			's'        => $search,
		);

		if ( $edit_id > 0 ) {
			$edit_model = CatalogRepository::find_model_by_id( $edit_id );
			if ( null !== $edit_model ) {
				$cancel_url = add_query_arg(
					array_filter(
						array(
							'post_type' => 'product',
							'page'      => self::PAGE,
							'tab'       => 'models',
							'brand_id'  => $brand_id > 0 ? $brand_id : null,
						)
					),
					admin_url( 'edit.php' )
				);
				Ui::box_open();
				echo '<h2 class="motored-box__title">' . esc_html__( 'Edit model', 'motored-core' ) . '</h2>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
				wp_nonce_field( self::CRUD_ACTION );
				echo '<input type="hidden" name="op" value="update_model" /><input type="hidden" name="tab" value="models" /><input type="hidden" name="id" value="' . esc_attr( (string) $edit_model['id'] ) . '" />';
				$this->render_filter_hidden_inputs( $filters );
				echo '<div class="motored-box__grid">';
				Ui::field( __( 'Model name', 'motored-core' ), 'compat-em-name', '<input id="compat-em-name" type="text" name="name" required value="' . esc_attr( $edit_model['name'] ) . '" />' );
				Ui::field( __( 'Displacement (cc)', 'motored-core' ), 'compat-em-cc', '<input id="compat-em-cc" type="number" name="cc" min="1" max="3000" value="' . esc_attr( null !== $edit_model['cc'] ? (string) $edit_model['cc'] : '' ) . '" />' );
				Ui::field( __( 'Aliases (optional)', 'motored-core' ), 'compat-em-aliases', '<input id="compat-em-aliases" type="text" name="aliases" value="' . esc_attr( $edit_model['aliases'] ) . '" />' );
				echo '</div>';
				echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Update model', 'motored-core' ) . '</button>';
				echo '<a class="button" href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Cancel', 'motored-core' ) . '</a></div>';
				echo '</form>';
				Ui::box_close();
			}
		}

		Ui::box_open();
		echo '<details class="motored-box__details">';
		echo '<summary>' . esc_html__( '+ Add model', 'motored-core' ) . '</summary><p></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_model" /><input type="hidden" name="tab" value="models" />';
		$this->render_filter_hidden_inputs( $filters );
		$opts = '<select id="compat-model-brand" name="brand_id" required>';
		foreach ( $brands as $b ) {
			$opts .= '<option value="' . esc_attr( (string) $b['id'] ) . '"' . selected( $brand_id, $b['id'], false ) . '>' . esc_html( $b['name'] ) . '</option>';
		}
		$opts .= '</select>';
		echo '<div class="motored-box__grid">';
		Ui::field( __( 'Brand', 'motored-core' ), 'compat-model-brand', $opts );
		Ui::field( __( 'Model name', 'motored-core' ), 'compat-model-name', '<input id="compat-model-name" type="text" name="name" required placeholder="XR 250 Tornado" />' );
		Ui::field( __( 'Displacement (cc)', 'motored-core' ), 'compat-model-cc', '<input id="compat-model-cc" type="number" name="cc" min="1" max="3000" />' );
		Ui::field( __( 'Aliases (optional)', 'motored-core' ), 'compat-model-aliases', '<input id="compat-model-aliases" type="text" name="aliases" placeholder="Tornado, XR250" />' );
		echo '</div>';
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save model', 'motored-core' ) . '</button></div>';
		echo '</form></details>';
		Ui::box_close();

		echo '<form method="get" class="motored-actions">';
		echo '<input type="hidden" name="post_type" value="product" /><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="tab" value="models" />';
		echo '<select name="brand_id"><option value="0">' . esc_html__( 'All brands', 'motored-core' ) . '</option>';
		foreach ( $brands as $b ) {
			echo '<option value="' . esc_attr( (string) $b['id'] ) . '"' . selected( $brand_id, $b['id'], false ) . '>' . esc_html( $b['name'] ) . '</option>';
		}
		echo '</select>';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search model or alias...', 'motored-core' ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'motored-core' ) . '</button>';
		if ( $brand_id > 0 || '' !== $search ) {
			$reset_url = add_query_arg(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE,
					'tab'       => 'models',
				),
				admin_url( 'edit.php' )
			);
			echo '<a class="button" href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Reset', 'motored-core' ) . '</a>';
		}
		echo '</form>';

		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Brand', 'motored-core' )    => 'md',
				__( 'Model', 'motored-core' )    => 'grow',
				__( 'cc', 'motored-core' )       => 'xs',
				__( 'Aliases', 'motored-core' )  => 'lg',
				__( 'Versions', 'motored-core' ) => 'xs',
				__( 'Actions', 'motored-core' )  => 'md',
			)
		);
		echo '<tbody>';
		foreach ( $list['items'] as $row ) {
			$motos_url = add_query_arg(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE,
					'tab'       => 'versions',
					'model_id'  => $row['id'],
				),
				admin_url( 'edit.php' )
			);
			$edit_url  = add_query_arg(
				array_filter(
					array(
						'post_type'  => 'product',
						'page'       => self::PAGE,
						'tab'        => 'models',
						'brand_id'   => $brand_id > 0 ? $brand_id : null,
						'edit_model' => $row['id'],
					)
				),
				admin_url( 'edit.php' )
			);
			echo '<tr><td>' . esc_html( $row['brand_name'] ) . '</td><td><strong>' . esc_html( $row['name'] ) . '</strong></td>';
			echo '<td class="motored-cell-center">' . esc_html( null !== $row['cc'] ? (string) $row['cc'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $row['aliases'] ) . '</td>';
			echo '<td class="motored-cell-center"><a href="' . esc_url( $motos_url ) . '">' . esc_html( (string) $row['versions_count'] ) . '</a></td><td>';
			echo '<div class="motored-actions">';
			echo '<a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'motored-core' ) . '</a>';
			if ( 0 === $row['versions_count'] ) {
				$this->render_delete_btn( 'delete_model', 'models', $row['id'], $filters );
			}
			echo '</div></td></tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		$this->render_pagination( 'models', $paged, $list['total'], 25, $filters );
	}

	/**
	 * Motorcycles (Versions & Fitments) tab: overview, 1-step Quick Add, filters, bulk actions & inline tire sizes.
	 */
	private function render_versions_tab(): void {
		$edit_id = $this->get_int( 'edit_version' );
		if ( $edit_id > 0 ) {
			$this->render_version_fitments_editor( $edit_id );
			return;
		}

		$search   = $this->get_text( 's' );
		$brand_id = $this->get_int( 'brand_id' );
		$model_id = $this->get_int( 'model_id' );
		$status_f = $this->get_key( 'status' );
		$ver_f    = $this->get_text( 'verified' );
		$ver_f    = in_array( $ver_f, array( '0', '1' ), true ) ? $ver_f : '';
		$paged    = max( 1, $this->get_int( 'paged', 1 ) );

		$filters = array(
			'brand_id' => $brand_id > 0 ? $brand_id : 0,
			'model_id' => $model_id > 0 ? $model_id : 0,
			'status'   => in_array( $status_f, array( 'published', 'draft' ), true ) ? $status_f : '',
			'verified' => $ver_f,
			'search'   => $search,
		);

		$filter_args = array(
			'brand_id' => $filters['brand_id'] > 0 ? $filters['brand_id'] : '',
			'model_id' => $filters['model_id'] > 0 ? $filters['model_id'] : '',
			'status'   => $filters['status'],
			'verified' => $filters['verified'],
			's'        => $search,
			'paged'    => $paged > 1 ? $paged : '',
		);

		$counts     = CatalogRepository::summary_counts();
		$brands     = CatalogRepository::brands();
		$list       = CatalogRepository::list_versions( $filters, $paged, 25 );
		$msg_code   = $this->get_key( 'msg' );
		$open_quick = ( 0 === ( $counts['versions_published'] + $counts['versions_draft'] ) )
			|| in_array( $msg_code, array( 'overlap', 'invalid_years', 'invalid_size', 'error' ), true );

		$this->render_catalog_datalists( true, true );
		$this->render_overview_box( $counts );
		$this->render_quick_add_box( $open_quick );

		echo '<form method="get" class="motored-actions">';
		echo '<input type="hidden" name="post_type" value="product" /><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="tab" value="versions" />';
		if ( $model_id > 0 ) {
			echo '<input type="hidden" name="model_id" value="' . esc_attr( (string) $model_id ) . '" />';
		}
		echo '<select name="brand_id"><option value="0">' . esc_html__( 'All brands', 'motored-core' ) . '</option>';
		foreach ( $brands as $b ) {
			echo '<option value="' . esc_attr( (string) $b['id'] ) . '"' . selected( $brand_id, $b['id'], false ) . '>' . esc_html( $b['name'] ) . '</option>';
		}
		echo '</select>';
		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'motored-core' ) . '</option>';
		echo '<option value="published"' . selected( $filters['status'], 'published', false ) . '>' . esc_html__( 'Published', 'motored-core' ) . '</option>';
		echo '<option value="draft"' . selected( $filters['status'], 'draft', false ) . '>' . esc_html__( 'Draft', 'motored-core' ) . '</option>';
		echo '</select>';
		echo '<select name="verified"><option value="">' . esc_html__( 'Verified: Any', 'motored-core' ) . '</option>';
		echo '<option value="1"' . selected( $ver_f, '1', false ) . '>' . esc_html__( 'Verified only', 'motored-core' ) . '</option>';
		echo '<option value="0"' . selected( $ver_f, '0', false ) . '>' . esc_html__( 'Pending review', 'motored-core' ) . '</option>';
		echo '</select>';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search brand, model, alias...', 'motored-core' ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'motored-core' ) . '</button>';
		if ( $brand_id > 0 || $model_id > 0 || '' !== $filters['status'] || '' !== $ver_f || '' !== $search ) {
			$reset_url = add_query_arg(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE,
					'tab'       => 'versions',
				),
				admin_url( 'edit.php' )
			);
			echo '<a class="button" href="' . esc_url( $reset_url ) . '">' . esc_html__( 'Reset', 'motored-core' ) . '</a>';
		}
		echo '</form>';

		$ver_ids = array();
		foreach ( $list['items'] as $row ) {
			$ver_ids[] = (int) $row['id'];
		}
		$fit_map = CatalogRepository::fitments_for_versions( $ver_ids );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="bulk_versions" /><input type="hidden" name="tab" value="versions" />';
		$this->render_filter_hidden_inputs( $filter_args );

		echo '<div class="motored-actions">';
		echo '<select name="bulk_action"><option value="">' . esc_html__( 'Bulk actions...', 'motored-core' ) . '</option>';
		echo '<option value="publish_verify">' . esc_html__( 'Publish & verify', 'motored-core' ) . '</option>';
		echo '<option value="publish">' . esc_html__( 'Publish', 'motored-core' ) . '</option>';
		echo '<option value="draft">' . esc_html__( 'Set as draft', 'motored-core' ) . '</option>';
		echo '<option value="delete">' . esc_html__( 'Delete', 'motored-core' ) . '</option>';
		echo '</select>';
		echo '<button type="submit" class="button">' . esc_html__( 'Apply', 'motored-core' ) . '</button>';
		echo '</div>';

		Ui::table_open();
		echo '<thead><tr>';
		echo '<th class="motored-col-check"><span class="screen-reader-text">' . esc_html__( 'Select', 'motored-core' ) . '</span></th>';
		echo '<th class="motored-col-grow">' . esc_html__( 'Motorcycle', 'motored-core' ) . '</th>';
		echo '<th class="motored-col-sm">' . esc_html__( 'Years', 'motored-core' ) . '</th>';
		echo '<th class="motored-col-lg">' . esc_html__( 'Tire sizes', 'motored-core' ) . '</th>';
		echo '<th class="motored-col-sm">' . esc_html__( 'Status', 'motored-core' ) . '</th>';
		echo '<th class="motored-col-lg">' . esc_html__( 'Actions', 'motored-core' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';
		if ( empty( $list['items'] ) ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No motorcycles match the current filters.', 'motored-core' ) . '</td></tr>';
		}
		foreach ( $list['items'] as $row ) {
			$vid      = (int) $row['id'];
			$name     = trim( (string) $row['brand_name'] . ' ' . (string) $row['model_name'] . ( '' !== (string) $row['variant'] ? ' ' . (string) $row['variant'] : '' ) );
			$years    = MatchEngine::format_years( (int) $row['year_from'], (int) $row['year_to'] );
			$is_pub   = 'published' === (string) $row['status'];
			$is_ver   = ! empty( $row['verified'] );
			$edit_url = add_query_arg(
				array(
					'post_type'    => 'product',
					'page'         => self::PAGE,
					'tab'          => 'versions',
					'edit_version' => $vid,
				),
				admin_url( 'edit.php' )
			);
			$row_fits = $fit_map[ $vid ] ?? array();
			$f_sizes  = array();
			$r_sizes  = array();
			foreach ( $row_fits as $rf ) {
				$lbl = $rf['designation'] . ( TireSpecs::FIT_ALT === $rf['fit_type'] ? ' (' . __( 'Alt', 'motored-core' ) . ')' : '' );
				if ( TireSpecs::POS_FRONT === $rf['position'] ) {
					$f_sizes[] = $lbl;
				} else {
					$r_sizes[] = $lbl;
				}
			}

			echo '<tr>';
			echo '<td class="motored-cell-center"><input type="checkbox" name="ids[]" value="' . esc_attr( (string) $vid ) . '" /></td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '"><strong>' . esc_html( $name ) . '</strong></a><br><span class="motored-mono">' . esc_html( (string) $row['slug'] ) . '</span></td>';
			echo '<td>' . esc_html( $years ) . '</td>';
			echo '<td class="motored-mono">';
			if ( empty( $f_sizes ) && empty( $r_sizes ) ) {
				echo esc_html__( 'No sizes yet', 'motored-core' );
			} else {
				if ( ! empty( $f_sizes ) ) {
					echo esc_html( __( 'F:', 'motored-core' ) . ' ' . implode( ', ', $f_sizes ) );
				}
				if ( ! empty( $f_sizes ) && ! empty( $r_sizes ) ) {
					echo '<br>';
				}
				if ( ! empty( $r_sizes ) ) {
					echo esc_html( __( 'R:', 'motored-core' ) . ' ' . implode( ', ', $r_sizes ) );
				}
			}
			echo '</td>';
			echo '<td><strong>' . esc_html( $is_pub ? __( 'Published', 'motored-core' ) : __( 'Draft', 'motored-core' ) ) . '</strong><br>';
			echo esc_html( $is_ver ? __( 'Verified', 'motored-core' ) : __( 'Pending review', 'motored-core' ) ) . '</td>';
			echo '<td><div class="motored-actions">';
			if ( $is_pub ) {
				echo '<button type="submit" class="button" name="row_action" value="' . esc_attr( 'draft:' . $vid ) . '">' . esc_html__( 'Set draft', 'motored-core' ) . '</button>';
			} else {
				echo '<button type="submit" class="button button-primary" name="row_action" value="' . esc_attr( 'publish:' . $vid ) . '">' . esc_html__( 'Publish', 'motored-core' ) . '</button>';
			}
			echo '<a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'motored-core' ) . '</a>';
			echo '<button type="submit" class="button" name="row_action" value="' . esc_attr( 'delete:' . $vid ) . '">' . esc_html__( 'Delete', 'motored-core' ) . '</button>';
			echo '</div></td>';
			echo '</tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		echo '</form>';
		$this->render_pagination( 'versions', $paged, $list['total'], 25, $filter_args );
	}

	/**
	 * Renders single-version edit, live store matching preview and fitments manager.
	 *
	 * @param int $version_id Version ID.
	 */
	private function render_version_fitments_editor( int $version_id ): void {
		$ver = CatalogRepository::find_version( $version_id );
		if ( null === $ver ) {
			echo '<p>' . esc_html__( 'Version not found.', 'motored-core' ) . '</p>';
			return;
		}
		$this->render_catalog_datalists( false, true );
		$fitments = CatalogRepository::fitments_for_version( $version_id );
		$back_url = add_query_arg(
			array(
				'post_type' => 'product',
				'page'      => self::PAGE,
				'tab'       => 'versions',
			),
			admin_url( 'edit.php' )
		);

		echo '<div class="motored-actions">';
		echo '<a class="button" href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to motorcycles', 'motored-core' ) . '</a>';
		if ( 'published' !== (string) $ver['status'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
			wp_nonce_field( self::CRUD_ACTION );
			echo '<input type="hidden" name="op" value="publish_verify_version" /><input type="hidden" name="tab" value="versions" /><input type="hidden" name="id" value="' . esc_attr( (string) $version_id ) . '" />';
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Publish & verify now', 'motored-core' ) . '</button>';
			echo '</form>';
		}
		echo '</div>';

		$preview = $this->compute_store_match_preview( $fitments );
		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Store matching preview', 'motored-core' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: front matches, 2: rear matches, 3: kit matches. */
				__( 'Matching indexed products in store right now: Front: %1$d · Rear: %2$d · Complete kits: %3$d', 'motored-core' ),
				$preview['front'],
				$preview['rear'],
				$preview['kit']
			)
		) . '</p>';
		if ( 'published' !== (string) $ver['status'] ) {
			echo '<p class="description">' . esc_html__( 'This motorcycle is currently a draft and is hidden from storefront selectors until published.', 'motored-core' ) . '</p>';
		}
		Ui::box_close();

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html( MatchEngine::version_label( (string) $ver['brand_name'], (string) $ver['model_name'], (string) $ver['variant'], (int) $ver['year_from'], (int) $ver['year_to'] ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_version" /><input type="hidden" name="tab" value="versions" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $version_id ) . '" /><input type="hidden" name="model_id" value="' . esc_attr( (string) $ver['model_id'] ) . '" />';
		$oem_front = '';
		$oem_rear  = '';
		foreach ( $fitments as $f ) {
			if ( TireSpecs::FIT_OEM !== $f['fit_type'] ) {
				continue;
			}
			if ( TireSpecs::POS_FRONT === $f['position'] && '' === $oem_front ) {
				$oem_front = (string) $f['designation'];
			} elseif ( TireSpecs::POS_REAR === $f['position'] && '' === $oem_rear ) {
				$oem_rear = (string) $f['designation'];
			}
		}
		echo '<div class="motored-box__grid">';
		Ui::field( __( 'Front tire size (OEM, as printed in the manual)', 'motored-core' ), 'compat-ev-front', '<input id="compat-ev-front" type="text" name="front_size" list="motored-sizes-list" value="' . esc_attr( $oem_front ) . '" placeholder="110/80-19 / 120/70ZR17" />' );
		Ui::field( __( 'Rear tire size (OEM, as printed in the manual)', 'motored-core' ), 'compat-ev-rear', '<input id="compat-ev-rear" type="text" name="rear_size" list="motored-sizes-list" value="' . esc_attr( $oem_rear ) . '" placeholder="150/70R17" />' );
		Ui::field( __( 'Year from', 'motored-core' ), 'compat-ev-yf', '<input id="compat-ev-yf" type="number" name="year_from" value="' . esc_attr( (string) $ver['year_from'] ) . '" required />' );
		Ui::field( __( 'Year to (0 = present)', 'motored-core' ), 'compat-ev-yt', '<input id="compat-ev-yt" type="number" name="year_to" value="' . esc_attr( (string) $ver['year_to'] ) . '" />' );
		Ui::field( __( 'Version (optional)', 'motored-core' ), 'compat-ev-var', '<input id="compat-ev-var" type="text" name="variant" placeholder="ABS, Rally, SC28" value="' . esc_attr( (string) $ver['variant'] ) . '" />' );
		$st_sel = '<select id="compat-ev-st" name="status"><option value="draft"' . selected( $ver['status'], 'draft', false ) . '>' . esc_html__( 'Draft', 'motored-core' ) . '</option><option value="published"' . selected( $ver['status'], 'published', false ) . '>' . esc_html__( 'Published', 'motored-core' ) . '</option></select>';
		Ui::field( __( 'Status', 'motored-core' ), 'compat-ev-st', $st_sel );
		Ui::field( __( 'Notes', 'motored-core' ), 'compat-ev-notes', '<input id="compat-ev-notes" type="text" name="notes" value="' . esc_attr( (string) $ver['notes'] ) . '" />' );
		Ui::field( __( 'Verified', 'motored-core' ), 'compat-ev-ver', '<label class="motored-field__check"><input id="compat-ev-ver" type="checkbox" name="verified" value="1"' . checked( ! empty( $ver['verified'] ), true, false ) . ' /> ' . esc_html__( 'Verified specification', 'motored-core' ) . '</label>' );
		echo '</div>';
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Update motorcycle', 'motored-core' ) . '</button></div>';
		echo '</form>';
		Ui::box_close();

		$has_front = false;
		$has_rear  = false;
		foreach ( $fitments as $f ) {
			if ( TireSpecs::POS_FRONT === $f['position'] ) {
				$has_front = true;
			} elseif ( TireSpecs::POS_REAR === $f['position'] ) {
				$has_rear = true;
			}
		}
		$default_pos = ( $has_front && ! $has_rear ) ? TireSpecs::POS_REAR : TireSpecs::POS_FRONT;

		Ui::box_open();
		echo '<details class="motored-box__details"><summary>' . esc_html__( 'Add an alternative size or a size with safety limits (optional)', 'motored-core' ) . '</summary><p></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_fitment" /><input type="hidden" name="tab" value="versions" /><input type="hidden" name="version_id" value="' . esc_attr( (string) $version_id ) . '" />';
		echo '<div class="motored-box__grid">';
		Ui::field( __( 'Position', 'motored-core' ), 'compat-fit-pos', '<select id="compat-fit-pos" name="position"><option value="front"' . selected( $default_pos, TireSpecs::POS_FRONT, false ) . '>' . esc_html__( 'Front', 'motored-core' ) . '</option><option value="rear"' . selected( $default_pos, TireSpecs::POS_REAR, false ) . '>' . esc_html__( 'Rear', 'motored-core' ) . '</option></select>' );
		Ui::field( __( 'Tire size', 'motored-core' ), 'compat-fit-size', '<input id="compat-fit-size" type="text" name="size" list="motored-sizes-list" required placeholder="120/70-17 or 4.10-18" />' );
		Ui::field( __( 'Fitment type', 'motored-core' ), 'compat-fit-type', '<select id="compat-fit-type" name="fit_type"><option value="oem">' . esc_html__( 'OEM (original)', 'motored-core' ) . '</option><option value="alt">' . esc_html__( 'Alternative', 'motored-core' ) . '</option></select>' );
		Ui::field( __( 'Source', 'motored-core' ), 'compat-fit-src', '<select id="compat-fit-src" name="source"><option value="manual">' . esc_html__( 'Manual', 'motored-core' ) . '</option><option value="manufacturer">' . esc_html__( 'Manufacturer', 'motored-core' ) . '</option><option value="third_party">' . esc_html__( 'Third party', 'motored-core' ) . '</option></select>' );
		Ui::field( __( 'Note', 'motored-core' ), 'compat-fit-note', '<input id="compat-fit-note" type="text" name="note" />' );
		echo '</div>';
		echo '<details class="motored-box__details"><summary>' . esc_html__( 'Optional safety limits (leave empty unless needed for high-speed / heavy motorcycles)', 'motored-core' ) . '</summary><p></p>';
		echo '<div class="motored-box__grid">';
		Ui::field( __( 'Min load index (optional)', 'motored-core' ), 'compat-fit-load', '<input id="compat-fit-load" type="number" name="min_load" min="20" max="120" />' );
		Ui::field( __( 'Min speed rating (optional)', 'motored-core' ), 'compat-fit-spd', '<input id="compat-fit-spd" type="text" name="min_speed" placeholder="H, V, W" />' );
		Ui::field( __( 'Required construction (optional)', 'motored-core' ), 'compat-fit-const', '<select id="compat-fit-const" name="req_construction"><option value="">&mdash;</option><option value="radial">Radial</option><option value="diagonal">Diagonal</option><option value="cinturado">Belted (B)</option></select>' );
		echo '</div></details><p></p>';
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save fitment', 'motored-core' ) . '</button></div>';
		echo '</form></details>';
		Ui::box_close();

		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Position', 'motored-core' ) => 'sm',
				__( 'Size', 'motored-core' )     => 'md',
				__( 'Type', 'motored-core' )     => 'sm',
				__( 'Limits', 'motored-core' )   => 'md',
				__( 'Note', 'motored-core' )     => 'grow',
				__( 'Actions', 'motored-core' )  => 'xs',
			)
		);
		echo '<tbody>';
		foreach ( $fitments as $f ) {
			$limits = array();
			if ( null !== $f['min_load'] ) {
				$limits[] = 'L>=' . $f['min_load'];
			}
			if ( null !== $f['min_speed_rank'] ) {
				$limits[] = 'S>=' . TireSpecs::speed_code_for_rank( $f['min_speed_rank'] );
			}
			if ( null !== $f['req_construction'] ) {
				$limits[] = $f['req_construction'];
			}
			echo '<tr><td>' . esc_html( self::position_label( (string) $f['position'] ) ) . '</td><td class="motored-mono"><strong>' . esc_html( $f['designation'] ) . '</strong></td>';
			echo '<td>' . esc_html( TireSpecs::FIT_ALT === $f['fit_type'] ? __( 'Alternative', 'motored-core' ) : __( 'OEM (original)', 'motored-core' ) ) . '</td><td>' . esc_html( implode( ', ', $limits ) ) . '</td>';
			echo '<td>' . esc_html( $f['note'] ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
			wp_nonce_field( self::CRUD_ACTION );
			echo '<input type="hidden" name="op" value="delete_fitment" /><input type="hidden" name="tab" value="versions" />';
			echo '<input type="hidden" name="version_id" value="' . esc_attr( (string) $version_id ) . '" /><input type="hidden" name="id" value="' . esc_attr( (string) $f['id'] ) . '" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Delete', 'motored-core' ) . '</button></form></td></tr>';
		}
		echo '</tbody>';
		Ui::table_close();
	}

	/**
	 * Sizes tab.
	 */
	private function render_sizes_tab(): void {
		$search = $this->get_text( 's' );
		$paged  = max( 1, $this->get_int( 'paged', 1 ) );
		$list   = CatalogRepository::list_sizes( $search, $paged, 30 );

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Add tire size', 'motored-core' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_size" /><input type="hidden" name="tab" value="sizes" />';
		Ui::field( __( 'Raw or canonical size', 'motored-core' ), 'compat-size-raw', '<input id="compat-size-raw" type="text" name="raw_size" required placeholder="120/70ZR17, 4.10-18, MT90B16" />' );
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Normalize & save', 'motored-core' ) . '</button></div>';
		echo '</form>';
		Ui::box_close();

		$this->render_search_bar( 'sizes', $search );
		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Canonical key', 'motored-core' ) => 'grow',
				__( 'Notation', 'motored-core' )      => 'sm',
				__( 'Width', 'motored-core' )         => 'xs',
				__( 'Profile', 'motored-core' )       => 'xs',
				__( 'Rim', 'motored-core' )           => 'xs',
			)
		);
		echo '<tbody>';
		foreach ( $list['items'] as $row ) {
			echo '<tr><td class="motored-mono"><strong>' . esc_html( $row['canonical'] ) . '</strong></td><td>' . esc_html( $row['notation'] ) . '</td>';
			echo '<td>' . esc_html( $row['width'] ) . '</td><td>' . esc_html( null !== $row['aspect'] ? (string) $row['aspect'] : '—' ) . '</td><td>' . esc_html( (string) $row['rim'] ) . '</td></tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		$this->render_pagination( 'sizes', $paged, $list['total'], 30, array( 's' => $search ) );
	}

	/**
	 * Directed equivalences tab: size autocomplete, rim guard, 1-click approve/pause.
	 */
	private function render_equivalences_tab(): void {
		$search  = $this->get_text( 's' );
		$paged   = max( 1, $this->get_int( 'paged', 1 ) );
		$list    = CatalogRepository::list_equivalences( $search, $paged, 25 );
		$filters = array(
			's'     => $search,
			'paged' => $paged > 1 ? $paged : '',
		);

		$this->render_catalog_datalists( false, true );

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Add directed equivalence', 'motored-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Defines which target tire size can replace an OEM motorcycle tire size (1 hop only, never transitive, same rim diameter required).', 'motored-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_equivalence" /><input type="hidden" name="tab" value="equivalences" />';
		$this->render_filter_hidden_inputs( $filters );
		echo '<div class="motored-box__grid">';
		Ui::field( __( 'OEM source size (e.g. 2.75-18)', 'motored-core' ), 'compat-eq-src', '<input id="compat-eq-src" type="text" name="source_size" list="motored-sizes-list" required />' );
		Ui::field( __( 'Compatible target size (e.g. 80/100-18)', 'motored-core' ), 'compat-eq-dst', '<input id="compat-eq-dst" type="text" name="target_size" list="motored-sizes-list" required />' );
		Ui::field( __( 'Position scope', 'motored-core' ), 'compat-eq-scope', '<select id="compat-eq-scope" name="position_scope"><option value="any">' . esc_html__( 'Any axle', 'motored-core' ) . '</option><option value="front">' . esc_html__( 'Front only', 'motored-core' ) . '</option><option value="rear">' . esc_html__( 'Rear only', 'motored-core' ) . '</option></select>' );
		Ui::field( __( 'Note', 'motored-core' ), 'compat-eq-note', '<input id="compat-eq-note" type="text" name="note" />' );
		Ui::field( __( 'Bidirectional', 'motored-core' ), 'compat-eq-inv', '<label class="motored-field__check"><input id="compat-eq-inv" type="checkbox" name="create_inverse" value="1" /> ' . esc_html__( 'Create inverse too (target -> source)', 'motored-core' ) . '</label>' );
		Ui::field( __( 'Storefront status', 'motored-core' ), 'compat-eq-app', '<label class="motored-field__check"><input id="compat-eq-app" type="checkbox" name="is_approved" value="1" checked="checked" /> ' . esc_html__( 'Approved for storefront', 'motored-core' ) . '</label>' );
		Ui::field( __( 'Approximate flag', 'motored-core' ), 'compat-eq-approx', '<label class="motored-field__check"><input id="compat-eq-approx" type="checkbox" name="is_approximate" value="1" checked="checked" /> ' . esc_html__( 'Mark as approximate', 'motored-core' ) . '</label>' );
		echo '</div>';
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save equivalence', 'motored-core' ) . '</button></div>';
		echo '</form>';
		Ui::box_close();

		$this->render_search_bar( 'equivalences', $search );
		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Source (OEM)', 'motored-core' ) => 'md',
				__( 'Target', 'motored-core' )       => 'md',
				__( 'Scope', 'motored-core' )        => 'xs',
				__( 'Approved', 'motored-core' )     => 'xs',
				__( 'Note', 'motored-core' )         => 'grow',
				__( 'Actions', 'motored-core' )      => 'md',
			)
		);
		echo '<tbody>';
		$scope_labels = array(
			'any'   => __( 'Any axle', 'motored-core' ),
			'front' => __( 'Front only', 'motored-core' ),
			'rear'  => __( 'Rear only', 'motored-core' ),
		);
		foreach ( $list['items'] as $row ) {
			$eid       = (int) $row['id'];
			$is_app    = ! empty( $row['is_approved'] );
			$scope_lbl = $scope_labels[ (string) $row['position_scope'] ] ?? (string) $row['position_scope'];
			echo '<tr><td class="motored-mono">' . esc_html( (string) $row['source_canonical'] ) . '</td>';
			echo '<td class="motored-mono"><strong>' . esc_html( (string) $row['target_canonical'] ) . '</strong></td>';
			echo '<td>' . esc_html( $scope_lbl ) . '</td>';
			echo '<td class="motored-cell-center">' . ( $is_app ? esc_html__( 'Yes', 'motored-core' ) : esc_html__( 'No', 'motored-core' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['note'] ) . '</td><td>';
			echo '<div class="motored-actions">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
			wp_nonce_field( self::CRUD_ACTION );
			echo '<input type="hidden" name="op" value="toggle_equivalence" /><input type="hidden" name="tab" value="equivalences" /><input type="hidden" name="id" value="' . esc_attr( (string) $eid ) . '" /><input type="hidden" name="approved" value="' . ( $is_app ? '0' : '1' ) . '" />';
			$this->render_filter_hidden_inputs( $filters );
			echo '<button type="submit" class="button">' . esc_html( $is_app ? __( 'Pause', 'motored-core' ) : __( 'Approve', 'motored-core' ) ) . '</button>';
			echo '</form>';
			$this->render_delete_btn( 'delete_equivalence', 'equivalences', $eid, $filters );
			echo '</div></td></tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		$this->render_pagination( 'equivalences', $paged, $list['total'], 25, array( 's' => $search ) );
	}

	/**
	 * JSON import (dry-run + chunked import) and export tab.
	 */
	private function render_import_tab(): void {
		$raw_json           = '';
		$default_status     = 'draft';
		$import_constraints = false;
		$dry_result         = null;
		$run_result         = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked inside check_admin_referer below.
		if ( isset( $_POST['motored_import_nonce'] ) ) {
			check_admin_referer( 'motored_compat_import_form', 'motored_import_nonce' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated JSON payload.
			$raw_json = isset( $_POST['json_payload'] ) && is_string( $_POST['json_payload'] ) ? (string) wp_unslash( $_POST['json_payload'] ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' === trim( $raw_json ) && isset( $_FILES['json_file']['tmp_name'] ) && is_uploaded_file( (string) $_FILES['json_file']['tmp_name'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$raw_json = (string) file_get_contents( (string) $_FILES['json_file']['tmp_name'] );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$default_status     = ( isset( $_POST['default_status'] ) && 'published' === $_POST['default_status'] ) ? 'published' : 'draft';
			$import_constraints = isset( $_POST['import_constraints'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$opts               = array(
				'default_status'     => $default_status,
				'import_constraints' => $import_constraints,
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$mode = isset( $_POST['import_action'] ) && is_string( $_POST['import_action'] ) ? sanitize_key( wp_unslash( $_POST['import_action'] ) ) : 'dry_run';
			if ( 'resume_import' === $mode ) {
				$resumed    = ImportRunner::resume_active();
				$run_result = array(
					'ok'      => $resumed,
					'message' => $resumed
						? __( 'Processed the next queued import batch.', 'motored-core' )
						: __( 'There is no active import job to resume.', 'motored-core' ),
					'stats'   => array(),
				);
			} elseif ( 'cancel_import' === $mode ) {
				$cancelled  = ImportRunner::cancel_active();
				$run_result = array(
					'ok'      => $cancelled,
					'message' => $cancelled
						? __( 'Active import cancelled and temporary payload cleaned up.', 'motored-core' )
						: __( 'There is no active import job to cancel.', 'motored-core' ),
					'stats'   => array(),
				);
			} elseif ( 'run_import' === $mode ) {
				$run_result = ImportRunner::start( $raw_json, $opts );
			} else {
				$dry_result = JsonImporter::dry_run( $raw_json, $opts );
			}
		}

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Bundled base catalog (Argentina)', 'motored-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Imports the bundled starter catalog of Argentine motorcycles in one click (safe to repeat; existing data is updated, not duplicated).', 'motored-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::TOOL_ACTION ) ) . '" class="motored-actions">';
		wp_nonce_field( self::TOOL_ACTION, self::TOOL_NONCE, false );
		echo '<input type="hidden" name="tool" value="prefill_base_data" />';
		echo '<input type="hidden" name="return_to" value="import" />';
		echo '<label class="motored-field__check"><input type="checkbox" name="force_draft" value="1" /> ' . esc_html__( 'Import every motorcycle as draft', 'motored-core' ) . '</label>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Load base catalog (Argentina)', 'motored-core' ) . '</button>';
		echo '</form>';
		Ui::box_close();

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Export JSON catalog', 'motored-core' ) . '</h2>';
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ), self::EXPORT_ACTION );
		echo '<div class="motored-actions"><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Download catalog JSON (schema v1)', 'motored-core' ) . '</a></div>';
		Ui::box_close();

		$last_status = ImportRunner::get_last_status();
		if ( ! empty( $last_status ) ) {
			$st_code  = (string) ( $last_status['status'] ?? 'completed' );
			$is_busy  = in_array( $st_code, array( 'queued', 'running' ), true );
			$versions = (int) ( $last_status['versions'] ?? 0 );
			$fits     = (int) ( $last_status['fitments'] ?? 0 );
			$equivs   = (int) ( $last_status['equivalences'] ?? 0 );
			$rejected = (int) ( $last_status['rejected'] ?? 0 );
			$offset   = (int) ( $last_status['offset'] ?? 0 );
			$total_v  = (int) ( $last_status['total_versions'] ?? 0 );
			$finished = (string) ( $last_status['finished_gmt'] ?? '' );

			Ui::box_open();
			echo '<h2 class="motored-box__title">' . esc_html__( 'Last import status', 'motored-core' ) . '</h2>';
			echo '<ul class="motored-notes">';
			echo '<li>' . esc_html(
				sprintf(
					/* translators: %s: import status code */
					__( 'Status: %s', 'motored-core' ),
					$st_code
				)
			) . '</li>';
			if ( $is_busy && $total_v > 0 ) {
				echo '<li>' . esc_html(
					sprintf(
						/* translators: 1: processed versions count, 2: total versions count */
						__( 'Progress: %1$d / %2$d versions', 'motored-core' ),
						$offset,
						$total_v
					)
				) . '</li>';
			}
			echo '<li>' . esc_html(
				sprintf(
					/* translators: 1: versions count, 2: fitments count, 3: equivalences count, 4: rejected items count */
					__( 'Processed: %1$d versions, %2$d fitments, %3$d equivalences, %4$d rejected items.', 'motored-core' ),
					$versions,
					$fits,
					$equivs,
					$rejected
				)
			) . '</li>';
			if ( '' !== $finished ) {
				echo '<li>' . esc_html(
					sprintf(
						/* translators: %s: UTC timestamp */
						__( 'Finished (UTC): %s', 'motored-core' ),
						$finished
					)
				) . '</li>';
			}
			echo '</ul>';
			if ( $is_busy ) {
				echo '<form method="post" class="motored-actions">';
				wp_nonce_field( 'motored_compat_import_form', 'motored_import_nonce' );
				echo '<button type="submit" name="import_action" value="resume_import" class="button">' . esc_html__( 'Run next batch now', 'motored-core' ) . '</button>';
				echo '<button type="submit" name="import_action" value="cancel_import" class="button">' . esc_html__( 'Cancel import', 'motored-core' ) . '</button>';
				echo '</form>';
			}
			Ui::box_close();
		}

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Import JSON catalog (max 2 MB)', 'motored-core' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'motored_compat_import_form', 'motored_import_nonce' );
		Ui::field( __( 'Upload .json file', 'motored-core' ), 'compat-json-file', '<input id="compat-json-file" type="file" name="json_file" accept=".json,application/json" />' );
		Ui::field( __( 'Or paste JSON payload', 'motored-core' ), 'compat-json-text', '<textarea id="compat-json-text" name="json_payload" class="motored-textarea motored-mono">' . esc_textarea( $raw_json ) . '</textarea>' );
		$st_sel = '<select id="compat-imp-st" name="default_status"><option value="draft"' . selected( $default_status, 'draft', false ) . '>' . esc_html__( 'Draft (review before showing in store)', 'motored-core' ) . '</option><option value="published"' . selected( $default_status, 'published', false ) . '>' . esc_html__( 'Published immediately', 'motored-core' ) . '</option></select>';
		Ui::field( __( 'Default version status', 'motored-core' ), 'compat-imp-st', $st_sel );
		Ui::field( __( 'Import speed/load constraints (advanced)', 'motored-core' ), 'compat-imp-cons', '<input id="compat-imp-cons" type="checkbox" name="import_constraints" value="1"' . checked( $import_constraints, true, false ) . ' />' );
		echo '<div class="motored-actions">';
		echo '<button type="submit" name="import_action" value="dry_run" class="button">' . esc_html__( 'Simulate (Dry-Run)', 'motored-core' ) . '</button>';
		echo '<button type="submit" name="import_action" value="run_import" class="button button-primary">' . esc_html__( 'Run import', 'motored-core' ) . '</button>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Dry-run previews counts without writing to the database. During import, overlapping year ranges and invalid entries are skipped and counted as rejected.', 'motored-core' ) . '</p>';
		echo '</form>';

		if ( is_array( $dry_result ) ) {
			echo '<h3>' . esc_html__( 'Dry-run summary', 'motored-core' ) . '</h3>';
			echo '<ul class="motored-notes">';
			echo '<li>' . esc_html( sprintf( 'Brands: +%d new / %d existing', (int) $dry_result['brands_new'], (int) $dry_result['brands_existing'] ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( 'Models: +%d new / %d existing', (int) $dry_result['models_new'], (int) $dry_result['models_existing'] ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( 'Versions: +%d new / %d updated / %d unchanged', (int) $dry_result['versions_new'], (int) $dry_result['versions_updated'], (int) $dry_result['versions_unchanged'] ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( 'Fitments: +%d new / %d updated · Equivalences: +%d · New sizes: +%d', (int) $dry_result['fitments_new'], (int) $dry_result['fitments_updated'], (int) $dry_result['equivalences_new'], (int) $dry_result['sizes_new'] ) ) . '</li>';
			echo '<li>' . esc_html( sprintf( 'Rejected: %d · Suspicious rim: %d · Year overlaps: %d', (int) $dry_result['rejected'], (int) $dry_result['suspicious'], (int) $dry_result['overlaps'] ) ) . '</li>';
			foreach ( $dry_result['messages'] as $msg ) {
				echo '<li>' . esc_html( (string) $msg ) . '</li>';
			}
			echo '</ul>';
		}

		if ( is_array( $run_result ) ) {
			$has_rejected = (int) ( $run_result['stats']['rejected'] ?? 0 ) > 0;
			$notice_class = empty( $run_result['ok'] ) ? 'notice-error' : ( $has_rejected ? 'notice-warning' : 'notice-success' );
			echo '<div class="notice ' . esc_attr( $notice_class ) . ' inline"><p>' . esc_html( (string) $run_result['message'] ) . '</p></div>';
		}
		Ui::box_close();
	}

	/**
	 * Product Index & Invalid sizes report tab.
	 */
	private function render_report_tab(): void {
		$paged   = max( 1, $this->get_int( 'paged', 1 ) );
		$indexed = ProductIndexRepository::count_indexed();
		$data    = ProductIndexRepository::find_products_with_invalid_sizes( 30, ( $paged - 1 ) * 30 );

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'WooCommerce Tire Product Index', 'motored-core' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: indexed products count, 2: invalid products count. */
				__( 'Indexed tire products: %1$d · Published products with missing/invalid size attributes: %2$d.', 'motored-core' ),
				$indexed,
				(int) $data['total']
			)
		) . '</p>';
		echo '<p>' . esc_html__( 'Published products listed below have position or size attributes assigned, but cannot be indexed until fixed.', 'motored-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::TOOL_ACTION ) ) . '" class="motored-actions">';
		wp_nonce_field( self::TOOL_ACTION, self::TOOL_NONCE, false );
		echo '<input type="hidden" name="tool" value="reindex" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Reindex all tire products', 'motored-core' ) . '</button>';
		echo '</form>';
		Ui::box_close();

		Ui::table_open();
		Ui::table_head(
			array(
				__( 'ID', 'motored-core' )      => 'xs',
				__( 'Product', 'motored-core' ) => 'grow',
				__( 'Assigned attributes', 'motored-core' ) => 'lg',
				__( 'Actions', 'motored-core' ) => 'md',
			)
		);
		echo '<tbody>';
		foreach ( $data['items'] as $row ) {
			$edit_link = get_edit_post_link( $row['product_id'] );
			echo '<tr><td>#' . esc_html( (string) $row['product_id'] ) . '</td>';
			echo '<td><a href="' . esc_url( is_string( $edit_link ) ? $edit_link : '#' ) . '">' . esc_html( $row['title'] ) . '</a></td>';
			echo '<td class="motored-mono">' . esc_html( $row['reason'] ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
			wp_nonce_field( self::CRUD_ACTION );
			echo '<input type="hidden" name="op" value="sync_product" /><input type="hidden" name="tab" value="report" /><input type="hidden" name="product_id" value="' . esc_attr( (string) $row['product_id'] ) . '" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Re-sync', 'motored-core' ) . '</button></form></td></tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		$this->render_pagination( 'report', $paged, $data['total'], 30 );
	}

	/**
	 * Renders a notice from query string feedback.
	 */
	private function render_notice(): void {
		$code = $this->get_key( 'msg' );
		if ( '' === $code ) {
			return;
		}
		$map = array(
			'saved'         => array( 'notice-success', __( 'Saved successfully.', 'motored-core' ) ),
			'bulk_done'     => array( 'notice-success', __( 'Bulk action applied.', 'motored-core' ) ),
			'resumed'       => array( 'notice-success', __( 'Processed the next queued import batch.', 'motored-core' ) ),
			'cancelled'     => array( 'notice-success', __( 'Active import cancelled and temporary payload cleaned up.', 'motored-core' ) ),
			'deleted'       => array( 'notice-success', __( 'Deleted successfully.', 'motored-core' ) ),
			'overlap'       => array( 'notice-error', __( 'Overlapping year range for this model and variant.', 'motored-core' ) ),
			'invalid_years' => array( 'notice-error', __( 'Invalid year range.', 'motored-core' ) ),
			'invalid_size'  => array( 'notice-error', __( 'Invalid or missing tire size.', 'motored-core' ) ),
			'rim_mismatch'  => array( 'notice-error', __( 'Source and target tire sizes must have the same rim diameter.', 'motored-core' ) ),
			'has_children'  => array( 'notice-error', __( 'Cannot delete while child records still exist.', 'motored-core' ) ),
			'error'         => array( 'notice-error', __( 'Action could not be completed.', 'motored-core' ) ),
		);
		if ( isset( $map[ $code ] ) ) {
			echo '<div class="notice ' . esc_attr( $map[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $code ][1] ) . '</p></div>';
		}
	}

	/**
	 * Renders shared HTML5 datalists for brands, models and canonical sizes.
	 *
	 * @param bool $include_bikes Include brand and model datalists.
	 * @param bool $include_sizes Include canonical size datalist.
	 */
	private function render_catalog_datalists( bool $include_bikes = true, bool $include_sizes = true ): void {
		if ( $include_bikes ) {
			echo '<datalist id="motored-brands-list">';
			foreach ( CatalogRepository::brands() as $b ) {
				echo '<option value="' . esc_attr( $b['name'] ) . '"></option>';
			}
			echo '</datalist>';
			$seen_models = array();
			echo '<datalist id="motored-models-list">';
			foreach ( CatalogRepository::models() as $m ) {
				if ( isset( $seen_models[ $m['name'] ] ) ) {
					continue;
				}
				$seen_models[ $m['name'] ] = true;
				echo '<option value="' . esc_attr( $m['name'] ) . '"></option>';
			}
			echo '</datalist>';
		}
		if ( $include_sizes ) {
			echo '<datalist id="motored-sizes-list">';
			foreach ( CatalogRepository::all_sizes_min() as $sz ) {
				echo '<option value="' . esc_attr( $sz['canonical'] ) . '"></option>';
			}
			echo '</datalist>';
		}
	}

	/**
	 * Evaluates matching indexed products in the store for a version's fitments (works for drafts too).
	 *
	 * @param list<array{id:int,version_id:int,position:string,size_id:int,canonical:string,fit_type:string,min_load:int|null,min_speed_rank:int|null,req_construction:string|null,source:string,note:string}> $fitments Version fitments.
	 * @return array{front:int,rear:int,kit:int}
	 */
	private function compute_store_match_preview( array $fitments ): array {
		$axes    = array(
			TireSpecs::POS_FRONT => array(),
			TireSpecs::POS_REAR  => array(),
		);
		$oem_ids = array(
			TireSpecs::POS_FRONT => array(),
			TireSpecs::POS_REAR  => array(),
		);
		foreach ( $fitments as $f ) {
			$pos = (string) $f['position'];
			if ( ! isset( $axes[ $pos ] ) ) {
				continue;
			}
			$canon                  = (string) $f['canonical'];
			$is_oem                 = TireSpecs::FIT_OEM === (string) $f['fit_type'];
			$axes[ $pos ][ $canon ] = array(
				'size_id' => (int) $f['size_id'],
				'type'    => $is_oem ? TireSpecs::FIT_OEM : TireSpecs::FIT_ALT,
				'from'    => '',
				'l'       => $f['min_load'],
				's'       => $f['min_speed_rank'],
				'c'       => $f['req_construction'],
			);
			if ( $is_oem ) {
				$oem_ids[ $pos ][ (int) $f['size_id'] ] = true;
			}
		}
		foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ) as $pos ) {
			if ( empty( $oem_ids[ $pos ] ) ) {
				continue;
			}
			foreach ( CatalogRepository::equivalences_from_sizes( array_keys( $oem_ids[ $pos ] ), $pos ) as $eq ) {
				$target = (string) $eq['target_canonical'];
				if ( isset( $axes[ $pos ][ $target ] ) ) {
					continue;
				}
				$axes[ $pos ][ $target ] = array(
					'size_id' => (int) $eq['target_size_id'],
					'type'    => TireSpecs::FIT_EQUIV,
					'from'    => (string) $eq['source_canonical'],
					'l'       => null,
					's'       => null,
					'c'       => null,
				);
			}
		}
		$front_ids = array();
		foreach ( $axes[ TireSpecs::POS_FRONT ] as $item ) {
			$front_ids[] = (int) $item['size_id'];
		}
		$rear_ids = array();
		foreach ( $axes[ TireSpecs::POS_REAR ] as $item ) {
			$rear_ids[] = (int) $item['size_id'];
		}
		if ( empty( $front_ids ) && empty( $rear_ids ) ) {
			return array(
				'front' => 0,
				'rear'  => 0,
				'kit'   => 0,
			);
		}
		$profile    = array(
			'front' => $axes[ TireSpecs::POS_FRONT ],
			'rear'  => $axes[ TireSpecs::POS_REAR ],
		);
		$candidates = ProductIndexRepository::query_candidates_for_sizes( $front_ids, $rear_ids );
		$strict     = Config::strict_specs();
		$counts     = array(
			'front' => 0,
			'rear'  => 0,
			'kit'   => 0,
		);
		foreach ( $candidates as $row ) {
			$eval = MatchEngine::evaluate_product( $row, $profile, $strict );
			if ( ! $eval['compatible'] ) {
				continue;
			}
			if ( TireSpecs::POS_KIT === (string) ( $row['position'] ?? '' ) ) {
				++$counts['kit'];
				continue;
			}
			if ( in_array( TireSpecs::POS_FRONT, $eval['positions'], true ) ) {
				++$counts['front'];
			}
			if ( in_array( TireSpecs::POS_REAR, $eval['positions'], true ) ) {
				++$counts['rear'];
			}
		}
		return $counts;
	}

	/**
	 * Translates a canonical position key for admin display.
	 *
	 * @param string $pos Position key.
	 */
	private static function position_label( string $pos ): string {
		if ( TireSpecs::POS_FRONT === $pos ) {
			return __( 'Front', 'motored-core' );
		}
		if ( TireSpecs::POS_REAR === $pos ) {
			return __( 'Rear', 'motored-core' );
		}
		if ( TireSpecs::POS_BOTH === $pos ) {
			return __( 'Front / Rear', 'motored-core' );
		}
		if ( TireSpecs::POS_KIT === $pos ) {
			return __( 'Kit (Front + Rear)', 'motored-core' );
		}
		return $pos;
	}

	/**
	 * Renders hidden filter inputs to preserve list filters across POST redirects.
	 *
	 * @param array<string,int|string> $filters Active filter values.
	 */
	private function render_filter_hidden_inputs( array $filters ): void {
		$map = array(
			'brand_id' => 'f_brand',
			'model_id' => 'f_model',
			'status'   => 'f_status',
			'verified' => 'f_verified',
			's'        => 'f_s',
			'paged'    => 'f_paged',
		);
		foreach ( $map as $k => $field ) {
			$val = isset( $filters[ $k ] ) ? (string) $filters[ $k ] : '';
			if ( ( '' !== $val && '0' !== $val ) || ( 'verified' === $k && '0' === $val ) ) {
				echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $val ) . '" />';
			}
		}
	}

	/**
	 * Extracts non-empty filter query arguments from POST hidden inputs.
	 *
	 * @return array<string,int|string>
	 */
	private function posted_filter_args(): array {
		$out      = array();
		$brand_id = $this->post_int( 'f_brand' );
		$model_id = $this->post_int( 'f_model' );
		$status   = $this->post_key( 'f_status' );
		$verified = $this->post_text( 'f_verified' );
		$search   = $this->post_text( 'f_s' );
		$paged    = $this->post_int( 'f_paged' );

		if ( $brand_id > 0 ) {
			$out['brand_id'] = $brand_id;
		}
		if ( $model_id > 0 ) {
			$out['model_id'] = $model_id;
		}
		if ( in_array( $status, array( 'published', 'draft' ), true ) ) {
			$out['status'] = $status;
		}
		if ( in_array( $verified, array( '0', '1' ), true ) ) {
			$out['verified'] = $verified;
		}
		if ( '' !== $search ) {
			$out['s'] = $search;
		}
		if ( $paged > 1 ) {
			$out['paged'] = $paged;
		}
		return $out;
	}

	/**
	 * Renders a GET search form.
	 *
	 * @param string $tab    Current tab.
	 * @param string $search Current search term.
	 */
	private function render_search_bar( string $tab, string $search ): void {
		echo '<form method="get" class="motored-actions">';
		echo '<input type="hidden" name="post_type" value="product" /><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search...', 'motored-core' ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'motored-core' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Renders pagination links while preserving active search/filter query parameters.
	 *
	 * @param string                   $tab        Tab slug.
	 * @param int                      $paged      Current page.
	 * @param int                      $total      Total items.
	 * @param int                      $per_page   Page size.
	 * @param array<string,int|string> $extra_args Optional query args to preserve.
	 */
	private function render_pagination( string $tab, int $paged, int $total, int $per_page, array $extra_args = array() ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}
		$clean_extra = array();
		foreach ( $extra_args as $k => $v ) {
			$sv = (string) $v;
			if ( 'paged' === $k || '' === $sv ) {
				continue;
			}
			$clean_extra[ $k ] = $v;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url = add_query_arg(
				array_merge(
					array(
						'post_type' => 'product',
						'page'      => self::PAGE,
						'tab'       => $tab,
						'paged'     => $i,
					),
					$clean_extra
				),
				admin_url( 'edit.php' )
			);
			echo $i === $paged
				? '<span class="tablenav-pages-navspan button disabled">' . esc_html( (string) $i ) . '</span> '
				: '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
		}
		echo '</div></div>';
	}

	/**
	 * Renders a single-record delete POST button.
	 *
	 * @param string                   $op      CRUD operation name.
	 * @param string                   $tab     Current tab.
	 * @param int                      $id      Row ID.
	 * @param array<string,int|string> $filters Optional active filters to preserve.
	 */
	private function render_delete_btn( string $op, string $tab, int $id, array $filters = array() ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" /><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
		$this->render_filter_hidden_inputs( $filters );
		echo '<button type="submit" class="button">' . esc_html__( 'Delete', 'motored-core' ) . '</button></form>';
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Callers check capability and nonces on POST handlers.
	/**
	 * Reads sanitized POST text.
	 *
	 * @param string $key Field name.
	 */
	private function post_text( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Reads sanitized POST key.
	 *
	 * @param string $key Field name.
	 */
	private function post_key( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Reads positive POST integer.
	 *
	 * @param string $key Field name.
	 */
	private function post_int( string $key ): int {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
	}

	/**
	 * Reads sanitized GET text.
	 *
	 * @param string $key Query key.
	 */
	private function get_text( string $key ): string {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Reads sanitized GET key.
	 *
	 * @param string $key      Query key.
	 * @param string $fallback Default value.
	 */
	private function get_key( string $key, string $fallback = '' ): string {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : $fallback;
	}

	/**
	 * Reads positive GET integer.
	 *
	 * @param string $key      Query key.
	 * @param int    $fallback Default value.
	 */
	private function get_int( string $key, int $fallback = 0 ): int {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : $fallback;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
}
