<?php
/**
 * Self-updates from GitHub releases.
 *
 * @package FoundFirst\GrowthHub
 */

namespace FoundFirst\GrowthHub\Updates;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Uses plugin-update-checker to offer GitHub releases through the normal WordPress update screen.
 * Installs the zip attached to the release (it contains vendor/), never GitHub's source zip.
 */
final class Updater {

	public const REPOSITORY = 'https://github.com/FoundFirstND/foundfirst-growth-hub-wp/';

	/** PUC's Api::REQUIRE_RELEASE_ASSETS: only ever install an attached zip. */
	private const REQUIRE_RELEASE_ASSETS = 2;

	/**
	 * Register the update checker.
	 */
	public static function register(): void {
		// A git checkout (the symlinked dev copy) must never be overwritten by an update.
		if ( file_exists( FFGH_DIR . '.git' ) || ! class_exists( PucFactory::class ) ) {
			return;
		}

		$checker = PucFactory::buildUpdateChecker( self::REPOSITORY, FFGH_FILE, 'foundfirst-growth-hub' );
		$checker->getVcsApi()->enableReleaseAssets( '/^foundfirst-growth-hub\.zip$/i', self::REQUIRE_RELEASE_ASSETS );
	}
}
