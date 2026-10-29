<?php
/**
 * Growth Hub API response.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Api;

defined( 'ABSPATH' ) || exit;

/**
 * The parts of an HTTP response the plugin cares about. Status 0 means the request never got
 * an HTTP answer (timeout, DNS, connection refused, no site key).
 */
final class Response {

	/**
	 * Constructor.
	 *
	 * @param int        $status      HTTP status, or 0 for a transport error.
	 * @param array|null $body        Decoded JSON body.
	 * @param int|null   $retry_after Seconds from a Retry-After header.
	 * @param string     $error       Transport error message (status 0 only).
	 */
	public function __construct(
		public readonly int $status,
		public readonly ?array $body = null,
		public readonly ?int $retry_after = null,
		public readonly string $error = ''
	) {}

	/**
	 * A response for a request that never got an HTTP answer.
	 *
	 * @param string $message What went wrong.
	 */
	public static function transport_error( string $message ): self {
		return new self( 0, null, null, $message );
	}

	/**
	 * 200 or 201.
	 */
	public function is_success(): bool {
		return 200 === $this->status || 201 === $this->status;
	}

	/**
	 * A short, human readable description for the admin screen and logs.
	 */
	public function describe(): string {
		if ( 0 === $this->status ) {
			return '' !== $this->error ? $this->error : 'No response.';
		}

		$message = is_array( $this->body ) && isset( $this->body['message'] ) && is_string( $this->body['message'] )
			? $this->body['message']
			: '';

		return trim( sprintf( 'HTTP %d %s', $this->status, mb_substr( $message, 0, 300 ) ) );
	}
}
