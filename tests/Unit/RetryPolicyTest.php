<?php
/**
 * RetryPolicy tests.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use FoundFirst\GrowthHub\Api\Response;
use FoundFirst\GrowthHub\Leads\RetryPolicy;
use FoundFirst\GrowthHub\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RetryPolicyTest extends TestCase {

	private const NOW = 1_800_000_000;

	private RetryPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->policy = new RetryPolicy();
	}

	public function test_201_and_200_duplicate_are_sent(): void {
		$this->assertSame( array( RetryPolicy::SENT, 0 ), $this->policy->decide( new Response( 201 ), 1, self::NOW + 86400, self::NOW ) );
		$this->assertSame( array( RetryPolicy::SENT, 0 ), $this->policy->decide( new Response( 200 ), 3, self::NOW + 86400, self::NOW ) );
	}

	public static function never_retried(): array {
		return array(
			'401' => array( 401 ),
			'422' => array( 422 ),
			'400' => array( 400 ),
			'403' => array( 403 ),
			'404' => array( 404 ),
			'301' => array( 301 ),
		);
	}

	#[DataProvider( 'never_retried' )]
	public function test_4xx_and_other_statuses_fail_without_retry( int $status ): void {
		$this->assertSame( array( RetryPolicy::FAIL, 0 ), $this->policy->decide( new Response( $status ), 1, self::NOW + 86400, self::NOW ) );
	}

	public static function backoff(): array {
		return array(
			'after attempt 1' => array( 1, 60 ),
			'after attempt 2' => array( 2, 300 ),
			'after attempt 3' => array( 3, 900 ),
			'after attempt 4' => array( 4, 3600 ),
			'after attempt 9' => array( 9, 3600 ),
		);
	}

	#[DataProvider( 'backoff' )]
	public function test_transient_errors_back_off( int $attempts, int $delay ): void {
		foreach ( array( Response::transport_error( 'cURL error 28: timed out' ), new Response( 500 ), new Response( 503 ), new Response( 429 ) ) as $response ) {
			$this->assertSame(
				array( RetryPolicy::RETRY, self::NOW + $delay ),
				$this->policy->decide( $response, $attempts, self::NOW + 86400, self::NOW ),
				"status {$response->status}"
			);
		}
	}

	public function test_429_honors_a_longer_retry_after(): void {
		$this->assertSame(
			array( RetryPolicy::RETRY, self::NOW + 120 ),
			$this->policy->decide( new Response( 429, null, 120 ), 1, self::NOW + 86400, self::NOW )
		);
		// A shorter Retry-After never shortens the backoff.
		$this->assertSame(
			array( RetryPolicy::RETRY, self::NOW + 900 ),
			$this->policy->decide( new Response( 429, null, 42 ), 3, self::NOW + 86400, self::NOW )
		);
	}

	public function test_gives_up_at_the_expiry_time(): void {
		$this->assertSame( array( RetryPolicy::FAIL, 0 ), $this->policy->decide( new Response( 503 ), 20, self::NOW, self::NOW ) );
	}

	public function test_last_retry_is_capped_at_the_expiry_time(): void {
		$this->assertSame(
			array( RetryPolicy::RETRY, self::NOW + 100 ),
			$this->policy->decide( new Response( 503 ), 10, self::NOW + 100, self::NOW )
		);
	}
}
