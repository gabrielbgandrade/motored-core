<?php
namespace Motored\Core\Tests\Unit\Modules\Waha;

use Brain\Monkey\Functions;
use Motored\Core\Integration\Waha\WahaClient;
use Motored\Core\Modules\Waha\Config;
use Motored\Core\Modules\Waha\KeyGuard;
use Motored\Core\Support\Logger;
use Motored\Core\Support\Secrets;
use Motored\Core\Support\Options;
use Motored\Core\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

require_once dirname( __DIR__, 2 ) . '/Fixtures/WcSettingsDoubles.php';

final class ApiKeyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! Secrets::available() ) {
			$this->markTestSkipped( 'libsodium is not available.' );
		}
		$this->fake_storage();
		$this->fake_logger();
		Secrets::reset();
		\WC_Admin_Settings::$errors = array();
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		// Default = no logged-in user (CLI/cron path). Explicit, because another test may have defined the function.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => $r['response']['code'] ?? 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] ?? '' );
	}

	protected function tearDown(): void {
		Logger::reset_secrets();
		Secrets::reset();
		parent::tearDown();
	}

	public function test_panel_key_is_read_decrypted_and_registered_for_log_redaction(): void {
		Secrets::store( Config::SECRET_OPTION, 'panel-key-123' );
		$this->assertSame( 'panel-key-123', Config::api_key() );
		$this->assertSame( Config::SOURCE_PANEL, Config::api_key_source() );
		$this->assertStringNotContainsString( 'panel-key-123', Logger::redact_string( 'oops panel-key-123' ) );
	}

	public function test_no_key_and_unreadable_key_both_count_as_not_configured(): void {
		$this->assertSame( '', Config::api_key() );
		$this->assertSame( Config::SOURCE_NONE, Config::api_key_source() );

		Secrets::store( Config::SECRET_OPTION, 'panel-key-123' );
		Functions\when( 'wp_salt' )->justReturn( 'salt-two' );
		Secrets::reset();
		$this->assertSame( '', Config::api_key(), 'never throws' );
		$this->assertSame( Config::SOURCE_UNREADABLE, Config::api_key_source() );
		$this->assertFalse( Config::is_configured() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_constant_wins_over_the_panel_key(): void {
		Secrets::store( Config::SECRET_OPTION, 'panel-key-123' );
		define( 'MOTORED_WAHA_API_KEY', 'constant-key-999' );
		$this->assertSame( 'constant-key-999', Config::api_key() );
		$this->assertSame( Config::SOURCE_CONSTANT, Config::api_key_source() );
		$this->assertTrue( Config::is_locked( 'api_key' ) );
	}

	public function test_url_with_embedded_credentials_is_rejected_by_the_client(): void {
		Secrets::store( Config::SECRET_OPTION, 'panel-key-123' );
		$this->options['motored_waha'] = array( 'url' => 'https://user:pass@waha.example.com' );
		Functions\expect( 'wp_remote_request' )->never();
		$result = ( new WahaClient() )->sessionStatus();
		$this->assertFalse( $result->ok );
		$this->assertSame( 'invalid_url', $result->code );
	}

	public function test_the_key_never_appears_in_results_or_log_context_of_failures(): void {
		Secrets::store( Config::SECRET_OPTION, 'panel-key-123' );
		$this->options['motored_waha'] = array( 'url' => 'https://waha.example.com' );
		Functions\when( 'wp_remote_request' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error: X-Api-Key: panel-key-123 timed out' ) );
		$result = ( new WahaClient() )->sessionStatus();
		$this->assertFalse( $result->ok );
		$this->assertStringNotContainsString( 'panel-key-123', json_encode( $result ) );
		$this->assertStringNotContainsString( 'panel-key-123', $result->message );

		Functions\when( 'wp_remote_request' )->justReturn( array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"bad X-Api-Key panel-key-123"}' ) );
		$auth = ( new WahaClient() )->sessionStatus();
		$this->assertSame( 'auth', $auth->code );
		$this->assertStringNotContainsString( 'panel-key-123', json_encode( $auth ) );
	}

	public function test_changing_the_host_deletes_the_stored_key_and_warns(): void {
		Secrets::store( Config::SECRET_OPTION, 'panel-key-123' );
		$old = array( 'url' => 'https://waha.example.com' );

		$kept = KeyGuard::filter( array( 'url' => 'https://WAHA.example.com/' ), $old );
		$this->assertSame( 'ok', Secrets::state( Config::SECRET_OPTION ), 'same host and port: key kept' );
		$this->assertSame( array(), \WC_Admin_Settings::$errors );
		$this->assertSame( 'https://WAHA.example.com/', $kept['url'] );

		KeyGuard::filter( array( 'url' => 'https://evil.example.net' ), $old );
		$this->assertSame( 'none', Secrets::state( Config::SECRET_OPTION ) );
		$this->assertCount( 1, \WC_Admin_Settings::$errors );
	}

	public function test_changing_only_the_port_also_deletes_the_key(): void {
		Secrets::store( Config::SECRET_OPTION, 'panel-key-123' );
		KeyGuard::filter( array( 'url' => 'https://waha.example.com:8443' ), array( 'url' => 'https://waha.example.com' ) );
		$this->assertSame( 'none', Secrets::state( Config::SECRET_OPTION ) );
	}

	public function test_urls_with_credentials_are_rejected_and_the_old_url_and_key_stay(): void {
		Secrets::store( Config::SECRET_OPTION, 'panel-key-123' );
		$saved = KeyGuard::filter( array( 'url' => 'https://u:p@evil.example.net' ), array( 'url' => 'https://waha.example.com' ) );
		$this->assertSame( 'https://waha.example.com', $saved['url'] );
		$this->assertSame( 'ok', Secrets::state( Config::SECRET_OPTION ) );
		$this->assertCount( 1, \WC_Admin_Settings::$errors );
	}

	public function test_a_logged_in_user_without_manage_options_cannot_change_the_url(): void {
		Secrets::store( Config::SECRET_OPTION, 'panel-key-123' );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'manage_woocommerce' === $cap );
		$saved = KeyGuard::filter( array( 'url' => 'https://evil.example.net' ), array( 'url' => 'https://waha.example.com' ) );
		$this->assertSame( 'https://waha.example.com', $saved['url'] );
		$this->assertSame( 'ok', Secrets::state( Config::SECRET_OPTION ) );
	}

	public function test_updating_the_option_without_url_change_keeps_everything(): void {
		Secrets::store( Config::SECRET_OPTION, 'panel-key-123' );
		$saved = KeyGuard::filter( array( 'url' => 'https://waha.example.com', 'enabled' => 'yes' ), array( 'url' => 'https://waha.example.com' ) );
		$this->assertSame( 'yes', $saved['enabled'] );
		$this->assertSame( 'ok', Secrets::state( Config::SECRET_OPTION ) );
	}

	public function test_legacy_plain_key_in_the_settings_array_still_works_until_migrated(): void {
		Options::reset();
		$this->options['motored_waha'] = array( 'api_key' => 'legacy-key-1' );
		$this->assertSame( 'legacy-key-1', Config::api_key() );
		$this->assertSame( Config::SOURCE_PANEL, Config::api_key_source() );
	}
	private function strip_comments( string $code ): string {
		$out = '';
		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}
		return $out;
	}

	public function test_only_the_client_and_config_touch_the_key_in_the_whole_plugin(): void {
		$allowed = array( 'Modules/Waha/Config.php', 'Integration/Waha/WahaClient.php' );
		$root    = dirname( __DIR__, 4 ) . '/src';
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$path     = str_replace( '\\', '/', (string) $file );
			$relative = substr( $path, strlen( str_replace( '\\', '/', $root ) ) + 1 );
			if ( 'php' !== $file->getExtension() || in_array( $relative, $allowed, true ) ) {
				continue;
			}
			$code = (string) file_get_contents( $path );
			$this->assertStringNotContainsString( 'Config::api_key()', $code, $path );
			$this->assertStringNotContainsString( "'X-Api-Key'", $this->strip_comments( $code ), $path );
		}
	}
}
