<?php
/**
 * Core SEO head renderer: document title, meta description, canonical, wp_robots, OG/Twitter and JSON-LD.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches to native WordPress hooks only; executes zero extra SQL queries on normal page views.
 */
final class HeadRenderer {

	public const META_TITLE     = '_motored_seo_title';
	public const META_DESC      = '_motored_seo_desc';
	public const META_ROBOTS    = '_motored_seo_robots';
	public const META_CANONICAL = '_motored_seo_canonical';

	/**
	 * Post types owned by Motored landing pages (never emit Product schema on these).
	 *
	 * @var list<string>
	 */
	private const LANDING_POST_TYPES = array( 'motored_bike', 'motored_tire_size' );

	/**
	 * All public post types supporting per-post Motored SEO overrides.
	 *
	 * @var list<string>
	 */
	public const MANAGED_POST_TYPES = array( 'product', 'page', 'post', 'motored_bike', 'motored_tire_size' );

	/**
	 * Public taxonomies supporting per-term SEO overrides (category, brand, bike brand).
	 *
	 * @var list<string>
	 */
	public const MANAGED_TAXONOMIES = array( 'product_cat', 'product_brand', 'motored_bike_brand' );

	/**
	 * Version constants of full SEO plugins that own title/description/canonical/OG/WebPage/Breadcrumb output.
	 *
	 * @var list<string>
	 */
	private const EXTERNAL_SEO_CONSTANTS = array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION' );

	/**
	 * Schema types an external SEO plugin already emits (ours would duplicate them).
	 *
	 * @var list<string>
	 */
	private const EXTERNAL_OWNED_SCHEMA = array( 'BreadcrumbList', 'WebPage', 'WebSite', 'Organization', 'AutoPartsStore', 'Article', 'CollectionPage' );

	/**
	 * Whether a full SEO plugin is active. Then this module steps back to what those plugins do not produce for our
	 * pages (robots rules + ItemList/FAQPage JSON-LD) so the head never carries two titles/descriptions/canonicals.
	 * Checked lazily (at hook time), so plugin load order does not matter. Filter: `motored_seo_external_plugin`.
	 */
	public static function external_seo_plugin(): bool {
		$active = false;
		foreach ( self::EXTERNAL_SEO_CONSTANTS as $const ) {
			if ( defined( $const ) ) {
				$active = true;
				break;
			}
		}
		return (bool) apply_filters( 'motored_seo_external_plugin', $active );
	}

