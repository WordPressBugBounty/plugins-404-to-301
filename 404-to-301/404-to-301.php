<?php
/**
 * Plugin Name:       404 to 301 - Redirect Manager, 301 Redirection, 404 Error Logs & 404 Monitoring
 * Plugin URI:        https://wordpress.org/plugins/404-to-301/
 * Description:       Custom redirects (301, 302, 307), automatic 404 redirection, full 404 error logs and email alerts — a complete redirect & 404 toolkit.
 * Version:           4.0.4
 * Author:            All in One SEO Team
 * Author URI:        https://aioseo.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       404-to-301
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.4
 *
 * @package AIOSEO\FourNotFour
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AIOSEO_404_TO_301_PHP_VERSION_DIR' ) ) {
	define( 'AIOSEO_404_TO_301_PHP_VERSION_DIR', basename( __DIR__ ) );
}

require_once __DIR__ . '/app/init/init.php';

// Check if this plugin should be disabled.
if ( aioseo_404_to_301_is_plugin_disabled() ) {
	return;
}

require_once __DIR__ . '/app/init/notices.php';

// We require PHP 7.4 or higher for the whole plugin to work.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', 'aioseo_plugin_php_notice' );

	return;
}

// We require WP 6.4+ for the whole plugin to work.
global $wp_version; // phpcs:ignore Squiz.NamingConventions.ValidVariableName
if ( version_compare( $wp_version, '6.4', '<' ) ) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName
	add_action( 'admin_notices', 'aioseo_plugin_wordpress_notice' );

	return;
}

// Plugin constants.
if ( ! defined( 'AIOSEO_404_TO_301_DIR' ) ) {
	define( 'AIOSEO_404_TO_301_DIR', __DIR__ );
}
if ( ! defined( 'AIOSEO_404_TO_301_FILE' ) ) {
	define( 'AIOSEO_404_TO_301_FILE', __FILE__ );
}

require_once __DIR__ . '/app/FourNotFour.php';

aioseo404To301();