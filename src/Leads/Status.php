<?php
/**
 * Last success / last error bookkeeping for the settings page.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Leads;

use FoundFirst\GrowthHub\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers when a lead was last delivered and the last problem, for the settings page.
 */
final class Status {

	/**
	 * A lead was delivered.
	 *
	 * @param int $now Current time.
	 */
	public static function record_success( int $now ): void {
		Options::set( Options::LAST_SUCCESS, $now );
	}

	/**
	 * Something went wrong. Never pass the site key in $message.
	 *
	 * @param int    $now       Current time.
	 * @param int    $http_code HTTP status, 0 for a transport error or local problem.
	 * @param string $message   What happened.
	 */
	public static function record_error( int $now, int $http_code, string $message ): void {
		Options::set(
			Options::LAST_ERROR,
			array(
				'time'    => $now,
				'code'    => $http_code,
				'message' => mb_substr( $message, 0, 500 ),
			)
		);
	}

	/**
	 * Time of the last delivered lead, or null.
	 */
	public static function last_success(): ?int {
		$value = (int) get_option( Options::LAST_SUCCESS, 0 );

		return $value > 0 ? $value : null;
	}

	/**
	 * The last error as array{time:int, code:int, message:string}, or null.
	 *
	 * @return array|null
	 */
	public static function last_error(): ?array {
		$value = get_option( Options::LAST_ERROR, null );

		return is_array( $value ) && isset( $value['time'], $value['message'] ) ? $value : null;
	}
}
