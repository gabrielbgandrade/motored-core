<?php
namespace Motored\Core\Tests\Unit\Modules\CatalogFilters;

use Brain\Monkey\Functions;
use Motored\Core\Modules\CatalogFilters\FilterState;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Tests\Unit\TestCase;

final class FilterStateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9\-]+/i', '-', trim( (string) $s ) ), '-' ) ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'add_query_arg' )->alias(
			static fn( $args, $url ) => $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args )
		);
	}

	public function test_parses_native_woo_params_keeps_numeric_slugs_as_strings_and_preserves_unknown_params(): void {
		$state = FilterState::from_query(
			array(
				'filter_ancho'      => '120,4.10',
				'query_type_ancho'  => 'or',
				'filter_rodado'     => '17',
				'product_brand'     => 'pirelli,metzeler',
				'oferta'            => '1',
				'moto'              => 'honda-xr-250-tornado-2001-2024',
				'pos'               => 'rear',
				'paged'             => '3',
				'orderby'           => 'price',
				'utm_source'        => 'ig',
			)
		);

		$this->assertSame( array( '120', '4-10' ), $state->attribute_terms( TireSpecs::ATTR_WIDTH ) );
		$this->assertSame( array( '17' ), $state->attribute_terms( TireSpecs::ATTR_RIM ) );
		foreach ( $state->attribute_terms( TireSpecs::ATTR_WIDTH ) as $slug ) {
			$this->assertIsString( $slug );
		}
		$this->assertSame( array( 'pirelli', 'metzeler' ), $state->brands() );
		$this->assertTrue( $state->on_sale() );
		$this->assertSame( 'honda-xr-250-tornado-2001-2024', $state->moto() );
		$this->assertSame( 'rear', $state->pos() );
		$this->assertSame( array( 'orderby' => 'price', 'utm_source' => 'ig' ), $state->extra() );
		$this->assertArrayNotHasKey( 'paged', $state->extra() );

		// Toggling strips /page/3/ and ?paged=, keeps unknown params, removes toggled value.
		$toggled = $state->toggle_url( 'https://example.test/tienda/page/3/', TireSpecs::ATTR_WIDTH, '120' );
		$this->assertStringStartsWith( 'https://example.test/tienda/?', $toggled );
		$this->assertStringContainsString( 'filter_ancho=4-10', $toggled );
		$this->assertStringContainsString( 'query_type_ancho=or', $toggled );
		$this->assertStringContainsString( 'orderby=price', $toggled );
		$this->assertStringContainsString( 'utm_source=ig', $toggled );
		$this->assertStringNotContainsString( 'paged=', $toggled );

		// Clearing removes all filter params + moto while preserving unknown params.
		$cleared = $state->clear_url( 'https://example.test/tienda/page/2/' );
		$this->assertSame( 'https://example.test/tienda/?orderby=price&utm_source=ig', $cleared );
	}
}
