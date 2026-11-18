<?php
/**
 * Collection of configured plans.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitized, ordered plans keyed by id. Pure: built from the stored array.
 */
final class Plans {

	/**
	 * Id of the list-price plan (the ERP price is the 6 interest-free installments price).
	 */
	public const LIST_ID = 'c6';

	/**
	 * Factory defaults (names are English source strings, translated when displayed).
	 *
	 * @return list<array{id:string,name:string,pct:float,max:int,type:string,active:string}>
	 */
	public static function defaults(): array {
		return array(
			array(
				'id'     => 'cash',
				'name'   => 'Transferencia, dinero en cuenta o efectivo',
				'pct'    => -10.0,
				'max'    => 1,
				'type'   => Plan::TYPE_CASH,
				'active' => 'yes',
			),
			array(
				'id'     => 'c3',
				'name'   => '3 cuotas sin interés',
				'pct'    => -5.0,
				'max'    => 3,
				'type'   => Plan::TYPE_CARD,
				'active' => 'yes',
			),
			array(
				'id'     => 'c6',
				'name'   => '6 cuotas sin interés',
				'pct'    => 0.0,
				'max'    => 6,
				'type'   => Plan::TYPE_CARD,
				'active' => 'yes',
			),
			array(
				'id'     => 'c9',
				'name'   => '9 cuotas sin interés',
				'pct'    => 10.0,
				'max'    => 9,
				'type'   => Plan::TYPE_CARD,
				'active' => 'yes',
			),
			array(
				'id'     => 'c12',
				'name'   => '12 cuotas sin interés',
				'pct'    => 20.0,
				'max'    => 12,
				'type'   => Plan::TYPE_CARD,
				'active' => 'yes',
			),
		);
	}

	/**
	 * Plans keyed by id.
	 *
	 * @var array<string,Plan>
	 */
	private array $plans = array();

	/**
	 * Constructor.
	 *
	 * @param mixed $raw Stored list of plan arrays (invalid rows and duplicate ids are dropped).
	 */
	public function __construct( $raw ) {
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			$plan = Plan::from_array( $row );
			if ( null !== $plan && ! isset( $this->plans[ $plan->id ] ) ) {
				$this->plans[ $plan->id ] = $plan;
			}
		}
	}

	/**
	 * All plans.
	 *
	 * @return array<string,Plan>
	 */
	public function all(): array {
		return $this->plans;
	}

	/**
	 * Active plans.
	 *
	 * @return array<string,Plan>
	 */
	public function active(): array {
		return array_filter( $this->plans, static fn( Plan $plan ): bool => $plan->active );
	}

	/**
	 * An ACTIVE plan by id, or null (unknown or inactive).
	 *
	 * @param string $id Plan id.
	 */
	public function find_active( string $id ): ?Plan {
		$plan = $this->plans[ $id ] ?? null;
		return null !== $plan && $plan->active ? $plan : null;
	}

	/**
	 * The default / list plan: `c6` when active, else the first active card plan, else the first active plan.
	 */
	public function default_plan(): ?Plan {
		$list = $this->find_active( self::LIST_ID );
		if ( null !== $list ) {
			return $list;
		}
		$active = $this->active();
		foreach ( $active as $plan ) {
			if ( Plan::TYPE_CARD === $plan->type ) {
				return $plan;
			}
		}
		return array() === $active ? null : reset( $active );
	}

	/**
	 * Validates a requested plan id: an existing, active plan, otherwise the default.
	 *
	 * @param string $requested Id from the browser or the session (untrusted).
	 */
	public function resolve( string $requested ): ?Plan {
		return $this->find_active( $requested ) ?? $this->default_plan();
	}

	/**
	 * Highest installment count among active plans.
	 */
	public function max_active_installments(): int {
		$max = 0;
		foreach ( $this->active() as $plan ) {
			$max = max( $max, $plan->max_installments );
		}
		return $max;
	}
}
