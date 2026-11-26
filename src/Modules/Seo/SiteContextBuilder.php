<?php
/**
 * Site-wide SEO context builder for Home, Products, Shop/Categories/Brands, Pages, Blog and Transactional views.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Populates SeoContext lazily when a dedicated module (such as BikePages) has not already set one.
 * Uses already-primed WordPress/WooCommerce objects only (0 extra SQL queries on normal page views).
 */
final class SiteContextBuilder {

	/**
	 * Query parameters that create filtered/sorted duplicate catalog views.
	 *
	 * @var list<string>
	 */
	private const FILTER_QUERY_PREFIXES = array( 'filter_', 'query_type_' );

	/**
	 * Exact query parameters that represent non-indexable catalog modifiers.
	 *
	 * @var list<string>
	 */
	private const MODIFIER_QUERY_KEYS = array(
		'orderby',
		'min_price',
		'max_price',
		'rating_filter',
		'oferta',
		'moto',
		'kit_medida',
		'product_brand',
		'add-to-cart',
	);

	/**
	 * Registers the site-wide context builder after BikePages (priority 20 on `wp`).
	 */
	public function register(): void {
		add_action( 'wp', array( $this, 'populate' ), 20 );
	}

	/**
	 * Builds full-site SEO metadata (title, description, canonical, robots, OG, JSON-LD) when no specialized
	 * module already populated SeoContext.
	 */
	public function populate(): void {
		if ( ! Config::site_wide() || HeadRenderer::external_seo_plugin() ) {
			return;
		}
		if ( ! empty( SeoContext::get() ) ) {
			return; // BikePages / SizePages / HubLinks already populated a specialized context.
		}

		if ( self::is_transactional_page() ) {
			SeoContext::set(
				array(
					'robots' => 'noindex,nofollow',
				)
			);
			return;
		}

		if ( function_exists( 'is_404' ) && is_404() ) {
			SeoContext::set( array( 'robots' => 'noindex,follow' ) );
			return;
		}

		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			SeoContext::set( $this->build_home() );
			return;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			SeoContext::set( $this->build_product() );
			return;
		}

		if ( self::is_catalog_archive() ) {
			SeoContext::set( $this->build_catalog_archive() );
			return;
		}

		if ( function_exists( 'is_singular' ) && is_singular( array( 'page', 'post' ) ) ) {
			SeoContext::set( $this->build_singular() );
			return;
		}

