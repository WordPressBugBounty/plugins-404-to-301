<?php
/**
 * Plugin uninstall handler.
 *
 * Runs when the user clicks "Delete" on the plugin in
 * `wp-admin/plugins.php`. Removes every database trace the plugin
 * leaves behind:
 *  - the v4 settings option,
 *  - the v4 custom tables (logs + redirects),
 *  - the legacy v3 `404_to_301` table and its options (in case the
 *    user uninstalls before migrating),
 *  - dismissed-notice user meta,
 *  - the cron events and transients owned by the opt-in features.
 *
 * Intentionally side-effect-free aside from those `DELETE`s and
 * `DROP TABLE`s — the file stays readable so audits can confirm
 * "deleting the plugin actually deletes the plugin's data".
 *
 * @package AIOSEO\FourNotFour
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Post-port options. Every opt-in feature's configuration - and the feature toggles
// themselves - ride inside these two rows.
delete_option( 'aioseo_404_to_301_options' );
delete_option( 'aioseo_404_to_301_options_internal' );
delete_option( 'aioseo_404_to_301_options_internal_network' );

// Pre-port flat settings option, deliberately left on disk by the migration.
delete_option( '404_to_301_settings' );
delete_option( '404_to_301_has_active' );
delete_option( '404_to_301_plugin_version' );
delete_option( '404_to_301_redundant_addons' );
delete_option( '404_to_301_lookup_cache_version' );
delete_option( 'aioseo_404_to_301_migrations_log' );

// BerlinDB schema-version markers, left behind by pre-port installs.
delete_option( 'wpdb_404_to_301_logs_version' );
delete_option( 'wpdb_404_to_301_redirects_version' );

// Legacy v3 options.
delete_option( 'i4t3_gnrl_options' );
delete_option( 'i4t3_activated_time' );
delete_option( 'i4t3_db_version' );
delete_option( 'i4t3_version_no' );
delete_option( 'i4t3_review_notice' );

global $wpdb;

// v4 tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}404_to_301_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}404_to_301_redirects" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Legacy v3 table (kept around in case the user uninstalls before migrating).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}404_to_301" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Post-port support tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aioseo_404_to_301_cache" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aioseo_404_to_301_notifications" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Per-user dismissed-notice flags.
delete_metadata( 'user', 0, 'i4t3_review_notice_dismissed', '', true );
delete_metadata( 'user', 0, '404_to_301_review_dismissed', '', true );
delete_metadata( 'user', 0, '404_to_301_migration_dismissed', '', true );
delete_metadata( 'user', 0, '404_to_301_dismissed_redundant_addons', '', true );

/*
 * Cron events + self-heal locks owned by the opt-in features. Hook and
 * transient names are spelled out rather than read off the classes: this
 * file runs without the plugin booted, so those constants aren't loaded.
 * They're pinned strings anyway (see `Cleaner\Cron::HOOK` and
 * `Reports\Cron::HOOK`), so duplicating them here is safe.
 */
wp_clear_scheduled_hook( 'd404_logs_cleaner_tick' );
wp_clear_scheduled_hook( 'd404_email_reports_tick' );
delete_transient( 'd404_logs_cleaner_cron_lock' );
delete_transient( 'd404_email_reports_cron_lock' );

/*
 * Storage owned by the retired option-backed Telegram alert queue. A site that upgrades runs
 * `Migrations\DiscardLegacyTelegramQueue` instead; this covers the one that deletes the plugin
 * before ever loading it again.
 */
$aioseo404To301QueuePrefix = $wpdb->esc_like( 'duckdev_d404_telegram_alerts_batch_' ) . '%';

// Spelled out per branch rather than interpolated: the batch keys are the only thing that varies,
// and a literal table/column keeps the query legible to the wp.org scanners.
if ( is_multisite() ) {
	$aioseo404To301QueueKeys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $aioseo404To301QueuePrefix )
	);
} else {
	$aioseo404To301QueueKeys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $aioseo404To301QueuePrefix )
	);
}

foreach ( (array) $aioseo404To301QueueKeys as $aioseo404To301QueueKey ) {
	delete_site_option( $aioseo404To301QueueKey );
}

delete_site_transient( 'duckdev_d404_telegram_alerts_process_lock' );
wp_clear_scheduled_hook( 'duckdev_d404_telegram_alerts_cron' );