<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\GarageRepository;
use Motored\Core\Tests\Unit\TestCase;

final class GarageRepositoryTest extends TestCase {

	/** @var array<int,mixed> */
	private array $user_meta = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		$this->user_meta = array();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) $s ) ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_user_meta' )->alias( fn( $uid ) => $this->user_meta[ (int) $uid ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( $uid, $key, $val ) {
				unset( $key );
				$this->user_meta[ (int) $uid ] = $val;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( $uid ) {
				unset( $this->user_meta[ (int) $uid ] );
				return true;
			}
		);
	}

	public function test_merge_deduplicates_and_enforces_max_bikes(): void {
		$server = array(
			'active' => 'moto-1',
			'bikes'  => array(
				array( 'slug' => 'moto-1', 'label' => 'Moto 1', 'added_gmt' => '2026-01-01 00:00:00' ),
				array( 'slug' => 'moto-2', 'label' => 'Moto 2', 'added_gmt' => '2026-01-02 00:00:00' ),
			),
		);
		$guest  = array(
			array( 'slug' => 'moto-2', 'label' => 'Moto 2 Updated' ),
			array( 'slug' => 'moto-3', 'label' => 'Moto 3' ),
			array( 'slug' => 'moto-4', 'label' => 'Moto 4' ),
		);

		$merged = GarageRepository::merge( $server, $guest, 3, 'moto-4' );
		$this->assertSame( 'moto-4', $merged['active'] );
		$this->assertCount( 3, $merged['bikes'] );
		$slugs = array_column( $merged['bikes'], 'slug' );
		$this->assertSame( array( 'moto-2', 'moto-3', 'moto-4' ), $slugs );
	}

	public function test_add_set_active_and_remove_bike_update_user_meta(): void {
		GarageRepository::add_bike( 7, 'tornado', 'Honda XR 250 Tornado' );
		$g = GarageRepository::add_bike( 7, 'fz16', 'Yamaha FZ16' );
		$this->assertSame( 'fz16', $g['active'] );

		$g = GarageRepository::set_active( 7, 'tornado' );
		$this->assertSame( 'tornado', $g['active'] );

		$g = GarageRepository::remove_bike( 7, 'tornado' );
		$this->assertSame( 'fz16', $g['active'] );
		$this->assertCount( 1, $g['bikes'] );

		$g = GarageRepository::remove_bike( 7, 'fz16' );
		$this->assertSame( '', $g['active'] );
		$this->assertArrayNotHasKey( 7, $this->user_meta );
	}
}
