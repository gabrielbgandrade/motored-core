<?php
namespace Motored\Core\Tests\Unit\Modules\CatalogFilters;

use Brain\Monkey\Functions;
use Motored\Core\Modules\CatalogFilters\FacetEngine;
use Motored\Core\Modules\CatalogFilters\FilterState;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Tests\Unit\TestCase;

final class FacetEngineTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		FacetEngine::reset();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9\-]+/i', '-', trim( (string) $s ) ), '-' ) ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	public function test_disjunctive_counts_kits_exclusion_imperial_no_profile_and_etrto_order(): void {
		$rows = array(
			// Product 1: single front metric 120/70-17, Pirelli, speed W, in stock.
			array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'pa_posicion', 'slug' => 'delantero', 'name' => 'Delantero' ),
			array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'product_brand', 'slug' => 'pirelli', 'name' => 'Pirelli' ),
			array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'pa_ancho', 'slug' => '120', 'name' => '120' ),
			array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'pa_perfil', 'slug' => '70', 'name' => '70' ),
			array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'pa_rodado', 'slug' => '17', 'name' => '17' ),
			array( 'id' => 1, 'stock_status' => 'instock', 'taxonomy' => 'pa_indice-velocidad', 'slug' => 'w', 'name' => 'W' ),

			// Product 2: single front metric 110/70-17, Metzeler, speed H, in stock.
			array( 'id' => 2, 'stock_status' => 'instock', 'taxonomy' => 'pa_posicion', 'slug' => 'delantero', 'name' => 'Delantero' ),
			array( 'id' => 2, 'stock_status' => 'instock', 'taxonomy' => 'product_brand', 'slug' => 'metzeler', 'name' => 'Metzeler' ),
			array( 'id' => 2, 'stock_status' => 'instock', 'taxonomy' => 'pa_ancho', 'slug' => '110', 'name' => '110' ),
			array( 'id' => 2, 'stock_status' => 'instock', 'taxonomy' => 'pa_perfil', 'slug' => '70', 'name' => '70' ),
			array( 'id' => 2, 'stock_status' => 'instock', 'taxonomy' => 'pa_rodado', 'slug' => '17', 'name' => '17' ),
			array( 'id' => 2, 'stock_status' => 'instock', 'taxonomy' => 'pa_indice-velocidad', 'slug' => 'h', 'name' => 'H' ),

			// Product 3: imperial rear 4.10-18 (NO pa_perfil!), Pirelli, speed S, in stock.
			array( 'id' => 3, 'stock_status' => 'instock', 'taxonomy' => 'pa_posicion', 'slug' => 'trasero', 'name' => 'Trasero' ),
			array( 'id' => 3, 'stock_status' => 'instock', 'taxonomy' => 'product_brand', 'slug' => 'pirelli', 'name' => 'Pirelli' ),
			array( 'id' => 3, 'stock_status' => 'instock', 'taxonomy' => 'pa_ancho', 'slug' => '4-10', 'name' => '4.10' ),
			array( 'id' => 3, 'stock_status' => 'instock', 'taxonomy' => 'pa_rodado', 'slug' => '18', 'name' => '18' ),
			array( 'id' => 3, 'stock_status' => 'instock', 'taxonomy' => 'pa_indice-velocidad', 'slug' => 's', 'name' => 'S' ),

			// Product 4: KIT with stray pa_ancho=120 — MUST be excluded from width/rim/speed facets!
			array( 'id' => 4, 'stock_status' => 'instock', 'taxonomy' => 'pa_posicion', 'slug' => 'kit', 'name' => 'Kit' ),
			array( 'id' => 4, 'stock_status' => 'instock', 'taxonomy' => 'product_brand', 'slug' => 'metzeler', 'name' => 'Metzeler' ),
			array( 'id' => 4, 'stock_status' => 'instock', 'taxonomy' => 'pa_ancho', 'slug' => '120', 'name' => '120' ),
			array( 'id' => 4, 'stock_status' => 'instock', 'taxonomy' => 'pa_rodado', 'slug' => '17', 'name' => '17' ),

			// Product 5: out of stock 120/70-17 — excluded when only_in_stock=true.
			array( 'id' => 5, 'stock_status' => 'outofstock', 'taxonomy' => 'pa_posicion', 'slug' => 'delantero', 'name' => 'Delantero' ),
			array( 'id' => 5, 'stock_status' => 'outofstock', 'taxonomy' => 'pa_ancho', 'slug' => '120', 'name' => '120' ),
			array( 'id' => 5, 'stock_status' => 'outofstock', 'taxonomy' => 'pa_perfil', 'slug' => '70', 'name' => '70' ),
			array( 'id' => 5, 'stock_status' => 'outofstock', 'taxonomy' => 'pa_rodado', 'slug' => '17', 'name' => '17' ),
		);

		$snapshot = FacetEngine::assemble_snapshot_rows( $rows );

		// 1. Size combinations exclude kits and out-of-stock, and emit p=null for imperial 4.10-18.
		$combos = FacetEngine::size_combinations( $snapshot, true );
		$this->assertCount( 3, $combos );
		$this->assertSame( '4-10', $combos[0]['w'] );
		$this->assertNull( $combos[0]['p'] );
		$this->assertSame( '18', $combos[0]['r'] );
		$this->assertSame( 1, $combos[0]['c'] );

		// 2. Unfiltered ETRTO speed order: S < H < W (not H < S < W!).
		$all_res    = FacetEngine::compute_facets( $snapshot, new FilterState(), array( 'only_in_stock' => true, 'sale_ids' => array( 1 => true, 4 => true ) ) );
		$this->assertSame( 4, $all_res['total'] );
		$speed_list = array_column( $all_res['facets'][ TireSpecs::ATTR_SPEED_RATING ], 'label' );
		$this->assertSame( array( 'S', 'H', 'W' ), $speed_list );
		$this->assertSame( 2, $all_res['facets']['oferta'][0]['count'] );

		// 3. Disjunctive counts: filter ancho=120 + rodado=17 -> total=1 (Product 1; Kit 4 excluded!),
		// yet ancho facet STILL shows 110 (count 1) alongside 120 (count 1) because both satisfy rodado=17!
		$state = FilterState::from_query(
			array(
				'filter_ancho'  => '120',
				'filter_rodado' => '17',
			)
		);
		$res   = FacetEngine::compute_facets( $snapshot, $state, array( 'only_in_stock' => true, 'sale_ids' => array( 1 => true ) ) );
		$this->assertSame( 1, $res['total'] );
		$widths = array_column( $res['facets'][ TireSpecs::ATTR_WIDTH ], 'count', 'slug' );
		$this->assertSame( array( '110' => 1, '120' => 1 ), $widths );
		// Numeric slugs strictly remain strings.
		foreach ( $res['facets'][ TireSpecs::ATTR_WIDTH ] as $w_item ) {
			$this->assertIsString( $w_item['slug'] );
		}
	}

	public function test_benchmark_3000_products_disjunctive_facets_runs_in_milliseconds(): void {
		$products = array();
		$widths   = array( '90', '100', '110', '120', '130', '140', '150', '160', '180', '190', '4-10', '2-75' );
		$profiles = array( '55', '60', '70', '80', '90' );
		$rims     = array( '14', '16', '17', '18', '19', '21' );
		$brands   = array( 'pirelli', 'metzeler', 'michelin', 'dunlop', 'bridgestone' );
		$speeds   = array( 's', 't', 'h', 'v', 'w' );

		for ( $i = 1; $i <= 3000; $i++ ) {
			$w          = $widths[ $i % count( $widths ) ];
			$is_imp     = str_contains( $w, '-' );
			$products[] = array(
				'id'       => $i,
				'in_stock' => 0 !== ( $i % 10 ),
				'is_kit'   => 0 === ( $i % 15 ),
				'cats'     => array( 'cubiertas' ),
				'brands'   => array( $brands[ $i % count( $brands ) ] ),
				'attrs'    => array(
					TireSpecs::ATTR_POSITION     => array( 0 === ( $i % 15 ) ? 'kit' : ( 0 === ( $i % 2 ) ? 'delantero' : 'trasero' ) ),
					TireSpecs::ATTR_WIDTH        => 0 === ( $i % 15 ) ? array() : array( $w ),
					TireSpecs::ATTR_PROFILE      => ( 0 === ( $i % 15 ) || $is_imp ) ? array() : array( $profiles[ $i % count( $profiles ) ] ),
					TireSpecs::ATTR_RIM          => 0 === ( $i % 15 ) ? array() : array( $rims[ $i % count( $rims ) ] ),
					TireSpecs::ATTR_SPEED_RATING => 0 === ( $i % 15 ) ? array() : array( $speeds[ $i % count( $speeds ) ] ),
				),
			);
		}

		$snapshot = array(
			'products' => $products,
			'labels'   => array(),
		);
		$state    = FilterState::from_query(
			array(
				'filter_rodado' => '17',
				'product_brand' => 'pirelli,metzeler',
			)
		);

		$start = microtime( true );
		$res   = FacetEngine::compute_facets(
			$snapshot,
			$state,
			array(
				'only_in_stock' => true,
				'sale_ids'      => array( 1 => true, 2 => true, 3 => true ),
			)
		);
		$ms    = ( microtime( true ) - $start ) * 1000.0;

		$this->assertGreaterThan( 0, $res['total'] );
		$this->assertLessThan( 150.0, $ms, sprintf( '3000-product facet count took %.2f ms', $ms ) );
	}
}
