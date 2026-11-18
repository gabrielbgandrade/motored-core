<?php
/**
 * One payment plan.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable plan: percentage adjustment over the list price (negative = discount, positive = surcharge).
 */
final class Plan {

	public const TYPE_CASH = 'cash';
	public const TYPE_CARD = 'card';

	public const MIN_PCT          = -90.0;
	public const MAX_PCT          = 100.0;
	public const MAX_INSTALLMENTS = 24;

	/**
	 * Constructor.
	 *
	 * @param string $id               Slug [a-z0-9_]{1,20}.
	 * @param string $name             Customer-facing name.
	 * @param float  $pct              Adjustment in percent.
	 * @param int    $max_installments Maximum installments (1 for cash).
	 * @param string $type             cash | card.
	 * @param bool   $active           Whether customers can use it.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly float $pct,
		public readonly int $max_installments,
		public readonly string $type,
		public readonly bool $active
	) {
	}

	/**
	 * Builds a sanitized plan from stored/posted data; null when the id or the name is unusable.
	 *
	 * @param mixed $raw Array with id, name, pct, max, type, active.
	 */
	public static function from_array( $raw ): ?self {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$id   = isset( $raw['id'] ) && is_scalar( $raw['id'] ) ? strtolower( (string) $raw['id'] ) : '';
		$name = isset( $raw['name'] ) && is_scalar( $raw['name'] ) ? trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $raw['name'] ) ) ?? '' ) : '';
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,20}$/', $id ) || '' === $name ) {
			return null;
		}
		$type = isset( $raw['type'] ) && self::TYPE_CASH === $raw['type'] ? self::TYPE_CASH : self::TYPE_CARD;
		$pct  = isset( $raw['pct'] ) && is_numeric( str_replace( ',', '.', (string) $raw['pct'] ) ) ? (float) str_replace( ',', '.', (string) $raw['pct'] ) : 0.0;
		$max  = isset( $raw['max'] ) && is_numeric( $raw['max'] ) ? (int) $raw['max'] : 1;
		$max  = self::TYPE_CASH === $type ? 1 : max( 1, min( self::MAX_INSTALLMENTS, $max ) );

		$active = isset( $raw['active'] ) && ( true === $raw['active'] || 'yes' === $raw['active'] || '1' === $raw['active'] || 1 === $raw['active'] );

		return new self(
			$id,
			mb_substr( $name, 0, 100 ),
			round( max( self::MIN_PCT, min( self::MAX_PCT, $pct ) ), 2 ),
			$max,
			$type,
			$active
		);
	}

	/**
	 * Storage shape.
	 *
	 * @return array{id:string,name:string,pct:float,max:int,type:string,active:string}
	 */
	public function to_array(): array {
		return array(
			'id'     => $this->id,
			'name'   => $this->name,
			'pct'    => $this->pct,
			'max'    => $this->max_installments,
			'type'   => $this->type,
			'active' => $this->active ? 'yes' : 'no',
		);
	}
}
