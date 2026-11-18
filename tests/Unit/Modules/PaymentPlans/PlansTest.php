<?php
namespace Motored\Core\Tests\Unit\Modules\PaymentPlans;

use Brain\Monkey\Functions;
use Motored\Core\Modules\PaymentPlans\Config;
use Motored\Core\Modules\PaymentPlans\PaymentPlansAdmin;
use Motored\Core\Modules\PaymentPlans\Plan;
use Motored\Core\Modules\PaymentPlans\Plans;
use Motored\Core\Tests\Unit\TestCase;

final class PlansTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( (string) $s ) );
	}

	private function plans( ?array $raw = null ): Plans {
		return new Plans( $raw ?? Plans::defaults() );
	}

	public function test_defaults(): void {
		$plans = $this->plans();
		$this->assertSame( array( 'cash', 'c3', 'c6', 'c9', 'c12' ), array_keys( $plans->all() ) );
		$this->assertSame( -10.0, $plans->all()['cash']->pct );
		$this->assertSame( 1, $plans->all()['cash']->max_installments );
		$this->assertSame( Plan::TYPE_CASH, $plans->all()['cash']->type );
		$this->assertSame( 20.0, $plans->all()['c12']->pct );
		$this->assertSame( 12, $plans->all()['c12']->max_installments );
		$this->assertSame( 'c6', $plans->default_plan()?->id );
		$this->assertSame( 12, $plans->max_active_installments() );
		$this->assertSame( 'no', Config::defaults()['enabled'] );
	}

	public function test_resolve_accepts_active_plans_only(): void {
		$raw         = Plans::defaults();
		$raw[1]['active'] = 'no'; // c3 inactive.
		$plans       = $this->plans( $raw );
		$this->assertSame( 'c9', $plans->resolve( 'c9' )?->id );
		$this->assertSame( 'c6', $plans->resolve( 'c3' )?->id, 'inactive falls back to default' );
		$this->assertSame( 'c6', $plans->resolve( 'nope' )?->id, 'unknown falls back to default' );
		$this->assertSame( 'c6', $plans->resolve( '' )?->id );
	}

	public function test_default_falls_back_when_list_plan_is_inactive(): void {
		$raw              = Plans::defaults();
		$raw[2]['active'] = 'no'; // c6.
		$this->assertSame( 'c3', $this->plans( $raw )->default_plan()?->id );
		$this->assertNull( ( new Plans( array() ) )->default_plan() );
		$this->assertNull( ( new Plans( array() ) )->resolve( 'c6' ) );
	}

	public function test_sanitizing_limits_and_types(): void {
		$plan = Plan::from_array( array( 'id' => 'X9', 'name' => ' <b>Big</b>  plan ', 'pct' => '500', 'max' => '99', 'type' => 'weird', 'active' => 'yes' ) );
		$this->assertNotNull( $plan );
		$this->assertSame( 'x9', $plan->id );
		$this->assertSame( 'Big plan', $plan->name );
		$this->assertSame( 100.0, $plan->pct );
		$this->assertSame( 24, $plan->max_installments );
		$this->assertSame( Plan::TYPE_CARD, $plan->type );

		$cash = Plan::from_array( array( 'id' => 'c', 'name' => 'Cash', 'pct' => '-5,5', 'max' => '6', 'type' => 'cash', 'active' => '1' ) );
		$this->assertSame( 1, $cash?->max_installments );
		$this->assertSame( -5.5, $cash?->pct );
		$this->assertNull( Plan::from_array( array( 'id' => 'bad id!', 'name' => 'x' ) ) );
		$this->assertNull( Plan::from_array( array( 'id' => 'ok', 'name' => '  ' ) ) );
		$this->assertNull( Plan::from_array( 'nope' ) );
	}

	public function test_admin_rows_drop_removed_invalid_and_duplicate_ids(): void {
		$rows = array(
			array( 'id' => 'c3', 'name' => 'Tres', 'pct' => '-5', 'max' => '3', 'type' => 'card', 'active' => '1' ),
			array( 'id' => 'c6', 'name' => 'Seis', 'pct' => '0', 'max' => '6', 'type' => 'card', 'remove' => '1' ),
			array( 'id' => 'c3', 'name' => 'Duplicado', 'pct' => '1', 'max' => '3', 'type' => 'card', 'active' => '1' ),
			array( 'id' => '', 'name' => '', 'pct' => '', 'max' => '', 'type' => 'card' ),
			array( 'id' => 'c24', 'name' => 'Veinticuatro', 'pct' => '30', 'max' => '24', 'type' => 'card' ),
		);
		$saved = PaymentPlansAdmin::sanitize_rows( $rows );
		$this->assertSame( array( 'c3', 'c24' ), array_column( $saved, 'id' ) );
		$this->assertSame( 'Tres', $saved[0]['name'] );
		$this->assertSame( 'yes', $saved[0]['active'] );
		$this->assertSame( 'no', $saved[1]['active'], 'unchecked checkbox = inactive' );
	}

	public function test_config_reads_stored_plans_and_switch(): void {
		$this->fake_storage();
		$this->options['motored_payment_plans'] = array( 'enabled' => 'yes', 'plans' => array( array( 'id' => 'solo', 'name' => 'Solo', 'pct' => 0, 'max' => 2, 'type' => 'card', 'active' => 'yes' ) ) );
		\Motored\Core\Support\Options::register_defaults( Config::OPTION_KEY, Config::defaults() );
		$this->assertTrue( Config::enabled() );
		$this->assertSame( array( 'solo' ), array_keys( Config::plans()->all() ) );
	}
}
