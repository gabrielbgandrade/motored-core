<?php
namespace Motored\Core\Tests\Unit\Modules\Waha;

require_once dirname( __DIR__, 2 ) . '/Fixtures/WcSettingsDoubles.php';

use Brain\Monkey\Functions;
use Motored\Core\Modules\Waha\Config;
use Motored\Core\Modules\Waha\WahaAdmin;
use Motored\Core\Support\Logger;
use Motored\Core\Support\Secrets;
use Motored\Core\Tests\Unit\TestCase;

final class WahaAdminSecretTest extends TestCase {

	private const KEY = 'panel-key-123';

	/** @var list<string> */
	private array $caps = array();

	/** @var list<array{0:string,1:string,2:array<string,mixed>}> */
	private array $logged = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! Secrets::available() ) {
			$this->markTestSkipped( 'libsodium is not available.' );
		}
		$this->fake_storage();
		Secrets::reset();
		\WC_Admin_Settings::$errors = array();
		$_POST                      = array();
		$this->logged               = array();
		$this->options['motored_general'] = array( 'log_level' => 'debug' );

		$logger = \Mockery::mock( 'WC_Logger_Fake' );
		$logger->shouldReceive( 'log' )->andReturnUsing(
			function ( $level, $message, $context ) {
				$this->logged[] = array( $level, $message, $context );
			}
		);
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		foreach ( array( '__', 'esc_html__', 'esc_html', 'esc_attr', 'esc_url', 'sanitize_text_field', 'wp_unslash' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'admin_url' )->alias( fn( $path = '' ) => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'wp_nonce_field' )->alias(
			function ( $action, $name ) {
				echo '<input type="hidden" name="' . $name . '" value="nonce-ok" />';
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias( fn( $nonce ) => 'nonce-ok' === $nonce ? 1 : false );
		Functions\when( 'check_admin_referer' )->alias(
			function () {
				if ( 'nonce-ok' !== ( $_POST['motored_waha_secret_nonce'] ?? '' ) ) {
					throw new \RuntimeException( 'bad nonce' );
				}
				return 1;
			}
		);
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'redirect' );
			}
		);
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
	}

	protected function tearDown(): void {
		$_POST = array();
		Logger::reset_secrets();
		Secrets::reset();
		parent::tearDown();
	}

	private function as_admin(): WahaAdmin {
		$this->caps = array( 'manage_woocommerce', 'manage_options' );
		return new WahaAdmin();
	}

	private function as_manager(): WahaAdmin {
		$this->caps = array( 'manage_woocommerce' );
		return new WahaAdmin();
	}

	private function html( WahaAdmin $admin ): string {
		ob_start();
		$admin->render_secret();
		return (string) ob_get_clean();
	}

	/**
	 * @param WahaAdmin $admin Admin.
	 * @return list<string>
	 */
	private function ids( WahaAdmin $admin ): array {
		return array_map( fn( $f ) => (string) ( $f['id'] ?? $f['type'] ), $admin->settings() );
	}

	public function test_shop_managers_see_neither_the_url_nor_the_key_field(): void {
		$ids = $this->ids( $this->as_manager() );
		$this->assertNotContains( 'motored_waha[url]', $ids );
		$this->assertNotContains( 'motored_waha_secret', $ids );
		$this->assertContains( 'motored_waha[session]', $ids );
		$this->assertSame( '', $this->html( $this->as_manager() ), 'render checks the capability too' );
	}

	public function test_administrators_get_the_url_and_a_write_only_key_row(): void {
		$ids = $this->ids( $this->as_admin() );
		$this->assertContains( 'motored_waha[url]', $ids );
		$this->assertContains( 'motored_waha_secret', $ids );
		$this->assertNotContains( 'motored_waha[api_key]', $ids, 'the key is not a Settings API field' );
	}

	public function test_saved_key_is_never_rendered_neither_plain_nor_encrypted(): void {
		Secrets::store( Config::SECRET_OPTION, self::KEY );
		$html = $this->html( $this->as_admin() );

		$this->assertStringContainsString( 'Key saved', $html );
		$this->assertStringContainsString( 'Replace', $html );
		$this->assertStringContainsString( 'Delete key', $html );
		$this->assertStringContainsString( 'autocomplete="new-password"', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringNotContainsString( 'panel-key', $html );
		$this->assertStringNotContainsString( (string) $this->options[ Config::SECRET_OPTION ], $html );
		$this->assertStringNotContainsString( 'v1:', $html );
		$this->assertStringNotContainsString( 'value=""', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]*type="password"[^>]*\svalue=/', $html );
		$this->assertStringNotContainsString( 'data-', $html );
		$this->assertStringContainsString( 'motored_waha_secret_nonce', $html );
	}

	public function test_a_failed_validation_never_repopulates_what_was_typed(): void {
		$_POST = array(
			'motored_waha_secret_nonce' => 'nonce-ok',
			'motored_waha_new_key'      => 'bad key with spaces',
		);
		$admin = $this->as_admin();
		$admin->save_secret();
		$this->assertCount( 1, \WC_Admin_Settings::$errors );
		$this->assertStringNotContainsString( 'bad key', \WC_Admin_Settings::$errors[0] );
		$this->assertStringNotContainsString( 'bad key', $this->html( $admin ) );
		$this->assertSame( 'none', Secrets::state( Config::SECRET_OPTION ) );
	}

	public function test_unreadable_key_shows_the_enter_again_warning(): void {
		Secrets::store( Config::SECRET_OPTION, self::KEY );
		Functions\when( 'wp_salt' )->justReturn( 'salt-two' );
		Secrets::reset();
		$html = $this->html( $this->as_admin() );
		$this->assertStringContainsString( 'could not be read. Enter it again.', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringNotContainsString( 'Key saved', $html );
	}

	public function test_constant_source_is_read_only_without_inputs(): void {
		// Source "constant" is covered end to end in ApiKeyTest (separate process); here the label only.
		$this->assertSame( 'Configured (constant)', WahaAdmin::key_label( Config::SOURCE_CONSTANT ) );
		$this->assertSame( 'Configured (panel)', WahaAdmin::key_label( Config::SOURCE_PANEL ) );
		$this->assertSame( 'Unreadable', WahaAdmin::key_label( Config::SOURCE_UNREADABLE ) );
		$this->assertSame( 'Not configured', WahaAdmin::key_label( Config::SOURCE_NONE ) );
	}

	public function test_saving_a_new_key_stores_it_encrypted_and_audits_without_the_value(): void {
		$_POST = array(
			'motored_waha_secret_nonce' => 'nonce-ok',
			'motored_waha_new_key'      => self::KEY,
		);
		$this->as_admin()->save_secret();

		$this->assertSame( array(), \WC_Admin_Settings::$errors );
		$this->assertSame( self::KEY, Secrets::read( Config::SECRET_OPTION ) );
		$this->assertStringStartsWith( 'v1:', (string) $this->options[ Config::SECRET_OPTION ] );
		$this->assertFalse( $this->autoload[ Config::SECRET_OPTION ] );
		$this->assertArrayNotHasKey( 'api_key', (array) ( $this->options['motored_waha'] ?? array() ) );
		$this->assertSame( 'info', $this->logged[0][0] );
		$this->assertSame( 5, $this->logged[0][2]['user'] );
		$this->assertStringNotContainsString( self::KEY, (string) json_encode( $this->logged ) );
	}

	public function test_an_empty_field_keeps_the_current_key(): void {
		Secrets::store( Config::SECRET_OPTION, self::KEY );
		$_POST = array(
			'motored_waha_secret_nonce' => 'nonce-ok',
			'motored_waha_new_key'      => '   ',
		);
		$this->as_admin()->save_secret();
		$this->assertSame( self::KEY, Secrets::read( Config::SECRET_OPTION ) );
		$this->assertSame( array(), $this->logged );
	}

	public function test_replacing_changes_the_key_and_audits_a_replacement(): void {
		Secrets::store( Config::SECRET_OPTION, self::KEY );
		$_POST = array(
			'motored_waha_secret_nonce' => 'nonce-ok',
			'motored_waha_new_key'      => 'second-key-456',
		);
		$this->as_admin()->save_secret();
		Secrets::reset();
		$this->assertSame( 'second-key-456', Secrets::read( Config::SECRET_OPTION ) );
		$this->assertSame( 'WAHA API key replaced.', $this->logged[0][1] );
		$this->assertStringNotContainsString( 'second-key-456', (string) json_encode( $this->logged ) );
	}

	public function test_a_bad_nonce_or_a_shop_manager_cannot_save_a_key(): void {
		$_POST = array(
			'motored_waha_secret_nonce' => 'forged',
			'motored_waha_new_key'      => self::KEY,
		);
		$this->as_admin()->save_secret();
		$this->assertSame( 'none', Secrets::state( Config::SECRET_OPTION ) );
		$this->assertCount( 1, \WC_Admin_Settings::$errors );

		\WC_Admin_Settings::$errors = array();
		$_POST['motored_waha_secret_nonce'] = 'nonce-ok';
		$this->as_manager()->save_secret();
		$this->assertSame( 'none', Secrets::state( Config::SECRET_OPTION ) );
		$this->assertCount( 1, \WC_Admin_Settings::$errors );
	}

	public function test_saving_other_sections_without_the_row_does_nothing(): void {
		$_POST = array( 'motored_waha_new_key' => self::KEY );
		$this->as_admin()->save_secret();
		$this->assertSame( 'none', Secrets::state( Config::SECRET_OPTION ) );
		$this->assertSame( array(), \WC_Admin_Settings::$errors );
	}

	public function test_delete_needs_the_confirmation_the_nonce_and_the_capability(): void {
		Secrets::store( Config::SECRET_OPTION, self::KEY );

		// No confirmation: kept.
		$_POST = array( 'motored_waha_secret_nonce' => 'nonce-ok' );
		try {
			$this->as_admin()->handle_delete_key();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}
		$this->assertSame( 'ok', Secrets::state( Config::SECRET_OPTION ) );

		// Shop manager: blocked before anything else.
		$_POST['motored_waha_confirm_delete'] = '1';
		try {
			$this->as_manager()->handle_delete_key();
			$this->fail( 'must die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$this->assertSame( 'ok', Secrets::state( Config::SECRET_OPTION ) );

		// Forged nonce.
		$_POST['motored_waha_secret_nonce'] = 'forged';
		try {
			$this->as_admin()->handle_delete_key();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'bad nonce', $e->getMessage() );
		}
		$this->assertSame( 'ok', Secrets::state( Config::SECRET_OPTION ) );

		// Administrator, nonce and confirmation: deleted and audited.
		$_POST['motored_waha_secret_nonce'] = 'nonce-ok';
		try {
			$this->as_admin()->handle_delete_key();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}
		$this->assertSame( 'none', Secrets::state( Config::SECRET_OPTION ) );
		$this->assertSame( 'WAHA API key deleted.', $this->logged[0][1] );
		$this->assertSame( 5, $this->logged[0][2]['user'] );
	}

	public function test_status_section_shows_only_the_state_of_the_key(): void {
		$admin = $this->as_admin();
		Functions\when( 'wp_date' )->justReturn( '00:00' );
		Functions\when( 'wp_timezone' )->alias( fn() => new \DateTimeZone( 'UTC' ) );

		$rows = $admin->status_rows( array() );
		$this->assertSame( 'Not configured', $rows['API key'] );

		Secrets::store( Config::SECRET_OPTION, self::KEY );
		$rows = $admin->status_rows( array() );
		$this->assertSame( 'Configured (panel)', $rows['API key'] );
		$this->assertStringNotContainsString( self::KEY, (string) json_encode( $rows ) );
		$this->assertStringNotContainsString( 'v1:', (string) json_encode( $rows ) );
	}

	public function test_uninstall_removes_the_secret_option(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/uninstall.php' );
		$this->assertStringContainsString( "delete_option( 'motored_waha_secret' )", $source );
	}
}
