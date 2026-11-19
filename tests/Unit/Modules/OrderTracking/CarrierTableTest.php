<?php
namespace Motored\Core\Tests\Unit\Modules\OrderTracking;

use Brain\Monkey\Functions;
use Motored\Core\Modules\OrderTracking\Config;
use Motored\Core\Modules\OrderTracking\OrderTrackingAdmin;
use Motored\Core\Modules\OrderTracking\TrackingLink;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;

require_once dirname( __DIR__, 2 ) . '/Fixtures/WcSettingsDoubles.php';

final class CarrierTableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		Options::register_defaults( Config::OPTION_KEY, Config::DEFAULTS );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'checked' )->alias( static fn( $a, $b = true, $echo = true ) => $a === $b ? 'checked="checked"' : '' );
	}

	/**
	 * @return array{id:string,name:string,url:string,active:bool}
	 */
	private function stored( string $id, string $name, string $url ): array {
		return array(
			'id'     => $id,
			'name'   => $name,
			'url'    => $url,
			'active' => true,
		);
	}

	public function test_id_is_a_slug_of_the_name(): void {
		$this->assertSame( 'correo-argentino', Config::slugify( 'Correo Argentino' ) );
		$this->assertSame( 'oca-e-pak', Config::slugify( '  OCA e-Pak!! ' ) );
		$this->assertSame( 'envio-rapido-nino', Config::slugify( 'Envío Rápido Niño' ) );
		$this->assertSame( 'carrier', Config::slugify( '???' ) );
		$this->assertLessThanOrEqual( 30, strlen( Config::slugify( str_repeat( 'abc ', 30 ) ) ) );
	}

	public function test_new_rows_get_generated_unique_ids_and_the_posted_id_is_ignored(): void {
		$result = Config::prepare_carriers(
			array(
				array( 'id' => 'forged', 'name' => 'Andreani', 'url' => 'https://a.com/{code}', 'active' => 'yes' ),
				array( 'name' => 'Andreani', 'url' => 'https://b.com/{code}' ),
				array( 'name' => 'Other', 'url' => 'https://c.com/{code}' ),
				array( 'name' => '', 'url' => 'https://d.com/{code}' ),
			),
			array()
		);
		$this->assertSame( array( 'andreani', 'andreani-2', 'other-2' ), array_column( $result['rows'], 'id' ) );
		$this->assertSame( array(), $result['rejected'] );
	}

	public function test_saved_id_is_read_only_when_the_name_changes(): void {
		$stored = array( $this->stored( 'andreani', 'Andreani', 'https://a.com/{code}' ) );
		$result = Config::prepare_carriers( array( array( 'id' => 'andreani', 'name' => 'Andreani Express', 'url' => 'https://a.com/x/{code}', 'active' => 'yes' ) ), $stored );
		$this->assertSame( 'andreani', $result['rows'][0]['id'] );
		$this->assertSame( 'Andreani Express', $result['rows'][0]['name'] );
	}

	public function test_template_without_code_or_https_is_rejected_and_the_row_is_not_saved(): void {
		foreach ( array( 'https://x.com/track', 'http://x.com/?id={code}', 'javascript:{code}', '' ) as $bad ) {
			$result = Config::prepare_carriers( array( array( 'name' => 'Rapiboy', 'url' => $bad, 'active' => 'yes' ) ), array() );
			$this->assertSame( array(), $result['rows'], $bad );
			$this->assertSame( array( 'Rapiboy' ), $result['rejected'], $bad );
		}
	}

	public function test_invalid_edit_keeps_the_previous_stored_row(): void {
		$stored = array( $this->stored( 'rapiboy', 'Rapiboy', TrackingLink::EXAMPLE_TEMPLATE ) );
		$result = Config::prepare_carriers( array( array( 'id' => 'rapiboy', 'name' => 'Rapiboy', 'url' => 'https://rapiboy.com/', 'active' => 'yes' ) ), $stored );
		$this->assertSame( $stored, $result['rows'] );
		$this->assertSame( array( 'Rapiboy' ), $result['rejected'] );
	}

	public function test_save_stores_only_valid_rows_and_reports_the_error_with_the_example(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		\WC_Admin_Settings::$errors = array();
		$_POST = array(
			OrderTrackingAdmin::SETTINGS_FIELD => 'n',
			OrderTrackingAdmin::CARRIERS_KEY   => array(
				array( 'name' => 'Rapiboy', 'url' => TrackingLink::EXAMPLE_TEMPLATE, 'active' => 'yes' ),
				array( 'name' => 'Sin code', 'url' => 'https://x.com/track', 'active' => 'yes' ),
			),
		);
		( new OrderTrackingAdmin() )->save_carriers();
		$_POST = array();

		$this->assertSame( array( 'rapiboy' ), array_column( $this->options['motored_order_tracking']['carriers'], 'id' ) );
		$errors = \WC_Admin_Settings::$errors;
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Sin code', $errors[0] );
		$this->assertStringContainsString( 'https://rapiboy.com/seguimientoPedido?id={code}', $errors[0] );
	}

	public function test_table_has_no_editable_id_placeholder_example_preview_and_shared_classes(): void {
		$this->options['motored_order_tracking'] = array( 'carriers' => array( $this->stored( 'rapiboy', 'Rapiboy', TrackingLink::EXAMPLE_TEMPLATE ) ) );
		ob_start();
		( new OrderTrackingAdmin() )->render_carriers_field( array( 'title' => 'Carriers' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'motored-table', $html );
		$this->assertStringContainsString( 'motored-table-wrap', $html );
		$this->assertStringContainsString( '<code>rapiboy</code>', $html );
		$this->assertStringNotContainsString( 'type="text" name="motored_tracking_carriers[0][id]"', $html, 'the id is not an editable input' );
		$this->assertStringContainsString( 'placeholder="https://rapiboy.com/seguimientoPedido?id={code}"', $html );
		$this->assertStringContainsString( 'https://rapiboy.com/seguimientoPedido?id=AB123456789', $html, 'static preview with the sample code' );
		$this->assertStringContainsString( 'Example: https://rapiboy.com/seguimientoPedido?id={code}', $html );
		$this->assertStringNotContainsString( 'style=', $html );
	}
}
