<?php
namespace Motored\Core\Tests\Unit\Modules\PaymentPlans;

use Motored\Core\Modules\PaymentPlans\PreferenceRewriter;
use Motored\Core\Tests\Unit\TestCase;

final class PreferenceRewriterTest extends TestCase {

	/**
	 * A body shaped like the one the MP plugin 8.10.2 sends (after LegacyPayloadSerializer).
	 */
	private function body( array $methods = array( 'installments' => 24 ), string $price = '12100.5' ): string {
		return '{"external_reference":"WC-77","binary_mode":false,"payer":{},"shipments":{},"metadata":{"a":1},'
			. '"items":[{"id":1,"title":"Tire x 4","unit_price":' . $price . ',"quantity":1},{"id":"shipping","unit_price":500,"quantity":1}],'
			. '"payment_methods":' . wp_json_encode_fake( $methods ) . '}';
	}

	private function apply( string $json, int $max, string $type, int $total = 1260050 ): array {
		$rewriter = new PreferenceRewriter();
		$out      = $rewriter->apply( json_decode( $json ), $max, $type, $total );
		return array( $out, $rewriter->error() );
	}

	public function test_caps_installments_at_the_plan_maximum(): void {
		[ $out, $error ] = $this->apply( $this->body(), 3, 'card' );
		$this->assertNull( $error );
		$this->assertSame( 3, $out->payment_methods->installments );
		$this->assertFalse( isset( $out->payment_methods->excluded_payment_types ), 'card plans do not exclude types' );
	}

	public function test_never_raises_a_lower_limit_of_the_plugin(): void {
		[ $out ] = $this->apply( $this->body( array( 'installments' => 2 ) ), 9, 'card' );
		$this->assertSame( 2, $out->payment_methods->installments );
	}

	public function test_missing_installments_or_methods_get_the_plan_maximum(): void {
		[ $out ] = $this->apply( $this->body( array() ), 6, 'card' );
		$this->assertSame( 6, $out->payment_methods->installments );
		$no_methods = str_replace( ',"payment_methods":[]', '', $this->body( array() ) );
		[ $out2 ] = $this->apply( $no_methods, 6, 'card' );
		$this->assertSame( 6, $out2->payment_methods->installments );
	}

	public function test_default_installments_above_the_maximum_are_clamped(): void {
		[ $out ] = $this->apply( $this->body( array( 'installments' => 24, 'default_installments' => 12 ) ), 3, 'card' );
		$this->assertSame( 3, $out->payment_methods->default_installments );
	}

	public function test_consumer_credits_is_excluded_by_every_plan_merging_without_duplicates(): void {
		[ $out ] = $this->apply( $this->body( array( 'installments' => 24, 'excluded_payment_methods' => array( array( 'id' => 'amex' ) ) ) ), 9, 'card' );
		$this->assertSame( array( 'amex', 'consumer_credits' ), array_map( static fn( $m ) => $m->id, $out->payment_methods->excluded_payment_methods ) );

		[ $again ] = $this->apply( $this->body( array( 'excluded_payment_methods' => array( array( 'id' => 'consumer_credits' ) ) ) ), 3, 'card' );
		$this->assertCount( 1, $again->payment_methods->excluded_payment_methods );

		[ $cash ] = $this->apply( $this->body(), 1, 'cash' );
		$this->assertSame( '[{"id":"consumer_credits"}]', json_encode( $cash->payment_methods->excluded_payment_methods ) );
	}

	public function test_consumer_credits_is_not_added_when_the_plan_setting_is_off(): void {
		$rewriter = new PreferenceRewriter();
		$out      = $rewriter->apply( json_decode( $this->body( array( 'excluded_payment_methods' => array( array( 'id' => 'amex' ) ) ) ) ), 3, 'card', 1260050, false );
		$this->assertSame( array( 'amex' ), array_map( static fn( $m ) => $m->id, $out->payment_methods->excluded_payment_methods ) );
		$cash = $rewriter->apply( json_decode( $this->body() ), 1, 'cash', 1260050, false );
		$this->assertSame( '[]', json_encode( $cash->payment_methods->excluded_payment_methods ) );
		$this->assertCount( 2, $cash->payment_methods->excluded_payment_types, 'cash still excludes cards' );
	}

	public function test_malformed_excluded_methods_fail_closed(): void {
		$json = '{"items":[{"unit_price":10,"quantity":1}],"payment_methods":{"excluded_payment_methods":"amex"}}';
		[ $out, $error ] = $this->apply( $json, 3, 'card', 1000 );
		$this->assertNull( $out );
		$this->assertSame( PreferenceRewriter::ERR_FORMAT, $error );
	}

