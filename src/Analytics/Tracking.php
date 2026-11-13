<?php
/**
 * Google Analytics events: click_to_call and generate_lead.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Analytics;

use FoundFirst\GrowthHub\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Loads assets/tracking.js on the front end when the setting is on. The script only pushes to
 * the site's own dataLayer / gtag: no requests of its own, no cookies, no personal data.
 */
final class Tracking {

	public const HANDLE = 'ffgh-tracking';

	/** Response-data key the form action adds so the browser knows the form went to Growth Hub. */
	public const RESPONSE_KEY = 'ffgh_ga';

	/** GA4 limit for event parameter values. */
	private const MAX_LENGTH = 100;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the script in the footer. Runs on every visitor page view, so it never throws.
	 */
	public function enqueue(): void {
		try {
			if ( ! Options::analytics_enabled() ) {
				return;
			}

			wp_enqueue_script(
				self::HANDLE,
				FFGH_URL . 'assets/tracking.js',
				array(),
				FFGH_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		} catch ( \Throwable $e ) {
			// Analytics must never break a page.
			unset( $e );
		}
	}

	/**
	 * The form_name event parameter: the Elementor form name, or its element ID. Never field values.
	 *
	 * @param mixed $name Form name setting.
	 * @param mixed $id   Form element ID setting.
	 */
	public static function form_name( $name, $id ): string {
		$clean = static function ( $value ): string {
			$value = is_scalar( $value ) ? (string) $value : '';

			return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
		};

		$name = $clean( $name );
		if ( '' === $name ) {
			$id   = $clean( $id );
			$name = '' !== $id ? 'form-' . $id : 'elementor-form';
		}

		return mb_substr( $name, 0, self::MAX_LENGTH );
	}
}
