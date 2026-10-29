<?php
/**
 * LeadSender tests: send-now, queueing, cron retries, alerts.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use FoundFirst\GrowthHub\Api\Response;
use FoundFirst\GrowthHub\Leads\FailureNotifier;
use FoundFirst\GrowthHub\Leads\LeadSender;
use FoundFirst\GrowthHub\Leads\QueueStore;
use FoundFirst\GrowthHub\Leads\RetryPolicy;
use FoundFirst\GrowthHub\Leads\Status;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Tests\Fakes\FakeHttpClient;
use FoundFirst\GrowthHub\Tests\Fakes\InMemoryQueue;
use FoundFirst\GrowthHub\Tests\TestCase;

final class LeadSenderTest extends TestCase {

	private const START = 1_800_000_000;

	private int $now = self::START;
	private FakeHttpClient $http;
	private InMemoryQueue $queue;
	private LeadSender $sender;

	protected function setUp(): void {
		parent::setUp();

		$this->options['admin_email'] = 'owner@example.test';
		$this->options['blogname']    = 'Red River Snow';

		$this->http   = new FakeHttpClient();
		$this->queue  = new InMemoryQueue();
		$this->sender = new LeadSender(
			$this->http,
			$this->queue,
			new RetryPolicy(),
			new FailureNotifier( $this->queue ),
			fn (): int => $this->now
		);
	}

	private function payload( string $key = 'key-1' ): array {
		return array(
			'idempotency_key' => $key,
			'email'           => 'jane@example.com',
			'sms_consent'     => false,
		);
	}

	private function created(): Response {
		return new Response(
			201,
			array(
				'data' => array(
					'id'        => 412,
					'duplicate' => false,
				),
			)
		);
	}

	public function test_successful_send_leaves_nothing_queued(): void {
		$this->http->push( $this->created() );

		$this->sender->submit( $this->payload() );

		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'POST', $this->http->requests[0]['method'] );
		$this->assertSame( '/leads', $this->http->requests[0]['path'] );
		$this->assertSame( $this->payload(), $this->http->requests[0]['body'] );
		$this->assertSame( 5, $this->http->requests[0]['timeout'] );
		$this->assertSame( array(), $this->queue->rows );
		$this->assertSame( self::START, Status::last_success() );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_timeout_queues_the_lead_and_schedules_a_retry_in_a_minute(): void {
		$this->http->push( Response::transport_error( 'cURL error 28: Operation timed out' ) );

		$this->sender->submit( $this->payload() );

		$this->assertSame( 1, $this->queue->count( QueueStore::PENDING ) );
		$row = $this->queue->rows[1];
		$this->assertSame( 1, $row['attempts'] );
		$this->assertSame( self::START + 60, $row['next_attempt_at'] );
		$this->assertSame( self::START + RetryPolicy::GIVE_UP_AFTER, $row['expires_at'] );
		$this->assertSame( array( array( self::START + 60, LeadSender::PROCESS_HOOK ) ), $this->scheduled );
		$this->assertStringContainsString( 'timed out', Status::last_error()['message'] );
		$this->assertSame( array(), $this->mails, 'A single blip does not email the admin.' );
	}

	public function test_cron_retries_with_the_same_body_and_idempotency_key_then_deletes(): void {
		$this->http->push( new Response( 503 ), new Response( 429, null, 120 ), new Response( 200, array( 'data' => array( 'duplicate' => true ) ) ) );

		$this->sender->submit( $this->payload() );

		// Not due yet: nothing is sent.
		$this->now = self::START + 30;
		$this->sender->process_queue();
		$this->assertCount( 1, $this->http->requests );

		$this->now = self::START + 60;
		$this->sender->process_queue();
		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( 2, $this->queue->rows[1]['attempts'] );
		$this->assertSame( $this->now + 300, $this->queue->rows[1]['next_attempt_at'], '429 Retry-After (120s) is shorter than the 5 minute backoff.' );

		$this->now += 300;
		$this->sender->process_queue();

		$this->assertCount( 3, $this->http->requests );
		foreach ( $this->http->requests as $request ) {
			$this->assertSame( $this->payload(), $request['body'] );
		}
		$this->assertSame( 15, $this->http->requests[2]['timeout'] );
		$this->assertSame( array(), $this->queue->rows );
		$this->assertSame( $this->now, Status::last_success() );
		$this->assertArrayNotHasKey( Options::QUEUE_LOCK, $this->options, 'Lock released.' );
	}

	public function test_401_is_never_retried_and_alerts_the_admin(): void {
		$this->http->push( new Response( 401, array( 'message' => 'Invalid or revoked site key.' ) ) );

		$this->sender->submit( $this->payload() );

		$this->assertSame( 1, $this->queue->count( QueueStore::FAILED ) );
		$this->assertSame( array(), $this->scheduled );

		$this->now += 7200;
		$this->sender->process_queue();
		$this->assertCount( 1, $this->http->requests, 'Failed leads are not picked up by cron.' );

		$error = Status::last_error();
		$this->assertSame( 401, $error['code'] );
		$this->assertStringContainsString( 'Invalid or revoked site key.', $error['message'] );
		$this->assertStringContainsString( 'site key', $error['message'] );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'owner@example.test', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Stopped (need attention): 1', $this->mails[0]['message'] );
	}

	public function test_422_is_never_retried(): void {
		$this->http->push( new Response( 422, array( 'message' => 'A phone number or an email address is required.' ) ) );

		$this->sender->submit( $this->payload() );

		$this->assertSame( 1, $this->queue->count( QueueStore::FAILED ) );
		$this->assertSame( 422, $this->queue->rows[1]['last_http_code'] );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_admin_is_emailed_at_most_once_a_day(): void {
		$this->http->push( new Response( 401 ), new Response( 401 ), new Response( 401 ) );

		$this->sender->submit( $this->payload( 'a' ) );
		$this->now += 3600;
		$this->sender->submit( $this->payload( 'b' ) );
		$this->assertCount( 1, $this->mails );

		$this->now = self::START + 86400;
		$this->sender->submit( $this->payload( 'c' ) );
		$this->assertCount( 2, $this->mails );
	}

	public function test_admin_is_emailed_when_a_lead_waits_more_than_15_minutes(): void {
		$this->http->push( new Response( 503 ), new Response( 503 ), new Response( 503 ) );

		$this->sender->submit( $this->payload() );
		$this->now = self::START + 60;
		$this->sender->process_queue();
		$this->assertSame( array(), $this->mails );

		$this->now = self::START + 60 + 300 + 600;
		$this->sender->process_queue();
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Waiting to retry: 1', $this->mails[0]['message'] );
	}

	public function test_gives_up_after_a_day(): void {
		$this->http->push( Response::transport_error( 'down' ) );
		$this->sender->submit( $this->payload() );

		for ( $i = 0; $i < 40 && 1 === $this->queue->count( QueueStore::PENDING ); $i++ ) {
			$this->now = (int) $this->queue->next_due_at();
			$this->http->push( new Response( 502 ) );
			$this->sender->process_queue();
		}

		$this->assertSame( 1, $this->queue->count( QueueStore::FAILED ) );
		$this->assertSame( self::START + RetryPolicy::GIVE_UP_AFTER, $this->now, 'The last attempt is at the give-up time.' );
		// At 0, 1m, 6m, 21m, then hourly from 1h21m to 23h21m, then once more at 24h: 1 + 3 + 23 + 1.
		$this->assertCount( 28, $this->http->requests );
	}

	public function test_overlapping_cron_runs_are_skipped_while_locked(): void {
		$this->http->push( new Response( 503 ) );
		$this->sender->submit( $this->payload() );

		$this->options[ Options::QUEUE_LOCK ] = $this->now + 300;
		$this->now                           += 60;
		$this->sender->process_queue();
		$this->assertCount( 1, $this->http->requests );

		// A stale lock (run died) is taken over.
		$this->now += 600;
		$this->http->push( $this->created() );
		$this->sender->process_queue();
		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( array(), $this->queue->rows );
	}

	public function test_cron_sends_at_most_ten_leads_per_run(): void {
		for ( $i = 1; $i <= 12; $i++ ) {
			$this->http->push( new Response( 503 ) );
			$this->sender->submit( $this->payload( "key-{$i}" ) );
		}
		$this->http->push( ...array_fill( 0, 10, $this->created() ) );

		$this->now += 60;
		$this->sender->process_queue();

		$this->assertCount( 22, $this->http->requests );
		$this->assertSame( 2, $this->queue->count( QueueStore::PENDING ) );
	}

	public function test_retry_failed_requeues_401_but_not_422(): void {
		$this->http->push( new Response( 401 ), new Response( 422 ) );
		$this->sender->submit( $this->payload( 'bad-key' ) );
		$this->sender->submit( $this->payload( 'bad-lead' ) );

		$this->assertSame( 1, $this->sender->retry_failed() );
		$this->assertSame( 1, $this->queue->count( QueueStore::PENDING ) );
		$this->assertSame( array( array( $this->now, LeadSender::PROCESS_HOOK ) ), $this->scheduled );

		$this->http->push( $this->created() );
		$this->sender->process_queue();
		$this->assertSame( 'bad-key', $this->http->requests[2]['body']['idempotency_key'] );
		$this->assertSame( 1, $this->queue->count( QueueStore::FAILED ) );
	}

	public function test_failed_leads_are_purged_after_30_days(): void {
		$this->http->push( new Response( 422 ) );
		$this->sender->submit( $this->payload() );

		$this->now += 29 * 86400;
		$this->sender->process_queue();
		$this->assertSame( 1, $this->queue->count( QueueStore::FAILED ) );

		$this->now += 2 * 86400;
		$this->sender->process_queue();
		$this->assertSame( 0, $this->queue->count( QueueStore::FAILED ) );
	}

	public function test_when_the_table_is_unavailable_it_still_sends_once(): void {
		$this->queue->fail_inserts = true;
		$this->http->push( $this->created() );

		$this->sender->submit( $this->payload() );

		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( self::START, Status::last_success() );
	}

	public function test_when_the_table_is_unavailable_and_sending_fails_the_admin_is_told(): void {
		$this->queue->fail_inserts = true;
		$this->http->push( new Response( 503 ) );

		$this->sender->submit( $this->payload() );

		$this->assertStringContainsString( 'could not be queued', Status::last_error()['message'] );
		$this->assertCount( 1, $this->mails );
	}
}
