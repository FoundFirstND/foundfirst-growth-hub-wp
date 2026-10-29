<?php
/**
 * Growth Hub API client interface.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a request to the Growth Hub API. Exists so tests can swap in a fake.
 */
interface HttpClient {

	/**
	 * Send a request.
	 *
	 * @param string     $method  GET or POST.
	 * @param string     $path    Path under /api/v1, e.g. '/leads'.
	 * @param array|null $body    JSON body.
	 * @param int        $timeout Seconds.
	 */
	public function request( string $method, string $path, ?array $body, int $timeout ): Response;
}
