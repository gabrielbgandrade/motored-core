<?php
namespace Motored\Core\Tests\Unit\Modules\ImageOptimizer;

use Brain\Monkey\Functions;
use Motored\Core\Modules\ImageOptimizer\BulkRunner;
use Motored\Core\Modules\ImageOptimizer\Config;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

final class BulkRunnerTest extends TestCase {

	private string $tmp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		BulkRunner::$test_scheduler = null;
		$this->fake_storage();
		$this->fake_logger();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_normalize_path' )->alias( static fn( $p ) => str_replace( '\\', '/', (string) $p ) );
		Functions\when( 'wp_basename' )->alias( static fn( $p ) => basename( (string) $p ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/\\' ) . '/' );

		$this->tmp_dir = sys_get_temp_dir() . '/motored_bulk_test_' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp_dir, 0777, true );
	}

	protected function tearDown(): void {
		BulkRunner::$test_scheduler = null;
		unset( $GLOBALS['wpdb'] );
		if ( '' !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			foreach ( (array) glob( $this->tmp_dir . '/*' ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) ) {
					unlink( $file );
				}
			}
			rmdir( $this->tmp_dir );
		}
		parent::tearDown();
	}

	public function test_start_queues_action_scheduler_when_available(): void {
		$wpdb           = \Mockery::mock( 'wpdb_fake' );
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '15' );
		$GLOBALS['wpdb'] = $wpdb;

		$scheduled                  = array();
		BulkRunner::$test_scheduler = function ( int $ts, string $hook, array $args ) use ( &$scheduled ): bool {
			$scheduled[] = array( $ts, $hook, $args );
			return true;
		};
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );

		$res = BulkRunner::start( true, true );
		$this->assertTrue( $res['ok'] );
		$this->assertFalse( $this->autoload[ BulkRunner::JOB_OPTION ] );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( BulkRunner::HOOK, $scheduled[0][1] );
		$this->assertSame( 'running', BulkRunner::status()['status'] );
		$this->assertTrue( BulkRunner::status()['dry_run'] );
	}

	public function test_dry_run_batch_completes_without_modifying_files(): void {
		$img = $this->tmp_dir . '/tire.jpg';
		file_put_contents( $img, str_repeat( 'A', 600 ) );

		$wpdb           = \Mockery::mock( 'wpdb_fake' );
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '1' );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array( '101' ) );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
		Functions\when( 'get_attached_file' )->justReturn( $img );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( array( 'sizes' => array() ) );
		Functions\expect( 'wp_update_attachment_metadata' )->never();

		$res   = BulkRunner::start( true, true );
		$state = BulkRunner::status();
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'completed', $state['status'] );
		$this->assertSame( 1, $state['processed'] );
		$this->assertSame( 1, $state['optimized'] );
		$this->assertSame( 600, $state['bytes_before'] );
		$this->assertSame( 600, $state['bytes_after'] );
	}

	public function test_live_batch_compresses_in_place_and_records_meta_and_savings(): void {
		$main = $this->tmp_dir . '/tire-main.jpg';
		$sub  = $this->tmp_dir . '/tire-main-300x300.jpg';
		file_put_contents( $main, str_repeat( 'M', 1000 ) );
		file_put_contents( $sub, str_repeat( 'S', 400 ) );

		$wpdb           = \Mockery::mock( 'wpdb_fake' );
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '1' );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array( '202' ) );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ): bool => $v instanceof \WP_Error );
		Functions\when( 'wp_unique_filename' )->alias( static fn( $dir, $name ): string => (string) $name );
		Functions\when( 'wp_check_filetype' )->justReturn( array( 'ext' => 'jpg', 'type' => 'image/jpeg' ) );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $path ): void {
				if ( is_string( $path ) && file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
		Functions\when( 'get_attached_file' )->justReturn( $main );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'width'  => 1200,
				'height' => 900,
				'sizes'  => array(
					'medium' => array( 'file' => 'tire-main-300x300.jpg', 'mime-type' => 'image/jpeg' ),
				),
			)
		);

		$editor = \Mockery::mock( 'WP_Image_Editor' );
		$editor->shouldReceive( 'maybe_exif_rotate' )->andReturn( false );
		$editor->shouldReceive( 'get_size' )->andReturn( array( 'width' => 1200, 'height' => 900 ) );
		$editor->shouldReceive( 'set_quality' )->andReturn( true );
		$editor->shouldReceive( 'save' )->andReturnUsing(
			static function ( $dest ) {
				$bytes = str_contains( $dest, 'motored-opt-' ) ? 300 : 300;
				file_put_contents( $dest, str_repeat( 'O', $bytes ) );
				return array( 'path' => $dest, 'mime-type' => 'image/jpeg' );
			}
		);
		Functions\when( 'wp_get_image_editor' )->justReturn( $editor );

		$updated_meta = null;
		Functions\expect( 'wp_update_attachment_metadata' )->once()->andReturnUsing(
			static function ( $id, $meta ) use ( &$updated_meta ) {
				$updated_meta = $meta;
				return true;
			}
		);
		Functions\expect( 'update_post_meta' )->once()->with( 202, '_motored_img_opt', \Mockery::type( 'array' ) );

		$res   = BulkRunner::start( false, true );
		$state = BulkRunner::status();
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'completed', $state['status'] );
		$this->assertSame( 1, $state['optimized'] );
		$this->assertSame( 1400, $state['bytes_before'] );
		$this->assertSame( 600, $state['bytes_after'] );
		$this->assertSame( 800, $this->options[ BulkRunner::SAVED_OPTION ] );
		$this->assertSame( 300, $updated_meta['filesize'] );
		$this->assertSame( 300, $updated_meta['sizes']['medium']['filesize'] );
	}

	public function test_cancel_stops_running_job_and_unschedules(): void {
		$this->options[ BulkRunner::JOB_OPTION ] = array(
			'status'     => 'running',
			'run_id'     => 'abc123',
			'updated_ts' => time(),
		);
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( BulkRunner::HOOK, array(), 'motored-core' );

		$res = BulkRunner::cancel();
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'cancelled', BulkRunner::status()['status'] );
	}

	public function test_batch_never_resurrects_a_job_cancelled_while_it_was_running(): void {
		$this->options[ BulkRunner::JOB_OPTION ] = array(
			'status'     => 'running',
			'run_id'     => 'run1',
			'updated_ts' => time(),
		);
		$wpdb           = \Mockery::mock( 'wpdb_fake' );
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->options  = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		// A full batch (10 ids = batch size) would normally schedule the next one...
		$wpdb->shouldReceive( 'get_col' )->andReturn( array_map( 'strval', range( 1, 10 ) ) );
		// ...but the DB (fresh read, bypassing caches) says another request cancelled it meanwhile.
		$wpdb->shouldReceive( 'get_var' )->andReturn(
			serialize(
				array(
					'status' => 'cancelled',
					'run_id' => 'run1',
				)
			)
		);
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'maybe_unserialize' )->alias( static fn( $v ) => unserialize( (string) $v ) );
		Functions\when( 'get_post_type' )->justReturn( 'post' ); // Each id is skipped quickly.

		$scheduled                  = 0;
		BulkRunner::$test_scheduler = static function () use ( &$scheduled ): bool {
			++$scheduled;
			return true;
		};

		( new BulkRunner() )->run_batch( 'run1' );
		$this->assertSame( 0, $scheduled, 'No next batch after a cancel.' );
		$this->assertSame( 0, BulkRunner::status()['processed'], 'Stale in-memory state was not written back.' );
	}
}