		if ( ( function_exists( 'is_home' ) && is_home() ) || ( function_exists( 'is_category' ) && is_category() ) || ( function_exists( 'is_tag' ) && is_tag() ) ) {
			SeoContext::set( $this->build_blog_archive() );
		}
	}

	/**
	 * Builds Organization / AutoPartsStore JSON-LD node (shared by Home and references).
	 *
	 * @return array<string,mixed>
	 */
	public static function organization_node(): array {
		$home = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$name = Config::org_name();
		$node = array(
			'@type' => array( 'AutoPartsStore', 'Organization' ),
			'@id'   => trailingslashit( $home ) . '#organization',
			'name'  => $name,
			'url'   => $home,
		);
		$logo = Config::org_logo();
		if ( '' !== $logo ) {
			$node['logo']  = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
			$node['image'] = $logo;
		}
		$phone = Config::org_phone();
		if ( '' !== $phone ) {
			$node['telephone'] = $phone;
		}
		$city    = Config::org_city();
		$region  = Config::org_region();
		$country = Config::org_country();
		if ( '' !== $city || '' !== $region ) {
			$address = array(
				'@type'          => 'PostalAddress',
				'addressCountry' => $country,
			);
			if ( '' !== $city ) {
				$address['addressLocality'] = $city;
			}
			if ( '' !== $region ) {
				$address['addressRegion'] = $region;
			}
			$node['address'] = $address;
		}
		$socials = Config::social_profiles();
		if ( array() !== $socials ) {
			$node['sameAs'] = $socials;
		}
		return $node;
	}

	/**
	 * Whether the current request is a private/transactional WooCommerce or Mi Garage page.
	 */
	private static function is_transactional_page(): bool {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}
		if ( function_exists( 'is_page' ) ) {
			$garage_id = (int) get_option( 'motored_mi_garage_page_id', 0 );
			if ( $garage_id > 0 && is_page( $garage_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current request is the Shop page or a WooCommerce product taxonomy archive.
	 */
	private static function is_catalog_archive(): bool {
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}
		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			return true;
		}
		return function_exists( 'is_tax' ) && is_tax( array( 'product_brand', 'product_cat', 'product_tag' ) );
	}

	/**
	 * Whether the current URL carries non-canonical filter or sort query parameters.
	 */
	public static function has_catalog_modifiers(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public query string inspection.
		foreach ( array_keys( $_GET ) as $raw_key ) {
			if ( ! is_string( $raw_key ) ) {
				continue;
			}
			$key = strtolower( $raw_key );
			if ( in_array( $key, self::MODIFIER_QUERY_KEYS, true ) ) {
				return true;
			}
			foreach ( self::FILTER_QUERY_PREFIXES as $prefix ) {
				if ( str_starts_with( $key, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Home page SEO payload (AutoPartsStore + WebSite + SearchAction).
	 *
	 * @return array<string,mixed>
	 */
	private function build_home(): array {
		$site_name = self::site_name();
		$tagline   = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'description' ) ) : '';
		$home_url  = home_url( '/' );

		$title = Config::home_title();
		if ( '' === $title ) {
			$title = '' !== $tagline ? $site_name . ' ' . Config::title_separator() . ' ' . $tagline : $site_name;
		}
		$desc = Config::home_description();
		if ( '' === $desc ) {
			$desc = '' !== $tagline
				? self::trim_text( $tagline, 155 )
				: sprintf(
					/* translators: %s: store name. */
					__( 'Motorcycle tires in Argentina. Find the exact tire size for your motorcycle at %s.', 'motored-core' ),
					$site_name
				);
		}

		$image = Config::default_og_image();
		if ( '' === $image && function_exists( 'get_queried_object_id' ) ) {
			$front_id = (int) get_queried_object_id();
			if ( $front_id > 0 ) {
				$image = self::post_image_url( $front_id );
			}
		}
		if ( '' === $image ) {
			$image = Config::org_logo();
		}

		$org_node = self::organization_node();
		$web_node = array(
			'@type'           => 'WebSite',
			'@id'             => trailingslashit( $home_url ) . '#website',
			'url'             => $home_url,
			'name'            => $site_name,
			'inLanguage'      => self::html_lang(),
			'publisher'       => array( '@id' => $org_node['@id'] ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => add_query_arg(
						array(
							's'         => '{search_term_string}',
							'post_type' => 'product',
						),
						$home_url
					),
				),
				'query-input' => 'required name=search_term_string',
			),
		);

		return array(
			'title'       => $title,
			'description' => $desc,
			'canonical'   => $home_url,
			'robots'      => 'index,follow',
			'og_type'     => 'website',
			'og_image'    => $image,
			'schema'      => array( $org_node, $web_node ),
		);
	}

	/**
	 * WooCommerce single product SEO payload (complements WooCommerce's native Product JSON-LD with BreadcrumbList + OG product tags).
	 *
	 * @return array<string,mixed>
	 */
	private function build_product(): array {
		$pid     = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		$product = $pid > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		$site_name = self::site_name();
		$name      = self::plain( $product->get_name() );
		$permalink = (string) get_permalink( $pid );
		$title     = self::compose_title( $name, $site_name );

		$raw_desc = self::plain( $product->get_short_description() );
		if ( '' === $raw_desc ) {
			$raw_desc = self::plain( $product->get_description() );
		}
		if ( '' !== $raw_desc ) {
			$desc = self::trim_text( $raw_desc, 155 );
		} else {
			/* translators: 1: product name, 2: store name. */
			$desc = self::trim_text( sprintf( __( 'Buy %1$s at %2$s. Check motorcycle compatibility, specs and payment options.', 'motored-core' ), $name, $site_name ), 155 );
		}

		$hidden = method_exists( $product, 'get_catalog_visibility' ) && 'hidden' === (string) $product->get_catalog_visibility();
		$image  = self::post_image_url( $pid );
		$price  = $product->get_price();
		$curr   = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'ARS';
		$avail  = $product->is_in_stock() ? 'in stock' : 'out of stock';

		$crumbs = $this->product_breadcrumbs( $pid, $name, $permalink );

		return array(
			'title'        => $title,
			'description'  => $desc,
			'canonical'    => $permalink,
			'robots'       => $hidden ? 'noindex,follow' : 'index,follow',
			'og_type'      => 'product',
			'og_image'     => $image,
			'og_image_id'  => (int) $product->get_image_id(),
			'product_meta' => array(
				'price'        => is_numeric( $price ) ? (string) $price : '',
				'currency'     => $curr,
				'availability' => $avail,
			),
			'schema'       => array( $crumbs ),
		);
	}

	/**
	 * Shop / Product Category / Brand / Attribute archive SEO payload.
	 *
	 * @return array<string,mixed>
	 */
	private function build_catalog_archive(): array {
		$site_name = self::site_name();
		$paged     = function_exists( 'get_query_var' ) ? max( 1, absint( get_query_var( 'paged', 1 ) ) ) : 1;
		$modified  = self::has_catalog_modifiers();
		$is_shop   = function_exists( 'is_shop' ) && is_shop();

		$label     = '';
		$desc      = '';
		$base_url  = '';
		$og_image  = '';
		$robots    = $modified ? 'noindex,follow' : 'index,follow';
		$trail     = array(
			array(
				'name' => __( 'Home', 'motored-core' ),
				'url'  => home_url( '/' ),
			),
		);
		$shop_url  = self::shop_url();
		$shop_name = self::shop_title();

		if ( $is_shop ) {
			$shop_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
			$label    = $shop_name;
			$base_url = $shop_url;
			if ( $shop_id > 0 ) {
				$raw_excerpt = self::plain( (string) get_post_field( 'post_excerpt', $shop_id ) );
				if ( '' !== $raw_excerpt ) {
					$desc = self::trim_text( $raw_excerpt, 155 );
				}
				$og_image = self::post_image_url( $shop_id );
			}
			if ( '' === $desc ) {
				/* translators: %s: store name. */
				$desc = sprintf( __( 'Browse motorcycle tires by size, brand or bike model at %s.', 'motored-core' ), $site_name );
			}
			$trail[] = array(
				'name' => $shop_name,
				'url'  => $shop_url,
			);
		} else {
			$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
			if ( ! $term instanceof \WP_Term ) {
				return array();
			}
			$label    = self::plain( $term->name );
			$link     = get_term_link( $term );
			$base_url = is_string( $link ) ? $link : home_url( '/' );
			$raw_desc = self::plain( (string) $term->description );
			if ( '' !== $raw_desc ) {
				$desc = self::trim_text( $raw_desc, 155 );
			} else {
				/* translators: 1: category or brand name, 2: store name. */
				$desc = sprintf( __( 'Motorcycle tires in %1$s. Check stock, sizes and compatibility at %2$s.', 'motored-core' ), $label, $site_name );
			}
			$thumb_id = function_exists( 'get_term_meta' ) ? (int) get_term_meta( $term->term_id, 'thumbnail_id', true ) : 0;
			if ( $thumb_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
				$img = wp_get_attachment_image_url( $thumb_id, 'large' );
				if ( is_string( $img ) ) {
					$og_image = $img;
				}
			}
			if ( 'product_tag' === $term->taxonomy && (int) $term->count < 2 ) {
				$robots = 'noindex,follow';
			}
			if ( '' !== $shop_url ) {
				$trail[] = array(
					'name' => $shop_name,
					'url'  => $shop_url,
				);
			}
			if ( function_exists( 'get_ancestors' ) && $term->parent > 0 ) {
				$ancestors = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
				foreach ( $ancestors as $anc_id ) {
					$anc = get_term( (int) $anc_id, $term->taxonomy );
					if ( $anc instanceof \WP_Term ) {
						$anc_link = get_term_link( $anc );
						if ( is_string( $anc_link ) ) {
							$trail[] = array(
								'name' => self::plain( $anc->name ),
								'url'  => $anc_link,
							);
						}
					}
				}
			}
			$trail[] = array(
				'name' => $label,
				'url'  => $base_url,
			);
		}

		$title_base = $paged > 1
			/* translators: 1: archive title, 2: page number. */
			? sprintf( __( '%1$s — Page %2$d', 'motored-core' ), $label, $paged )
			: $label;
		$title     = self::compose_title( $title_base, $site_name );
		$canonical = $modified ? $base_url : 'self';

		$schema = array(
			array(
				'@type'       => 'CollectionPage',
				'name'        => $label,
				'description' => $desc,
				'url'         => $base_url,
				'inLanguage'  => self::html_lang(),
			),
			self::breadcrumb_node( $trail ),
		);

		return array(
			'title'       => $title,
			'description' => $desc,
			'canonical'   => $canonical,
			'robots'      => $robots,
			'og_type'     => 'website',
			'og_image'    => $og_image,
			'schema'      => $schema,
		);
	}

	/**
	 * Regular WordPress Page or Blog Post SEO payload.
	 *
	 * @return array<string,mixed>
	 */
	private function build_singular(): array {
		$pid  = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		$post = $pid > 0 && function_exists( 'get_post' ) ? get_post( $pid ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$site_name = self::site_name();
		$name      = self::plain( (string) get_the_title( $pid ) );
		$permalink = (string) get_permalink( $pid );
		$is_post   = 'post' === $post->post_type;

		$raw_desc = self::plain( (string) $post->post_excerpt );
		if ( '' === $raw_desc ) {
			$content  = function_exists( 'strip_shortcodes' ) ? strip_shortcodes( (string) $post->post_content ) : (string) $post->post_content;
			$raw_desc = self::plain( $content );
		}
		$desc  = '' !== $raw_desc ? self::trim_text( $raw_desc, 155 ) : '';
		$image = self::post_image_url( $pid );

		$trail = array(
			array(
				'name' => __( 'Home', 'motored-core' ),
				'url'  => home_url( '/' ),
			),
		);
		if ( $is_post ) {
			$posts_page_id = (int) get_option( 'page_for_posts', 0 );
			if ( $posts_page_id > 0 ) {
				$trail[] = array(
					'name' => self::plain( (string) get_the_title( $posts_page_id ) ),
					'url'  => (string) get_permalink( $posts_page_id ),
				);
			}
		}
		$trail[] = array(
			'name' => $name,
			'url'  => $permalink,
		);

		$schema = array( self::breadcrumb_node( $trail ) );
		$extra  = array();
		if ( $is_post ) {
			$published = function_exists( 'get_post_time' ) ? (string) get_post_time( 'c', true, $post ) : '';
			$modified  = function_exists( 'get_post_modified_time' ) ? (string) get_post_modified_time( 'c', true, $post ) : '';
			$author    = function_exists( 'get_the_author_meta' ) ? self::plain( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ) : $site_name;
			$article   = array(
				'@type'            => 'Article',
				'headline'         => $name,
				'description'      => $desc,
				'mainEntityOfPage' => $permalink,
				'inLanguage'       => self::html_lang(),
				'publisher'        => array( '@id' => trailingslashit( home_url( '/' ) ) . '#organization' ),
			);
			if ( '' !== $published ) {
				$article['datePublished'] = $published;
			}
			if ( '' !== $modified ) {
				$article['dateModified'] = $modified;
			}
			if ( '' !== $author ) {
				$article['author'] = array(
					'@type' => 'Person',
					'name'  => $author,
				);
			}
			if ( '' !== $image ) {
				$article['image'] = $image;
			}
			$schema[] = $article;
			$extra    = array(
				'published' => $published,
				'modified'  => $modified,
			);
		} else {
			$schema[] = array(
				'@type'       => 'WebPage',
				'name'        => $name,
				'description' => $desc,
				'url'         => $permalink,
				'inLanguage'  => self::html_lang(),
			);
		}

		return array(
			'title'        => self::compose_title( $name, $site_name ),
			'description'  => $desc,
			'canonical'    => $permalink,
			'robots'       => 'index,follow',
			'og_type'      => $is_post ? 'article' : 'website',
			'og_image'     => $image,
			'og_image_id'  => function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $pid ) : 0,
			'article_meta' => $extra,
			'schema'       => $schema,
		);
	}

	/**
	 * Blog index, category or tag archive SEO payload.
	 *
	 * @return array<string,mixed>
	 */
	private function build_blog_archive(): array {
		$site_name = self::site_name();
		$paged     = function_exists( 'get_query_var' ) ? max( 1, absint( get_query_var( 'paged', 1 ) ) ) : 1;
		$label     = '';
		$desc      = '';

		if ( function_exists( 'is_home' ) && is_home() ) {
			$pid   = (int) get_option( 'page_for_posts', 0 );
			$label = $pid > 0 ? self::plain( (string) get_the_title( $pid ) ) : __( 'Blog', 'motored-core' );
			$desc  = $pid > 0 ? self::trim_text( self::plain( (string) get_post_field( 'post_excerpt', $pid ) ), 155 ) : '';
		} else {
			$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
			if ( $term instanceof \WP_Term ) {
				$label = self::plain( $term->name );
				$desc  = self::trim_text( self::plain( (string) $term->description ), 155 );
			}
		}
		if ( '' === $label ) {
			return array();
		}
		$title_base = $paged > 1
			/* translators: 1: archive title, 2: page number. */
			? sprintf( __( '%1$s — Page %2$d', 'motored-core' ), $label, $paged )
			: $label;

		return array(
			'title'       => self::compose_title( $title_base, $site_name ),
			'description' => $desc,
			'canonical'   => 'self',
			'robots'      => 'index,follow',
			'og_type'     => 'website',
		);
	}

	/**
	 * Builds product BreadcrumbList JSON-LD: Home -> Shop -> Category chain -> Product.
	 *
	 * @param int    $pid       Product ID.
	 * @param string $name      Product name.
	 * @param string $permalink Product URL.
	 * @return array<string,mixed>
	 */
	private function product_breadcrumbs( int $pid, string $name, string $permalink ): array {
		$trail    = array(
			array(
				'name' => __( 'Home', 'motored-core' ),
				'url'  => home_url( '/' ),
			),
		);
		$shop_url = self::shop_url();
		if ( '' !== $shop_url ) {
			$trail[] = array(
				'name' => self::shop_title(),
				'url'  => $shop_url,
			);
		}
		if ( function_exists( 'get_the_terms' ) ) {
			$terms = get_the_terms( $pid, 'product_cat' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$primary = self::pick_deepest_term( $terms );
				if ( $primary instanceof \WP_Term ) {
					if ( function_exists( 'get_ancestors' ) && $primary->parent > 0 ) {
						foreach ( array_reverse( get_ancestors( $primary->term_id, 'product_cat', 'taxonomy' ) ) as $anc_id ) {
							$anc = get_term( (int) $anc_id, 'product_cat' );
							if ( $anc instanceof \WP_Term ) {
								$link = get_term_link( $anc );
								if ( is_string( $link ) ) {
									$trail[] = array(
										'name' => self::plain( $anc->name ),
										'url'  => $link,
									);
								}
							}
						}
					}
					$link = get_term_link( $primary );
					if ( is_string( $link ) ) {
						$trail[] = array(
							'name' => self::plain( $primary->name ),
							'url'  => $link,
						);
					}
				}
			}
		}
		$trail[] = array(
			'name' => $name,
			'url'  => $permalink,
		);
		return self::breadcrumb_node( $trail );
	}

	/**
	 * Picks the most specific (non-uncategorized, deepest parent) product category term.
	 *
	 * @param array<int,mixed> $terms WP_Term list.
	 */
	private static function pick_deepest_term( array $terms ): ?\WP_Term {
		$best       = null;
		$best_score = -1;
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			if ( 'uncategorized' === $term->slug || 'sin-categorizar' === $term->slug ) {
				continue;
			}
			$score = $term->parent > 0 ? 2 : 1;
			if ( $score > $best_score ) {
				$best       = $term;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * Builds a schema.org BreadcrumbList node.
	 *
	 * @param list<array{name:string,url:string}> $items Breadcrumb items.
	 * @return array<string,mixed>
	 */
	private static function breadcrumb_node( array $items ): array {
		$list = array();
		$pos  = 1;
		foreach ( $items as $item ) {
			if ( '' === $item['name'] ) {
				continue;
			}
			$entry = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => $item['name'],
			);
			if ( '' !== $item['url'] ) {
				$entry['item'] = $item['url'];
			}
			$list[] = $entry;
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list,
		);
	}

	/**
	 * Joins page label + separator + site name cleanly (avoiding duplicate brand suffix).
	 *
	 * @param string $label     Page/product title.
	 * @param string $site_name Store name.
	 */
	private static function compose_title( string $label, string $site_name ): string {
		if ( '' === $site_name || stripos( $label, $site_name ) !== false ) {
			return $label;
		}
		return $label . ' ' . Config::title_separator() . ' ' . $site_name;
	}

	/**
	 * Featured image URL (`large` sub-size) for a post/product.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function post_image_url( int $post_id ): string {
		if ( ! function_exists( 'get_post_thumbnail_id' ) || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return '';
		}
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id < 1 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $thumb_id, 'large' );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Store name helper.
	 */
	private static function site_name(): string {
		return function_exists( 'get_bloginfo' ) ? self::plain( (string) get_bloginfo( 'name' ) ) : '';
	}

	/**
	 * WooCommerce Shop page URL.
	 */
	private static function shop_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			return (string) wc_get_page_permalink( 'shop' );
		}
		return '';
	}

	/**
	 * WooCommerce Shop page title.
	 */
	private static function shop_title(): string {
		if ( function_exists( 'wc_get_page_id' ) ) {
			$id = (int) wc_get_page_id( 'shop' );
			if ( $id > 0 && function_exists( 'get_the_title' ) ) {
				$t = self::plain( (string) get_the_title( $id ) );
				if ( '' !== $t ) {
					return $t;
				}
			}
		}
		return __( 'Shop', 'motored-core' );
	}

	/**
	 * BCP-47 language tag for JSON-LD `inLanguage`.
	 */
	private static function html_lang(): string {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'es_AR';
		return str_replace( '_', '-', $locale );
	}

	/**
	 * Strips tags and collapses whitespace.
	 *
	 * @param string $raw Raw HTML/text.
	 */
	private static function plain( string $raw ): string {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $raw ) : strip_tags( $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback.
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return is_string( $text ) ? trim( $text ) : '';
	}

	/**
	 * Trims text on a word boundary to fit SERP snippet length.
	 *
	 * @param string $text Plain text.
	 * @param int    $max  Maximum characters.
	 */
	private static function trim_text( string $text, int $max ): string {
		if ( '' === $text ) {
			return '';
		}
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $len <= $max ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max - 1, 'UTF-8' ) : substr( $text, 0, $max - 1 );
		$sp  = strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > (int) ( $max * 0.6 ) ) {
			$cut = substr( $cut, 0, $sp );
		}
		return rtrim( $cut, " \t\n\r\0\x0B.,;:-" ) . '…';
	}
}
