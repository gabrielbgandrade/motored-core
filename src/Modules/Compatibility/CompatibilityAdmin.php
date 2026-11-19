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

	public const SECTION       = 'compatibility';
	public const PAGE          = 'motored-compatibility';
	public const TOOL_ACTION   = 'motored_compat_tool';
	public const EXPORT_ACTION = 'motored_compat_export';
	public const CRUD_ACTION   = 'motored_compat_crud';

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

		AttributeHooks::register_product_meta_hooks(
			array( $this, 'render_product_meta_field' ),
			array( $this, 'save_product_meta_field' )
		);
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
				'title'    => __( 'Show incompatible badge on product page', 'motored-core' ),
				'desc'     => __( 'Display "Does not match your bike" with a link to compatible tires when a tire does not fit the active garage bike.', 'motored-core' ),
				'id'       => 'motored_compatibility[show_incompatible_badge]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['show_incompatible_badge'],
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
		echo '<tr valign="top"><th scope="row">' . esc_html__( 'Catalog & attributes setup', 'motored-core' ) . '</th><td>';
		Ui::box_open();
		echo '<div class="motored-actions">';
		echo '<button type="submit" class="button" formaction="' . esc_url(
			add_query_arg(
				array(
					'tool'     => 'install_attributes',
					'_wpnonce' => $nonce,
				),
				$post_base
			)
		) . '" formmethod="post">' . esc_html__( '1. Install global attributes', 'motored-core' ) . '</button>';
		echo '<button type="submit" class="button" formaction="' . esc_url(
			add_query_arg(
				array(
					'tool'     => 'seed_terms',
					'_wpnonce' => $nonce,
				),
				$post_base
			)
		) . '" formmethod="post">' . esc_html__( '2. Seed fixed attribute terms', 'motored-core' ) . '</button>';
		echo '<button type="submit" class="button" formaction="' . esc_url(
			add_query_arg(
				array(
					'tool'     => 'reindex',
					'_wpnonce' => $nonce,
				),
				$post_base
			)
		) . '" formmethod="post">' . esc_html__( '3. Reindex tire products', 'motored-core' ) . '</button>';
		echo '<a class="button button-primary" href="' . esc_url( $manage_url ) . '">' . esc_html__( 'Open Compatibility catalog', 'motored-core' ) . '</a>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Run step 1 before step 2 so WooCommerce registers all global taxonomies cleanly.', 'motored-core' ) . '</p>';
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
		check_admin_referer( self::TOOL_ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked above.
		$tool = isset( $_REQUEST['tool'] ) && is_string( $_REQUEST['tool'] ) ? sanitize_key( wp_unslash( $_REQUEST['tool'] ) ) : '';
		$msg  = '';

		if ( 'install_attributes' === $tool ) {
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
		}

		$back = admin_url( 'admin.php?page=wc-settings&tab=motored&section=' . self::SECTION );
		if ( '' !== $msg ) {
			$back = add_query_arg( 'motored_compat_msg', rawurlencode( $msg ), $back );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Displays feedback notice on WooCommerce > Settings > Motored > Compatibility after setup tools run.
	 */
	public function render_settings_notice(): void {
		if ( 'wc-settings' !== $this->get_key( 'page' ) || 'motored' !== $this->get_key( 'tab' ) || self::SECTION !== $this->get_key( 'section' ) ) {
			return;
		}
		$raw = $this->get_text( 'motored_compat_msg' );
		if ( '' === $raw ) {
			return;
		}
		$parts = explode( ':', $raw );
		$text  = '';
		if ( 'attrs' === $parts[0] && 3 === count( $parts ) ) {
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
		}
		if ( '' !== $text ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
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
	 * Renders the _motored_full_size meta input on simple products.
	 */
	public function render_product_meta_field(): void {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		echo '<div class="options_group show_if_simple">';
		woocommerce_wp_text_input(
			array(
				'id'          => Storefront::FULL_SIZE_META,
				'label'       => __( 'Full tire size', 'motored-core' ),
				'placeholder' => '120/70ZR17 / 120/70ZR17 + 180/55ZR17',
				'desc_tip'    => true,
				'description' => __( 'Optional display label for Technical information (especially recommended for kits). Leave empty to auto-generate.', 'motored-core' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Saves _motored_full_size on product save (WC verifies its own nonce before firing this hook).
	 *
	 * @param mixed $product WC_Product object.
	 */
	public function save_product_meta_field( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce already verified woocommerce_meta_nonce.
		if ( ! isset( $_POST[ Storefront::FULL_SIZE_META ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		$val = sanitize_text_field( (string) wp_unslash( $_POST[ Storefront::FULL_SIZE_META ] ) );
		if ( '' === $val ) {
			$product->delete_meta_data( Storefront::FULL_SIZE_META );
		} else {
			$product->update_meta_data( Storefront::FULL_SIZE_META, $val );
		}
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
		$tab    = isset( $_POST['tab'] ) && is_string( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'brands';
		$status = 'ok';
		$extra  = array();

		if ( 'save_brand' === $op ) {
			$name   = $this->post_text( 'name' );
			$ok     = CatalogRepository::upsert_brand( $name ) > 0;
			$status = $ok ? 'saved' : 'error';
		} elseif ( 'delete_brand' === $op ) {
			$ok     = CatalogRepository::delete_brand( $this->post_int( 'id' ) );
			$status = $ok ? 'deleted' : 'has_children';
		} elseif ( 'save_model' === $op ) {
			$brand_id = $this->post_int( 'brand_id' );
			$name     = $this->post_text( 'name' );
			$cc_raw   = $this->post_int( 'cc' );
			$aliases  = $this->post_text( 'aliases' );
			$ok       = CatalogRepository::upsert_model( $brand_id, $name, '', $cc_raw > 0 ? $cc_raw : null, $aliases ) > 0;
			$status   = $ok ? 'saved' : 'error';
		} elseif ( 'delete_model' === $op ) {
			$ok     = CatalogRepository::delete_model( $this->post_int( 'id' ) );
			$status = $ok ? 'deleted' : 'has_children';
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
			if ( $year_from < 1950 || ( $year_to > 0 && $year_to < $year_from ) ) {
				$status = 'invalid_years';
			} elseif ( CatalogRepository::has_year_overlap( $model_id, $var_slug, $year_from, $year_to, $vid ) ) {
				$status = 'overlap';
			} elseif ( $vid > 0 ) {
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
				$extra['edit_version'] = $vid;
				$status                = 'saved';
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
				CatalogRepository::upsert_fitment(
					array(
						'version_id'       => $vid,
						'position'         => $pos,
						'size_id'          => $size['id'],
						'fit_type'         => $this->post_key( 'fit_type' ),
						'min_load'         => $min_load,
						'min_speed_rank'   => $min_speed,
						'req_construction' => '' !== $req_const ? $req_const : null,
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
		} elseif ( 'delete_equivalence' === $op ) {
			CatalogRepository::delete_equivalence( $this->post_int( 'id' ) );
			$status = 'deleted';
		} elseif ( 'sync_product' === $op ) {
			ProductIndexSync::sync_product( $this->post_int( 'product_id' ) );
			$status = 'saved';
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
	 * Renders the Productos > Compatibilidad management screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'motored-core' ), 403 );
		}

		$tab  = $this->get_key( 'tab', 'brands' );
		$tabs = array(
			'brands'       => __( 'Brands', 'motored-core' ),
			'models'       => __( 'Models', 'motored-core' ),
			'versions'     => __( 'Versions & Fitments', 'motored-core' ),
			'sizes'        => __( 'Sizes', 'motored-core' ),
			'equivalences' => __( 'Equivalences', 'motored-core' ),
			'import'       => __( 'Import / Export JSON', 'motored-core' ),
			'report'       => __( 'Invalid sizes report', 'motored-core' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'brands';
		}

		echo '<div class="wrap motored-admin">';
		echo '<h1>' . esc_html__( 'Motorcycle Compatibility', 'motored-core' ) . '</h1>';
		$this->render_notice();

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
			$active = $tab === $key ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><p></p>';

		if ( 'brands' === $tab ) {
			$this->render_brands_tab();
		} elseif ( 'models' === $tab ) {
			$this->render_models_tab();
		} elseif ( 'versions' === $tab ) {
			$this->render_versions_tab();
		} elseif ( 'sizes' === $tab ) {
			$this->render_sizes_tab();
		} elseif ( 'equivalences' === $tab ) {
			$this->render_equivalences_tab();
		} elseif ( 'import' === $tab ) {
			$this->render_import_tab();
		} elseif ( 'report' === $tab ) {
			$this->render_report_tab();
		}

		echo '</div>';
	}

	/**
	 * Brands tab: add + paginated list.
	 */
	private function render_brands_tab(): void {
		$search = $this->get_text( 's' );
		$paged  = max( 1, $this->get_int( 'paged', 1 ) );
		$list   = CatalogRepository::list_brands( $search, $paged, 25 );

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Add brand', 'motored-core' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_brand" /><input type="hidden" name="tab" value="brands" />';
		Ui::field( __( 'Brand name', 'motored-core' ), 'compat-brand-name', '<input id="compat-brand-name" type="text" name="name" required placeholder="Honda" />' );
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save brand', 'motored-core' ) . '</button></div>';
		echo '</form>';
		Ui::box_close();

		$this->render_search_bar( 'brands', $search );
		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Brand', 'motored-core' )   => 'grow',
				__( 'Slug', 'motored-core' )    => 'md',
				__( 'Models', 'motored-core' )  => 'xs',
				__( 'Actions', 'motored-core' ) => 'sm',
			)
		);
		echo '<tbody>';
		foreach ( $list['items'] as $row ) {
			echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td class="motored-mono">' . esc_html( $row['slug'] ) . '</td>';
			echo '<td class="motored-cell-center">' . esc_html( (string) $row['models_count'] ) . '</td><td>';
			if ( 0 === $row['models_count'] ) {
				$this->render_delete_btn( 'delete_brand', 'brands', $row['id'] );
			} else {
				echo '&mdash;';
			}
			echo '</td></tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		$this->render_pagination( 'brands', $paged, $list['total'], 25 );
	}

	/**
	 * Models tab: add + paginated list.
	 */
	private function render_models_tab(): void {
		$search = $this->get_text( 's' );
		$paged  = max( 1, $this->get_int( 'paged', 1 ) );
		$brands = CatalogRepository::brands();
		$list   = CatalogRepository::list_models( 0, $search, $paged, 25 );

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Add model', 'motored-core' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_model" /><input type="hidden" name="tab" value="models" />';
		$opts = '<select id="compat-model-brand" name="brand_id" required>';
		foreach ( $brands as $b ) {
			$opts .= '<option value="' . esc_attr( (string) $b['id'] ) . '">' . esc_html( $b['name'] ) . '</option>';
		}
		$opts .= '</select>';
		Ui::field( __( 'Brand', 'motored-core' ), 'compat-model-brand', $opts );
		Ui::field( __( 'Model name', 'motored-core' ), 'compat-model-name', '<input id="compat-model-name" type="text" name="name" required placeholder="XR 250 Tornado" />' );
		Ui::field( __( 'Displacement (cc)', 'motored-core' ), 'compat-model-cc', '<input id="compat-model-cc" type="number" name="cc" min="1" max="3000" />' );
		Ui::field( __( 'Aliases (optional)', 'motored-core' ), 'compat-model-aliases', '<input id="compat-model-aliases" type="text" name="aliases" placeholder="Tornado, XR250" />' );
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save model', 'motored-core' ) . '</button></div>';
		echo '</form>';
		Ui::box_close();

		$this->render_search_bar( 'models', $search );
		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Brand', 'motored-core' )    => 'md',
				__( 'Model', 'motored-core' )    => 'grow',
				__( 'Aliases', 'motored-core' )  => 'lg',
				__( 'Versions', 'motored-core' ) => 'xs',
				__( 'Actions', 'motored-core' )  => 'sm',
			)
		);
		echo '<tbody>';
		foreach ( $list['items'] as $row ) {
			echo '<tr><td>' . esc_html( $row['brand_name'] ) . '</td><td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( $row['aliases'] ) . '</td><td class="motored-cell-center">' . esc_html( (string) $row['versions_count'] ) . '</td><td>';
			if ( 0 === $row['versions_count'] ) {
				$this->render_delete_btn( 'delete_model', 'models', $row['id'] );
			} else {
				echo '&mdash;';
			}
			echo '</td></tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		$this->render_pagination( 'models', $paged, $list['total'], 25 );
	}

	/**
	 * Versions & Fitments tab.
	 */
	private function render_versions_tab(): void {
		$edit_id = $this->get_int( 'edit_version' );
		if ( $edit_id > 0 ) {
			$this->render_version_fitments_editor( $edit_id );
			return;
		}

		$search = $this->get_text( 's' );
		$paged  = max( 1, $this->get_int( 'paged', 1 ) );
		$list   = CatalogRepository::list_versions( array( 'search' => $search ), $paged, 25 );
		$models = CatalogRepository::models();
		$brands = array();
		foreach ( CatalogRepository::brands() as $b ) {
			$brands[ $b['id'] ] = $b['name'];
		}

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Add motorcycle version', 'motored-core' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_version" /><input type="hidden" name="tab" value="versions" />';
		$opts = '<select id="compat-ver-model" name="model_id" required>';
		foreach ( $models as $m ) {
			$label = ( $brands[ $m['brand_id'] ] ?? '' ) . ' — ' . $m['name'];
			$opts .= '<option value="' . esc_attr( (string) $m['id'] ) . '">' . esc_html( $label ) . '</option>';
		}
		$opts .= '</select>';
		Ui::field( __( 'Model', 'motored-core' ), 'compat-ver-model', $opts );
		Ui::field( __( 'Year from', 'motored-core' ), 'compat-ver-yf', '<input id="compat-ver-yf" type="number" name="year_from" min="1950" max="2100" required />' );
		Ui::field( __( 'Year to (0 = present)', 'motored-core' ), 'compat-ver-yt', '<input id="compat-ver-yt" type="number" name="year_to" min="0" max="2100" value="0" />' );
		Ui::field( __( 'Variant (optional)', 'motored-core' ), 'compat-ver-var', '<input id="compat-ver-var" type="text" name="variant" placeholder="Rally / GT / ABS" />' );
		$st_sel = '<select id="compat-ver-status" name="status"><option value="draft">' . esc_html__( 'Draft', 'motored-core' ) . '</option><option value="published">' . esc_html__( 'Published', 'motored-core' ) . '</option></select>';
		Ui::field( __( 'Status', 'motored-core' ), 'compat-ver-status', $st_sel );
		Ui::field( __( 'Verified', 'motored-core' ), 'compat-ver-verified', '<input id="compat-ver-verified" type="checkbox" name="verified" value="1" />' );
		Ui::field( __( 'Notes', 'motored-core' ), 'compat-ver-notes', '<input id="compat-ver-notes" type="text" name="notes" />' );
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save version', 'motored-core' ) . '</button></div>';
		echo '</form>';
		Ui::box_close();

		$this->render_search_bar( 'versions', $search );
		Ui::table_open();
		Ui::table_head(
			array(
				__( 'Motorcycle', 'motored-core' ) => 'grow',
				__( 'Years', 'motored-core' )      => 'sm',
				__( 'Status', 'motored-core' )     => 'xs',
				__( 'Verified', 'motored-core' )   => 'xs',
				__( 'Fitments', 'motored-core' )   => 'xs',
				__( 'Actions', 'motored-core' )    => 'md',
			)
		);
		echo '<tbody>';
		foreach ( $list['items'] as $row ) {
			$name     = trim( (string) $row['brand_name'] . ' ' . (string) $row['model_name'] . ( '' !== (string) $row['variant'] ? ' ' . (string) $row['variant'] : '' ) );
			$years    = MatchEngine::format_years( (int) $row['year_from'], (int) $row['year_to'] );
			$edit_url = add_query_arg(
				array(
					'post_type'    => 'product',
					'page'         => self::PAGE,
					'tab'          => 'versions',
					'edit_version' => (int) $row['id'],
				),
				admin_url( 'edit.php' )
			);
			echo '<tr><td><a href="' . esc_url( $edit_url ) . '"><strong>' . esc_html( $name ) . '</strong></a><br><span class="motored-mono">' . esc_html( (string) $row['slug'] ) . '</span></td>';
			echo '<td>' . esc_html( $years ) . '</td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			echo '<td class="motored-cell-center">' . ( ! empty( $row['verified'] ) ? esc_html__( 'Yes', 'motored-core' ) : esc_html__( 'No', 'motored-core' ) ) . '</td>';
			echo '<td class="motored-cell-center">' . esc_html( (string) $row['fitments_count'] ) . '</td><td>';
			echo '<div class="motored-actions"><a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit fitments', 'motored-core' ) . '</a>';
			$this->render_delete_btn( 'delete_version', 'versions', (int) $row['id'] );
			echo '</div></td></tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		$this->render_pagination( 'versions', $paged, $list['total'], 25 );
	}

	/**
	 * Renders single-version edit + fitments table & add form.
	 *
	 * @param int $version_id Version ID.
	 */
	private function render_version_fitments_editor( int $version_id ): void {
		$ver = CatalogRepository::find_version( $version_id );
		if ( null === $ver ) {
			echo '<p>' . esc_html__( 'Version not found.', 'motored-core' ) . '</p>';
			return;
		}
		$back_url = add_query_arg(
			array(
				'post_type' => 'product',
				'page'      => self::PAGE,
				'tab'       => 'versions',
			),
			admin_url( 'edit.php' )
		);
		echo '<p><a class="button" href="' . esc_url( $back_url ) . '">&larr; ' . esc_html__( 'Back to versions', 'motored-core' ) . '</a></p>';

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html( $ver['brand_name'] . ' ' . $ver['model_name'] ) . ' <span class="motored-mono">(' . esc_html( (string) $ver['slug'] ) . ')</span></h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_version" /><input type="hidden" name="tab" value="versions" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $version_id ) . '" /><input type="hidden" name="model_id" value="' . esc_attr( (string) $ver['model_id'] ) . '" />';
		Ui::field( __( 'Year from', 'motored-core' ), 'compat-ev-yf', '<input id="compat-ev-yf" type="number" name="year_from" value="' . esc_attr( (string) $ver['year_from'] ) . '" required />' );
		Ui::field( __( 'Year to (0 = present)', 'motored-core' ), 'compat-ev-yt', '<input id="compat-ev-yt" type="number" name="year_to" value="' . esc_attr( (string) $ver['year_to'] ) . '" />' );
		Ui::field( __( 'Variant', 'motored-core' ), 'compat-ev-var', '<input id="compat-ev-var" type="text" name="variant" value="' . esc_attr( (string) $ver['variant'] ) . '" />' );
		$st_sel = '<select id="compat-ev-st" name="status"><option value="draft"' . selected( $ver['status'], 'draft', false ) . '>' . esc_html__( 'Draft', 'motored-core' ) . '</option><option value="published"' . selected( $ver['status'], 'published', false ) . '>' . esc_html__( 'Published', 'motored-core' ) . '</option></select>';
		Ui::field( __( 'Status', 'motored-core' ), 'compat-ev-st', $st_sel );
		Ui::field( __( 'Verified', 'motored-core' ), 'compat-ev-ver', '<input id="compat-ev-ver" type="checkbox" name="verified" value="1"' . checked( ! empty( $ver['verified'] ), true, false ) . ' />' );
		Ui::field( __( 'Notes', 'motored-core' ), 'compat-ev-notes', '<input id="compat-ev-notes" type="text" name="notes" value="' . esc_attr( (string) $ver['notes'] ) . '" />' );
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Update version', 'motored-core' ) . '</button></div>';
		echo '</form>';
		Ui::box_close();

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Add tire fitment', 'motored-core' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_fitment" /><input type="hidden" name="tab" value="versions" /><input type="hidden" name="version_id" value="' . esc_attr( (string) $version_id ) . '" />';
		Ui::field( __( 'Position', 'motored-core' ), 'compat-fit-pos', '<select id="compat-fit-pos" name="position"><option value="front">' . esc_html__( 'Front', 'motored-core' ) . '</option><option value="rear">' . esc_html__( 'Rear', 'motored-core' ) . '</option></select>' );
		Ui::field( __( 'Tire size', 'motored-core' ), 'compat-fit-size', '<input id="compat-fit-size" type="text" name="size" required placeholder="120/70-17 or 4.10-18" />' );
		Ui::field( __( 'Fitment type', 'motored-core' ), 'compat-fit-type', '<select id="compat-fit-type" name="fit_type"><option value="oem">' . esc_html__( 'OEM (original)', 'motored-core' ) . '</option><option value="alt">' . esc_html__( 'Alternative', 'motored-core' ) . '</option></select>' );
		Ui::field( __( 'Source', 'motored-core' ), 'compat-fit-src', '<select id="compat-fit-src" name="source"><option value="manual">' . esc_html__( 'Manual', 'motored-core' ) . '</option><option value="manufacturer">' . esc_html__( 'Manufacturer', 'motored-core' ) . '</option><option value="third_party">' . esc_html__( 'Third party', 'motored-core' ) . '</option></select>' );
		Ui::field( __( 'Note', 'motored-core' ), 'compat-fit-note', '<input id="compat-fit-note" type="text" name="note" />' );
		echo '<details><summary>' . esc_html__( 'Optional safety limits (leave empty unless needed for high-speed / heavy motorcycles)', 'motored-core' ) . '</summary><p></p>';
		Ui::field( __( 'Min load index (optional)', 'motored-core' ), 'compat-fit-load', '<input id="compat-fit-load" type="number" name="min_load" min="20" max="120" />' );
		Ui::field( __( 'Min speed rating (optional)', 'motored-core' ), 'compat-fit-spd', '<input id="compat-fit-spd" type="text" name="min_speed" placeholder="H, V, W" />' );
		Ui::field( __( 'Required construction (optional)', 'motored-core' ), 'compat-fit-const', '<select id="compat-fit-const" name="req_construction"><option value="">&mdash;</option><option value="radial">Radial</option><option value="diagonal">Diagonal</option><option value="cinturado">Belted (B)</option></select>' );
		echo '</details><p></p>';
		echo '<div class="motored-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save fitment', 'motored-core' ) . '</button></div>';
		echo '</form>';
		Ui::box_close();

		$fitments = CatalogRepository::fitments_for_version( $version_id );
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
			echo '<tr><td>' . esc_html( $f['position'] ) . '</td><td class="motored-mono"><strong>' . esc_html( $f['canonical'] ) . '</strong></td>';
			echo '<td>' . esc_html( $f['fit_type'] ) . '</td><td>' . esc_html( implode( ', ', $limits ) ) . '</td>';
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
		$this->render_pagination( 'sizes', $paged, $list['total'], 30 );
	}

	/**
	 * Directed equivalences tab.
	 */
	private function render_equivalences_tab(): void {
		$search = $this->get_text( 's' );
		$paged  = max( 1, $this->get_int( 'paged', 1 ) );
		$list   = CatalogRepository::list_equivalences( $search, $paged, 25 );

		Ui::box_open();
		echo '<h2 class="motored-box__title">' . esc_html__( 'Add directed equivalence', 'motored-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Defines which target tire size can replace an OEM motorcycle tire size (1 hop only, never transitive).', 'motored-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="save_equivalence" /><input type="hidden" name="tab" value="equivalences" />';
		Ui::field( __( 'OEM source size (e.g. 4.10-18)', 'motored-core' ), 'compat-eq-src', '<input id="compat-eq-src" type="text" name="source_size" required />' );
		Ui::field( __( 'Compatible target size (e.g. 120/80-18)', 'motored-core' ), 'compat-eq-dst', '<input id="compat-eq-dst" type="text" name="target_size" required />' );
		Ui::field( __( 'Position scope', 'motored-core' ), 'compat-eq-scope', '<select id="compat-eq-scope" name="position_scope"><option value="any">' . esc_html__( 'Any axle', 'motored-core' ) . '</option><option value="front">' . esc_html__( 'Front only', 'motored-core' ) . '</option><option value="rear">' . esc_html__( 'Rear only', 'motored-core' ) . '</option></select>' );
		Ui::field( __( 'Create inverse too (target -> source)', 'motored-core' ), 'compat-eq-inv', '<input id="compat-eq-inv" type="checkbox" name="create_inverse" value="1" />' );
		Ui::field( __( 'Approved for storefront', 'motored-core' ), 'compat-eq-app', '<input id="compat-eq-app" type="checkbox" name="is_approved" value="1" checked="checked" />' );
		Ui::field( __( 'Mark as approximate', 'motored-core' ), 'compat-eq-approx', '<input id="compat-eq-approx" type="checkbox" name="is_approximate" value="1" checked="checked" />' );
		Ui::field( __( 'Note', 'motored-core' ), 'compat-eq-note', '<input id="compat-eq-note" type="text" name="note" />' );
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
				__( 'Actions', 'motored-core' )      => 'xs',
			)
		);
		echo '<tbody>';
		foreach ( $list['items'] as $row ) {
			echo '<tr><td class="motored-mono">' . esc_html( (string) $row['source_canonical'] ) . '</td>';
			echo '<td class="motored-mono"><strong>' . esc_html( (string) $row['target_canonical'] ) . '</strong></td>';
			echo '<td>' . esc_html( (string) $row['position_scope'] ) . '</td>';
			echo '<td class="motored-cell-center">' . ( ! empty( $row['is_approved'] ) ? esc_html__( 'Yes', 'motored-core' ) : esc_html__( 'No', 'motored-core' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['note'] ) . '</td><td>';
			$this->render_delete_btn( 'delete_equivalence', 'equivalences', (int) $row['id'] );
			echo '</td></tr>';
		}
		echo '</tbody>';
		Ui::table_close();
		$this->render_pagination( 'equivalences', $paged, $list['total'], 25 );
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
	 * Invalid sizes report tab.
	 */
	private function render_report_tab(): void {
		$paged = max( 1, $this->get_int( 'paged', 1 ) );
		$data  = ProductIndexRepository::find_products_with_invalid_sizes( 30, ( $paged - 1 ) * 30 );

		Ui::box_open();
		echo '<p>' . esc_html__( 'Published products that have position or size attributes assigned, but cannot be indexed (missing or unparseable size).', 'motored-core' ) . '</p>';
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
			'deleted'       => array( 'notice-success', __( 'Deleted successfully.', 'motored-core' ) ),
			'overlap'       => array( 'notice-error', __( 'Overlapping year range for this model and variant.', 'motored-core' ) ),
			'invalid_years' => array( 'notice-error', __( 'Invalid year range.', 'motored-core' ) ),
			'invalid_size'  => array( 'notice-error', __( 'Invalid or missing tire size.', 'motored-core' ) ),
			'has_children'  => array( 'notice-error', __( 'Cannot delete while child records still exist.', 'motored-core' ) ),
			'error'         => array( 'notice-error', __( 'Action could not be completed.', 'motored-core' ) ),
		);
		if ( isset( $map[ $code ] ) ) {
			echo '<div class="notice ' . esc_attr( $map[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $code ][1] ) . '</p></div>';
		}
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
	 * Renders pagination links.
	 *
	 * @param string $tab      Tab slug.
	 * @param int    $paged    Current page.
	 * @param int    $total    Total items.
	 * @param int    $per_page Page size.
	 */
	private function render_pagination( string $tab, int $paged, int $total, int $per_page ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url = add_query_arg(
				array(
					'post_type' => 'product',
					'page'      => self::PAGE,
					'tab'       => $tab,
					'paged'     => $i,
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
	 * @param string $op  CRUD operation name.
	 * @param string $tab Current tab.
	 * @param int    $id  Row ID.
	 */
	private function render_delete_btn( string $op, string $tab, int $id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=' . self::CRUD_ACTION ) ) . '">';
		wp_nonce_field( self::CRUD_ACTION );
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" /><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
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