	/**
	 * Registers frontend head, robots, canonical and title hooks.
	 */
	public function register(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 20, 2 );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), 25 );
		add_action( 'wp_head', array( $this, 'render_head' ), 2 );
	}

	/**
	 * Overrides <title> when SeoContext or per-post SEO meta supplies one.
	 *
	 * @param mixed $title Existing pre-filtered title.
	 */
	public function filter_document_title( $title ): string {
		$ctx = $this->resolved_context();
		if ( empty( $ctx ) || ! Config::enabled() || self::external_seo_plugin() ) {
			return is_string( $title ) ? $title : '';
		}
		$ctx_title = trim( (string) ( $ctx['title'] ?? '' ) );
		if ( '' !== $ctx_title ) {
			return $ctx_title;
		}
		return is_string( $title ) ? $title : '';
	}

	/**
	 * Overrides canonical URL when SeoContext or per-post canonical override provides a target.
	 *
	 * @param mixed $canonical Existing canonical URL.
	 * @param mixed $post      Post object.
	 * @return string|false
	 */
	public function filter_canonical_url( $canonical, $post = null ) {
		unset( $post );
		$ctx = $this->resolved_context();
		if ( empty( $ctx ) || ! Config::enabled() || self::external_seo_plugin() ) {
			return $canonical;
		}
		$raw = trim( (string) ( $ctx['canonical'] ?? '' ) );
		if ( '' === $raw ) {
			return $canonical;
		}
		if ( 'self' === $raw ) {
			return $this->clean_current_url();
		}
		return esc_url_raw( $raw );
	}

	/**
	 * Enforces robots directives: noindex on search, transactional pages, paged > 1 (opt-in) and non-indexable pages,
	 * plus modern rich snippet directives (`max-image-preview:large`, `max-snippet:-1`, `max-video-preview:-1`) on indexable views.
	 *
	 * @param mixed $robots Robots directives.
	 * @return array<string,mixed>
	 */
	public function filter_robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();

		$ctx        = $this->resolved_context();
		$ctx_robots = strtolower( trim( (string) ( $ctx['robots'] ?? '' ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public pagination query arg.
		$paged_get = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 0;
		$paged_qv  = function_exists( 'get_query_var' ) ? absint( get_query_var( 'paged', 0 ) ) : 0;
		$is_paged  = ( function_exists( 'is_paged' ) && is_paged() ) || $paged_get > 1 || $paged_qv > 1;
		$is_search = function_exists( 'is_search' ) && is_search();
		if ( ( '' === $ctx_robots && ! $is_paged && ! $is_search ) || ! Config::enabled() ) {
			return $robots;
		}
		$no_index  = ( Config::noindex_search() && $is_search )
			|| ( Config::noindex_paged() && $is_paged )
			|| str_contains( $ctx_robots, 'noindex' );
		$no_follow = str_contains( $ctx_robots, 'nofollow' );

		if ( $no_index ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			if ( $no_follow ) {
				unset( $robots['follow'] );
				$robots['nofollow'] = true;
			} else {
				$robots['nofollow'] = false;
				$robots['follow']   = true;
			}
		} elseif ( str_contains( $ctx_robots, 'index' ) ) {
			unset( $robots['noindex'], $robots['nofollow'] );
			$robots['index']             = true;
			$robots['follow']            = true;
			$robots['max-image-preview'] = 'large';
			$robots['max-snippet']       = '-1';
			$robots['max-video-preview'] = '-1';
		}

		return $robots;
	}

	/**
	 * Outputs meta description, archive/home canonical (when singular rel_canonical does not run), OG/Twitter and JSON-LD.
	 */
	public function render_head(): void {
		$ctx = $this->resolved_context();
		if ( empty( $ctx ) || ! Config::enabled() ) {
			return;
		}
		if ( self::external_seo_plugin() ) {
			if ( Config::schema_enabled() && ! empty( $ctx['schema'] ) && is_array( $ctx['schema'] ) ) {
				$this->render_schema( $ctx['schema'], self::EXTERNAL_OWNED_SCHEMA );
			}
			return;
		}

		$desc = trim( (string) ( $ctx['description'] ?? '' ) );
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}

		$canonical = $this->resolve_output_canonical( $ctx );
		if ( '' !== $canonical && ( ! function_exists( 'is_singular' ) || ! is_singular() ) ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}

		if ( Config::og_enabled() ) {
			$this->render_open_graph( $ctx, $canonical );
		}

		if ( Config::schema_enabled() && ! empty( $ctx['schema'] ) && is_array( $ctx['schema'] ) ) {
			$this->render_schema( $ctx['schema'] );
		}
	}

	/**
	 * Merges SeoContext with any per-post SEO override meta on managed public post types.
	 *
	 * @return array<string,mixed>
	 */
	private function resolved_context(): array {
		$ctx = SeoContext::get();
		if ( function_exists( 'is_singular' ) && is_singular( self::MANAGED_POST_TYPES ) && function_exists( 'get_queried_object_id' ) ) {
			$pid = (int) get_queried_object_id();
			if ( $pid > 0 && function_exists( 'get_post_meta' ) ) {
				$meta_title = trim( (string) get_post_meta( $pid, self::META_TITLE, true ) );
				$meta_desc  = trim( (string) get_post_meta( $pid, self::META_DESC, true ) );
				$meta_bot   = trim( (string) get_post_meta( $pid, self::META_ROBOTS, true ) );
				$meta_canon = trim( (string) get_post_meta( $pid, self::META_CANONICAL, true ) );
				if ( '' !== $meta_title ) {
					$ctx['title'] = $meta_title;
				}
				if ( '' !== $meta_desc ) {
					$ctx['description'] = $meta_desc;
				}
				if ( '' !== $meta_canon ) {
					$ctx['canonical'] = esc_url_raw( $meta_canon );
				}
				// An explicit noindex always wins. Index cannot bypass availability/indexability policy.
				if ( 'noindex,follow' === $meta_bot || 'noindex,nofollow' === $meta_bot ) {
					$ctx['robots'] = $meta_bot;
				} elseif ( 'index,follow' === $meta_bot && ! str_contains( (string) ( $ctx['robots'] ?? '' ), 'noindex' ) ) {
					$ctx['robots'] = $meta_bot;
				}
			}
		} elseif ( function_exists( 'is_tax' ) && is_tax( self::MANAGED_TAXONOMIES ) && function_exists( 'get_queried_object' ) && function_exists( 'get_term_meta' ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$meta_title = trim( (string) get_term_meta( $term->term_id, self::META_TITLE, true ) );
				$meta_desc  = trim( (string) get_term_meta( $term->term_id, self::META_DESC, true ) );
				$meta_bot   = trim( (string) get_term_meta( $term->term_id, self::META_ROBOTS, true ) );
				if ( '' !== $meta_title ) {
					$ctx['title'] = $meta_title;
				}
				if ( '' !== $meta_desc ) {
					$ctx['description'] = $meta_desc;
				}
				if ( 'noindex,follow' === $meta_bot || 'noindex,nofollow' === $meta_bot ) {
					$ctx['robots'] = $meta_bot;
				} elseif ( 'index,follow' === $meta_bot && ! str_contains( (string) ( $ctx['robots'] ?? '' ), 'noindex' ) ) {
					$ctx['robots'] = $meta_bot;
				}
			}
		}
		return $ctx;
	}

	/**
	 * Resolves final canonical string for OG/archive output.
	 *
	 * @param array<string,mixed> $ctx Resolved context.
	 */
	private function resolve_output_canonical( array $ctx ): string {
		$raw = trim( (string) ( $ctx['canonical'] ?? '' ) );
		if ( 'self' === $raw ) {
			return $this->clean_current_url();
		}
		if ( '' !== $raw ) {
			return esc_url_raw( $raw );
		}
		if ( function_exists( 'wp_get_canonical_url' ) ) {
			$wp_canon = wp_get_canonical_url();
			if ( is_string( $wp_canon ) && '' !== $wp_canon ) {
				return $wp_canon;
			}
		}
		return '';
	}

	/**
	 * Outputs Open Graph (including product/article metadata) and Twitter Card tags.
	 *
	 * @param array<string,mixed> $ctx       Resolved context.
	 * @param string              $canonical Canonical URL.
	 */
	private function render_open_graph( array $ctx, string $canonical ): void {
		$title = trim( (string) ( $ctx['title'] ?? '' ) );
		$desc  = trim( (string) ( $ctx['description'] ?? '' ) );
		if ( '' === $title && '' === $desc ) {
			return;
		}
		$og_type   = trim( (string) ( $ctx['og_type'] ?? 'website' ) );
		$image     = trim( (string) ( $ctx['og_image'] ?? '' ) );
		$locale    = function_exists( 'get_locale' ) ? (string) get_locale() : 'es_AR';
		$site_name = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
		if ( '' === $image ) {
			$image = Config::default_og_image();
		}

		echo '<meta property="og:locale" content="' . esc_attr( $locale ) . '" />' . "\n";
		if ( '' !== $site_name ) {
			echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
		}
		echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '" />' . "\n";
		if ( '' !== $title ) {
			echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		}
		if ( '' !== $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
		if ( '' !== $canonical ) {
			echo '<meta property="og:url" content="' . esc_url( $canonical ) . '" />' . "\n";
		}
		if ( '' !== $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
			$img_id = isset( $ctx['og_image_id'] ) ? (int) $ctx['og_image_id'] : 0;
			if ( $img_id > 0 && function_exists( 'wp_get_attachment_image_src' ) ) {
				$src = wp_get_attachment_image_src( $img_id, 'large' );
				if ( is_array( $src ) && ! empty( $src[1] ) && ! empty( $src[2] ) ) {
					echo '<meta property="og:image:width" content="' . (int) $src[1] . '" />' . "\n";
					echo '<meta property="og:image:height" content="' . (int) $src[2] . '" />' . "\n";
				}
			}
		}

		// Product social tags (WhatsApp / Facebook / Pinterest product previews).
		if ( ! empty( $ctx['product_meta'] ) && is_array( $ctx['product_meta'] ) ) {
			$pm = $ctx['product_meta'];
			if ( '' !== (string) ( $pm['price'] ?? '' ) ) {
				echo '<meta property="product:price:amount" content="' . esc_attr( (string) $pm['price'] ) . '" />' . "\n";
				echo '<meta property="product:price:currency" content="' . esc_attr( (string) ( $pm['currency'] ?? 'ARS' ) ) . '" />' . "\n";
			}
			if ( '' !== (string) ( $pm['availability'] ?? '' ) ) {
				echo '<meta property="product:availability" content="' . esc_attr( (string) $pm['availability'] ) . '" />' . "\n";
			}
		}

		// Article timestamps for blog posts.
		if ( ! empty( $ctx['article_meta'] ) && is_array( $ctx['article_meta'] ) ) {
			$am = $ctx['article_meta'];
			if ( '' !== (string) ( $am['published'] ?? '' ) ) {
				echo '<meta property="article:published_time" content="' . esc_attr( (string) $am['published'] ) . '" />' . "\n";
			}
			if ( '' !== (string) ( $am['modified'] ?? '' ) ) {
				echo '<meta property="article:modified_time" content="' . esc_attr( (string) $am['modified'] ) . '" />' . "\n";
			}
		}

		echo '<meta name="twitter:card" content="' . esc_attr( '' !== $image ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
		if ( '' !== $title ) {
			echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		}
		if ( '' !== $desc ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
		if ( '' !== $image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
		}
	}

	/**
	 * Outputs sanitized JSON-LD graph while enforcing landing-page schema guardrails.
	 *
	 * @param array<int|string,mixed> $nodes      Schema nodes.
	 * @param string[]                $skip_types Node types to leave out (owned by an external SEO plugin).
	 */
	private function render_schema( array $nodes, array $skip_types = array() ): void {
		$is_landing = ( function_exists( 'is_singular' ) && is_singular( self::LANDING_POST_TYPES ) )
			|| ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( self::LANDING_POST_TYPES ) )
			|| ( function_exists( 'is_tax' ) && is_tax( 'motored_bike_brand' ) );

		$clean = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
				continue;
			}
			$types = is_array( $node['@type'] ) ? array_map( 'strval', $node['@type'] ) : array( (string) $node['@type'] );
			if ( array() !== array_intersect( $types, $skip_types ) ) {
				continue;
			}
			// Non-negotiable rule: never emit Product markup on bike/size landing pages (use ItemList).
			if ( $is_landing && in_array( 'Product', $types, true ) ) {
				continue;
			}
			// Never emit FAQPage unless visible FAQ questions are present.
			if ( in_array( 'FAQPage', $types, true ) && ( empty( $node['mainEntity'] ) || ! is_array( $node['mainEntity'] ) ) ) {
				continue;
			}
			$clean[] = $node;
		}
		if ( empty( $clean ) ) {
			return;
		}
		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => $clean,
		);
		$json  = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( is_string( $json ) && '' !== $json ) {
			echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe JSON-LD encoded via wp_json_encode().
		}
	}

	/**
	 * Builds the current URL stripped of non-canonical query parameters (?pos, ?v, ?paged, ?moto, ?filter_*).
	 */
	private function clean_current_url(): string {
		$base  = $this->clean_base_url();
		$paged = function_exists( 'get_query_var' ) ? absint( get_query_var( 'paged', 0 ) ) : 0;
		if ( $paged < 2 || ( function_exists( 'is_singular' ) && is_singular() ) ) {
			return $base;
		}
		if ( '' !== (string) get_option( 'permalink_structure' ) && ! str_contains( $base, '?' ) ) {
			return trailingslashit( $base ) . user_trailingslashit( 'page/' . $paged, 'paged' );
		}
		return add_query_arg( 'paged', $paged, $base );
	}

	/**
	 * Builds the unpaginated canonical base for the current context.
	 */
	private function clean_base_url(): string {
		if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'get_permalink' ) ) {
			$url = get_permalink();
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		if ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_permalink' ) ) {
			$shop = (string) wc_get_page_permalink( 'shop' );
			if ( '' !== $shop ) {
				return $shop;
			}
		}
		if ( function_exists( 'is_tax' ) && is_tax() && function_exists( 'get_queried_object' ) && function_exists( 'get_term_link' ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) && '' !== $link ) {
					return $link;
				}
			}
		}
		if ( ( ( function_exists( 'is_category' ) && is_category() ) || ( function_exists( 'is_tag' ) && is_tag() ) ) && function_exists( 'get_queried_object' ) && function_exists( 'get_term_link' ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) && '' !== $link ) {
					return $link;
				}
			}
		}
		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive() && function_exists( 'get_post_type_archive_link' ) ) {
			$pt   = (string) get_query_var( 'post_type', 'motored_bike' );
			$link = get_post_type_archive_link( $pt );
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}
		if ( function_exists( 'is_home' ) && is_home() ) {
			$posts_page = (int) get_option( 'page_for_posts', 0 );
			if ( $posts_page > 0 && function_exists( 'get_permalink' ) ) {
				$url = get_permalink( $posts_page );
				if ( is_string( $url ) && '' !== $url ) {
					return $url;
				}
			}
		}
		return home_url( '/' );
	}
}
