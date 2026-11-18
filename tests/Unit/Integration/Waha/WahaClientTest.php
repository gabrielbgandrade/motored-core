<?php
namespace Motored\Core\Tests\Unit\Integration\Waha;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Motored\Core\Integration\Waha\WahaClient;
use Motored\Core\Tests\Unit\TestCase;

final class WahaClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->fake_storage();
		$this->fake_logger();
		$this->options['motored_waha'] = array(
			'url'     => 'https://waha.example.com/',
			'api_key' => 'secret-key',
			'session' => 'main',
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => $r['response']['code'] ?? 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] ?? '' );
	}

	/**
	 * @param array<string,mixed>|string $body Body.
	 * @return array<string,mixed>
	 */
	private function response( int $code, $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => is_string( $body ) ? $body : json_encode( $body ),
		);
	}

	public function test_session_status_success_sends_hardened_request(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturnUsing(
			function ( $url, $args ) {
				$this->assertSame( 'https://waha.example.com/api/sessions/main', $url );
				$this->assertSame( 'GET', $args['method'] );
				$this->assertSame( 10, $args['timeout'] );
				$this->assertSame( 0, $args['redirection'] );
				$this->assertTrue( $args['sslverify'] );
				$this->assertSame( 'secret-key', $args['headers']['X-Api-Key'] );
				return $this->response( 200, array( 'name' => 'main', 'status' => 'WORKING' ) );
			}
		);
		$result = ( new WahaClient() )->sessionStatus();
		$this->assertTrue( $result->ok );
		$this->assertSame( 'WORKING', $result->data['status'] );
	}

	public function test_send_text_posts_json_and_drops_response_body(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturnUsing(
			function ( $url, $args ) {
				$this->assertSame( 'https://waha.example.com/api/sendText', $url );
				$this->assertSame( 'POST', $args['method'] );
				$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
				$this->assertSame(
					array(
						'session' => 'main',
						'chatId'  => '5491155551234@c.us',
						'text'    => 'Hola',
					),
					json_decode( $args['body'], true )
				);
				return $this->response( 201, array( 'id' => 'abc', 'to' => '5491155551234@c.us' ) );
			}
		);
		$result = ( new WahaClient() )->sendText( '5491155551234@c.us', 'Hola' );
		$this->assertTrue( $result->ok );
		$this->assertSame( array(), $result->data );
	}

	public function test_check_exists_parses_chat_id(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturnUsing(
			function ( $url ) {
				$this->assertSame( 'https://waha.example.com/api/contacts/check-exists?phone=5491155551234&session=main', $url );
				return $this->response( 200, array( 'numberExists' => true, 'chatId' => '5491155551234@c.us' ) );
			}
		);
		$result = ( new WahaClient() )->checkExists( '5491155551234' );
		$this->assertTrue( $result->ok );
		$this->assertTrue( $result->data['exists'] );
		$this->assertSame( '5491155551234@c.us', $result->data['chat_id'] );
	}

	public function test_401_is_auth_and_not_retryable(): void {
		Functions\when( 'wp_remote_request' )->justReturn( $this->response( 401, '{"message":"Unauthorized"}' ) );
		$result = ( new WahaClient() )->sessionStatus();
		$this->assertFalse( $result->ok );
		$this->assertSame( 'auth', $result->code );
		$this->assertSame( 401, $result->http_status );
		$this->assertFalse( $result->retryable );
	}

	public function test_timeout_is_retryable(): void {
		Functions\when( 'wp_remote_request' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds' ) );
		$result = ( new WahaClient() )->sendText( 'x@c.us', 'Hola' );
		$this->assertFalse( $result->ok );
		$this->assertSame( 'timeout', $result->code );
		$this->assertTrue( $result->retryable );
		$this->assertSame( 0, $result->http_status );
	}

	public function test_connection_failure_and_server_error_are_retryable(): void {
		Functions\when( 'wp_remote_request' )->justReturn( new \WP_Error( 'http_request_failed', 'Could not resolve host' ) );
		$this->assertSame( 'transport', ( new WahaClient() )->sessionStatus()->code );

		Functions\when( 'wp_remote_request' )->justReturn( $this->response( 503, '' ) );
		$result = ( new WahaClient() )->sessionStatus();
		$this->assertSame( 'server_error', $result->code );
		$this->assertTrue( $result->retryable );
	}

	public function test_invalid_json_is_not_retryable(): void {
		Functions\when( 'wp_remote_request' )->justReturn( $this->response( 200, '<html>oops</html>' ) );
		$result = ( new WahaClient() )->sendText( 'x@c.us', 'Hola' );
		$this->assertFalse( $result->ok );
		$this->assertSame( 'invalid_response', $result->code );
		$this->assertFalse( $result->retryable );
	}

	public function test_unexpected_shape_is_invalid_response(): void {
		Functions\when( 'wp_remote_request' )->justReturn( $this->response( 200, array( 'foo' => 'bar' ) ) );
		$this->assertSame( 'invalid_response', ( new WahaClient() )->checkExists( '549' )->code );
		$this->assertSame( 'invalid_response', ( new WahaClient() )->sessionStatus()->code );
	}

	public function test_http_url_is_rejected_unless_filter_allows_it(): void {
		$this->options['motored_waha']['url'] = 'http://waha.local';
		Functions\expect( 'wp_remote_request' )->never();
		$this->assertSame( 'insecure_url', ( new WahaClient() )->sessionStatus()->code );
	}

	public function test_http_url_allowed_by_filter(): void {
		$this->options['motored_waha']['url'] = 'http://waha.local';
		Filters\expectApplied( 'motored_waha_allow_insecure_http' )->once()->andReturn( true );
		Functions\when( 'wp_remote_request' )->justReturn( $this->response( 200, array( 'status' => 'WORKING' ) ) );
		$this->assertTrue( ( new WahaClient() )->sessionStatus()->ok );
	}

	public function test_missing_configuration_makes_no_request(): void {
		$this->options['motored_waha']['api_key'] = '';
		Functions\expect( 'wp_remote_request' )->never();
		$this->assertSame( 'not_configured', ( new WahaClient() )->sessionStatus()->code );
	}
}
