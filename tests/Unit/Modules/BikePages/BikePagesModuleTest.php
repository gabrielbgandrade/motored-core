<?php
namespace Motored\Core\Tests\Unit\Modules\BikePages;

use Brain\Monkey\Functions;
use Motored\Core\Integration\BikeDemandHooks;
use Motored\Core\Modules\BikePages\BikePageSync;
use Motored\Core\Modules\BikePages\BikeRenderer;
use Motored\Core\Modules\BikePages\GoneHandler;
use Motored\Core\Modules\BikePages\BikePagesModule;
use Motored\Core\Modules\BikePages\Config;
use Motored\Core\Modules\BikePages\DemandMigration;
use Motored\Core\Modules\BikePages\DemandPrivacy;
use Motored\Core\Modules\BikePages\DemandRestController;
use Motored\Core\Modules\BikePages\DemandStore;
use Motored\Core\Modules\BikePages\HubLinks;
use Motored\Core\Modules\BikePages\Signature;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Support\Cache;
use Motored\Core\Support\Context;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/stubs/object-cache.php';

final class BikePagesModuleTest extends TestCase {

	/**
	 * Captured log lines from fake logger.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $logs = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		$this->logs = array();
		$logger     = $this->fake_logger();
		$logger->shouldReceive( 'info', 'warning', 'error', 'notice', 'debug' )->andReturnUsing(
			function ( $msg = '', $ctx = array() ): void {
				$this->logs[] = array( 'msg' => $msg, 'ctx' => $ctx );
			}
		);
		HubLinks::reset();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$this->options['motored_bike_pages'] = Config::DEFAULTS;
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->alias( static fn( $u ) => (string) $u );
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9\-]+/i', '-', trim( (string) $s ) ), '-' ) ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'sanitize_email' )->alias( static fn( $e ) => filter_var( trim( (string) $e ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $e ) : '' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v, $flags = 0 ) => json_encode( $v, (int) $flags ) );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.test' . $p );
	}

	protected function tearDown(): void {
		HubLinks::reset();
		unset( $GLOBALS['wpdb'], $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	public function test_module_id_and_signature_collapses_identical_oem_sizes_across_years(): void {
		$mod = new BikePagesModule();
		$this->assertSame( 'bike_pages', $mod->id() );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		$this->assertTrue( $mod->should_load( new Context() ) );
		$this->assertFalse( BikePageSync::is_signature_indexable( 'benelli-trk-502-2017', false ) );
		$this->assertNotNull( BikeDemandHooks::class );

		$model    = array(
			'id'         => 10,
			'name'       => 'TRK 502',
			'slug'       => 'trk-502',
			'brand_name' => 'Benelli',
			'brand_slug' => 'benelli',
		);
		$versions = array(
			array( 'id' => 1, 'slug' => 'benelli-trk-502-2017-2020', 'year_from' => 2017, 'year_to' => 2020, 'variant' => '', 'status' => 'published' ),
			array( 'id' => 2, 'slug' => 'benelli-trk-502-2021-actual', 'year_from' => 2021, 'year_to' => 0, 'variant' => '', 'status' => 'published' ),
		);
		$shared   = array(
			array( 'position' => TireSpecs::POS_FRONT, 'canonical' => '120/70-17', 'fit_type' => TireSpecs::FIT_OEM, 'min_load' => 58, 'min_speed_rank' => 15, 'req_construction' => 'radial' ),
			array( 'position' => TireSpecs::POS_REAR, 'canonical' => '160/60-17', 'fit_type' => TireSpecs::FIT_OEM, 'min_load' => 69, 'min_speed_rank' => 15, 'req_construction' => 'radial' ),
		);
		$groups   = Signature::group_model_versions( $model, $versions, array( 1 => $shared, 2 => $shared ) );

		$this->assertCount( 1, $groups );
		$this->assertSame( '2017+', $groups[0]['years_label'] );
		$this->assertSame( array( 'benelli-trk-502-2017-2020', 'benelli-trk-502-2021-actual' ), $groups[0]['version_slugs'] );
		// One signature -> clean URL without the year (the page covers 2017+, riders search "<brand> <model>").
		$this->assertSame( 'benelli-trk-502', $groups[0]['post_slug'] );
		$this->assertSame( 'benelli-trk-502-2017', $groups[0]['year_slug'] );
	}

	public function test_slug_assignment_is_sticky_and_disambiguates_other_signatures_by_year(): void {
		$g = static fn( string $sig, int $from ): array => array(
			'sig_key'   => $sig,
			'base_slug' => 'honda-cbr900rr',
			'year_slug' => 'honda-cbr900rr-' . $from,
			'post_slug' => '',
		);
		$groups = array( $g( 'new', 2002 ), $g( 'old', 1992 ) ); // Newest first, as group_model_versions() sorts.

		// No page holds the clean slug yet: the newest signature takes it.
		$fresh = Signature::assign_post_slugs( $groups, array() );
		$this->assertSame( array( 'honda-cbr900rr', 'honda-cbr900rr-1992' ), array_column( $fresh, 'post_slug' ) );

		// Migration from year slugs: same outcome (only the clean-slug owner is renamed; the other keeps its URL).
		$migrated = Signature::assign_post_slugs( $groups, array( 'new' => 'honda-cbr900rr-2002', 'old' => 'honda-cbr900rr-1992' ) );
		$this->assertSame( array( 'honda-cbr900rr', 'honda-cbr900rr-1992' ), array_column( $migrated, 'post_slug' ) );

		// Sticky: the older page already ranks on the clean slug, so a newer generation never takes it over.
		$sticky = Signature::assign_post_slugs( $groups, array( 'old' => 'honda-cbr900rr' ) );
		$this->assertSame( array( 'honda-cbr900rr-2002', 'honda-cbr900rr' ), array_column( $sticky, 'post_slug' ) );
	}

	public function test_meta_description_stays_within_160_chars_and_oem_designations_per_axle(): void {
		$sizes = 'Front 120/70ZR17, Rear 160/60ZR17';
		$full  = BikeRenderer::seo_description( 'Benelli TRK 502 (2017+)', $sizes, 2 );
		$this->assertSame( 'Tires for Benelli TRK 502 (2017+). Front 120/70ZR17, Rear 160/60ZR17. 2 compatible options available with shipping across the country.', $full );
		$long = BikeRenderer::seo_description( 'Harley-Davidson Electra Glide Ultra Limited Shrine (2014–2016)', $sizes . ' / 130/90B16 / 180/65B16 reinforced touring', 12 );
		$this->assertLessThanOrEqual( BikeRenderer::SEO_DESC_MAX, mb_strlen( $long ) );
		$this->assertStringStartsWith( 'Tires for Harley-Davidson', $long );

		$oem = Signature::oem_designations(
			array(
				array( 'position' => TireSpecs::POS_FRONT, 'fit_type' => TireSpecs::FIT_OEM, 'canonical' => '120/70-17', 'designation' => '120/70ZR17' ),
				array( 'position' => TireSpecs::POS_REAR, 'fit_type' => TireSpecs::FIT_OEM, 'canonical' => '160/60-17', 'designation' => '' ),
				array( 'position' => TireSpecs::POS_REAR, 'fit_type' => TireSpecs::FIT_ALT, 'canonical' => '150/70-17', 'designation' => '150/70R17' ),
			)
		);
		$this->assertSame( array( 'f' => array( '120/70ZR17' ), 'r' => array( '160/60-17' ) ), $oem );
	}

	public function test_seo_title_fits_serp_width_and_gone_paths_only_match_single_bike_pages(): void {
		$title = BikeRenderer::seo_title( 'Benelli TRK 502 (2017+)', 'Motored' );
		$this->assertSame( 'Tires Benelli TRK 502 (2017+): sizes and prices | Motored', $title );
		$this->assertLessThanOrEqual( BikeRenderer::SEO_TITLE_MAX, mb_strlen( $title ) );
		// Long site name -> dropped before the keywords are.
		$this->assertSame( 'Tires Benelli TRK 502 (2017+): sizes and prices', BikeRenderer::seo_title( 'Benelli TRK 502 (2017+)', 'Motored Neumáticos Argentina' ) );
		// Very long model name -> shortest form.
		$long = 'Harley-Davidson Electra Glide Ultra Limited Shrine (2014–2016)';
		$this->assertSame( 'Tires ' . $long, BikeRenderer::seo_title( $long, 'Motored' ) );

		$this->assertSame( 'benelli-trk-502', GoneHandler::slug_from_path( '/cubiertas-para/benelli-trk-502/', '/', 'cubiertas-para' ) );
		$this->assertSame( 'benelli-trk-502', GoneHandler::slug_from_path( '/shop/cubiertas-para/benelli-trk-502', '/shop/', 'cubiertas-para' ) );
		$this->assertSame( '', GoneHandler::slug_from_path( '/cubiertas-para/', '/', 'cubiertas-para' ) );
		$this->assertSame( '', GoneHandler::slug_from_path( '/cubiertas-para/marca/benelli/', '/', 'cubiertas-para' ) );
		$this->assertSame( '', GoneHandler::slug_from_path( '/product/benelli-trk-502/', '/', 'cubiertas-para' ) );
		$this->assertSame( '', GoneHandler::slug_from_path( '/cubiertas-parax/benelli/', '/', 'cubiertas-para' ) );
	}

	public function test_rest_requests_resolve_bike_page_urls(): void {
		// Regression: the product banner / garage / tab rows come from REST; without the filter they linked to ?moto=.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		$_GET['rest_route'] = '/motored/v1/compat/bike/benelli-trk-502-2017-actual';
		try {
			( new BikePagesModule() )->register();
		} finally {
			unset( $_GET['rest_route'] );
		}
		$this->assertNotFalse( has_filter( 'motored_compat_bike_page_url' ) );
		// Storefront-only hooks stay out of REST.
		$this->assertFalse( has_action( 'wp' ) );
		$this->assertFalse( has_filter( 'get_the_archive_description' ) );
	}

	public function test_hub_links_resolves_multiple_version_slugs_in_one_query(): void {
		$wpdb           = \Mockery::mock( 'wpdb_fake' );
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'ID'         => 99,
					'post_name'  => 'benelli-trk-502-2017',
					'motored_slugs_json' => json_encode( array( 'benelli-trk-502-2017-2020', 'benelli-trk-502-2021-actual' ) ),
				),
			)
		);
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/cubiertas-para/benelli-trk-502-2017/' );

		$hub = new HubLinks();
		$this->assertSame( 'https://example.test/cubiertas-para/benelli-trk-502-2017/', $hub->filter_bike_page_url( '', 'benelli-trk-502-2017-2020' ) );
		// Second lookup uses memoized map (0 extra SQL queries).
		$this->assertSame( 'https://example.test/cubiertas-para/benelli-trk-502-2017/', $hub->filter_bike_page_url( '', 'benelli-trk-502-2021-actual' ) );
	}

	public function test_demand_store_parses_contacts_masks_logs_and_emits_hook(): void {
		$e = DemandStore::parse_contacts( 'Rider@Example.COM', '+54 9 11 5555-4444' );
		$this->assertSame( 'rider@example.com', $e['email'] );
		$this->assertSame( '+5491155554444', $e['phone'] );
		$this->assertNotEmpty( $e['hash'] );

		$sql = DemandMigration::statement( 'wp_motored_bike_demand', 'DEFAULT CHARSET=utf8mb4' );
		$this->assertStringContainsString( 'UNIQUE KEY uniq_contact_sig_pos (contact_hash,signature,position)', $sql );

		$wpdb            = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 77;
		$wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'get_row' )->once()->andReturn(
			array(
				'id'         => 10,
				'model_id'   => 3,
				'slug'       => 'benelli-trk-502-2017',
				'year_from'  => 2017,
				'year_to'    => 0,
				'variant'    => '',
				'status'     => 'published',
				'brand_name' => 'Benelli',
				'model_name' => 'TRK 502',
			)
		);
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'id'               => 1,
					'version_id'       => 10,
					'position'         => TireSpecs::POS_FRONT,
					'size_id'          => 5,
					'canonical'        => '120/70-17',
					'fit_type'         => TireSpecs::FIT_OEM,
					'min_load'         => null,
					'min_speed_rank'   => null,
					'req_construction' => 'radial',
					'designation'      => '120/70ZR17',
					'source'           => 'manual',
					'note'             => '',
				),
			)
		);
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( 0 );
		$wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;

		$hook_payload = null;
		Functions\when( 'do_action' )->alias(
			static function ( $tag, ...$args ) use ( &$hook_payload ): void {
				if ( 'motored_bike_demand_created' === $tag ) {
					$hook_payload = $args;
				}
			}
		);

		$res = DemandStore::record(
			array(
				'moto'     => 'benelli-trk-502-2017',
				'position' => 'front',
				'contact'  => 'rider@example.com',
				'consent'  => true,
			)
		);

		$this->assertTrue( $res['ok'] );
		$this->assertSame( 77, $res['id'] );
		$this->assertNotNull( $hook_payload );
		$this->assertSame( 77, $hook_payload[0] );
		$this->assertSame( 'Benelli', $hook_payload[1]['brand'] );
		// Logs must mask PII.
		$log_json = json_encode( $this->logs );
		$this->assertStringNotContainsString( 'rider@example.com', (string) $log_json );
	}

	public function test_demand_rest_controller_enforces_honeypot_and_rate_limit(): void {
		if ( ! class_exists( 'WP_REST_Request' ) ) {
			eval( 'class WP_REST_Request { public function __construct( public string $slug = "" ) {} public function get_param( string $k ) { return "slug" === $k ? $this->slug : null; } }' );
		}
		if ( ! class_exists( 'WP_REST_Response' ) ) {
			eval( 'class WP_REST_Response { public array $headers = array(); public function __construct( public mixed $data = null, public int $status = 200 ) {} public function header( string $k, string $v ): void { $this->headers[ $k ] = $v; } }' );
		}
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$wpdb          = \Mockery::mock( 'wpdb_fake' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;

		$req = \Mockery::mock( \WP_REST_Request::class );
		$req->shouldReceive( 'get_param' )->with( 'website' )->andReturn( 'http://spam.example' );
		$req->shouldReceive( 'get_header' )->with( 'X-Motored-Nonce' )->andReturn( 'nonce' );
		$res = ( new DemandRestController() )->handle_notify( $req );
		$this->assertSame( 200, $res->status );
		$this->assertTrue( $res->data['ok'] );

		// Exceed rate limit -> 429.
		$key = 'notify_rl_' . hash( 'sha256', 'ip203.0.113.10:' . (int) floor( time() / DemandRestController::RATE_LIMIT_WINDOW ) );
		Cache::set( $key, DemandRestController::RATE_LIMIT_MAX, 600, 'bike_demand' );
		$req2 = \Mockery::mock( \WP_REST_Request::class );
		$req2->shouldReceive( 'get_param' )->with( 'website' )->andReturn( '' );
		$req2->shouldReceive( 'get_header' )->with( 'X-Motored-Nonce' )->andReturn( 'nonce' );
		$res2 = ( new DemandRestController() )->handle_notify( $req2 );
		$this->assertSame( 429, $res2->status );
	}

	public function test_equal_oem_with_different_alternatives_does_not_share_a_signature(): void {
		$oem = array( 'fit_type' => 'oem', 'position' => 'front', 'canonical' => '120/70-17' );
		$alt = array( 'fit_type' => 'alt', 'position' => 'front', 'canonical' => '110/70-17' );
		$base = Signature::compute_for_fitments( array( $oem ) );
		$with_alt = Signature::compute_for_fitments( array( $oem, $alt ) );
		$this->assertNotSame( $base, $with_alt );
		$this->assertSame( $with_alt, Signature::compute_for_fitments( array( $alt, $oem ) ) );
		$alt['canonical'] = '130/70-17';
		$this->assertNotSame( $with_alt, Signature::compute_for_fitments( array( $oem, $alt ) ) );
	}

	public function test_technical_matches_without_purchasable_stock_are_not_available(): void {
		$product = \Mockery::mock( '\\WC_Product' );
		$product->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$product->shouldReceive( 'is_visible' )->andReturn( true );
		$product->shouldReceive( 'is_purchasable' )->andReturn( false );
		$this->assertFalse( \Motored\Core\Modules\BikePages\Availability::is_available( $product ) );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_summary_checks_products_after_the_twenty_fourth_result(): void {
		$product = \Mockery::mock( '\\WC_Product' );
		$product->shouldReceive( 'get_status' )->andReturn( 'publish' );
		$product->shouldReceive( 'is_visible', 'is_purchasable', 'is_in_stock' )->andReturn( true );
		$product->shouldReceive( 'get_image_id' )->andReturn( 0 );
		$product->shouldReceive( 'get_name' )->andReturn( 'Tire' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/product/tire/' );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$price_call = 0;
		Functions\when( 'wc_get_price_to_display' )->alias( static function () use ( &$price_call ) { return ++$price_call > 24 ? 10.0 : 100.0; } );
		$method = new \ReflectionMethod( BikeRenderer::class, 'summarize_products' );
		$summary = $method->invoke( null, range( 1, 30 ) );
		$this->assertSame( 30, $summary['in_stock_count'] );
		$this->assertSame( 10.0, $summary['min_price'] );
	}

	public function test_demand_privacy_exports_and_erases_by_email(): void {
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'id'          => 5,
					'brand_name'  => 'Benelli',
					'model_name'  => 'TRK 502',
					'years_label' => '2017+',
					'position'    => 'front',
					'email'       => 'rider@example.com',
					'phone'       => '',
					'consent_gmt' => '2026-11-24 12:00:00',
					'created_gmt' => '2026-11-24 12:00:00',
				),
			)
		);
		$wpdb->shouldReceive( 'delete' )->once()->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;

		$privacy = new DemandPrivacy();
		$exp     = $privacy->export( 'rider@example.com' );
		$this->assertCount( 1, $exp['data'] );
		$erase = $privacy->erase( 'rider@example.com' );
		$this->assertTrue( $erase['items_removed'] );
	}
}
