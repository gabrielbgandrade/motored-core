<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Recovery\Snapshot;
use Motored\Core\Tests\Unit\TestCase;

final class SnapshotTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	public function test_keeps_only_ids_quantity_and_attributes(): void {
		$result = Snapshot::from_cart(
			array(
				array(
					'product_id'   => '12',
					'variation_id' => '34',
					'quantity'     => 2,
					'variation'    => array( 'attribute_pa_rodado' => 'r15', 'attribute_a' => 'x' ),
					'data'         => 'WC_Product object, name and price must never be copied',
					'line_total'   => 999.99,
				),
			)
		);
		$this->assertSame(
			array(
				array(
					'product_id'   => 12,
					'variation_id' => 34,
					'qty'          => 2,
					'attributes'   => array( 'attribute_a' => 'x', 'attribute_pa_rodado' => 'r15' ),
				),
			),
			$result['items']
		);
		$this->assertStringNotContainsString( '999', (string) json_encode( $result['items'] ) );
		$this->assertSame( 40, strlen( $result['hash'] ) );
	}

	public function test_invalid_lines_are_skipped_and_empty_cart_has_no_hash(): void {
		$result = Snapshot::from_cart(
			array(
				'junk',
				array( 'product_id' => 0, 'quantity' => 1 ),
				array( 'product_id' => 5, 'quantity' => 0 ),
				array( 'product_id' => 5, 'quantity' => -3 ),
			)
		);
		$this->assertSame( array(), $result['items'] );
		$this->assertSame( '', $result['hash'] );
	}

	public function test_hash_is_stable_regardless_of_line_order_and_changes_with_quantity(): void {
		$a = array( 'product_id' => 1, 'quantity' => 1 );
		$b = array( 'product_id' => 2, 'quantity' => 3 );
		$this->assertSame( Snapshot::from_cart( array( $a, $b ) )['hash'], Snapshot::from_cart( array( $b, $a ) )['hash'] );
		$this->assertNotSame( Snapshot::from_cart( array( $a, $b ) )['hash'], Snapshot::from_cart( array( $a, array( 'product_id' => 2, 'quantity' => 4 ) ) )['hash'] );
	}

	public function test_caps_the_number_of_items(): void {
		$lines = array();
		for ( $i = 1; $i <= 80; $i++ ) {
			$lines[] = array( 'product_id' => $i, 'quantity' => 1 );
		}
		$this->assertCount( Snapshot::MAX_ITEMS, Snapshot::from_cart( $lines )['items'] );
	}

	public function test_decode(): void {
		$this->assertSame( array(), Snapshot::decode( 'not json' ) );
		$this->assertSame( array( array( 'product_id' => 1 ) ), Snapshot::decode( '[{"product_id":1},"x"]' ) );
	}
}
