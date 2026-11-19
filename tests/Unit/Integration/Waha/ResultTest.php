<?php
namespace Motored\Core\Tests\Unit\Integration\Waha;

use Motored\Core\Integration\Waha\Result;
use Motored\Core\Support\Logger;
use Motored\Core\Tests\Unit\TestCase;

final class ResultTest extends TestCase {

	protected function tearDown(): void {
		Logger::reset_secrets();
		parent::tearDown();
	}

	public function test_message_and_data_never_carry_the_api_key(): void {
		Logger::protect( 'sup3r-secret-key' );
		$failure = Result::failure( 'transport', 'cURL error: header X-Api-Key: sup3r-secret-key rejected' );
		$success = Result::success( 200, array( 'status' => 'WORKING', 'X-Api-Key' => 'sup3r-secret-key', 'echo' => 'key=sup3r-secret-key' ) );

		$this->assertStringNotContainsString( 'sup3r-secret-key', $failure->message );
		$this->assertStringNotContainsString( 'sup3r-secret-key', (string) json_encode( $success->data ) );
		$this->assertSame( 'WORKING', $success->data['status'] );
		$this->assertSame( 'transport', $failure->code );
		$this->assertFalse( $failure->ok );
	}
}
