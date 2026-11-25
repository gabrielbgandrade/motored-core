<?php
namespace Motored\Core\Tests\Unit\Modules\MiGarage;

use Brain\Monkey\Functions;
use Motored\Core\Modules\MiGarage\GarageRepository;
use Motored\Core\Modules\MiGarage\Privacy;
use Motored\Core\Tests\Unit\TestCase;

final class PrivacyTest extends TestCase {

	public function test_exports_and_erases_garage_user_meta(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) $s ) ) );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$user = \Mockery::mock( 'WP_User' );
		$user->ID = 12;
		Functions\when( 'get_user_by' )->alias(
			static fn( $field, $val ) => 'moto@example.com' === $val ? $user : false
		);

		$stored = array(
			'active' => 'honda-tornado',
			'bikes'  => array(
				array(
					'slug'      => 'honda-tornado',
					'label'     => 'Honda XR 250 Tornado (2001-2024)',
					'added_gmt' => '2026-11-19 10:00:00',
				),
			),
		);
		Functions\when( 'get_user_meta' )->alias( static function () use ( &$stored ) { return $stored; } );
		Functions\expect( 'delete_user_meta' )->once()->with( 12, GarageRepository::META_KEY )->andReturnUsing(
			static function () use ( &$stored ): bool {
				$stored = '';
				return true;
			}
		);

		$privacy = new Privacy();
		$this->assertArrayHasKey( 'motored-compatibility', $privacy->add_exporter( array() ) );
		$this->assertArrayHasKey( 'motored-compatibility', $privacy->add_eraser( array() ) );

		$exp = $privacy->export( 'moto@example.com' );
		$this->assertTrue( $exp['done'] );
		$this->assertCount( 1, $exp['data'] );
		$this->assertSame( 'honda-tornado', $exp['data'][0]['data'][0]['value'] );

		$erased = $privacy->erase( 'moto@example.com' );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertTrue( $erased['done'] );

		$unknown = $privacy->erase( 'missing@example.com' );
		$this->assertFalse( $unknown['items_removed'] );
	}
}
