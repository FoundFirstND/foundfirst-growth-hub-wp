<?php
/**
 * PHPUnit bootstrap: no WordPress, WP functions are stubbed with Brain Monkey per test.
 *
 * @package FoundFirst\GrowthHub
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', '/tmp/wordpress/' );
define( 'FFGH_VERSION', '0.0.0-test' );
define( 'FFGH_FILE', dirname( __DIR__ ) . '/foundfirst-growth-hub.php' );
define( 'FFGH_DIR', dirname( __DIR__ ) . '/' );
define( 'FFGH_URL', 'https://example.test/wp-content/plugins/foundfirst-growth-hub/' );

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error for tests.
	 */
	class WP_Error {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

require_once __DIR__ . '/stubs/elementor.php';
