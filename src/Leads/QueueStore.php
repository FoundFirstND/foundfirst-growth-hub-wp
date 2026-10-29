<?php
/**
 * Lead queue storage interface.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Where leads wait between attempts. WpdbQueue in production, an in-memory fake in tests.
 */
interface QueueStore {

	public const PENDING = 'pending';
	public const FAILED  = 'failed';

	/**
	 * Add a lead. Returns null if it couldn't be stored.
	 *
	 * @param array $payload         POST /leads body.
	 * @param int   $now             Current time.
	 * @param int   $next_attempt_at When cron may pick it up.
	 * @param int   $expires_at      When to give up.
	 */
	public function insert( array $payload, int $now, int $next_attempt_at, int $expires_at ): ?QueuedLead;

	/**
	 * Pending leads whose next attempt is due, oldest first.
	 *
	 * @param int $now   Current time.
	 * @param int $limit Max rows.
	 * @return QueuedLead[]
	 */
	public function due( int $now, int $limit ): array;

	/**
	 * Remove a lead (it was delivered).
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): void;

	/**
	 * Record a failed attempt and when to try again.
	 *
	 * @param int    $id              Row id.
	 * @param int    $attempts        Attempts so far.
	 * @param int    $next_attempt_at Next try.
	 * @param int    $http_code       Last HTTP status (0 = transport error).
	 * @param string $error           Last error.
	 * @param int    $now             Current time.
	 */
	public function mark_retry( int $id, int $attempts, int $next_attempt_at, int $http_code, string $error, int $now ): void;

	/**
	 * Stop retrying a lead.
	 *
	 * @param int    $id        Row id.
	 * @param int    $attempts  Attempts so far.
	 * @param int    $http_code Last HTTP status (0 = transport error).
	 * @param string $error     Last error.
	 * @param int    $now       Current time.
	 */
	public function mark_failed( int $id, int $attempts, int $http_code, string $error, int $now ): void;

	/**
	 * Put failed leads back in the queue, except ones the API rejected as invalid (422 and other
	 * 4xx apart from 401), which would fail again. Returns how many were requeued.
	 *
	 * @param int $now        Current time.
	 * @param int $expires_at New give-up time.
	 */
	public function requeue_failed( int $now, int $expires_at ): int;

	/**
	 * Delete failed leads last touched before a time (they hold personal data).
	 *
	 * @param int $before Timestamp.
	 */
	public function purge_failed( int $before ): void;

	/**
	 * Number of leads with a status.
	 *
	 * @param string $status PENDING or FAILED.
	 */
	public function count( string $status ): int;

	/**
	 * Earliest next_attempt_at of pending leads, or null when none are pending.
	 */
	public function next_due_at(): ?int;

	/**
	 * created_at of the oldest pending lead, or null when none are pending.
	 */
	public function oldest_pending_created_at(): ?int;
}
