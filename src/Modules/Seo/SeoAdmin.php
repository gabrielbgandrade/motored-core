<?php
/**
 * Admin interface for Seo: WooCommerce settings section, Redirects manager and per-post SERP meta box.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Admin\Ui;
use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Uses shared Admin\Ui and motored-admin.css classes only (no inline styles or raw tables).
 */
final class SeoAdmin {

	public const SECTION           = 'seo';
	public const NONCE_FIELD       = '_motored_seo_nonce';
	public const NONCE_ACTION      = 'motored_seo_metabox';
	public const ACTION_ADD_REDIR  = 'motored_seo_add_redirect';
	public const ACTION_DEL_REDIR  = 'motored_seo_del_redirect';
	public const REDIR_NONCE_ACT   = 'motored_seo_redirects';
	public const REDIR_NONCE_FIELD = 'motored_seo_redir_nonce';
	public const SCRIPT_HANDLE     = 'motored-seo-admin';
	private const NOTICE_BUCKET    = 'seo_admin';

	/**
	 * Registers settings section, post meta box, SERP preview script and redirect manager actions.
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'SEO', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			65
		);
		add_action( 'woocommerce_admin_field_motored_seo_redirects', array( $this, 'render_redirects_manager' ) );
		add_action( 'admin_post_' . self::ACTION_ADD_REDIR, array( $this, 'handle_add_redirect' ) );
		add_action( 'admin_post_' . self::ACTION_DEL_REDIR, array( $this, 'handle_delete_redirect' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post_meta' ), 10, 2 );
		foreach ( HeadRenderer::MANAGED_TAXONOMIES as $tax ) {
			add_action( $tax . '_edit_form_fields', array( $this, 'render_term_fields' ), 20 );
			add_action( 'edited_' . $tax, array( $this, 'save_term_meta' ), 10, 1 );
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
	}

	/**
	 * WooCommerce > Settings > Motored > SEO fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		return array(
			array(
				'title' => __( 'General SEO & Home Page', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Full-site SEO built natively on WordPress core and Redis: titles, meta descriptions, canonicals, Open Graph, JSON-LD, XML sitemaps, robots.txt and 301 redirects.', 'motored-core' ),
				'id'    => 'motored_seo_title',
			),
			array(
				'title'    => __( 'Enable Motored SEO', 'motored-core' ),
				'desc'     => __( 'Activate titles, descriptions, canonicals, sitemaps, redirects and JSON-LD (automatically steps back if Yoast/Rank Math is active).', 'motored-core' ),
				'id'       => 'motored_seo[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Manage full site SEO', 'motored-core' ),
				'desc'     => __( 'Extend SEO to Home, Products, Shop, Categories, Brands, Pages and Blog posts (not just motorcycle landing pages).', 'motored-core' ),
				'id'       => 'motored_seo[site_wide]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['site_wide'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Title separator', 'motored-core' ),
				'desc'     => __( 'Symbol between the page title and your store name.', 'motored-core' ),
				'id'       => 'motored_seo[title_separator]',
				'type'     => 'select',
				'default'  => Config::DEFAULTS['title_separator'],
				'options'  => array(
					'|' => '|',
					'–' => '–',
					'-' => '-',
					'·' => '·',
				),
				'autoload' => false,
			),
			array(
				'title'       => __( 'Home page SEO title', 'motored-core' ),
				'desc'        => __( 'Leave blank to use "Site name | Tagline". Ideal length: 50–60 characters.', 'motored-core' ),
				'id'          => 'motored_seo[home_title]',
				'type'        => 'text',
				'class'       => 'motored-input-wide',
				'placeholder' => __( 'Motored Neumáticos | Motorcycle tires in Argentina', 'motored-core' ),
				'default'     => '',
				'autoload'    => false,
			),
			array(
				'title'       => __( 'Home page meta description', 'motored-core' ),
				'desc'        => __( 'Shown in Google search snippets for your home page. Ideal length: 130–155 characters.', 'motored-core' ),
				'id'          => 'motored_seo[home_description]',
				'type'        => 'textarea',
				'class'       => 'motored-input-wide motored-textarea',
				'placeholder' => __( 'Find the exact tire for your motorcycle. Real stock, installments and nationwide shipping.', 'motored-core' ),
				'default'     => '',
				'autoload'    => false,
			),
			array(
				'title'    => __( 'Noindex search results', 'motored-core' ),
				'desc'     => __( 'Emit noindex, follow on internal search pages (?s=).', 'motored-core' ),
				'id'       => 'motored_seo[noindex_search]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['noindex_search'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Noindex paginated archives', 'motored-core' ),
				'desc'     => __( 'Emit noindex, follow on /page/2+ URLs. Leave off so Google can crawl deep catalog pages with self-referencing canonicals.', 'motored-core' ),
				'id'       => 'motored_seo[noindex_paged]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['noindex_paged'],
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_seo_title',
			),

			array(
				'title' => __( 'Business Identity, Local SEO & Social (Schema / Open Graph)', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Powers Google AutoPartsStore/Organization Knowledge Graph, Sitelinks Searchbox, enriched WooCommerce Product snippets and WhatsApp/social cards.', 'motored-core' ),
				'id'    => 'motored_seo_schema_title',
			),
			array(
				'title'    => __( 'Open Graph & Twitter cards', 'motored-core' ),
				'desc'     => __( 'Output og:*, product:* and twitter:card tags for crisp WhatsApp, Instagram and Facebook link previews.', 'motored-core' ),
				'id'       => 'motored_seo[og_enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['og_enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'JSON-LD structured data', 'motored-core' ),
				'desc'     => __( 'Output AutoPartsStore, WebSite (SearchAction), BreadcrumbList, CollectionPage, Article, ItemList and FAQPage schema.', 'motored-core' ),
				'id'       => 'motored_seo[schema_enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['schema_enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Enrich WooCommerce Product schema', 'motored-core' ),
				'desc'     => __( 'Add Brand, MPN/SKU, NewCondition, seller reference, gallery images and tire specs to WooCommerce native Product JSON-LD.', 'motored-core' ),
				'id'       => 'motored_seo[enhance_wc_product_schema]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enhance_wc_product_schema'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Default Open Graph image URL', 'motored-core' ),
				'desc'     => __( 'Fallback social share image (recommended 1200×630 px) when a page has no featured image.', 'motored-core' ),
				'id'       => 'motored_seo[default_og_image]',
				'type'     => 'url',
				'class'    => 'motored-input-wide',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'       => __( 'Business name (Organization)', 'motored-core' ),
				'desc'        => __( 'Leave blank to use the WordPress site title.', 'motored-core' ),
				'id'          => 'motored_seo[org_name]',
				'type'        => 'text',
				'class'       => 'motored-input-wide',
				'placeholder' => 'Motored Neumáticos',
				'default'     => '',
				'autoload'    => false,
			),
			array(
				'title'    => __( 'Organization logo URL', 'motored-core' ),
				'desc'     => __( 'Leave blank to use the theme custom logo automatically.', 'motored-core' ),
				'id'       => 'motored_seo[org_logo]',
				'type'     => 'url',
				'class'    => 'motored-input-wide',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'       => __( 'Business phone (Local SEO)', 'motored-core' ),
				'desc'        => __( 'International format, e.g. +54 9 11 1234-5678.', 'motored-core' ),
				'id'          => 'motored_seo[org_phone]',
				'type'        => 'text',
				'placeholder' => '+54 9 11 0000-0000',
				'default'     => '',
				'autoload'    => false,
			),
			array(
				'title'    => __( 'City (Local SEO)', 'motored-core' ),
				'id'       => 'motored_seo[org_city]',
				'type'     => 'text',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'    => __( 'Province / Region (Local SEO)', 'motored-core' ),
				'id'       => 'motored_seo[org_region]',
				'type'     => 'text',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'title'    => __( 'Social profile URLs (sameAs)', 'motored-core' ),
				'desc'     => __( 'One URL per line (Instagram, Facebook, YouTube, TikTok). Links your official profiles in Google Knowledge Graph.', 'motored-core' ),
				'id'       => 'motored_seo[social_profiles]',
				'type'     => 'textarea',
				'class'    => 'motored-input-wide motored-textarea',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_seo_schema_title',
			),

			array(
				'title' => __( 'Google Merchant Center & Shipping / Returns (Schema)', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Enables free Google Shopping listings via a lightweight Redis-cached XML feed (no Google plugin required) and fills Return/Shipping fields in Product JSON-LD.', 'motored-core' ),
				'id'    => 'motored_seo_merchant_title',
			),
			array(
				'title'    => __( 'Enable Google Merchant XML feed', 'motored-core' ),
				'desc'     => __( 'Expose /feed/motored-merchant/ for Google Merchant Center scheduled fetch (Redis-cached, simple & variable products).', 'motored-core' ),
				'id'       => 'motored_seo[merchant_feed_enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['merchant_feed_enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Google product category', 'motored-core' ),
				'desc'     => __( 'Official Google taxonomy ID (913 = Motor Vehicle Tires).', 'motored-core' ),
				'id'       => 'motored_seo[merchant_google_category]',
				'type'     => 'text',
				'default'  => Config::DEFAULTS['merchant_google_category'],
				'autoload' => false,
			),
			array(
				'title'             => __( 'Return policy window (days)', 'motored-core' ),
				'desc'              => __( 'Emits hasMerchantReturnPolicy in Product JSON-LD (10 days in Argentina; 0 to omit).', 'motored-core' ),
				'id'                => 'motored_seo[return_days]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['return_days'],
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '365',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'       => __( 'Flat shipping cost (optional)', 'motored-core' ),
				'desc'        => __( 'Enter 0 for free shipping or a flat ARS estimate for shippingDetails & Merchant feed. Leave blank if shipping varies by postcode.', 'motored-core' ),
				'id'          => 'motored_seo[shipping_cost]',
				'type'        => 'text',
				'placeholder' => '0',
				'default'     => '',
				'autoload'    => false,
			),
			array(
				'title'             => __( 'Handling time (max business days)', 'motored-core' ),
				'id'                => 'motored_seo[shipping_handling_days]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['shipping_handling_days'],
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '30',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'title'             => __( 'Transit time (max business days)', 'motored-core' ),
				'id'                => 'motored_seo[shipping_transit_days]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['shipping_transit_days'],
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '60',
					'step' => '1',
				),
				'autoload'          => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_seo_merchant_title',
			),

			array(
				'title' => __( 'XML Sitemap, robots.txt & 301 Redirects', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Protects crawl budget, prevents duplicate filter indexing, adds <lastmod> to sitemaps and caches 404 redirects in Redis.', 'motored-core' ),
				'id'    => 'motored_seo_crawl_title',
			),
			array(
				'title'    => __( 'Disable users XML sitemap', 'motored-core' ),
				'desc'     => __( 'Remove /wp-sitemap-users-1.xml to avoid exposing admin usernames and thin author archives.', 'motored-core' ),
				'id'       => 'motored_seo[disable_user_sitemap]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['disable_user_sitemap'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Optimize virtual robots.txt', 'motored-core' ),
				'desc'     => __( 'Disallow cart/checkout/account/filter/sort query parameters and declare the XML sitemap URL.', 'motored-core' ),
				'id'       => 'motored_seo[robots_txt_enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['robots_txt_enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Automatic 301 on slug changes', 'motored-core' ),
				'desc'     => __( 'Automatically save a 301 redirect whenever you change the URL slug of a published product, page, post or bike page.', 'motored-core' ),
				'id'       => 'motored_seo[auto_product_redirects]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['auto_product_redirects'],
				'autoload' => false,
			),
			// No id on purpose: WooCommerce skips id-less fields when saving settings.
			array( 'type' => 'motored_seo_redirects' ),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_seo_crawl_title',
			),
		);
	}

	/**
	 * Renders the Sitemap/robots.txt links and 301/302 Redirects Manager inside WooCommerce > Settings > Motored > SEO.
	 */
	public function render_redirects_manager(): void {
		$notice  = $this->pull_notice();
		$rules   = Redirects::list_rules( 50 );
		$sitemap = home_url( '/wp-sitemap.xml' );
		$robots  = home_url( '/robots.txt' );
		$post_to = admin_url( 'admin-post.php' );

		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Sitemap & 301 Redirects', 'motored-core' ) . '</th><td class="forminp">';
		Ui::box_open();
		if ( null !== $notice ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				esc_attr( $notice['ok'] ? 'success' : 'error' ),
				esc_html( $notice['text'] )
			);
		}

