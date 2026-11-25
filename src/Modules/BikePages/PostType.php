<?php
/**
 * Custom post type (motored_bike) and brand taxonomy (motored_bike_brand) for BikePages.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\BikePages;

defined( 'ABSPATH' ) || exit;

/**
 * Registers CPT and taxonomy with admin-only rewrite flush versioning (never on frontend requests).
 */
final class PostType {

	public const POST_TYPE         = 'motored_bike';
	public const BRAND_TAXONOMY    = 'motored_bike_brand';
	public const REWRITE_VER_OPT   = 'motored_bike_pages_rewrite_ver';
	public const REWRITE_VERSION   = '1';
	public const REWRITE_HEAL_LOCK = 'motored_bike_pages_rewrite_heal';

	/**
	 * Registers CPT, taxonomy and admin-only rewrite flush check.
	 *
	 * @param bool $register_admin_flush Whether to attach admin_init rewrite flush checks.
	 */
	public function register( bool $register_admin_flush = true ): void {
		add_action( 'init', array( $this, 'register_types' ), 5 );
		add_action( 'init', array( $this, 'register_single_template' ), 20 );
		if ( $register_admin_flush ) {
			add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ) );
		}
	}

	/**
	 * Block themes: registers a plugin `single-motored_bike` template (core API, WP 6.7+) so a bike page is
	 * header + H1 (post title) + content + footer, without the blog post meta ("Written by … in …") that a
	 * theme's generic `single` template prints for posts that have no author or category.
	 *
	 * A theme that ships its own `single-motored_bike.html` (or an edit saved in the Site Editor) still wins.
	 * Classic themes are untouched: their single.php already prints the title + the_content.
	 */
	public function register_single_template(): void {
		if ( ! function_exists( 'register_block_template' ) || ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return;
		}
		// Hubs: H1 + the [motored_bike_hub] directory instead of the theme's post loop (which would print every
		// bike page's content and never shows the directory, as core/term-description skips our filter).
		foreach ( array(
			'archive-' . self::POST_TYPE       => __( 'Motorcycle pages hub', 'motored-core' ),
			'taxonomy-' . self::BRAND_TAXONOMY => __( 'Motorcycle brand hub', 'motored-core' ),
		) as $slug => $title ) {
			register_block_template(
				'motored-core//' . $slug,
				array(
					'title'       => $title,
					'description' => __( 'Tires-by-motorcycle landing page (Motored).', 'motored-core' ),
					'content'     => self::hub_template_content(),
				)
			);
		}
		register_block_template(
			'motored-core//single-' . self::POST_TYPE,
			array(
				'title'       => __( 'Motorcycle page', 'motored-core' ),
				'description' => __( 'Tires-by-motorcycle landing page (Motored).', 'motored-core' ),
				'post_types'  => array( self::POST_TYPE ),
				'content'     => self::single_template_content(),
			)
		);
	}

	/**
	 * Block markup of the hub templates (archive + brand): core blocks + the hub shortcode.
	 */
	public static function hub_template_content(): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n"
			. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->' . "\n"
			. '<main class="wp-block-group">' . "\n"
			. '<!-- wp:query-title {"type":"archive","showPrefix":false} /-->' . "\n"
			. '<!-- wp:shortcode -->[' . HubLinks::HUB_SHORTCODE . ']<!-- /wp:shortcode -->' . "\n"
			. '</main>' . "\n"
			. '<!-- /wp:group -->' . "\n"
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}

	/**
	 * Block markup of the single bike template: only core blocks and the standard header/footer template parts.
	 */
	public static function single_template_content(): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' . "\n"
			. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->' . "\n"
			. '<main class="wp-block-group">' . "\n"
			. '<!-- wp:post-title {"level":1} /-->' . "\n"
			. '<!-- wp:post-content {"layout":{"type":"constrained"}} /-->' . "\n"
			. '</main>' . "\n"
			. '<!-- /wp:group -->' . "\n"
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}

	/**
	 * Registers `motored_bike` CPT and `motored_bike_brand` taxonomy.
	 */
	public function register_types(): void {
		$base       = Config::base_slug();
		$brand_base = Config::brand_base();

		if ( function_exists( 'register_taxonomy' ) ) {
			register_taxonomy(
				self::BRAND_TAXONOMY,
				array( self::POST_TYPE ),
				array(
					'labels'            => array(
						'name'          => __( 'Motorcycle brands', 'motored-core' ),
						'singular_name' => __( 'Motorcycle brand', 'motored-core' ),
					),
					'public'            => true,
					'hierarchical'      => false,
					'show_ui'           => true,
					'show_admin_column' => true,
					'show_in_rest'      => true,
					'rewrite'           => array(
						'slug'       => $base . '/' . $brand_base,
						'with_front' => false,
					),
				)
			);
		}

		if ( function_exists( 'register_post_type' ) ) {
			register_post_type(
				self::POST_TYPE,
				array(
					'labels'              => array(
						'name'          => __( 'Motorcycle pages', 'motored-core' ),
						'singular_name' => __( 'Motorcycle page', 'motored-core' ),
					),
					'public'              => true,
					'has_archive'         => $base,
					'hierarchical'        => false,
					'show_ui'             => true,
					'show_in_menu'        => 'edit.php?post_type=product',
					'show_in_rest'        => true,
					'menu_icon'           => 'dashicons-car',
					'supports'            => array( 'title', 'editor', 'revisions', 'custom-fields' ),
					'taxonomies'          => array( self::BRAND_TAXONOMY ),
					'exclude_from_search' => false,
					'publicly_queryable'  => true,
					'rewrite'             => array(
						'slug'       => $base,
						'with_front' => false,
					),
				)
			);
		}
	}

	/**
	 * Flushes rewrite rules on admin_init when the CPT slug/version changes or rules are missing.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}
		$expected         = self::REWRITE_VERSION . ':' . Config::base_slug() . ':' . Config::brand_base();
		$stored           = (string) get_option( self::REWRITE_VER_OPT, '' );
		$version_mismatch = $expected !== $stored;
		$missing_rule     = ! $version_mismatch && $this->is_missing_from_rewrite_rules();
		if ( ! $version_mismatch && ! $missing_rule ) {
			return;
		}
		if ( $missing_rule && false !== get_transient( self::REWRITE_HEAL_LOCK ) ) {
			return;
		}
		$this->register_types();
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		update_option( self::REWRITE_VER_OPT, $expected, false );
		if ( $missing_rule ) {
			set_transient( self::REWRITE_HEAL_LOCK, '1', 300 );
		}
	}

	/**
	 * Checks whether compiled rewrite rules contain our base slug.
	 */
	private function is_missing_from_rewrite_rules(): bool {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || array() === $rules ) {
			return false;
		}
		$needle = Config::base_slug();
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( false !== strpos( (string) $pattern, $needle ) ) {
				return false;
			}
		}
		return true;
	}
}
