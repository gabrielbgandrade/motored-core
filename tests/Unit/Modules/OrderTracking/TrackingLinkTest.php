<?php
namespace Motored\Core\Tests\Unit\Modules\OrderTracking;

use Brain\Monkey\Functions;
use Motored\Core\Modules\OrderTracking\Config;
use Motored\Core\Modules\OrderTracking\Tracking;
use Motored\Core\Modules\OrderTracking\TrackingLink;
use Motored\Core\Tests\Unit\TestCase;

final class TrackingLinkTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_url_raw' )->alias( static fn( $u ) => $u );
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	public function test_code_keeps_only_safe_characters_and_40_chars(): void {
		$this->assertSame( 'AB-12_3.4b', TrackingLink::sanitize_code( ' AB-12_3.4 <b>' ) );
		$this->assertSame( 40, strlen( TrackingLink::sanitize_code( str_repeat( 'a1', 50 ) ) ) );
		$this->assertSame( '', TrackingLink::sanitize_code( '¡!? ' ) );
	}

	public function test_manual_url_accepts_only_https_and_300_chars(): void {
		$this->assertSame( 'https://x.com/t?c=1', TrackingLink::sanitize_url( ' https://x.com/t?c=1 ' ) );
		foreach ( array( 'http://x.com/t', 'javascript:alert(1)', 'ftp://x.com', '//x.com', 'https://', 'https://x.com/a b', 'https://x.com/"><script>', 'https://x.com/' . str_repeat( 'a', 300 ) ) as $bad ) {
			$this->assertSame( '', TrackingLink::sanitize_url( $bad ), $bad );
		}
	}

	public function test_template_needs_code_and_https_scheme(): void {
		$this->assertSame( 'https://c.com/?n={code}', TrackingLink::sanitize_template( ' https://c.com/?n={code} ' ) );
		$this->assertSame( TrackingLink::EXAMPLE_TEMPLATE, TrackingLink::sanitize_template( TrackingLink::EXAMPLE_TEMPLATE ) );
		foreach ( array( 'http://c.com/?n={code}', 'https://c.com/', 'javascript:{code}', '' ) as $bad ) {
			$this->assertSame( '', TrackingLink::sanitize_template( $bad ), $bad );
		}
	}

	public function test_static_preview_uses_the_sample_code(): void {
		$this->assertSame( 'https://rapiboy.com/seguimientoPedido?id=AB123456789', TrackingLink::preview( TrackingLink::EXAMPLE_TEMPLATE ) );
		$this->assertSame( '', TrackingLink::preview( 'https://c.com/no-code' ) );
	}

	public function test_priority_override_then_template_then_empty(): void {
		$tpl = 'https://c.com/t/{code}';
		$this->assertSame( 'https://m.com/x', TrackingLink::build( 'https://m.com/x', $tpl, 'AB1' ) );
		$this->assertSame( 'https://c.com/t/AB1', TrackingLink::build( '', $tpl, 'AB1' ) );
		$this->assertSame( 'https://c.com/t/AB1', TrackingLink::build( 'http://insecure.com', $tpl, 'AB1' ), 'http override is ignored' );
		$this->assertSame( '', TrackingLink::build( '', $tpl, '' ) );
		$this->assertSame( '', TrackingLink::build( '', '', 'AB1' ) );
	}

	public function test_tracking_entry_is_sanitized_and_hashed(): void {
		$t = Tracking::from_array( array( 'carrier_id' => 'Andreani', 'code' => 'a b!1', 'url_override' => 'http://x.com', 'updated_gmt' => '55' ) );
		$this->assertSame( 'andreani', $t->carrier_id );
		$this->assertSame( 'ab1', $t->code );
		$this->assertSame( '', $t->url_override );
		$this->assertSame( md5( 'ab1' ), $t->hash() );
		$this->assertTrue( Tracking::from_array( 'junk' )->is_empty() );
		$this->assertSame( '', Tracking::from_array( array() )->hash() );
	}

	public function test_carrier_list_sanitizing(): void {
		$rows = Config::sanitize_carriers(
			array(
				array( 'id' => '', 'name' => 'Correo Argentino', 'url' => 'https://c.com/{code}', 'active' => 'yes' ),
				array( 'id' => 'correo-argentino', 'name' => 'Duplicate', 'url' => '', 'active' => 'yes' ),
				array( 'id' => 'other', 'name' => 'Reserved', 'url' => '', 'active' => 'yes' ),
				array( 'id' => 'x', 'name' => '', 'url' => '', 'active' => 'yes' ),
				array( 'id' => 'bad', 'name' => 'Bad', 'url' => 'javascript:{code}' ),
				'junk',
			)
		);
		$this->assertSame( array( 'correo-argentino', 'bad' ), array_column( $rows, 'id' ) );
		$this->assertSame( 'https://c.com/{code}', $rows[0]['url'] );
		$this->assertSame( '', $rows[1]['url'] );
		$this->assertSame( array(), Config::DEFAULTS['carriers'], 'ships empty: no invented URLs' );
		$many = Config::sanitize_carriers( array_map( static fn( $n ) => array( 'name' => 'n' . $n ), range( 1, 30 ) ) );
		$this->assertCount( Config::MAX_CARRIERS, $many );
	}
}
