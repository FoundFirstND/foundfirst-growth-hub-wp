<?php
/**
 * Delivers leads to Growth Hub without losing any.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Leads;

use Closure;
use FoundFirst\GrowthHub\Api\HttpClient;
use FoundFirst\GrowthHub\Api\Response;
use FoundFirst\GrowthHub\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Write first, then send: a lead is stored in the queue before the first attempt, so a timeout,
 * an API outage or even a PHP fatal mid-send leaves it in the table for cron to retry.
 */
final class LeadSender {

	/** Cron hook for a one-off run when a retry is due. */
	public const PROCESS_HOOK = 'ffgh_process_queue';

	/** Hourly cron hook that catches anything the one-off runs missed. */
	public const SWEEP_HOOK = 'ffgh_queue_sweep';

	/** Leads sent per cron run. The API allows 30 requests a minute per key. */
	private const BATCH = 10;

	/** Alert the admin when a lead has been waiting this long. */
	private const ALERT_AFTER_WAITING = 900;

	/** Keep failed leads this long so they can be retried, then delete them (personal data). */
	private const KEEP_FAILED = 30 * 86400;

	/** Lock lifetime, in case a cron run dies without releasing it. */
	private const LOCK_TTL = 300;

	/**
	 * Current time source.
	 *
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * Constructor.
	 *
	 * @param HttpClient      $http     API client.
	 * @param QueueStore      $queue    Queue storage.
	 * @param RetryPolicy     $policy   Retry rules.
	 * @param FailureNotifier $notifier Admin emails.
	 * @param Closure|null    $clock    Returns the current Unix time (tests).
	 */
	public function __construct(
		private HttpClient $http,
		private QueueStore $queue,
		private RetryPolicy $policy,
		private FailureNotifier $notifier,
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Handle a new form submission: queue it, then try to send it right away with a short
	 * timeout. Never throws.
	 *
	 * @param array $payload POST /leads body including idempotency_key.
	 */
	public function submit( array $payload ): void {
		$now = $this->now();

		// next_attempt_at a minute out: a cron run starting during this request won't send it twice.
		$lead = $this->queue->insert( $payload, $now, $now + 60, $now + RetryPolicy::GIVE_UP_AFTER );

		$timeout = (int) apply_filters( 'ffgh_send_timeout', 5 );

		if ( null === $lead ) {
			// The table is unavailable. Still try once; if that fails there is nowhere to keep it.
			$response = $this->http->request( 'POST', '/leads', $payload, $timeout );
			if ( $response->is_success() ) {
				Status::record_success( $now );
			} else {
				Status::record_error( $now, $response->status, 'Lead not delivered and could not be queued (database table missing?): ' . $response->describe() );
				$this->notifier->maybe_alert( $now );
			}
			return;
		}

		$this->attempt( $lead, $timeout );
	}

	/**
	 * Cron: send leads that are due, under a lock so two runs can't overlap.
	 */
	public function process_queue(): void {
		if ( ! $this->acquire_lock() ) {
			return;
		}

		try {
			$now = $this->now();
			$this->queue->purge_failed( $now - self::KEEP_FAILED );

			foreach ( $this->queue->due( $now, self::BATCH ) as $lead ) {
				$this->attempt( $lead, (int) apply_filters( 'ffgh_retry_timeout', 15 ) );
			}

			$oldest = $this->queue->oldest_pending_created_at();
			if ( null !== $oldest && $this->now() - $oldest >= self::ALERT_AFTER_WAITING ) {
				$this->notifier->maybe_alert( $this->now() );
			}

			$next = $this->queue->next_due_at();
			if ( null !== $next ) {
				$this->schedule( $next );
			}
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Put failed leads (except ones the API rejected as invalid) back in the queue and
	 * schedule a run. Used after the admin fixes the site key.
	 */
	public function retry_failed(): int {
		$now   = $this->now();
		$count = $this->queue->requeue_failed( $now, $now + RetryPolicy::GIVE_UP_AFTER );

		if ( $count > 0 ) {
			$this->schedule( $now );
		}

		return $count;
	}

	/**
	 * Send one queued lead and act on the result.
	 *
	 * @param QueuedLead $lead    Lead.
	 * @param int        $timeout Seconds.
	 */
	private function attempt( QueuedLead $lead, int $timeout ): void {
		$response = $this->http->request( 'POST', '/leads', $lead->payload, $timeout );
		$now      = $this->now();
		$attempts = $lead->attempts + 1;

		[ $outcome, $next ] = $this->policy->decide( $response, $attempts, $lead->expires_at, $now );

		if ( RetryPolicy::SENT === $outcome ) {
			$this->queue->delete( $lead->id );
			Status::record_success( $now );
			return;
		}

		$error = $this->explain( $response );
		Status::record_error( $now, $response->status, $error );

		if ( RetryPolicy::RETRY === $outcome ) {
			$this->queue->mark_retry( $lead->id, $attempts, $next, $response->status, $error, $now );
			$this->schedule( $next );
			return;
		}

		$this->queue->mark_failed( $lead->id, $attempts, $response->status, $error, $now );
		$this->notifier->maybe_alert( $now );
	}

	/**
	 * Admin-facing description of a failed response.
	 *
	 * @param Response $response Response.
	 */
	private function explain( Response $response ): string {
		$text = $response->describe();

		return match ( true ) {
			401 === $response->status => $text . ' Check the site key in Settings → Growth Hub.',
			422 === $response->status => $text . ' Growth Hub rejected the lead; check the form field mapping.',
			default => $text,
		};
	}

	/**
	 * Make sure a cron run happens at or before $at.
	 *
	 * @param int $at Timestamp.
	 */
	private function schedule( int $at ): void {
		$scheduled = wp_next_scheduled( self::PROCESS_HOOK );
		if ( false === $scheduled || $scheduled > $at ) {
			wp_schedule_single_event( $at, self::PROCESS_HOOK );
		}
	}

	/**
	 * Take the queue lock. add_option is atomic: it fails if the row already exists.
	 */
	private function acquire_lock(): bool {
		$now = $this->now();
		if ( add_option( Options::QUEUE_LOCK, $now + self::LOCK_TTL, '', false ) ) {
			return true;
		}

		if ( (int) get_option( Options::QUEUE_LOCK, 0 ) < $now ) {
			// Stale lock from a run that died.
			delete_option( Options::QUEUE_LOCK );
			return (bool) add_option( Options::QUEUE_LOCK, $now + self::LOCK_TTL, '', false );
		}

		return false;
	}

	/**
	 * Release the queue lock.
	 */
	private function release_lock(): void {
		delete_option( Options::QUEUE_LOCK );
	}

	/**
	 * Current time.
	 */
	private function now(): int {
		return ( $this->clock )();
	}
}
