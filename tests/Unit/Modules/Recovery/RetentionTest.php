<?php
namespace Motored\Core\Tests\Unit\Modules\Recovery;

use Motored\Core\Modules\Recovery\Retention;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase {

	private const NOW = 1700000000;

	public function test_terminal_rows_use_seven_days_and_others_the_configured_retention(): void {
		$cutoffs = Retention::cutoffs( self::NOW, 30 );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 7 * 86400 ), $cutoffs['terminal'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 30 * 86400 ), $cutoffs['other'] );
	}

	public function test_retention_is_at_least_one_day(): void {
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 86400 ), Retention::cutoffs( self::NOW, 0 )['other'] );
	}

	public function test_batch_size_is_500(): void {
		$this->assertSame( 500, Retention::BATCH );
	}
}
