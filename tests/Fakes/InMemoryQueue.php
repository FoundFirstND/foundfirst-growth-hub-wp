<?php
/**
 * In-memory queue.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Fakes;

use FoundFirst\GrowthHub\Leads\QueuedLead;
use FoundFirst\GrowthHub\Leads\QueueStore;

/**
 * Same behavior as WpdbQueue, stored in an array.
 */
final class InMemoryQueue implements QueueStore {

	/** @var array<int, array<string, mixed>> */
	public array $rows = array();

	public bool $fail_inserts = false;

	private int $next_id = 1;

	public function insert( array $payload, int $now, int $next_attempt_at, int $expires_at ): ?QueuedLead {
		if ( $this->fail_inserts ) {
			return null;
		}

		$id                = $this->next_id++;
		$this->rows[ $id ] = array(
			'payload'         => $payload,
			'status'          => self::PENDING,
			'attempts'        => 0,
			'last_http_code'  => 0,
			'last_error'      => '',
			'created_at'      => $now,
			'next_attempt_at' => $next_attempt_at,
			'expires_at'      => $expires_at,
			'updated_at'      => $now,
		);

		return $this->lead( $id );
	}

	public function due( int $now, int $limit ): array {
		$due = array_filter( $this->rows, fn ( $row ) => self::PENDING === $row['status'] && $row['next_attempt_at'] <= $now );
		uasort( $due, fn ( $a, $b ) => $a['next_attempt_at'] <=> $b['next_attempt_at'] );

		return array_map( fn ( $id ) => $this->lead( $id ), array_slice( array_keys( $due ), 0, $limit ) );
	}

	public function delete( int $id ): void {
		unset( $this->rows[ $id ] );
	}

	public function mark_retry( int $id, int $attempts, int $next_attempt_at, int $http_code, string $error, int $now ): void {
		$this->rows[ $id ] = array_merge(
			$this->rows[ $id ],
			compact( 'attempts', 'next_attempt_at' ),
			array(
				'status'         => self::PENDING,
				'last_http_code' => $http_code,
				'last_error'     => $error,
				'updated_at'     => $now,
			)
		);
	}

	public function mark_failed( int $id, int $attempts, int $http_code, string $error, int $now ): void {
		$this->rows[ $id ] = array_merge(
			$this->rows[ $id ],
			compact( 'attempts' ),
			array(
				'status'          => self::FAILED,
				'next_attempt_at' => 0,
				'last_http_code'  => $http_code,
				'last_error'      => $error,
				'updated_at'      => $now,
			)
		);
	}

	public function requeue_failed( int $now, int $expires_at ): int {
		$count = 0;
		foreach ( $this->rows as $id => $row ) {
			$code = $row['last_http_code'];
			if ( self::FAILED === $row['status'] && ( 401 === $code || $code < 400 || $code >= 500 ) ) {
				$this->rows[ $id ] = array_merge(
					$row,
					array(
						'status'          => self::PENDING,
						'attempts'        => 0,
						'next_attempt_at' => $now,
						'expires_at'      => $expires_at,
						'updated_at'      => $now,
					)
				);
				++$count;
			}
		}
		return $count;
	}

	public function purge_failed( int $before ): void {
		$this->rows = array_filter( $this->rows, fn ( $row ) => ! ( self::FAILED === $row['status'] && $row['updated_at'] < $before ) );
	}

	public function count( string $status ): int {
		return count( array_filter( $this->rows, fn ( $row ) => $row['status'] === $status ) );
	}

	public function next_due_at(): ?int {
		$times = array_column( array_filter( $this->rows, fn ( $row ) => self::PENDING === $row['status'] ), 'next_attempt_at' );
		return array() === $times ? null : min( $times );
	}

	public function oldest_pending_created_at(): ?int {
		$times = array_column( array_filter( $this->rows, fn ( $row ) => self::PENDING === $row['status'] ), 'created_at' );
		return array() === $times ? null : min( $times );
	}

	private function lead( int $id ): QueuedLead {
		$row = $this->rows[ $id ];
		return new QueuedLead( $id, $row['payload'], $row['attempts'], $row['created_at'], $row['expires_at'], $row['status'] );
	}
}
