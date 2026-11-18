<?php
/**
 * Module contract.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Contracts;

use Motored\Core\Support\Context;

defined( 'ABSPATH' ) || exit;

/**
 * A functional unit (e.g. Waha, Notifications, Recovery).
 *
 * Plugging a new module in: create src/Modules/<Name>/<Name>Module.php that
 * implements this interface, then add its class name to Plugin::MODULES.
 * Modules are instantiated with no constructor arguments.
 */
interface Module {

	/**
	 * Unique, lowercase slug (a-z, 0-9, underscore). Also the Options key.
	 */
	public function id(): string;

	/**
	 * Decides whether the module is needed for this request.
	 *
	 * MUST be cheap: no database, HTTP, filesystem or option reads, and no
	 * translation calls. Only inspect the Context.
	 *
	 * @param Context $context Request context.
	 */
	public function should_load( Context $context ): bool;

	/**
	 * Registers hooks. Only called when should_load() returned true.
	 */
	public function register(): void;
}