	public function test_cash_plan_merges_card_exclusions_with_the_existing_ones(): void {
		$methods = array(
			'installments'             => 24,
			'excluded_payment_methods' => array( array( 'id' => 'amex' ) ),
			'excluded_payment_types'   => array( array( 'id' => 'ticket' ), array( 'id' => 'credit_card' ) ),
		);
		[ $out ] = $this->apply( $this->body( $methods ), 1, 'cash' );
		$ids     = array_map( static fn( $t ) => $t->id, $out->payment_methods->excluded_payment_types );
		$this->assertSame( array( 'ticket', 'credit_card', 'debit_card' ), $ids, 'merged, no duplicate' );
		$this->assertSame( 'amex', $out->payment_methods->excluded_payment_methods[0]->id );
		$this->assertSame( 1, $out->payment_methods->installments );
		$this->assertNotContains( 'account_money', $ids );
	}

	public function test_cash_plan_without_previous_exclusions(): void {
		[ $out ] = $this->apply( $this->body(), 1, 'cash' );
		$this->assertSame( '[{"id":"credit_card"},{"id":"debit_card"}]', json_encode( $out->payment_methods->excluded_payment_types ) );
	}

	public function test_other_keys_and_empty_objects_survive_the_round_trip(): void {
		[ $out ] = $this->apply( $this->body(), 3, 'card' );
		$json    = json_encode( $out );
		$this->assertStringContainsString( '"payer":{}', $json );
		$this->assertStringContainsString( '"shipments":{}', $json );
		$this->assertStringContainsString( '"metadata":{"a":1}', $json );
		$this->assertSame( 'WC-77', $out->external_reference );
		$this->assertFalse( $out->binary_mode );
	}

	public function test_total_mismatch_is_rejected_with_one_cent_tolerance(): void {
		[ $ok ]            = $this->apply( $this->body(), 3, 'card', 1260051 );
		$this->assertNotNull( $ok, '1 cent of difference is tolerated' );
		[ $out, $error ]   = $this->apply( $this->body(), 3, 'card', 1260052 );
		$this->assertNull( $out );
		$this->assertSame( PreferenceRewriter::ERR_TOTAL, $error );
	}

	public function test_quantity_is_part_of_the_sum(): void {
		$json = '{"items":[{"unit_price":100.25,"quantity":3}]}';
		[ $out ] = $this->apply( $json, 3, 'card', 30075 );
		$this->assertNotNull( $out );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function bad_bodies(): array {
		return array(
			'no items'      => array( '{"payment_methods":{"installments":24}}' ),
			'empty items'   => array( '{"items":[],"payment_methods":{}}' ),
			'bad price'     => array( '{"items":[{"unit_price":"abc","quantity":1}]}' ),
			'zero quantity' => array( '{"items":[{"unit_price":10,"quantity":0}]}' ),
			'bad methods'   => array( '{"items":[{"unit_price":1000,"quantity":1}],"payment_methods":"x"}' ),
		);
	}

	public function test_unreadable_bodies_fail(): void {
		foreach ( self::bad_bodies() as $label => $case ) {
			[ $out, $error ] = $this->apply( $case[0], 3, 'card', 1000 );
			$this->assertNull( $out, $label );
			$this->assertNotNull( $error, $label );
		}
		$rewriter = new PreferenceRewriter();
		$this->assertNull( $rewriter->apply( null, 3, 'card', 1000 ) );
		$this->assertSame( PreferenceRewriter::ERR_BODY, $rewriter->error() );
		$this->assertNull( $rewriter->apply( json_decode( '{"items":[{"unit_price":10,"quantity":1}]}' ), 0, 'card', 1000 ) );
	}

	public function test_malformed_excluded_types_fail_for_cash(): void {
		$json = '{"items":[{"unit_price":10,"quantity":1}],"payment_methods":{"excluded_payment_types":"credit_card"}}';
		[ $out, $error ] = $this->apply( $json, 1, 'cash', 1000 );
		$this->assertNull( $out );
		$this->assertSame( PreferenceRewriter::ERR_FORMAT, $error );
	}
}

/**
 * JSON for the fixture: an empty PHP array means an empty JSON array here (the plugin sends objects with keys).
 *
 * @param array<string,mixed> $value Value.
 */
function wp_json_encode_fake( array $value ): string {
	return array() === $value ? '[]' : (string) json_encode( $value );
}
