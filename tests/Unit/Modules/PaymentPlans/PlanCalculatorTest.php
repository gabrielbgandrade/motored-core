<?php
namespace Motored\Core\Tests\Unit\Modules\PaymentPlans;

use Motored\Core\Modules\PaymentPlans\Plan;
use Motored\Core\Modules\PaymentPlans\PlanCalculator;
use Motored\Core\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PlanCalculatorTest extends TestCase {

	private function plan( float $pct, int $max ): Plan {
		return new Plan( 'p', 'Plan', $pct, $max, Plan::TYPE_CARD, true );
	}

	public function test_to_cents_rounds_half_up(): void {
		$this->assertSame( 12345, PlanCalculator::to_cents( 123.45 ) );
		$this->assertSame( 29, PlanCalculator::to_cents( 0.29 ) );
		$this->assertSame( 1000, PlanCalculator::to_cents( '10' ) );
		$this->assertSame( 124, PlanCalculator::to_cents( 1.235 ) );
		$this->assertSame( 0, PlanCalculator::to_cents( 'abc' ) );
	}

	public function test_total_discounts_and_surcharges(): void {
		$this->assertSame( 90000, PlanCalculator::total( 100000, -10.0 ) );
		$this->assertSame( 95000, PlanCalculator::total( 100000, -5.0 ) );
		$this->assertSame( 100000, PlanCalculator::total( 100000, 0.0 ) );
		$this->assertSame( 110000, PlanCalculator::total( 100000, 10.0 ) );
		$this->assertSame( 120000, PlanCalculator::total( 100000, 20.0 ) );
	}

	public function test_total_rounds_to_cents_half_up(): void {
		// 1001 * 0.95 = 950.95 -> 951 ; 5 cents * 10% = 0.5 -> half up = 6 total.
		$this->assertSame( 951, PlanCalculator::total( 1001, -5.0 ) );
		$this->assertSame( 6, PlanCalculator::total( 5, 10.0 ) );
		// 1005 * 1.1 = 1105.5 -> 1106.
		$this->assertSame( 1106, PlanCalculator::total( 1005, 10.0 ) );
		$this->assertSame( 0, PlanCalculator::total( -50, 10.0 ) );
	}

	public function test_split_puts_the_leftover_in_the_last_installment(): void {
		$this->assertSame( array( 333, 333, 334 ), PlanCalculator::split( 1000, 3 ) );
		$this->assertSame( array( 1000 ), PlanCalculator::split( 1000, 1 ) );
		$this->assertSame( array( 0, 0, 2 ), PlanCalculator::split( 2, 3 ) );
	}

	#[DataProvider( 'totals' )]
	public function test_installments_always_sum_to_the_total( int $base, float $pct, int $n ): void {
		$quote = PlanCalculator::quote( $this->plan( $pct, $n ), $base );
		$this->assertSame( $quote->total, array_sum( $quote->schedule() ) );
		$this->assertCount( $n, $quote->schedule() );
		$this->assertSame( $quote->total - $quote->base, $quote->fee() );
	}

	/**
	 * @return array<string,array{int,float,int}>
	 */
	public static function totals(): array {
		return array(
			'odd cents'     => array( 1234567, 20.0, 12 ),
			'tiny'          => array( 7, 10.0, 6 ),
			'discount'      => array( 987654, -5.0, 3 ),
			'half cent'     => array( 1005, 10.0, 9 ),
			'one payment'   => array( 99999, -10.0, 1 ),
			'fractional pct' => array( 123457, 7.35, 7 ),
		);
	}

	public function test_quote_values(): void {
		$quote = PlanCalculator::quote( $this->plan( 10.0, 9 ), 100000 );
		$this->assertSame( 110000, $quote->total );
		$this->assertSame( 10000, $quote->fee() );
		$this->assertSame( 12222, $quote->installment );
		$this->assertSame( 12222 + 2, $quote->last );
	}

	public function test_base_with_taxes_is_computed_by_the_caller_in_cents(): void {
		// 1000.00 net + 210.00 tax = 1210.00 base; plan -10% -> fee -121.00.
		$base  = PlanCalculator::to_cents( 1000.0 + 210.0 );
		$quote = PlanCalculator::quote( $this->plan( -10.0, 1 ), $base );
		$this->assertSame( 121000, $quote->base );
		$this->assertSame( 108900, $quote->total );
		$this->assertSame( -12100, $quote->fee() );
	}
}
