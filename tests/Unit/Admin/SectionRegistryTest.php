<?php
namespace Motored\Core\Tests\Unit\Admin;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Tests\Unit\TestCase;

final class SectionRegistryTest extends TestCase {

	public function test_sections_are_sorted_by_priority_then_registration(): void {
		SectionRegistry::add( 'status', fn() => 'Status', null, fn() => null, 1000 );
		SectionRegistry::add( 'b', fn() => 'B', fn() => array(), null, 10 );
		SectionRegistry::add( '', fn() => 'General', fn() => array(), null, 0 );
		SectionRegistry::add( 'a', fn() => 'A', fn() => array(), null, 10 );

		$this->assertSame( array( '', 'b', 'a', 'status' ), array_map( 'strval', array_keys( SectionRegistry::all() ) ) );
		$this->assertNull( SectionRegistry::get( 'missing' ) );
	}

	public function test_invalid_id_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		SectionRegistry::add( 'Bad Id', fn() => 'x' );
	}
}
