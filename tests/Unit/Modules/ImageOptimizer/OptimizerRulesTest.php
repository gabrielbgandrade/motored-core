<?php
namespace Motored\Core\Tests\Unit\Modules\ImageOptimizer;

use Motored\Core\Modules\ImageOptimizer\OptimizerRules;
use Motored\Core\Tests\Unit\TestCase;

final class OptimizerRulesTest extends TestCase {

	public function test_optimizable_mime_accepts_only_jpeg_png_webp(): void {
		$this->assertTrue( OptimizerRules::is_optimizable_mime( 'image/jpeg' ) );
		$this->assertTrue( OptimizerRules::is_optimizable_mime( ' image/WEBP ' ) );
		$this->assertTrue( OptimizerRules::is_optimizable_mime( 'image/png' ) );
		$this->assertFalse( OptimizerRules::is_optimizable_mime( 'image/svg+xml' ) );
		$this->assertFalse( OptimizerRules::is_optimizable_mime( 'application/pdf' ) );
	}

	public function test_output_formats_honors_flags_and_server_webp_support(): void {
		$base = array( 'image/gif' => 'image/gif' );
		$this->assertSame( $base, OptimizerRules::output_formats( $base, true, true, false ) );
		$this->assertSame(
			array(
				'image/gif'  => 'image/gif',
				'image/jpeg' => 'image/webp',
			),
			OptimizerRules::output_formats( $base, true, false, true )
		);
		$this->assertSame(
			array(
				'image/gif'  => 'image/gif',
				'image/jpeg' => 'image/webp',
				'image/png'  => 'image/webp',
			),
			OptimizerRules::output_formats( $base, true, true, true )
		);
	}

	public function test_filter_sizes_drops_large_core_sizes_only_when_enabled(): void {
		$sizes = array(
			'thumbnail' => array(),
			'medium'    => array(),
			'1536x1536' => array(),
			'2048x2048' => array(),
		);
		$this->assertSame( $sizes, OptimizerRules::filter_sizes( $sizes, false ) );
		$this->assertSame(
			array(
				'thumbnail' => array(),
				'medium'    => array(),
			),
			OptimizerRules::filter_sizes( $sizes, true )
		);
	}

	public function test_should_keep_reencoded_and_format_bytes(): void {
		$this->assertTrue( OptimizerRules::should_keep_reencoded( 1000, 800, false ) );
		$this->assertFalse( OptimizerRules::should_keep_reencoded( 1000, 1000, false ) );
		$this->assertTrue( OptimizerRules::should_keep_reencoded( 1000, 1000, true ) );
		$this->assertFalse( OptimizerRules::should_keep_reencoded( 1000, 0, true ) );

		$this->assertSame( '512 B', OptimizerRules::format_bytes( 512 ) );
		$this->assertSame( '2.5 KB', OptimizerRules::format_bytes( 2560 ) );
		$this->assertSame( '1.50 MB', OptimizerRules::format_bytes( 1572864 ) );
	}

	public function test_sanitize_job_state_normalizes_invalid_values(): void {
		$state = OptimizerRules::sanitize_job_state(
			array(
				'status' => 'weird',
				'total'  => -5,
				'cursor' => '42',
			)
		);
		$this->assertSame( 'idle', $state['status'] );
		$this->assertSame( 0, $state['total'] );
		$this->assertSame( 42, $state['cursor'] );
		$this->assertTrue( $state['only_unoptimized'] );
	}
}
