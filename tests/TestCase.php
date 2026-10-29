<?php
/**
 * Base test case.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Sets up Brain Monkey and in-memory options, transients, cron and mail.
 */
abstract class TestCase extends PHPUnitTestCase {

	/** @var array<string, mixed> */
	protected array $options = array();

	/** @var array<string, array{0: mixed, 1: int}> Value and TTL. */
	protected array $transients = array();

	/** @var array<int, array{0: int, 1: string}> Timestamp and hook. */
	protected array $scheduled = array();

	/** @var array<int, array{to: string, subject: string, message: string}> */
	protected array $mails = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();

		Functions\when( 'get_option' )->alias( fn ( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '' ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);

		Functions\when( 'get_transient' )->alias( fn ( $name ) => $this->transients[ $name ][0] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl = 0 ) {
				$this->transients[ $name ] = array( $value, $ttl );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);

		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) {
				$times = array_column( array_filter( $this->scheduled, fn ( $event ) => $event[1] === $hook ), 0 );
				return array() === $times ? false : min( $times );
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) {
				$this->scheduled[] = array( $timestamp, $hook );
				return true;
			}
		);

		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mails[] = compact( 'to', 'subject', 'message' );
				return true;
			}
		);

		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'admin_url' )->alias( fn ( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'untrailingslashit' )->alias( fn ( $value ) => rtrim( $value, '/\\' ) );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
