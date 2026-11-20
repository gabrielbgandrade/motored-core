<?php
namespace Motored\Core\Tests\Unit\Modules\Compatibility;

use Brain\Monkey\Functions;
use Motored\Core\Modules\Compatibility\ImportRunner;
use Motored\Core\Modules\Compatibility\JsonExporter;
use Motored\Core\Modules\Compatibility\JsonImporter;
use Motored\Core\Modules\Compatibility\SizeParser;
use Motored\Core\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class JsonImporterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', trim( (string) $s ) ), '-' ) ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v, $flags = 0 ) => json_encode( $v, (int) $flags ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_rejects_empty_oversize_and_invalid_schema(): void {
		$this->assertFalse( JsonImporter::parse_payload( '' )['ok'] );
		$this->assertFalse( JsonImporter::parse_payload( str_repeat( ' ', JsonImporter::MAX_BYTES + 1 ) )['ok'] );
		$this->assertFalse( JsonImporter::parse_payload( '{"schema_version":2,"brands":[]}' )['ok'] );
		$this->assertFalse( JsonImporter::parse_payload( '{invalid' )['ok'] );
	}

	public function test_dry_run_counts_new_items_overlaps_suspicious_rims_and_ignores_constraints_by_default(): void {
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'esc_like' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$GLOBALS['wpdb'] = $wpdb;

		$payload = array(
			'schema_version' => 1,
			'brands'         => array(
				array(
					'name'   => 'Honda',
					'models' => array(
						array(
							'name'     => 'XR 250 Tornado',
							'aliases'  => 'Tornado',
							'versions' => array(
								array(
									'year_from' => 2001,
									'year_to'   => 2024,
									'fitments'  => array(
										array( 'position' => 'front', 'size' => '90/90-21', 'min_speed' => 'V' ),
										array( 'position' => 'rear', 'size' => '120/80-28' ), // Suspicious rim 28.
										array( 'position' => 'rear', 'size' => 'bad-size' ),  // Rejected.
									),
								),
								array(
									'year_from' => 2020,
									'year_to'   => 0,
									'fitments'  => array(),
								),
							),
						),
					),
				),
			),
			'equivalences'   => array(
				array(
					'source'         => '4.10-18',
					'target'         => '120/80-18',
					'create_inverse' => true,
				),
			),
		);

		$res = JsonImporter::dry_run( (string) json_encode( $payload ) );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 1, $res['brands_new'] );
		$this->assertSame( 1, $res['models_new'] );
		$this->assertSame( 2, $res['versions_new'] );
		$this->assertSame( 1, $res['overlaps'] );
		$this->assertSame( 1, $res['suspicious'] );
		$this->assertSame( 1, $res['rejected'] );
		$this->assertSame( 2, $res['equivalences_new'] );
		$this->assertSame( 4, $res['sizes_new'] );

		// Verify flatten_items ignores AI constraints unless explicitly enabled.
		$parsed = JsonImporter::parse_payload( (string) json_encode( $payload ) );
		$flat   = JsonImporter::flatten_items( $parsed );
		$this->assertCount( 2, $flat['versions'] );
	}

	public function test_exporter_produces_schema_version_one_roundtrip(): void {
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array( array( 'id' => 1, 'name' => 'Yamaha', 'slug' => 'yamaha' ) ),
			array( array( 'id' => 2, 'brand_id' => 1, 'name' => 'FZ16', 'slug' => 'fz16', 'cc' => 153, 'aliases' => 'FZ' ) ),
			array( array( 'id' => 3, 'model_id' => 2, 'slug' => 'yamaha-fz16-2010-actual', 'year_from' => 2010, 'year_to' => 0, 'variant' => '', 'status' => 'published', 'verified' => 1, 'notes' => '' ) ),
			array( array( 'version_id' => 3, 'position' => 'front', 'canonical' => '100/80-17', 'fit_type' => 'oem', 'min_load' => null, 'min_speed_rank' => null, 'req_construction' => null, 'source' => 'manufacturer', 'note' => '' ) ),
			array( array( 'source' => '4.10-18', 'target' => '120/80-18', 'position_scope' => 'any', 'is_approved' => 1, 'is_approximate' => 1, 'note' => '' ) )
		);
		$GLOBALS['wpdb'] = $wpdb;

		$json    = JsonExporter::to_json();
		$decoded = JsonImporter::parse_payload( $json );
		$this->assertTrue( $decoded['ok'] );
		$this->assertSame( 1, $decoded['versions_count'] );
		$this->assertSame( 'Yamaha', $decoded['brands'][0]['name'] );
		$this->assertSame( '4.10-18', $decoded['equivalences'][0]['source'] );
	}

	public function test_import_chunk_rejects_overlapping_years_at_write_time(): void {
		$this->fake_storage();
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'query' )->twice()->andReturn( 1 ); // Update model + NULL cc in upsert_model().
		// 1: find_brand_by_slug -> exists; 2: find_model -> exists; 3: find_version_by_natural_key -> null.
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 1, 'name' => 'Honda', 'slug' => 'honda' ),
			array( 'id' => 2, 'brand_id' => 1, 'name' => 'Tornado', 'slug' => 'tornado', 'cc' => null, 'aliases' => '' ),
			null
		);
		// has_year_overlap returns an existing overlapping version 2015-2022.
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array( array( 'id' => 10, 'year_from' => 2015, 'year_to' => 2022 ) )
		);
		$wpdb->shouldReceive( 'insert' )->never();
		$GLOBALS['wpdb'] = $wpdb;

		$chunk = array(
			array(
				'brand_name' => 'Honda',
				'brand_slug' => 'honda',
				'model_name' => 'Tornado',
				'model_slug' => 'tornado',
				'cc'         => null,
				'aliases'    => '',
				'version'    => array(
					'year_from' => 2020,
					'year_to'   => 2024,
					'fitments'  => array(
						array( 'position' => 'front', 'size' => '90/90-21' ),
					),
				),
			),
		);

		$stats = JsonImporter::import_chunk( $chunk );
		$this->assertSame( 0, $stats['versions'] );
		$this->assertSame( 1, $stats['rejected'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_import_runner_concurrency_lock_partial_status_and_as_failure_fallback(): void {
		$this->fake_storage();
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );

		// Concurrency guard blocks when a fresh active job exists with staged payload.
		$this->options[ ImportRunner::JOB_OPTION_PREF . 'job1' ] = array( 'versions' => array(), 'equivalences' => array(), 'stats' => array() );
		$this->options[ ImportRunner::LAST_STATUS_OPT ]           = array(
			'status'         => 'queued',
			'job_id'         => 'job1',
			'offset'         => 0,
			'total_versions' => 80,
			'updated_ts'     => time(),
		);

		$busy = ImportRunner::start( '{"schema_version":1,"brands":[]}' );
		$this->assertFalse( $busy['ok'] );
		$this->assertSame( 'busy', $busy['mode'] );

		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		$this->assertTrue( ImportRunner::cancel_active() );
		$this->assertArrayNotHasKey( ImportRunner::JOB_OPTION_PREF . 'job1', $this->options );
		$this->assertSame( 'cancelled', $this->options[ ImportRunner::LAST_STATUS_OPT ]['status'] );

		// When rejected items occur (invalid equivalence below), status must be 'partial' with rejected count in message.
		// Also verify Action Scheduler schedule failure (> 50 versions with as_schedule_single_action returning 0) falls back to sync.
		$versions = array();
		for ( $i = 0; $i <= ImportRunner::BATCH_SIZE; $i++ ) {
			$versions[] = array( 'year_from' => 1900 ); // Invalid year -> rejected without DB brand/model calls.
		}
		$payload = (string) json_encode(
			array(
				'schema_version' => 1,
				'brands'         => array(
					array(
						'name'   => 'Test',
						'models' => array(
							array(
								'name'     => 'M1',
								'versions' => $versions,
							),
						),
					),
				),
			)
		);
		Functions\when( 'as_schedule_single_action' )->justReturn( 0 );

		$res = ImportRunner::start( $payload );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'sync', $res['mode'] );
		$this->assertSame( 51, $res['stats']['rejected'] );
		$this->assertStringContainsString( 'Partial import completed', $res['message'] );
		$this->assertSame( 'partial', $this->options[ ImportRunner::LAST_STATUS_OPT ]['status'] );
	}

	public function test_rejects_cross_rim_equivalences_in_dry_run_and_import_chunk(): void {
		$this->fake_storage();
		$wpdb         = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array( 'id' => 10, 'canonical' => '3.50-10', 'notation' => 'imperial', 'width' => '3.50', 'aspect' => null, 'rim' => 10 ),
			array( 'id' => 11, 'canonical' => '110/70-12', 'notation' => 'metric', 'width' => '110', 'aspect' => 70, 'rim' => 12 )
		);
		$wpdb->shouldReceive( 'insert' )->never();
		$GLOBALS['wpdb'] = $wpdb;

		$payload = (string) json_encode(
			array(
				'schema_version' => 1,
				'brands'         => array(),
				'equivalences'   => array(
					array( 'source' => '3.50-10', 'target' => '110/70-12' ),
				),
			)
		);

		$dry = JsonImporter::dry_run( $payload );
		$this->assertTrue( $dry['ok'] );
		$this->assertSame( 1, $dry['rejected'] );
		$this->assertSame( 0, $dry['equivalences_new'] );
		$this->assertStringContainsString( 'rim mismatch', $dry['messages'][0] );

		$stats = JsonImporter::import_chunk(
			array(),
			array(
				array( 'source' => '3.50-10', 'target' => '110/70-12' ),
			)
		);
		$this->assertSame( 0, $stats['equivalences'] );
		$this->assertSame( 1, $stats['rejected'] );
	}

	public function test_force_draft_overrides_published_status_in_dry_run_and_import_chunk(): void {
		$this->fake_storage();
		$wpdb            = \Mockery::mock( 'wpdb_fake' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 42;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'query' )->twice()->andReturn( 1 ); // Update model + NULL cc.
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			null, // dry_run: find_brand_by_slug.
			null, // dry_run 2: find_brand_by_slug.
			array( 'id' => 1, 'name' => 'Honda', 'slug' => 'honda' ), // import_chunk: find_brand_by_slug.
			array( 'id' => 2, 'brand_id' => 1, 'name' => 'Tornado', 'slug' => 'tornado', 'cc' => null, 'aliases' => '' ), // find_model.
			null, // find_version_by_natural_key (overlap check).
			null  // find_version_by_natural_key (upsert_version).
		);
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() ); // has_year_overlap.
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' ); // unique_version_slug.
		$wpdb->shouldReceive( 'insert' )->once()->withArgs(
			static function ( $table, $data ): bool {
				unset( $table );
				return 'draft' === ( $data['status'] ?? '' );
			}
		)->andReturn( 1 );
		$GLOBALS['wpdb'] = $wpdb;

		$payload = (string) json_encode(
			array(
				'schema_version' => 1,
				'brands'         => array(
					array(
						'name'   => 'Honda',
						'models' => array(
							array(
								'name'     => 'Tornado',
								'versions' => array(
									array(
										'year_from' => 2015,
										'year_to'   => 0,
										'status'    => 'published',
										'verified'  => 1,
									),
								),
							),
						),
					),
				),
			)
		);

		$dry_default = JsonImporter::dry_run( $payload );
		$this->assertSame( 1, $dry_default['versions_published'] );
		$this->assertSame( 0, $dry_default['versions_draft'] );

		$dry_forced = JsonImporter::dry_run( $payload, array( 'force_draft' => true ) );
		$this->assertSame( 0, $dry_forced['versions_published'] );
		$this->assertSame( 1, $dry_forced['versions_draft'] );

		$stats = JsonImporter::import_chunk(
			array(
				array(
					'brand_name' => 'Honda',
					'brand_slug' => 'honda',
					'model_name' => 'Tornado',
					'model_slug' => 'tornado',
					'cc'         => null,
					'aliases'    => '',
					'version'    => array(
						'year_from' => 2015,
						'year_to'   => 0,
						'status'    => 'published',
						'verified'  => 1,
					),
				),
			),
			array(),
			array( 'force_draft' => true )
		);
		$this->assertSame( 1, $stats['versions'] );
		$this->assertSame( 0, $stats['rejected'] );
	}

	public function test_bundled_argentine_base_catalog_is_valid_schema_v1(): void {
		$path = dirname( __DIR__, 4 ) . '/src/Modules/Compatibility/data/compat-base-ar.json';
		$raw  = (string) file_get_contents( $path );
		$this->assertNotSame( '', $raw );

		$parsed = JsonImporter::parse_payload( $raw );
		$this->assertTrue( $parsed['ok'], $parsed['error'] );
		$this->assertGreaterThan( 100, $parsed['versions_count'] );

		foreach ( $parsed['brands'] as $brand ) {
			foreach ( $brand['models'] ?? array() as $model ) {
				foreach ( $model['versions'] ?? array() as $ver ) {
					foreach ( $ver['fitments'] ?? array() as $fit ) {
						$this->assertNotNull(
							SizeParser::parse( (string) ( $fit['size'] ?? '' ) ),
							sprintf( 'Invalid size in base catalog for %s %s', (string) $brand['name'], (string) $model['name'] )
						);
					}
				}
			}
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_import_runner_sync_option_bypasses_action_scheduler_queueing(): void {
		$this->fake_storage();
		Functions\expect( 'as_schedule_single_action' )->never();

		$versions = array();
		for ( $i = 0; $i <= ImportRunner::BATCH_SIZE; $i++ ) {
			$versions[] = array( 'year_from' => 1900 ); // Invalid year -> rejected synchronously without DB calls.
		}
		$payload = (string) json_encode(
			array(
				'schema_version' => 1,
				'brands'         => array(
					array(
						'name'   => 'Test',
						'models' => array(
							array(
								'name'     => 'M1',
								'versions' => $versions,
							),
						),
					),
				),
			)
		);

		$res = ImportRunner::start( $payload, array( 'sync' => true ) );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'sync', $res['mode'] );
		$this->assertSame( 51, $res['stats']['rejected'] );
		$this->assertSame( 'sync', $this->options[ ImportRunner::LAST_STATUS_OPT ]['mode'] );
	}

	public function test_fitments_keep_oem_designation_and_derive_construction(): void {
		$m   = new \ReflectionMethod( JsonImporter::class, 'normalize_fitments' );
		$out = $m->invoke(
			null,
			array(
				'fitments' => array(
					array( 'position' => 'front', 'size' => '120/70ZR17', 'fit_type' => 'oem' ),
					array( 'position' => 'rear', 'size' => '110/80-19', 'fit_type' => 'compatible' ),
				),
			),
			false
		);
		$this->assertSame( '120/70-17', $out[0]['canonical'] );
		$this->assertSame( '120/70ZR17', $out[0]['designation'] );
		$this->assertSame( 'radial', $out[0]['req_construction'] );
		$this->assertSame( 'oem', $out[0]['fit_type'] );
		$this->assertNull( $out[1]['designation'] );
		$this->assertNull( $out[1]['req_construction'] );
		$this->assertSame( 'alt', $out[1]['fit_type'] );
	}
}
