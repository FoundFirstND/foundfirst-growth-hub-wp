<?php
/**
 * What to do after a send attempt.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Leads;

use FoundFirst\GrowthHub\Api\Response;

defined( 'ABSPATH' ) || exit;

/**
 * The retry rules from API.md:
 * - 200/201: done.
 * - Timeout/connection error, 429, 5xx: retry with the same body after 1, 5, 15, 60 minutes, then
 *   hourly, honoring Retry-After on a 429, until the lead's give-up time (about a day).
 * - 401, 422 and every other status: never retried automatically.
 */
final class RetryPolicy {

	public const SENT  = 'sent';
	public const RETRY = 'retry';
	public const FAIL  = 'fail';

	/** Give up this long after a lead was queued. */
	public const GIVE_UP_AFTER = 86400;

	/** Seconds to wait after attempt 1, 2, 3, 4; the last value repeats. */
	private const BACKOFF = array( 60, 300, 900, 3600 );

	/**
	 * Decide what happens to a lead after an attempt.
	 *
	 * @param Response $response   The attempt's response.
	 * @param int      $attempts   Attempts including this one.
	 * @param int      $expires_at The lead's give-up time.
	 * @param int      $now        Current time.
	 * @return array{0: string, 1: int} Outcome and, for RETRY, the next attempt time (else 0).
	 */
	public function decide( Response $response, int $attempts, int $expires_at, int $now ): array {
		if ( $response->is_success() ) {
			return array( self::SENT, 0 );
		}

		if ( ! self::is_retryable( $response ) || $now >= $expires_at ) {
			return array( self::FAIL, 0 );
		}

		$delay = self::BACKOFF[ min( max( $attempts, 1 ), count( self::BACKOFF ) ) - 1 ];
		if ( 429 === $response->status && null !== $response->retry_after ) {
			$delay = max( $delay, $response->retry_after );
		}

		// One last try right at the give-up time rather than skipping it.
		return array( self::RETRY, min( $now + $delay, $expires_at ) );
	}

	/**
	 * Whether repeating the same request could succeed.
	 *
	 * @param Response $response Response.
	 */
	public static function is_retryable( Response $response ): bool {
		return 0 === $response->status || 429 === $response->status || $response->status >= 500;
	}
}
