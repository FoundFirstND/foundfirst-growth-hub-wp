<?php
/**
 * Lead queue in a custom database table.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Leads;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table; caching would make the queue stale.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name (from $wpdb->prefix) is interpolated.

/**
 * Stores queued leads in {prefix}ffgh_lead_queue. Times are Unix timestamps (UTC).
 */
final class WpdbQueue implements QueueStore {

	public const TABLE = 'ffgh_lead_queue';

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or update the table. dbDelta compares this definition with the live table, so the
	 * formatting (two spaces after PRIMARY KEY, one column per line) matters.
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			idempotency_key varchar(100) NOT NULL,
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			last_http_code smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			created_at bigint(20) unsigned NOT NULL,
			next_attempt_at bigint(20) unsigned NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status_next (status,next_attempt_at)
			) {$collate};"
		);
	}

	/**
	 * Add a lead.
	 *
	 * @param array $payload         POST /leads body.
	 * @param int   $now             Current time.
	 * @param int   $next_attempt_at When cron may pick it up.
	 * @param int   $expires_at      When to give up.
	 */
	public function insert( array $payload, int $now, int $next_attempt_at, int $expires_at ): ?QueuedLead {
		global $wpdb;

		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			return null;
		}

		$ok = $wpdb->insert(
			self::table(),
			array(
				'idempotency_key' => (string) ( $payload['idempotency_key'] ?? '' ),
				'payload'         => $json,
				'status'          => self::PENDING,
				'attempts'        => 0,
				'created_at'      => $now,
				'next_attempt_at' => $next_attempt_at,
				'expires_at'      => $expires_at,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d' )
		);

		if ( false === $ok ) {
			return null;
		}

		return new QueuedLead( (int) $wpdb->insert_id, $payload, 0, $now, $expires_at );
	}

	/**
	 * Pending leads whose next attempt is due, oldest first.
	 *
	 * @param int $now   Current time.
	 * @param int $limit Max rows.
	 * @return QueuedLead[]
	 */
	public function due( int $now, int $limit ): array {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, payload, attempts, created_at, expires_at, status FROM {$table}
				WHERE status = %s AND next_attempt_at <= %d ORDER BY next_attempt_at ASC, id ASC LIMIT %d",
				self::PENDING,
				$now,
				$limit
			),
			ARRAY_A
		);

		$leads = array();
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			if ( ! is_array( $payload ) ) {
				// Unreadable row: park it as failed so it doesn't block the queue.
				$this->mark_failed( (int) $row['id'], (int) $row['attempts'], 0, 'Stored lead data is unreadable.', $now );
				continue;
			}

			$leads[] = new QueuedLead(
				(int) $row['id'],
				$payload,
				(int) $row['attempts'],
				(int) $row['created_at'],
				(int) $row['expires_at'],
				(string) $row['status']
			);
		}

		return $leads;
	}

	/**
	 * Remove a lead.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Record a failed attempt and when to try again.
	 *
	 * @param int    $id              Row id.
	 * @param int    $attempts        Attempts so far.
	 * @param int    $next_attempt_at Next try.
	 * @param int    $http_code       Last HTTP status.
	 * @param string $error           Last error.
	 * @param int    $now             Current time.
	 */
	public function mark_retry( int $id, int $attempts, int $next_attempt_at, int $http_code, string $error, int $now ): void {
		$this->update( $id, self::PENDING, $attempts, $next_attempt_at, $http_code, $error, $now );
	}

	/**
	 * Stop retrying a lead.
	 *
	 * @param int    $id        Row id.
	 * @param int    $attempts  Attempts so far.
	 * @param int    $http_code Last HTTP status.
	 * @param string $error     Last error.
	 * @param int    $now       Current time.
	 */
	public function mark_failed( int $id, int $attempts, int $http_code, string $error, int $now ): void {
		$this->update( $id, self::FAILED, $attempts, 0, $http_code, $error, $now );
	}

	/**
	 * Put retryable failed leads back in the queue.
	 *
	 * @param int $now        Current time.
	 * @param int $expires_at New give-up time.
	 */
	public function requeue_failed( int $now, int $expires_at ): int {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = 0, next_attempt_at = %d, expires_at = %d, updated_at = %d
				WHERE status = %s AND ( last_http_code = 401 OR last_http_code < 400 OR last_http_code >= 500 )",
				self::PENDING,
				$now,
				$expires_at,
				$now,
				self::FAILED
			)
		);
	}

	/**
	 * Delete failed leads last touched before a time.
	 *
	 * @param int $before Timestamp.
	 */
	public function purge_failed( int $before ): void {
		global $wpdb;

		$table = self::table();
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE status = %s AND updated_at < %d", self::FAILED, $before )
		);
	}

	/**
	 * Number of leads with a status.
	 *
	 * @param string $status PENDING or FAILED.
	 */
	public function count( string $status ): int {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
	}

	/**
	 * Earliest next_attempt_at of pending leads.
	 */
	public function next_due_at(): ?int {
		global $wpdb;

		$table = self::table();
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(next_attempt_at) FROM {$table} WHERE status = %s", self::PENDING ) );

		return null === $value ? null : (int) $value;
	}

	/**
	 * created_at of the oldest pending lead.
	 */
	public function oldest_pending_created_at(): ?int {
		global $wpdb;

		$table = self::table();
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$table} WHERE status = %s", self::PENDING ) );

		return null === $value ? null : (int) $value;
	}

	/**
	 * Update a row after an attempt.
	 *
	 * @param int    $id              Row id.
	 * @param string $status          New status.
	 * @param int    $attempts        Attempts so far.
	 * @param int    $next_attempt_at Next try (0 when failed).
	 * @param int    $http_code       Last HTTP status.
	 * @param string $error           Last error.
	 * @param int    $now             Current time.
	 */
	private function update( int $id, string $status, int $attempts, int $next_attempt_at, int $http_code, string $error, int $now ): void {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'          => $status,
				'attempts'        => $attempts,
				'next_attempt_at' => $next_attempt_at,
				'last_http_code'  => $http_code,
				'last_error'      => mb_substr( $error, 0, 1000 ),
				'updated_at'      => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%d', '%d', '%s', '%d' ),
			array( '%d' )
		);
	}
}
