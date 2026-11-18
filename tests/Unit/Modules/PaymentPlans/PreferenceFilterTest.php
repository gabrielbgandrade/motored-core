<?php
namespace Motored\Core\Tests\Unit\Modules\PaymentPlans;

use Brain\Monkey\Functions;
use Motored\Core\Integration\MercadoPago\CheckoutPro;
use Motored\Core\Integration\MercadoPago\PreferenceFilter;
use Motored\Core\Modules\PaymentPlans\Config;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class PreferenceFilterTest extends TestCase {

	private const URL = 'https://api.mercadopago.com/v1/asgard/preferences';

	/** @var array<string,string> */
	private array $meta = array();

	/** @var list<string> */
	private array $notes = array();

	private float $total = 12600.5;

	private bool $order_exists = true;

	protected function setUp(): void {
		parent::setUp();
		$this->meta  = array();
		$this->notes = array();
		$this->fake_storage();
		$this->fake_logger();
		Options::register_defaults( 'payment_plans', Config::defaults() );
		$this->options['motored_payment_plans'] = array( 'enabled' => 'yes' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $this->order_exists && 77 === (int) $id ? $this->order() : false );
	}

	private function order(): \WC_Order {
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( $key ) => $this->meta[ $key ] ?? '' );
		$order->shouldReceive( 'get_total' )->andReturn( $this->total );
		$order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( $note ) {
				$this->notes[] = $note;
				return 1;
			}
		);
		return $order;
	}

	private function plan( string $id, int $max, string $type = 'card' ): void {
		$this->meta = array(
			'_motored_plan_id'               => $id,
			'_motored_plan_max_installments' => (string) $max,
			'_motored_plan_type'             => $type,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function args( ?string $body = null ): array {
		return array(
			'method' => 'POST',
			'body'   => $body ?? '{"external_reference":"WC-77","items":[{"unit_price":12100.5,"quantity":1},{"unit_price":500,"quantity":1}],"payment_methods":{"installments":24,"excluded_payment_methods":[{"id":"amex"}]}}',
		);
	}

	public function test_other_requests_are_untouched(): void {
		$this->plan( 'c3', 3 );
		$get = array( 'method' => 'GET' ) + $this->args();
		$this->assertSame( $get, PreferenceFilter::filter_args( $get, self::URL ) );
		$this->assertSame( $this->args(), PreferenceFilter::filter_args( $this->args(), 'https://api.mercadopago.com/v1/payments' ) );
		$this->assertSame( $this->args(), PreferenceFilter::filter_args( $this->args(), 'https://evil.example/v1/asgard/preferences' ) );
		$this->assertSame( $this->args(), PreferenceFilter::filter_args( $this->args(), self::URL . '/123' ) );
	}

	public function test_order_without_plan_is_left_intact(): void {
		$args = $this->args();
		$this->assertSame( $args, PreferenceFilter::filter_args( $args, self::URL ) );
	}

	public function test_plan_caps_installments_and_keeps_other_exclusions(): void {
		$this->plan( 'c3', 3 );
		$out  = PreferenceFilter::filter_args( $this->args(), self::URL );
		$body = json_decode( $out['body'] );
		$this->assertArrayNotHasKey( PreferenceFilter::FLAG, $out );
		$this->assertSame( 3, $body->payment_methods->installments );
		$this->assertSame( 'amex', $body->payment_methods->excluded_payment_methods[0]->id );
		$this->assertSame( 'WC-77', $body->external_reference );
	}

	public function test_cash_plan_excludes_cards(): void {
		$this->plan( 'cash', 1, 'cash' );
		$body = json_decode( PreferenceFilter::filter_args( $this->args(), self::URL )['body'] );
		$this->assertSame( 1, $body->payment_methods->installments );
		$this->assertSame( array( 'credit_card', 'debit_card' ), array_map( static fn( $t ) => $t->id, $body->payment_methods->excluded_payment_types ) );
	}

	public function test_credit_exclusion_follows_the_order_setting(): void {
		$this->plan( 'c3', 3 );
		$on = json_decode( PreferenceFilter::filter_args( $this->args(), self::URL )['body'] );
		$this->assertContains( 'consumer_credits', array_map( static fn( $m ) => $m->id, $on->payment_methods->excluded_payment_methods ) );

		$this->meta['_motored_plan_excl_credit'] = '0';
		$off = json_decode( PreferenceFilter::filter_args( $this->args(), self::URL )['body'] );
		$this->assertSame( array( 'amex' ), array_map( static fn( $m ) => $m->id, $off->payment_methods->excluded_payment_methods ) );
	}

	public function test_plan_type_falls_back_to_the_configured_plan(): void {
		$this->plan( 'cash', 1, '' );
		$body = json_decode( PreferenceFilter::filter_args( $this->args(), self::URL )['body'] );
		$this->assertCount( 2, $body->payment_methods->excluded_payment_types );
	}

	/**
	 * @return array<string,array{string,string,bool}>
	 */
	public static function failures(): array {
		return array(
			'unreadable body'    => array( 'not json', 'order', true ),
			'no external ref'    => array( '{"items":[]}', 'order', true ),
			'order not found'    => array( '{"external_reference":"WC-999","items":[]}', 'order', true ),
		);
	}

	public function test_unidentifiable_orders_are_blocked_only_when_plans_are_enabled(): void {
		foreach ( self::failures() as $label => $case ) {
			$out = PreferenceFilter::filter_args( $this->args( $case[0] ), self::URL );
			$this->assertSame( $case[1], $out[ PreferenceFilter::FLAG ] ?? null, $label );
		}
		$this->options['motored_payment_plans'] = array( 'enabled' => 'no' );
		Options::reset();
		Options::register_defaults( 'payment_plans', Config::defaults() );
		$args = $this->args( 'not json' );
		$this->assertSame( $args, PreferenceFilter::filter_args( $args, self::URL ), 'module off: nothing to protect' );
	}

	public function test_a_saved_plan_is_enforced_even_if_the_module_was_disabled_later(): void {
		$this->plan( 'c3', 3 );
		$this->options['motored_payment_plans'] = array( 'enabled' => 'no' );
		$body = json_decode( PreferenceFilter::filter_args( $this->args(), self::URL )['body'] );
		$this->assertSame( 3, $body->payment_methods->installments );
	}

	public function test_fails_closed_when_the_body_cannot_be_changed(): void {
		$this->plan( 'c3', 3 );
		$bad_methods = '{"external_reference":"WC-77","items":[{"unit_price":12600.5,"quantity":1}],"payment_methods":"x"}';
		$out         = PreferenceFilter::filter_args( $this->args( $bad_methods ), self::URL );
		$this->assertSame( 'format', $out[ PreferenceFilter::FLAG ] );
		$this->assertStringContainsString( 'blocked', $this->notes[0] );
	}

	public function test_fails_closed_on_total_mismatch(): void {
		$this->plan( 'c3', 3 );
		$this->total = 9999.0;
		$out         = PreferenceFilter::filter_args( $this->args(), self::URL );
		$this->assertSame( 'total', $out[ PreferenceFilter::FLAG ] );
		$this->assertCount( 1, $this->notes );
	}

	public function test_fails_closed_on_invalid_plan_meta(): void {
		$this->plan( 'c3', 0 );
		$this->assertSame( 'plan', PreferenceFilter::filter_args( $this->args(), self::URL )[ PreferenceFilter::FLAG ] );
		$this->plan( 'gone', 3, '' );
		$this->assertSame( 'plan', PreferenceFilter::filter_args( $this->args(), self::URL )[ PreferenceFilter::FLAG ] );
	}

	public function test_block_returns_an_error_only_for_flagged_requests(): void {
		$flagged = array( PreferenceFilter::FLAG => 'total' );
		$error   = PreferenceFilter::block( false, $flagged, self::URL );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'motored_plan_blocked', $error->get_error_code() );
		$this->assertFalse( PreferenceFilter::block( false, array(), self::URL ) );
		$response = array( 'response' => 'x' );
		$this->assertSame( $response, PreferenceFilter::block( $response, $flagged, self::URL ), 'a previous short-circuit is respected' );
	}

	public function test_hooks_are_registered_late_and_first(): void {
		PreferenceFilter::register();
		$this->assertSame( 9999, has_filter( 'http_request_args', array( PreferenceFilter::class, 'filter_args' ) ) );
		$this->assertSame( 1, has_filter( 'pre_http_request', array( PreferenceFilter::class, 'block' ) ) );
	}

	public function test_order_id_from_reference(): void {
		$this->assertSame( 77, CheckoutPro::order_id_from_reference( 'WC-77' ) );
		$this->options['_mp_store_identificator'] = 'SHOP2-';
		$this->assertSame( 1234, CheckoutPro::order_id_from_reference( 'SHOP2-1234' ) );
		$this->assertSame( 0, CheckoutPro::order_id_from_reference( 'SHOP2-' ) );
		$this->assertSame( 0, CheckoutPro::order_id_from_reference( 'abc' ) );
	}
}