		echo '<p class="motored-actions">';
		echo '<a class="button" href="' . esc_url( $sitemap ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open XML Sitemap', 'motored-core' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $robots ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open robots.txt', 'motored-core' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( MerchantFeed::feed_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open Google Merchant Feed (XML)', 'motored-core' ) . '</a>';
		echo '</p>';

		wp_nonce_field( self::REDIR_NONCE_ACT, self::REDIR_NONCE_FIELD, false );
		echo '<div class="motored-box__grid">';
		echo '<div class="motored-box__cell">';
		Ui::field(
			__( 'Source path (old URL)', 'motored-core' ),
			'motored-redir-src',
			'<input id="motored-redir-src" type="text" name="motored_seo_redir[source]" placeholder="/producto/viejo-slug/" />'
		);
		echo '</div><div class="motored-box__cell">';
		Ui::field(
			__( 'Target URL or path', 'motored-core' ),
			'motored-redir-dst',
			'<input id="motored-redir-dst" type="text" name="motored_seo_redir[target]" placeholder="/producto/nuevo-slug/" />'
		);
		echo '</div><div class="motored-box__cell">';
		$code_sel = '<select id="motored-redir-code" name="motored_seo_redir[code]">'
			. '<option value="301">' . esc_html__( '301 (Permanent)', 'motored-core' ) . '</option>'
			. '<option value="302">' . esc_html__( '302 (Temporary)', 'motored-core' ) . '</option>'
			. '</select>';
		Ui::field( __( 'HTTP status', 'motored-core' ), 'motored-redir-code', $code_sel );
		echo '</div></div>';

		printf(
			'<p class="motored-actions"><button type="submit" class="button" name="action" value="%1$s" formmethod="post" formaction="%2$s">%3$s</button></p>',
			esc_attr( self::ACTION_ADD_REDIR ),
			esc_url( $post_to ),
			esc_html__( 'Add redirect', 'motored-core' )
		);

		if ( array() === $rules ) {
			echo '<p class="description">' . esc_html__( 'No 301/302 redirects recorded yet. Slug changes and manual rules will appear here.', 'motored-core' ) . '</p>';
		} else {
			Ui::table_open();
			Ui::table_head(
				array(
					__( 'Source path', 'motored-core' ) => 'grow',
					__( 'Target URL', 'motored-core' )  => 'grow',
					__( 'Code', 'motored-core' )        => 'xs',
					__( 'Action', 'motored-core' )      => 'sm',
				)
			);
			foreach ( $rules as $rule ) {
				$del_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => self::ACTION_DEL_REDIR,
							'id'     => $rule['id'],
						),
						$post_to
					),
					self::REDIR_NONCE_ACT
				);
				echo '<tr>';
				echo '<td class="motored-mono">' . esc_html( $rule['source_path'] ) . '</td>';
				echo '<td class="motored-mono">' . esc_html( $rule['target_url'] ) . '</td>';
				echo '<td>' . (int) $rule['status_code'] . '</td>';
				echo '<td><a class="button button-small button-link-delete" href="' . esc_url( $del_url ) . '">' . esc_html__( 'Delete', 'motored-core' ) . '</a></td>';
				echo '</tr>';
			}
			Ui::table_close();
		}

		Ui::box_close();
		echo '</td></tr>';
	}

	/**
	 * Handles adding a manual 301/302 redirect rule (manage_woocommerce + nonce).
	 */
	public function handle_add_redirect(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'motored-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::REDIR_NONCE_ACT, self::REDIR_NONCE_FIELD );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$raw    = isset( $_POST['motored_seo_redir'] ) && is_array( $_POST['motored_seo_redir'] ) ? wp_unslash( $_POST['motored_seo_redir'] ) : array();
		$source = isset( $raw['source'] ) && is_string( $raw['source'] ) ? sanitize_text_field( $raw['source'] ) : '';
		$target = isset( $raw['target'] ) && is_string( $raw['target'] ) ? sanitize_text_field( $raw['target'] ) : '';
		$code   = isset( $raw['code'] ) && is_scalar( $raw['code'] ) ? (int) $raw['code'] : 301;

		$ok = Redirects::add( $source, $target, $code );
		$this->push_notice(
			$ok,
			$ok
				? __( 'Redirect saved.', 'motored-core' )
				: __( 'Could not save redirect. Check source path and target URL (loops are not allowed).', 'motored-core' )
		);
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=motored&section=' . self::SECTION ) );
		exit;
	}

	/**
	 * Handles deleting a redirect rule (manage_woocommerce + nonce).
	 */
	public function handle_delete_redirect(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'motored-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::REDIR_NONCE_ACT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked right above.
		$id = isset( $_GET['id'] ) && is_scalar( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$ok = $id > 0 && Redirects::delete( $id );
		$this->push_notice(
			$ok,
			$ok ? __( 'Redirect deleted.', 'motored-core' ) : __( 'Could not delete redirect.', 'motored-core' )
		);
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=motored&section=' . self::SECTION ) );
		exit;
	}

	/**
	 * Registers the Motored SEO meta box across Products, Pages, Posts, BikePages and SizePages.
	 */
	public function add_meta_boxes(): void {
		foreach ( HeadRenderer::MANAGED_POST_TYPES as $pt ) {
			if ( function_exists( 'post_type_exists' ) && ! post_type_exists( $pt ) ) {
				continue;
			}
			add_meta_box(
				'motored-seo-overrides',
				__( 'Motored SEO', 'motored-core' ),
				array( $this, 'render_meta_box' ),
				$pt,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Renders live Google SERP preview + per-post SEO override fields using Admin\Ui.
	 *
	 * @param mixed $post Current WP_Post.
	 */
	public function render_meta_box( $post ): void {
		$pid = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$title     = $pid > 0 ? (string) get_post_meta( $pid, HeadRenderer::META_TITLE, true ) : '';
		$desc      = $pid > 0 ? (string) get_post_meta( $pid, HeadRenderer::META_DESC, true ) : '';
		$robots    = $pid > 0 ? (string) get_post_meta( $pid, HeadRenderer::META_ROBOTS, true ) : '';
		$canonical = $pid > 0 ? (string) get_post_meta( $pid, HeadRenderer::META_CANONICAL, true ) : '';

		$post_title = $pid > 0 && function_exists( 'get_the_title' ) ? wp_strip_all_tags( (string) get_the_title( $pid ) ) : '';
		$site_name  = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
		$fallback_t = '' !== $post_title ? $post_title . ' ' . Config::title_separator() . ' ' . $site_name : $site_name;
		$permalink  = $pid > 0 && function_exists( 'get_permalink' ) ? (string) get_permalink( $pid ) : home_url( '/' );

		Ui::box_open( true );
		echo '<div id="motored-seo-box" data-fallback-title="' . esc_attr( $fallback_t ) . '" data-fallback-desc="' . esc_attr__( 'Auto-generated from product/page summary when left blank.', 'motored-core' ) . '">';
		echo '<div class="motored-box__preview motored-box__serp">';
		echo '<div class="motored-box__serp-url">' . esc_html( $permalink ) . '</div>';
		echo '<div class="motored-box__serp-title" data-serp-title="1">' . esc_html( '' !== $title ? $title : $fallback_t ) . '</div>';
		echo '<div class="motored-box__serp-desc" data-serp-desc="1">' . esc_html( '' !== $desc ? $desc : __( 'Auto-generated from product/page summary when left blank.', 'motored-core' ) ) . '</div>';
		echo '</div>';

		Ui::field(
			__( 'SEO title override', 'motored-core' ),
			'motored-seo-title',
			'<input id="motored-seo-title" type="text" name="motored_seo_meta[title]" value="' . esc_attr( $title ) . '" maxlength="90" /><span class="motored-field__hint motored-box__counter" data-counter-for="motored-seo-title" data-max="60"></span>',
			__( 'Ideal: 50–60 characters. Leave blank to generate automatically.', 'motored-core' )
		);
		Ui::field(
			__( 'Meta description override', 'motored-core' ),
			'motored-seo-desc',
			'<textarea id="motored-seo-desc" name="motored_seo_meta[desc]" maxlength="240">' . esc_textarea( $desc ) . '</textarea><span class="motored-field__hint motored-box__counter" data-counter-for="motored-seo-desc" data-max="155"></span>',
			__( 'Ideal: 130–155 characters. Leave blank to generate automatically.', 'motored-core' )
		);
		Ui::field(
			__( 'Canonical URL override', 'motored-core' ),
			'motored-seo-canonical',
			'<input id="motored-seo-canonical" type="url" name="motored_seo_meta[canonical]" value="' . esc_attr( $canonical ) . '" placeholder="' . esc_attr( $permalink ) . '" />',
			__( 'Optional. Leave blank to use the clean self-referencing permalink.', 'motored-core' )
		);
		$bot_sel = '<select id="motored-seo-robots" name="motored_seo_meta[robots]">'
			. '<option value=""' . selected( $robots, '', false ) . '>' . esc_html__( 'Automatic (recommended)', 'motored-core' ) . '</option>'
			. '<option value="index,follow"' . selected( $robots, 'index,follow', false ) . '>' . esc_html__( 'Force index, follow', 'motored-core' ) . '</option>'
			. '<option value="noindex,follow"' . selected( $robots, 'noindex,follow', false ) . '>' . esc_html__( 'Force noindex, follow', 'motored-core' ) . '</option>'
			. '<option value="noindex,nofollow"' . selected( $robots, 'noindex,nofollow', false ) . '>' . esc_html__( 'Force noindex, nofollow', 'motored-core' ) . '</option>'
			. '</select>';
		Ui::field( __( 'Search engine indexing (robots)', 'motored-core' ), 'motored-seo-robots', $bot_sel );
		echo '</div>';
		Ui::box_close();
	}

	/**
	 * Saves per-post SEO overrides when editing any managed post type and invalidates sitemap cache.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post object.
	 */
	public function save_post_meta( int $post_id, $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || ! in_array( (string) $post->post_type, HeadRenderer::MANAGED_POST_TYPES, true ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$raw   = isset( $_POST['motored_seo_meta'] ) && is_array( $_POST['motored_seo_meta'] ) ? wp_unslash( $_POST['motored_seo_meta'] ) : array();
		$title = isset( $raw['title'] ) && is_string( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '';
		$desc  = isset( $raw['desc'] ) && is_string( $raw['desc'] ) ? sanitize_textarea_field( $raw['desc'] ) : '';
		$canon = isset( $raw['canonical'] ) && is_string( $raw['canonical'] ) ? esc_url_raw( trim( $raw['canonical'] ) ) : '';
		$bot   = isset( $raw['robots'] ) && is_string( $raw['robots'] ) ? sanitize_text_field( $raw['robots'] ) : '';
		if ( ! in_array( $bot, array( '', 'index,follow', 'noindex,follow', 'noindex,nofollow' ), true ) ) {
			$bot = '';
		}

		self::sync_meta( $post_id, HeadRenderer::META_TITLE, $title );
		self::sync_meta( $post_id, HeadRenderer::META_DESC, $desc );
		self::sync_meta( $post_id, HeadRenderer::META_CANONICAL, $canon );
		self::sync_meta( $post_id, HeadRenderer::META_ROBOTS, $bot );
		SitemapFilters::invalidate_cache();
	}

	/**
	 * Renders SEO override fields on Edit Category / Edit Brand taxonomy screens.
	 *
	 * @param mixed $term WP_Term being edited.
	 */
	public function render_term_fields( $term ): void {
		$tid    = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : 0;
		$title  = $tid > 0 ? (string) get_term_meta( $tid, HeadRenderer::META_TITLE, true ) : '';
		$desc   = $tid > 0 ? (string) get_term_meta( $tid, HeadRenderer::META_DESC, true ) : '';
		$robots = $tid > 0 ? (string) get_term_meta( $tid, HeadRenderer::META_ROBOTS, true ) : '';

		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Motored SEO', 'motored-core' ) . '</th><td>';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		Ui::box_open();
		Ui::field(
			__( 'SEO title override', 'motored-core' ),
			'motored-seo-term-title',
			'<input id="motored-seo-term-title" type="text" name="motored_seo_term[title]" value="' . esc_attr( $title ) . '" maxlength="90" />',
			__( 'Ideal: 50–60 characters. Leave blank to generate automatically.', 'motored-core' )
		);
		Ui::field(
			__( 'Meta description override', 'motored-core' ),
			'motored-seo-term-desc',
			'<textarea id="motored-seo-term-desc" name="motored_seo_term[desc]" maxlength="240">' . esc_textarea( $desc ) . '</textarea>',
			__( 'Ideal: 130–155 characters. Leave blank to generate automatically.', 'motored-core' )
		);
		$bot_sel = '<select id="motored-seo-term-robots" name="motored_seo_term[robots]">'
			. '<option value=""' . selected( $robots, '', false ) . '>' . esc_html__( 'Automatic (recommended)', 'motored-core' ) . '</option>'
			. '<option value="index,follow"' . selected( $robots, 'index,follow', false ) . '>' . esc_html__( 'Force index, follow', 'motored-core' ) . '</option>'
			. '<option value="noindex,follow"' . selected( $robots, 'noindex,follow', false ) . '>' . esc_html__( 'Force noindex, follow', 'motored-core' ) . '</option>'
			. '</select>';
		Ui::field( __( 'Search engine indexing (robots)', 'motored-core' ), 'motored-seo-term-robots', $bot_sel );
		Ui::box_close();
		echo '</td></tr>';
	}

	/**
	 * Saves per-term SEO overrides on product categories and brands.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_term_meta( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$raw   = isset( $_POST['motored_seo_term'] ) && is_array( $_POST['motored_seo_term'] ) ? wp_unslash( $_POST['motored_seo_term'] ) : array();
		$title = isset( $raw['title'] ) && is_string( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '';
		$desc  = isset( $raw['desc'] ) && is_string( $raw['desc'] ) ? sanitize_textarea_field( $raw['desc'] ) : '';
		$bot   = isset( $raw['robots'] ) && is_string( $raw['robots'] ) ? sanitize_text_field( $raw['robots'] ) : '';
		if ( ! in_array( $bot, array( '', 'index,follow', 'noindex,follow' ), true ) ) {
			$bot = '';
		}
		self::sync_term_meta( $term_id, HeadRenderer::META_TITLE, $title );
		self::sync_term_meta( $term_id, HeadRenderer::META_DESC, $desc );
		self::sync_term_meta( $term_id, HeadRenderer::META_ROBOTS, $bot );
		SitemapFilters::invalidate_cache();
	}

	/**
	 * Enqueues tiny vanilla live SERP preview script ONLY on managed post edit screens.
	 */
	public function enqueue_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || 'post' !== (string) ( $screen->base ?? '' ) ) {
			return;
		}
		if ( ! in_array( (string) ( $screen->id ?? '' ), HeadRenderer::MANAGED_POST_TYPES, true ) ) {
			return;
		}
		$ver = MOTORED_CORE_VERSION;
		if ( defined( 'MOTORED_CORE_PATH' ) ) {
			$path = MOTORED_CORE_PATH . 'assets/admin/motored-seo.js';
			if ( is_file( $path ) ) {
				$mtime = filemtime( $path );
				if ( false !== $mtime ) {
					$ver = (string) $mtime;
				}
			}
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			MOTORED_CORE_URL . 'assets/admin/motored-seo.js',
			array(),
			$ver,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	/**
	 * Updates or deletes a post meta key cleanly.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param string $value   Sanitized value.
	 */
	private static function sync_meta( int $post_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}
		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Updates or deletes a term meta key cleanly.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Meta key.
	 * @param string $value   Sanitized value.
	 */
	private static function sync_term_meta( int $term_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
			return;
		}
		update_term_meta( $term_id, $key, $value );
	}

	/**
	 * Stores a short-lived admin flash notice in Support\Cache.
	 *
	 * @param bool   $ok   Success flag.
	 * @param string $text Notice text.
	 */
	private function push_notice( bool $ok, string $text ): void {
		Cache::set(
			'notice:' . get_current_user_id(),
			array(
				'ok'   => $ok,
				'text' => $text,
			),
			120,
			self::NOTICE_BUCKET
		);
	}

	/**
	 * Reads and clears the pending admin flash notice.
	 *
	 * @return array{ok:bool,text:string}|null
	 */
	private function pull_notice(): ?array {
		$key    = 'notice:' . get_current_user_id();
		$found  = false;
		$notice = Cache::get( $key, self::NOTICE_BUCKET, $found );
		if ( ! $found || ! is_array( $notice ) ) {
			return null;
		}
		Cache::delete( $key, self::NOTICE_BUCKET );
		return array(
			'ok'   => ! empty( $notice['ok'] ),
			'text' => isset( $notice['text'] ) && is_string( $notice['text'] ) ? $notice['text'] : '',
		);
	}
}
