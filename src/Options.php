<?php
/**
 * Option names and accessors.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub;

defined( 'ABSPATH' ) || exit;

/**
 * All plugin options live here so uninstall.php and the settings page agree on names.
 * Every option is stored with autoload off: none of them are needed on normal page views.
 */
final class Options {

	public const BASE_URL     = 'ffgh_api_base_url';
	public const SITE_KEY     = 'ffgh_site_key';
	public const LAST_SUCCESS = 'ffgh_last_success_at';
	public const LAST_ERROR   = 'ffgh_last_error';
	public const LAST_ALERT   = 'ffgh_last_alert_at';
	public const DB_VERSION   = 'ffgh_db_version';
	public const QUEUE_LOCK   = 'ffgh_queue_lock';
	public const PROMO_IMAGE  = 'ffgh_show_promo_image';

	public const DEFAULT_BASE_URL = 'https://app.foundfirst.us';

	/**
	 * API base URL without trailing slash, e.g. https://app.foundfirst.us.
	 */
	public static function base_url(): string {
		$url = (string) get_option( self::BASE_URL, '' );

		return untrailingslashit( '' !== $url ? $url : self::DEFAULT_BASE_URL );
	}

	/**
	 * The site key, or '' when none is saved. Never output this.
	 */
	public static function site_key(): string {
		return (string) get_option( self::SITE_KEY, '' );
	}

	/**
	 * Whether the promo banner shows the post's photo. Off unless turned on in the settings.
	 */
	public static function show_promo_image(): bool {
		return '1' === (string) get_option( self::PROMO_IMAGE, '0' );
	}

	/**
	 * Save an option with autoload off.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	public static function set( string $name, $value ): void {
		update_option( $name, $value, false );
	}
}
