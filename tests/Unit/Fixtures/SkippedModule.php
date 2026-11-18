<?php
namespace Motored\Core\Tests\Unit\Fixtures;

use Motored\Core\Contracts\Module;
use Motored\Core\Support\Context;

/** Module whose should_load() is false. */
final class SkippedModule implements Module {

	public function id(): string {
		return 'skipped';
	}

	public function should_load( Context $context ): bool {
		return false;
	}

	public function register(): void {
		LoadingModule::$log[] = 'registered:skipped';
	}
}
