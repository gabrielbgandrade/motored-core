<?php
namespace Motored\Core\Tests\Unit\Modules\CatalogFilters;

use Brain\Monkey\Functions;
use Motored\Core\Modules\CatalogFilters\Config;
use Motored\Core\Modules\CatalogFilters\QueryFilter;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class QueryFilterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		$this->options['motored_catalog_filters'] = array_merge( Config::DEFAULTS, array( 'enabled' => 'yes' ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) $s ) ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( trim( (string) $k ) ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	public function test_oferta_intersects_existing_moto_post_in_and_sets_zero_on_empty(): void {
		if ( ! class_exists( 'WP_Query' ) ) {
			eval( 'class WP_Query { public array $vars = array(); public function get($k, $d = "") { return $this->vars[$k] ?? $d; } public function set($k, $v) { $this->vars[$k] = $v; } }' );
		}

		$_GET['oferta'] = '1';
		Functions\when( 'wc_get_product_ids_on_sale' )->justReturn( array( 20, 30 ) );

		$q = new \WP_Query();
		$q->set( 'post__in', array( 10, 20 ) );
		( new QueryFilter() )->filter_product_query( $q );
		$this->assertSame( array( 20 ), $q->get( 'post__in' ) );

		$q2 = new \WP_Query();
		$q2->set( 'post__in', array( 99 ) );
		( new QueryFilter() )->filter_product_query( $q2 );
		$this->assertSame( array( 0 ), $q2->get( 'post__in' ) );
	}

	public function test_robots_noindex_applies_only_on_filtered_urls(): void {
		$qf = new QueryFilter();
		$this->assertSame( array(), $qf->robots_noindex( array() ) );

		$_GET['filter_rodado'] = '17';
		$this->assertSame( array(), $qf->robots_noindex( array() ) );
		$query = \Mockery::mock( \WP_Query::class );
		$query->shouldReceive( 'get' )->with( 'wc_query' )->andReturn( 'product_query' );
		$previous = $GLOBALS['wp_query'] ?? null;
		$GLOBALS['wp_query'] = $query;
		try {
			$robots = $qf->robots_noindex( array( 'nofollow' => true ) );
		} finally {
			$GLOBALS['wp_query'] = $previous;
		}
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['nofollow'] );
	}
}
