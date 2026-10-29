<?php
/**
 * Scripted HTTP client.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Fakes;

use FoundFirst\GrowthHub\Api\HttpClient;
use FoundFirst\GrowthHub\Api\Response;

/**
 * Returns queued responses in order and records every request.
 */
final class FakeHttpClient implements HttpClient {

	/** @var Response[] */
	private array $responses = array();

	/** @var array<int, array{method: string, path: string, body: ?array, timeout: int}> */
	public array $requests = array();

	public function push( Response ...$responses ): self {
		array_push( $this->responses, ...$responses );
		return $this;
	}

	public function request( string $method, string $path, ?array $body, int $timeout ): Response {
		$this->requests[] = compact( 'method', 'path', 'body', 'timeout' );

		if ( array() === $this->responses ) {
			throw new \LogicException( "Unexpected request: {$method} {$path}" );
		}

		return array_shift( $this->responses );
	}
}
