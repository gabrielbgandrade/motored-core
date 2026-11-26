<?php
namespace Motored\Core\Tests\Unit\Modules\Seo;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Seo\Config;
use Motored\Core\Modules\Seo\HeadRenderer;
use Motored\Core\Modules\Seo\Redirects;
use Motored\Core\Modules\Seo\SeoContext;
use Motored\Core\Modules\Seo\SeoMigration;
use Motored\Core\Modules\Seo\SeoModule;
use Motored\Core\Modules\Seo\SitemapFilters;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class SeoModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		SeoContext::reset();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$this->options['motored_seo'] = Config::DEFAULTS;
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->alias( static fn( $u ) => (string) $u );
		Functions\when( 'esc_url_raw' )->alias( static fn( $u ) => (string) $u );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v, $flags = 0 ) => json_encode( $v, (int) $flags ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9\-]+/i', '-', trim( (string) $s ) ), '-' ) ) );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.test' . $p );
	}

	protected function tearDown(): void {
		SeoContext::reset();
		unset( $_GET['paged'], $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_module_context_gates_and_migration_schema(): void {
		$mod = new SeoModule();
		$this->assertSame( 'seo', $mod->id() );

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		$this->assertTrue( $mod->should_load( new Context() ) );

		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$this->assertFalse( $mod->should_load( new Context() ) );

		$sql = SeoMigration::statement( 'wp_motored_seo_redirects', 'DEFAULT CHARSET=utf8mb4' );
		$this->assertStringContainsString( 'UNIQUE KEY source_path (source_path)', $sql );
	}

	public function test_head_renderer_enforces_noindex_on_search_paged_and_strips_product_schema_on_landing_pages(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 0 );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_paged' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( 0 );

		SeoContext::set(
			array(
				'title'       => 'Cubiertas Benelli TRK 502',
				'description' => 'Medidas de fábrica 120/70ZR17 y 160/60ZR17.',
				'canonical'   => 'https://example.test/cubiertas-para/benelli-trk-502-2017/',
				'robots'      => 'index,follow',
				'schema'      => array(
					array( '@type' => 'Product', 'name' => 'Must be stripped' ),
					array( '@type' => 'FAQPage', 'mainEntity' => array() ),
					array( '@type' => 'BreadcrumbList', 'itemListElement' => array( array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home' ) ) ),
				),
			)
		);

		$renderer = new HeadRenderer();
		$this->assertSame( 'Cubiertas Benelli TRK 502', $renderer->filter_document_title( 'Old' ) );
		$this->assertSame( 'https://example.test/cubiertas-para/benelli-trk-502-2017/', $renderer->filter_canonical_url( '' ) );

		$robots = $renderer->filter_robots( array() );
		$this->assertTrue( $robots['index'] );
		$this->assertArrayNotHasKey( 'noindex', $robots );

		// Paged > 1 stays indexable by default (useful catalog pages are crawlable).
		$_GET['paged'] = '2';
		$paged_robots  = $renderer->filter_robots( array( 'index' => true ) );
		$this->assertTrue( $paged_robots['index'] );
		$this->assertArrayNotHasKey( 'noindex', $paged_robots );

		// Opt-in setting flips paged > 1 to noindex, follow.
		Options::update( Config::OPTION_KEY, array( 'noindex_paged' => 'yes' ) );
		$paged_robots = $renderer->filter_robots( array( 'index' => true ) );
		$this->assertTrue( $paged_robots['noindex'] );
		$this->assertArrayNotHasKey( 'index', $paged_robots );
		Options::update( Config::OPTION_KEY, array( 'noindex_paged' => 'no' ) );
		unset( $_GET['paged'] );

		ob_start();
		$renderer->render_head();
		$head = (string) ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="Medidas de fábrica 120/70ZR17 y 160/60ZR17." />', $head );
		$this->assertStringContainsString( 'BreadcrumbList', $head );
		$this->assertStringNotContainsString( 'Must be stripped', $head );
		$this->assertStringNotContainsString( 'FAQPage', $head );
	}

	public function test_head_renderer_steps_back_when_an_seo_plugin_is_active(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 0 );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		\Brain\Monkey\Filters\expectApplied( 'motored_seo_external_plugin' )->andReturn( true );

		SeoContext::set(
			array(
				'title'       => 'Cubiertas Benelli TRK 502',
				'description' => 'Medidas de fábrica.',
				'canonical'   => 'https://example.test/cubiertas-para/benelli-trk-502/',
				'robots'      => 'index,follow',
				'schema'      => array(
					array( '@type' => 'BreadcrumbList', 'itemListElement' => array() ),
					array( '@type' => 'WebPage', 'name' => 'x' ),
					array( '@type' => 'ItemList', 'itemListElement' => array() ),
					array( '@type' => 'FAQPage', 'mainEntity' => array( array( '@type' => 'Question', 'name' => 'q' ) ) ),
				),
			)
		);
		$renderer = new HeadRenderer();
		// Title and canonical belong to the SEO plugin.
		$this->assertSame( 'Theme title', $renderer->filter_document_title( 'Theme title' ) );
		$this->assertSame( 'https://example.test/plugin-canonical/', $renderer->filter_canonical_url( 'https://example.test/plugin-canonical/' ) );

		ob_start();
		$renderer->render_head();
		$head = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'name="description"', $head );
		$this->assertStringNotContainsString( 'og:title', $head );
		$this->assertStringNotContainsString( 'BreadcrumbList', $head );
		$this->assertStringNotContainsString( '"WebPage"', $head );
		$this->assertStringContainsString( 'ItemList', $head );
		$this->assertStringContainsString( 'FAQPage', $head );
	}

	public function test_sitemap_filters_exclude_non_indexable_bikes_and_empty_brands(): void {
		$sf   = new SitemapFilters();
		$args = $sf->filter_posts_query_args( array(), 'motored_bike' );
		$this->assertArrayHasKey( 'meta_query', $args );

		$wpdb                   = \Mockery::mock( 'wpdb_fake' );
		$wpdb->posts            = 'wp_posts';
		$wpdb->postmeta         = 'wp_postmeta';
		$wpdb->term_relationships = 'wp_term_relationships';
		$wpdb->term_taxonomy    = 'wp_term_taxonomy';
		$wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( 5, 8 ) );
		$GLOBALS['wpdb'] = $wpdb;

		$tax_args = $sf->filter_taxonomies_query_args( array(), 'motored_bike_brand' );
		$this->assertSame( array( 5, 8 ), $tax_args['include'] );
	}

	public function test_redirects_prevents_self_loops_and_resolves_only_on_404(): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $c ) => parse_url( (string) $url, (int) $c ) );
		$this->assertSame( '/cubiertas-para/benelli-trk-502-2017', Redirects::normalize_path( 'https://example.test/Cubiertas-Para/Benelli-TRK-502-2017/?foo=bar' ) );
		$this->assertFalse( Redirects::add( '/cubiertas-para/a/', 'https://example.test/cubiertas-para/a/' ) );

		// 200 requests never hit DB.
		Functions\when( 'is_404' )->justReturn( false );
		$wpdb = \Mockery::mock( 'wpdb_fake' );
		$wpdb->shouldReceive( 'get_row' )->never();
		$GLOBALS['wpdb'] = $wpdb;
		( new Redirects() )->maybe_redirect();
		$this->assertTrue( true );
	}

	public function test_sitemap_disables_users_adds_lastmod_and_optimizes_robots_txt(): void {
		$sf = new SitemapFilters();
		$this->assertFalse( $sf->filter_provider( (object) array(), 'users' ) );
		$this->assertIsObject( $sf->filter_provider( (object) array(), 'posts' ) );

		$entry = $sf->filter_posts_entry(
			array( 'loc' => 'https://example.test/producto/x/' ),
			(object) array( 'post_modified_gmt' => '2026-11-26 12:00:00' )
		);
		$this->assertSame( '2026-11-26T12:00:00+00:00', $entry['lastmod'] );

		$robots = $sf->filter_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n", true );
		$this->assertStringContainsString( 'Disallow: /*?add-to-cart=*', $robots );
		$this->assertStringContainsString( 'Disallow: /*?filter_*', $robots );
		$this->assertStringContainsString( 'Sitemap: https://example.test/wp-sitemap.xml', $robots );
	}

	public function test_site_context_builder_populates_home_organization_and_search_action(): void {
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_account_page' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 0 );
		Functions\when( 'get_bloginfo' )->alias( static fn( $k ) => 'name' === $k ? 'Motored' : 'Cubiertas para moto' );
		Functions\when( 'get_locale' )->justReturn( 'es_AR' );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/' ) . '/' );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );

		( new \Motored\Core\Modules\Seo\SiteContextBuilder() )->populate();
		$ctx = SeoContext::get();

		$this->assertSame( 'Motored | Cubiertas para moto', $ctx['title'] );
		$this->assertSame( 'https://example.test/', $ctx['canonical'] );
		$this->assertCount( 2, $ctx['schema'] );
		$this->assertSame( 'WebSite', $ctx['schema'][1]['@type'] );
		$this->assertSame( 'SearchAction', $ctx['schema'][1]['potentialAction']['@type'] );
	}

	public function test_site_context_builder_detects_catalog_filter_modifiers(): void {
		$_GET['filter_ancho'] = '160';
		$this->assertTrue( \Motored\Core\Modules\Seo\SiteContextBuilder::has_catalog_modifiers() );
		unset( $_GET['filter_ancho'] );
		$this->assertFalse( \Motored\Core\Modules\Seo\SiteContextBuilder::has_catalog_modifiers() );
	}

	public function test_merchant_feed_builds_valid_google_shopping_xml(): void {
		$xml = \Motored\Core\Modules\Seo\MerchantFeed::build_xml(
			array(
				array(
					'id'                      => '101',
					'title'                   => 'Cubierta 160/60R17 Pirelli MT60 RS & Kit',
					'description'             => 'Cubierta radial trasera.',
					'link'                    => 'https://example.test/producto/pirelli-mt60/',
					'image_link'              => 'https://example.test/img/mt60.jpg',
					'additional_images'       => array( 'https://example.test/img/mt60-2.jpg' ),
					'availability'            => 'in_stock',
					'price'                   => '600000.00 ARS',
					'sale_price'              => '540000.00 ARS',
					'brand'                   => 'Pirelli',
					'mpn'                     => 'PIR-1606017',
					'google_product_category' => '913',
					'product_type'            => 'Cubiertas > Trail',
					'shipping_country'        => 'AR',
					'shipping_price'          => '0.00 ARS',
				),
			),
			'Motored Neumáticos',
			'https://example.test/',
			'Cubiertas para moto'
		);

		$this->assertStringContainsString( 'xmlns:g="http://base.google.com/ns/1.0"', $xml );
		$this->assertStringContainsString( '<g:id>101</g:id>', $xml );
		$this->assertStringContainsString( '&amp; Kit</g:title>', $xml );
		$this->assertStringContainsString( '<g:price>600000.00 ARS</g:price>', $xml );
		$this->assertStringContainsString( '<g:sale_price>540000.00 ARS</g:sale_price>', $xml );
		$this->assertStringContainsString( '<g:google_product_category>913</g:google_product_category>', $xml );
		$this->assertStringContainsString( '<g:brand>Pirelli</g:brand>', $xml );
		$this->assertStringContainsString( '<g:mpn>PIR-1606017</g:mpn>', $xml );
		$this->assertStringContainsString( '<g:country>AR</g:country>', $xml );
	}

	public function test_return_policy_and_shipping_details_nodes(): void {
		$policy = \Motored\Core\Modules\Seo\ProductSchemaEnhancer::return_policy_node();
		$this->assertIsArray( $policy );
		$this->assertSame( 10, $policy['merchantReturnDays'] );
		$this->assertSame( 'AR', $policy['applicableCountry'] );

		// Blank shipping_cost omits shippingDetails by default.
		$this->assertNull( \Motored\Core\Modules\Seo\ProductSchemaEnhancer::shipping_details_node() );

		Options::update( Config::OPTION_KEY, array( 'shipping_cost' => '0' ) );
		$ship = \Motored\Core\Modules\Seo\ProductSchemaEnhancer::shipping_details_node();
		$this->assertIsArray( $ship );
		$this->assertSame( '0.00', $ship['shippingRate']['value'] );
		$this->assertSame( 'AR', $ship['shippingDestination']['addressCountry'] );
	}
}
