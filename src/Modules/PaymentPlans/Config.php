<?php
/**
 * PaymentPlans configuration.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads option `motored_payment_plans` (autoload=false): `enabled` and `plans`.
 */
final class Config {

	public const OPTION_KEY = 'payment_plans';

	/**
	 * Option defaults (the module is OFF by default).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled' => 'no',
			'plans'   => Plans::defaults(),
		);
	}

	/**
	 * Module master switch.
	 */
	public static function enabled(): bool {
		return Options::is_enabled( self::OPTION_KEY, 'enabled' );
	}

	/**
	 * Configured plans.
	 */
	public static function plans(): Plans {
		return new Plans( Options::get( self::OPTION_KEY, 'plans', Plans::defaults() ) );
	}
}
