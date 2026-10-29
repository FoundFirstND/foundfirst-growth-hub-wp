<?php
/**
 * A lead row in the queue.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * One queued lead. Times are Unix timestamps.
 */
final class QueuedLead {

	/**
	 * Constructor.
	 *
	 * @param int    $id         Row id.
	 * @param array  $payload    POST /leads body (includes idempotency_key).
	 * @param int    $attempts   Send attempts so far.
	 * @param int    $created_at When the lead was submitted.
	 * @param int    $expires_at When to give up retrying.
	 * @param string $status     QueueStore::PENDING or QueueStore::FAILED.
	 */
	public function __construct(
		public readonly int $id,
		public readonly array $payload,
		public readonly int $attempts,
		public readonly int $created_at,
		public readonly int $expires_at,
		public readonly string $status = QueueStore::PENDING
	) {}
}
