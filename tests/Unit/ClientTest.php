<?php
/**
 * Api\Client tests (wp_remote_request faked).
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use Brain\Monkey\Functions;
use FoundFirst\GrowthHub\Api\Client;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Tests\TestCase;

final class ClientTest extends TestCase {

	/** @var array{0: string, 1: array}|null */
	private ?array $sent = null;

	/** @var mixed */
	private $reply;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) {
				$this->sent = array( $url, $args );
				return $this->reply;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn ( $r ) => $r['body'] ?? '' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn ( $r ) => $r['response']['code'] ?? '' );
		Functions\when( 'wp_remote_retrieve_header' )->alias( fn ( $r, $name ) => $r['headers'][ $name ] ?? '' );
	}

	public function test_posts_json_with_bearer_key_to_the_v1_url(): void {
		$this->options[ Options::SITE_KEY ] = 'ghk_abc';
		$this->options[ Options::BASE_URL ] = 'http://growth-hub.test/';
		$this->reply                        = array(
			'response' => array( 'code' => 201 ),
			'body'     => '{"data":{"id":412,"duplicate":false}}',
		);

		$response = ( new Client() )->request( 'POST', '/leads', array( 'email' => 'a@b.co' ), 5 );

		$this->assertSame( 'http://growth-hub.test/api/v1/leads', $this->sent[0] );
		$this->assertSame( 'POST', $this->sent[1]['method'] );
		$this->assertSame( 5, $this->sent[1]['timeout'] );
		$this->assertSame( 'Bearer ghk_abc', $this->sent[1]['headers']['Authorization'] );
		$this->assertSame( 'application/json', $this->sent[1]['headers']['Accept'] );
		$this->assertSame( 'application/json', $this->sent[1]['headers']['Content-Type'] );
		$this->assertSame( '{"email":"a@b.co"}', $this->sent[1]['body'] );
		$this->assertTrue( $response->is_success() );
		$this->assertSame( 412, $response->body['data']['id'] );
	}

	public function test_defaults_to_production_url(): void {
		$this->options[ Options::SITE_KEY ] = 'ghk_abc';
		$this->reply                        = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"data":null}',
		);

		( new Client() )->request( 'GET', '/promo', null, 3 );

		$this->assertSame( 'https://app.foundfirst.us/api/v1/promo', $this->sent[0] );
		$this->assertArrayNotHasKey( 'body', $this->sent[1] );
	}

	public function test_reads_retry_after(): void {
		$this->options[ Options::SITE_KEY ] = 'ghk_abc';
		$this->reply                        = array(
			'response' => array( 'code' => 429 ),
			'headers'  => array( 'retry-after' => '42' ),
			'body'     => '{"message":"Too Many Attempts."}',
		);

		$response = ( new Client() )->request( 'POST', '/leads', array(), 5 );

		$this->assertSame( 429, $response->status );
		$this->assertSame( 42, $response->retry_after );
		$this->assertSame( 'HTTP 429 Too Many Attempts.', $response->describe() );
	}

	public function test_transport_errors_become_status_zero(): void {
		$this->options[ Options::SITE_KEY ] = 'ghk_abc';
		$this->reply                        = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$response = ( new Client() )->request( 'POST', '/leads', array(), 5 );

		$this->assertSame( 0, $response->status );
		$this->assertSame( 'cURL error 28: Operation timed out', $response->describe() );
	}

	public function test_no_key_means_no_request(): void {
		$response = ( new Client() )->request( 'POST', '/leads', array(), 5 );

		$this->assertNull( $this->sent );
		$this->assertSame( 0, $response->status );
	}
}
