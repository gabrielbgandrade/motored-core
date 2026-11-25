<?php
/**
 * Admin interface for Seo: WooCommerce settings section and per-post SEO override meta box.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Uses shared Admin\Ui and motored-admin.css classes only (no inline styles or tables).
 */
final class SeoAdmin {

	public const SECTION      = 'seo';
	public const NONCE_FIELD  = '_motored_seo_nonce';
	public const NONCE_ACTION = 'motored_seo_metabox';

	/**
	 * Post types displaying the SEO overrides meta box.
	 *
	 * @var list<string>
	 */
	private const POST_TYPES = array( 'motored_bike', 'motored_tire_size' );

	/**
	 * Registers settings section and post meta box hooks.
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'SEO', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			65
		);
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post_meta' ), 10, 2 );
	}

	/**
	 * WooCommerce > Settings > Motored > SEO fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		return array(
			array(
				'title' => __( 'Core SEO & Structured Data', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'Lightweight titles, meta descriptions, canonicals, XML sitemaps, 301 redirects and JSON-LD built on WordPress core.', 'motored-core' ),
				'id'    => 'motored_seo_title',
			),
			array(
				'title'    => __( 'Enable core SEO', 'motored-core' ),
				'desc'     => __( 'Manage titles, descriptions, canonicals, sitemaps, redirects and JSON-LD for Motored landing pages.', 'motored-core' ),
				'id'       => 'motored_seo[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Noindex paginated archives', 'motored-core' ),
				'desc'     => __( 'Emit noindex, follow on /page/2+ and ?paged=2+ URLs. Off by default: useful catalog pages stay crawlable with a self-referencing canonical.', 'motored-core' ),
				'id'       => 'motored_seo[noindex_paged]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['noindex_paged'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Noindex search results', 'motored-core' ),
				'desc'     => __( 'Emit noindex, follow on WordPress search result pages (?s=).', 'motored-core' ),
				'id'       => 'motored_seo[noindex_search]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['noindex_search'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Open Graph & Twitter tags', 'motored-core' ),
				'desc'     => __( 'Output og:* and twitter:card tags on SEO-managed pages.', 'motored-core' ),
				'id'       => 'motored_seo[og_enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['og_enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'JSON-LD structured data', 'motored-core' ),
				'desc'     => __( 'Output BreadcrumbList, ItemList and FAQPage schema on motorcycle and size landing pages.', 'motored-core' ),
				'id'       => 'motored_seo[schema_enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['schema_enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Default Open Graph image URL', 'motored-core' ),
				'desc'     => __( 'Fallback social share image when a motorcycle or size page has no product image.', 'motored-core' ),
				'id'       => 'motored_seo[default_og_image]',
				'type'     => 'url',
				'class'    => 'motored-input-wide',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_seo_end',
			),
		);
	}

	/**
	 * Registers the SEO overrides meta box on BikePages and SizePages post types.
	 */
	public function add_meta_boxes(): void {
		foreach ( self::POST_TYPES as $pt ) {
			if ( function_exists( 'post_type_exists' ) && ! post_type_exists( $pt ) ) {
				continue;
			}
			add_meta_box(
				'motored-seo-overrides',
				__( 'Motored SEO overrides', 'motored-core' ),
				array( $this, 'render_meta_box' ),
				$pt,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Renders per-post SEO override fields using Admin\Ui.
	 *
	 * @param mixed $post Current WP_Post.
	 */
	public function render_meta_box( $post ): void {
		$pid = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$title  = $pid > 0 ? (string) get_post_meta( $pid, HeadRenderer::META_TITLE, true ) : '';
		$desc   = $pid > 0 ? (string) get_post_meta( $pid, HeadRenderer::META_DESC, true ) : '';
		$robots = $pid > 0 ? (string) get_post_meta( $pid, HeadRenderer::META_ROBOTS, true ) : '';

		Ui::box_open( true );
		Ui::field(
			__( 'SEO title override', 'motored-core' ),
			'motored-seo-title',
			'<input id="motored-seo-title" type="text" name="motored_seo_meta[title]" value="' . esc_attr( $title ) . '" />',
			__( 'Leave blank to use the automatically generated title.', 'motored-core' )
		);
		Ui::field(
			__( 'Meta description override', 'motored-core' ),
			'motored-seo-desc',
			'<textarea id="motored-seo-desc" name="motored_seo_meta[desc]">' . esc_textarea( $desc ) . '</textarea>',
			__( 'Leave blank to use the automatically generated description.', 'motored-core' )
		);
		$bot_sel = '<select id="motored-seo-robots" name="motored_seo_meta[robots]">'
			. '<option value=""' . selected( $robots, '', false ) . '>' . esc_html__( 'Automatic (index when compatible products exist)', 'motored-core' ) . '</option>'
			. '<option value="index,follow"' . selected( $robots, 'index,follow', false ) . '>' . esc_html__( 'Force index, follow', 'motored-core' ) . '</option>'
			. '<option value="noindex,follow"' . selected( $robots, 'noindex,follow', false ) . '>' . esc_html__( 'Force noindex, follow', 'motored-core' ) . '</option>'
			. '</select>';
		Ui::field( __( 'Robots override', 'motored-core' ), 'motored-seo-robots', $bot_sel );
		Ui::box_close();
	}

	/**
	 * Saves per-post SEO overrides when editing a motorcycle or size landing page.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post object.
	 */
	public function save_post_meta( int $post_id, $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || ! in_array( (string) $post->post_type, self::POST_TYPES, true ) ) {
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
		$bot   = isset( $raw['robots'] ) && is_string( $raw['robots'] ) ? sanitize_text_field( $raw['robots'] ) : '';
		if ( ! in_array( $bot, array( '', 'index,follow', 'noindex,follow' ), true ) ) {
			$bot = '';
		}

		self::sync_meta( $post_id, HeadRenderer::META_TITLE, $title );
		self::sync_meta( $post_id, HeadRenderer::META_DESC, $desc );
		self::sync_meta( $post_id, HeadRenderer::META_ROBOTS, $bot );
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
}
