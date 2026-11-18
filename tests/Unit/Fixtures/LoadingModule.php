<?php
namespace Motored\Core\Tests\Unit\Fixtures;

use Motored\Core\Contracts\Module;
use Motored\Core\Support\Context;

/** Module whose should_load() is true. */
final class LoadingModule implements Module {

	/** @var list<string> */
	public static array $log = array();

	public function id(): string {
		return 'loading';
	}

	public function should_load( Context $context ): bool {
		return true;
	}

	public function register(): void {
		self::$log[] = 'registered:loading';
	}
}
