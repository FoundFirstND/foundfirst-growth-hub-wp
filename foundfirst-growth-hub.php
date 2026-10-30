<?php
/**
 * Plugin Name:       FoundFirst Growth Hub
 * Plugin URI:        https://github.com/FoundFirstND/foundfirst-growth-hub-wp
 * Description:       Sends Elementor Pro form leads to FoundFirst Growth Hub and shows the Growth Hub promo banner.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            FoundFirst
 * Author URI:        https://foundfirst.us
 * License:           Proprietary
 * Text Domain:       foundfirst-growth-hub
 * Update URI:        https://github.com/FoundFirstND/foundfirst-growth-hub-wp
 *
 * @package FoundFirst\GrowthHub
 */

defined( 'ABSPATH' ) || exit;

define( 'FFGH_VERSION', '0.2.0' );
define( 'FFGH_FILE', __FILE__ );
define( 'FFGH_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFGH_URL', plugin_dir_url( __FILE__ ) );

// Keep this file parseable on old PHP: no features newer than PHP 5.6 above the version check.
$ffgh_problem = '';
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	$ffgh_problem = sprintf( 'FoundFirst Growth Hub needs PHP 8.1 or newer (this site runs %s). The plugin is inactive.', PHP_VERSION );
} elseif ( version_compare( $GLOBALS['wp_version'], '6.4', '<' ) ) {
	$ffgh_problem = 'FoundFirst Growth Hub needs WordPress 6.4 or newer. The plugin is inactive.';
} elseif ( ! is_readable( FFGH_DIR . 'vendor/autoload.php' ) ) {
	$ffgh_problem = 'FoundFirst Growth Hub is missing its vendor folder. Install it from the release zip, or run composer install. The plugin is inactive.';
}

if ( '' !== $ffgh_problem ) {
	add_action(
		'admin_notices',
		function () use ( $ffgh_problem ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $ffgh_problem ) . '</p></div>';
			}
		}
	);
	return;
}
unset( $ffgh_problem );

require_once FFGH_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \FoundFirst\GrowthHub\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \FoundFirst\GrowthHub\Plugin::class, 'deactivate' ) );

\FoundFirst\GrowthHub\Plugin::boot();
