<?php
/**
 * Growth Hub API client.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Api;

use FoundFirst\GrowthHub\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the Growth Hub API from the server with wp_remote_request.
 */
final class Client implements HttpClient {

	/**
	 * Send a request.
	 *
	 * @param string     $method  GET or POST.
	 * @param string     $path    Path under /api/v1, e.g. '/leads'.
	 * @param array|null $body    JSON body.
	 * @param int        $timeout Seconds.
	 */
	public function request( string $method, string $path, ?array $body, int $timeout ): Response {
		$key = Options::site_key();
		if ( '' === $key ) {
			return Response::transport_error( 'No site key is saved in Settings → Growth Hub.' );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => 'FoundFirstGrowthHub/' . FFGH_VERSION . '; ' . home_url(),
			'headers'     => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$result = wp_remote_request( Options::base_url() . '/api/v1' . $path, $args );

		if ( is_wp_error( $result ) ) {
			return Response::transport_error( $result->get_error_message() );
		}

		$decoded     = json_decode( (string) wp_remote_retrieve_body( $result ), true );
		$retry_after = wp_remote_retrieve_header( $result, 'retry-after' );

		return new Response(
			(int) wp_remote_retrieve_response_code( $result ),
			is_array( $decoded ) ? $decoded : null,
			is_numeric( $retry_after ) ? max( 0, (int) $retry_after ) : null
		);
	}
}
