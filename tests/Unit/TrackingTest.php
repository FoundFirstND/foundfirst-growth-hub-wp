<?php
/**
 * Google Analytics events: setting and script enqueue.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Tests\Unit;

use Brain\Monkey\Functions;
use FoundFirst\GrowthHub\Analytics\Tracking;
use FoundFirst\GrowthHub\Options;
use FoundFirst\GrowthHub\Tests\TestCase;

final class TrackingTest extends TestCase {

	/** @var array<int, array> */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_enqueue_script' )->alias(
			function ( ...$args ) {
				$this->enqueued[] = $args;
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( fn ( $text ) => trim( strip_tags( $text ) ) );
	}

	public function test_on_by_default_including_existing_installs_without_the_option(): void {
		$this->assertArrayNotHasKey( Options::ANALYTICS, $this->options );
		$this->assertTrue( Options::analytics_enabled() );
	}

	public function test_enqueues_the_script_in_the_footer_without_dependencies(): void {
		( new Tracking() )->enqueue();

		$this->assertSame(
			array(
				array(
					'ffgh-tracking',
					FFGH_URL . 'assets/tracking.js',
					array(),
					FFGH_VERSION,
					array(
						'in_footer' => true,
						'strategy'  => 'defer',
					),
				),
			),
			$this->enqueued
		);
	}

	public function test_setting_on_enqueues(): void {
		$this->options[ Options::ANALYTICS ] = '1';
		( new Tracking() )->enqueue();

		$this->assertCount( 1, $this->enqueued );
	}

	public function test_setting_off_does_not_load_the_script(): void {
		$this->options[ Options::ANALYTICS ] = '0';
		( new Tracking() )->enqueue();

		$this->assertFalse( Options::analytics_enabled() );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_registers_on_the_front_end_enqueue_hook(): void {
		$tracking = new Tracking();
		$tracking->register();

		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( $tracking, 'enqueue' ) ) );
	}

	public function test_never_throws_into_the_page(): void {
		Functions\when( 'wp_enqueue_script' )->alias(
			function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		( new Tracking() )->enqueue();

		$this->addToAssertionCount( 1 );
	}

	public function test_form_name_uses_the_name_then_the_element_id(): void {
		$this->assertSame( 'Contact Us', Tracking::form_name( "  <b>Contact</b>\n Us ", 'abc123' ) );
		$this->assertSame( 'form-abc123', Tracking::form_name( '', 'abc123' ) );
		$this->assertSame( 'elementor-form', Tracking::form_name( null, null ) );
		$this->assertSame( 100, mb_strlen( Tracking::form_name( str_repeat( 'é', 150 ), '' ) ) );
	}
}
