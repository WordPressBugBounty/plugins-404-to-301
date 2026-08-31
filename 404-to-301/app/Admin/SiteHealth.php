<?php
/**
 * WordPress Site Health integration.
 *
 * Surfaces 404-to-301 diagnostics in Tools → Site Health: a
 * dedicated section in the Info tab (read-only state dump) and a
 * handful of Status tests that flag the issues we've seen account
 * for most support tickets — log table bloat, conflicting redirect
 * plugins, broken default targets, silent cron failures on the
 * Email Reports and Logs Cleaner features.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use AIOSEO\FourNotFour\Utils\Plugin;

use AIOSEO\FourNotFour\Main\Cleaner\Cron as CleanerCron;
use AIOSEO\FourNotFour\Main\Reports\Cron as ReportsCron;

/**
 * Class SiteHealth
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Admin
 */
class SiteHealth {

	/**
	 * Row-count threshold above which the logs table is considered
	 * "large enough to recommend pruning".
	 *
	 * @since 4.0.0
	 */
	const LOGS_LARGE_ROWS = 50_000;

	/**
	 * Cron-overdue grace multiplier.
	 *
	 * A scheduled hook is flagged as stalled once it is more than
	 * `2 ×` its interval past due — one missed tick is normal noise
	 * (server load, DISABLE_WP_CRON gaps); two missed ticks in a row
	 * is a real problem worth surfacing.
	 *
	 * @since 4.0.0
	 */
	const CRON_OVERDUE_MULTIPLIER = 2;

	/**
	 * Logs Cleaner cron hook name.
	 *
	 * @since 4.0.0
	 */
	const CLEANER_CRON = CleanerCron::HOOK;

	/**
	 * Email Reports cron hook name.
	 *
	 * @since 4.0.0
	 */
	const EMAIL_REPORTS_CRON = ReportsCron::HOOK;

	/**
	 * Register the Site Health filters.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_filter( 'debug_information', [ $this, 'debugInformation' ] );
		add_filter( 'site_status_tests', [ $this, 'registerTests' ] );
	}

	// --------------------------------------------------------------------- //
	// Debug Info — Tools → Site Health → Info tab.
	// --------------------------------------------------------------------- //

	/**
	 * Add a "404 to 301" section to the Site Health Info screen.
	 *
	 * @since 4.0.0
	 *
	 * @param array $info Existing debug info sections.
	 *
	 * @return array
	 */
	public function debugInformation( $info ): array {
		$info = is_array( $info ) ? $info : [];

		$values = aioseo404To301()->options->all();

		$fields = [
			'plugin_version'   => [
				'label' => __( 'Plugin version', '404-to-301' ),
				'value' => defined( 'AIOSEO_404_TO_301_VERSION' ) ? AIOSEO_404_TO_301_VERSION : '',
			],
			'db_version'       => [
				'label' => __( 'Database schema version', '404-to-301' ),
				'value' => defined( 'AIOSEO_404_TO_301_DB_VERSION' ) ? AIOSEO_404_TO_301_DB_VERSION : '',
			],
			'redirect_enabled' => [
				'label' => __( 'Redirect 404s', '404-to-301' ),
				'value' => $this->yesNo( ! empty( $values['redirect_enabled'] ) ),
			],
			'redirect_type'    => [
				'label' => __( 'Default redirect type', '404-to-301' ),
				'value' => (string) ( $values['redirect_type'] ?? '' ),
			],
			'redirect_target'  => [
				'label' => __( 'Default redirect target', '404-to-301' ),
				'value' => (string) ( $values['redirect_target'] ?? '' ),
			],
			'redirect_link'    => [
				'label'   => __( 'Default redirect URL', '404-to-301' ),
				'value'   => (string) ( $values['redirect_link'] ?? '' ),
				'private' => true,
			],
			'disable_guessing' => [
				'label' => __( 'Block WordPress URL guessing', '404-to-301' ),
				'value' => (string) ( $values['disable_guessing'] ?? '' ),
			],
			'logs_enabled'     => [
				'label' => __( 'Log 404 errors', '404-to-301' ),
				'value' => $this->yesNo( ! empty( $values['logs_enabled'] ) ),
			],
			'logs_skip_bots'   => [
				'label' => __( 'Skip bot 404s', '404-to-301' ),
				'value' => $this->yesNo( ! empty( $values['logs_skip_bots'] ) ),
			],
			'logs_rows'        => [
				'label' => __( 'Logged 404 rows', '404-to-301' ),
				'value' => number_format_i18n( (float) $this->rowCount( '404_to_301_logs' ) ),
			],
			'logs_size'        => [
				'label' => __( 'Logs table size', '404-to-301' ),
				'value' => size_format( $this->tableSizeBytes( '404_to_301_logs' ) ),
			],
			'redirect_rows'    => [
				'label' => __( 'Redirect rules', '404-to-301' ),
				'value' => number_format_i18n( (float) $this->rowCount( '404_to_301_redirects' ) ),
			],
			'email_enabled'    => [
				'label' => __( 'Email notifications', '404-to-301' ),
				'value' => $this->yesNo( ! empty( $values['email_enabled'] ) ),
			],
			'email_recipients' => [
				'label' => __( 'Email recipients', '404-to-301' ),
				'value' => (string) count( (array) ( $values['email_recipient'] ?? [] ) ),
			],
			'track_admin_404'  => [
				'label' => __( 'Track admin 404s', '404-to-301' ),
				'value' => $this->yesNo( ! empty( $values['track_admin_404'] ) ),
			],
			'mask_ip'          => [
				'label' => __( 'Storing visitor IPs', '404-to-301' ),
				'value' => $this->yesNo( ! empty( $values['mask_ip'] ) ),
			],
			'cleaner_active'   => [
				'label' => __( 'Logs Cleaner enabled', '404-to-301' ),
				'value' => $this->yesNo( $this->isCleanerActive() ),
			],
			'cleaner_next_run' => [
				'label' => __( 'Logs Cleaner next run', '404-to-301' ),
				'value' => $this->formatNextRun( self::CLEANER_CRON ),
			],
			'reports_next_run' => [
				'label' => __( 'Email Reports next run', '404-to-301' ),
				'value' => $this->formatNextRun( self::EMAIL_REPORTS_CRON ),
			],
			'conflicting'      => [
				'label' => __( 'Conflicting redirect plugins', '404-to-301' ),
				'value' => $this->conflictingPluginsLabel(),
			],
		];

		$info['404-to-301'] = [
			'label'       => __( '404 to 301', '404-to-301' ),
			'description' => __( 'Diagnostic information for the 404 to 301 plugin.', '404-to-301' ),
			'fields'      => $fields,
		];

		return $info;
	}

	// --------------------------------------------------------------------- //
	// Status tests — Tools → Site Health → Status tab.
	// --------------------------------------------------------------------- //

	/**
	 * Register our direct status tests.
	 *
	 * Every test is `direct` (synchronous): each callback runs a
	 * single cheap query or option read — no HTTP, no heavy joins —
	 * so adding them to the Status page does not slow it down.
	 *
	 * @since 4.0.0
	 *
	 * @param array $tests Existing site status tests.
	 *
	 * @return array
	 */
	public function registerTests( $tests ): array {
		$tests = is_array( $tests ) ? $tests : [];

		$ours = [
			'd404_logs_table_size'    => [ __( '404 to 301 logs table size', '404-to-301' ), 'testLogsTableSize' ],
			'd404_cleaner_cron'       => [ __( '404 to 301 Logs Cleaner cron', '404-to-301' ), 'testCleanerCron' ],
			'd404_email_reports_cron' => [ __( '404 to 301 Email Reports cron', '404-to-301' ), 'testEmailReportsCron' ],
			'd404_conflicting_plugin' => [ __( '404 to 301 conflicting plugins', '404-to-301' ), 'testConflictingPlugins' ],
			'd404_logging_state'      => [ __( '404 to 301 logging state', '404-to-301' ), 'testLoggingState' ],
			'd404_db_version'         => [ __( '404 to 301 database schema', '404-to-301' ), 'testDbVersion' ],
			'd404_broken_links'       => [ __( '404 to 301 broken link monitoring', '404-to-301' ), 'testBrokenLinkMonitoring' ],
		];

		foreach ( $ours as $id => list( $label, $method ) ) {
			// WordPress silently skips a test whose callback isn't callable, so a typo here costs the
			// whole panel with no error anywhere.
			if ( ! is_callable( [ $this, $method ] ) ) {
				continue;
			}

			$tests['direct'][ $id ] = [
				'label' => $label,
				'test'  => [ $this, $method ],
			];
		}

		return $tests;
	}

	/**
	 * Test: is the logs table large enough that auto-cleanup is worth
	 * recommending?
	 *
	 * Branches on whether a cleanup policy is set, but points at the same Logs settings tab either
	 * way - that's where the policy lives now.
	 *
	 * @since 4.0.0
	 *
	 * @return array
	 */
	public function testLogsTableSize(): array {
		$rows = $this->rowCount( '404_to_301_logs' );

		if ( $rows < self::LOGS_LARGE_ROWS ) {
			return $this->buildResult(
				'good',
				'd404_logs_table_size',
				__( 'Your 404 logs table is a healthy size', '404-to-301' ),
				__( 'The 404 to 301 logs table is well within recommended limits.', '404-to-301' )
			);
		}

		$description = sprintf(
			/* translators: %s: number of rows in the logs table. */
			__( 'Your 404 to 301 logs table currently holds %s rows. Large log tables slow down queries on the Logs screen and bloat your database backups.', '404-to-301' ),
			number_format_i18n( (float) $rows )
		);

		if ( $this->isCleanerActive() ) {
			$description .= ' ' . __( 'Automatic cleanup is on — review its policy to keep the table trimmed.', '404-to-301' );
			$actionLabel  = __( 'Review Auto Cleanup', '404-to-301' );
		} else {
			$description .= ' ' . __( 'The built-in Logs Cleaner can prune old entries automatically on a schedule you choose.', '404-to-301' );
			$actionLabel  = __( 'Set Up Auto Cleanup', '404-to-301' );
		}

		$actionUrl = Plugin::getUrl( 'settings' );

		return $this->buildResult(
			'recommended',
			'd404_logs_table_size',
			__( 'Your 404 logs table is getting large', '404-to-301' ),
			$description,
			$actionLabel,
			$actionUrl
		);
	}

	/**
	 * Test: if the Logs Cleaner feature is enabled, is its cron firing on
	 * schedule? Skipped entirely when the feature is off.
	 *
	 * @since 4.0.0
	 *
	 * @return array
	 */
	public function testCleanerCron(): array {
		if ( ! $this->isCleanerActive() ) {
			return $this->buildResult(
				'good',
				'd404_cleaner_cron',
				__( 'Logs Cleaner is not enabled', '404-to-301' ),
				__( 'No cron health check needed — the Logs Cleaner feature is not enabled.', '404-to-301' )
			);
		}

		return $this->cronHealthResult(
			'd404_cleaner_cron',
			self::CLEANER_CRON,
			__( 'Logs Cleaner', '404-to-301' ),
			Plugin::getUrl( 'settings' )
		);
	}

	/**
	 * Test: if Email Reports cron is scheduled, is it on schedule?
	 *
	 * @since 4.0.0
	 *
	 * @return array
	 */
	public function testEmailReportsCron(): array {
		if ( ! wp_next_scheduled( self::EMAIL_REPORTS_CRON ) ) {
			return $this->buildResult(
				'good',
				'd404_email_reports_cron',
				__( 'Email Reports cron is not scheduled', '404-to-301' ),
				__( 'No cron health check needed — the Email Reports feature is not enabled or not configured to send reports.', '404-to-301' )
			);
		}

		return $this->cronHealthResult(
			'd404_email_reports_cron',
			self::EMAIL_REPORTS_CRON,
			__( 'Email Reports', '404-to-301' ),
			Plugin::getUrl( 'settings' )
		);
	}

	/**
	 * Test: warn when another redirect-handling plugin is active.
	 *
	 * Two plugins handling 404s tends to produce duplicate logging,
	 * unpredictable redirect precedence and the occasional loop —
	 * never the kind of bug a user enjoys debugging.
	 *
	 * @since 4.0.0
	 *
	 * @return array
	 */
	public function testConflictingPlugins(): array {
		$conflicts = $this->detectConflictingPlugins();

		if ( empty( $conflicts ) ) {
			return $this->buildResult(
				'good',
				'd404_conflicting_plugin',
				__( 'No conflicting redirect plugins detected', '404-to-301' ),
				__( 'Nothing else on this site is fighting 404 to 301 for control of redirects.', '404-to-301' )
			);
		}

		return $this->buildResult(
			'recommended',
			'd404_conflicting_plugin',
			__( 'Another redirect plugin is active', '404-to-301' ),
			sprintf(
				/* translators: %s: comma-separated plugin names. */
				__(
					'404 to 301 detected another redirect-handling plugin running alongside it: %s. Running two redirect plugins can cause duplicate logging, redirect loops or unpredictable precedence. Disable one of them if the duplication is unintentional.', // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					'404-to-301'
				),
				esc_html( implode( ', ', $conflicts ) )
			)
		);
	}

	/**
	 * Test: redirect is enabled but logging is off (or vice-versa) —
	 * surface the inconsistency so the user knows.
	 *
	 * @since 4.0.0
	 *
	 * @return array
	 */
	public function testLoggingState(): array {
		$logsOn     = (bool) aioseo404To301()->options->logs->enabled;
		$redirectOn = (bool) aioseo404To301()->options->redirects->enabled;

		if ( $redirectOn && ! $logsOn ) {
			return $this->buildResult(
				'recommended',
				'd404_logging_state',
				__( '404 logging is disabled', '404-to-301' ),
				__(
					'404 to 301 is actively redirecting visitors away from broken URLs, but logging is turned off — you have no visibility into which URLs are 404ing. Re-enable logging on the Settings page if you want to see what is breaking.', // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					'404-to-301'
				),
				__( 'Open Settings', '404-to-301' ),
				Plugin::getUrl( 'settings' )
			);
		}

		return $this->buildResult(
			'good',
			'd404_logging_state',
			__( '404 to 301 logging is healthy', '404-to-301' ),
			__( 'Logging and redirect settings are consistent.', '404-to-301' )
		);
	}

	/**
	 * Test: did the DB migration finish?
	 *
	 * `db_version` lagging behind `AIOSEO_404_TO_301_DB_VERSION` means the upgrade
	 * routine bailed partway and the schema is mid-flight.
	 *
	 * @since 4.0.0
	 *
	 * @return array
	 */
	public function testDbVersion(): array {
		$storedVer = (string) aioseo404To301()->internalOptions->internal->lastSchemaVersion;
		$expected  = defined( 'AIOSEO_404_TO_301_DB_VERSION' ) ? AIOSEO_404_TO_301_DB_VERSION : '';

		if ( '' === $storedVer || '' === $expected || version_compare( $storedVer, $expected, '>=' ) ) {
			return $this->buildResult(
				'good',
				'd404_db_version',
				__( '404 to 301 database schema is up to date', '404-to-301' ),
				__( 'The plugin database tables match the expected schema version.', '404-to-301' )
			);
		}

		return $this->buildResult(
			'critical',
			'd404_db_version',
			__( '404 to 301 database schema is out of date', '404-to-301' ),
			sprintf(
				/* translators: 1: stored DB version, 2: expected DB version. */
				__(
					'The stored database schema version (%1$s) is older than the version this plugin expects (%2$s). A previous upgrade may have been interrupted. Visit any 404 to 301 admin page to trigger the upgrader again.', // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					'404-to-301'
				),
				esc_html( $storedVer ),
				esc_html( $expected )
			)
		);
	}

	// --------------------------------------------------------------------- //
	// Helpers.
	// --------------------------------------------------------------------- //

	/**
	 * Shared cron-health evaluator used by every cron-shaped test.
	 *
	 * @since 4.0.0
	 *
	 * @param string $testId      Test identifier.
	 * @param string $hook         Cron hook name.
	 * @param string $displayName Human-readable name of the cron's owner.
	 * @param string $settingsUrl Where to send the user to fix it.
	 *
	 * @return array
	 */
	private function cronHealthResult( string $testId, string $hook, string $displayName, string $settingsUrl ): array {
		$next = wp_next_scheduled( $hook );

		if ( ! $next ) {
			return $this->buildResult(
				'recommended',
				$testId,
				sprintf(
					/* translators: %s: feature name. */
					__( '%s cron is not scheduled', '404-to-301' ),
					$displayName
				),
				sprintf(
					/* translators: %s: feature name. */
					__(
						'The %s cron event is missing from WP-Cron. This usually means the feature was switched off mid-cycle or another plugin cleared the schedule. Re-saving its settings will reschedule it.', // phpcs:ignore Generic.Files.LineLength.MaxExceeded
						'404-to-301'
					),
					$displayName
				),
				__( 'Open Settings', '404-to-301' ),
				$settingsUrl
			);
		}

		$schedules = wp_get_schedules();
		$interval  = (int) ( $schedules[ wp_get_schedule( $hook ) ]['interval'] ?? 0 );
		$overdue   = $interval > 0 && ( time() - $next ) > ( $interval * self::CRON_OVERDUE_MULTIPLIER );

		if ( ! $overdue ) {
			return $this->buildResult(
				'good',
				$testId,
				sprintf(
					/* translators: %s: feature name. */
					__( '%s cron is healthy', '404-to-301' ),
					$displayName
				),
				sprintf(
					/* translators: 1: feature name, 2: next-run timestamp. */
					__( 'The %1$s cron is scheduled and on time. Next run: %2$s.', '404-to-301' ),
					$displayName,
					esc_html( $this->formatTimestamp( $next ) )
				)
			);
		}

		return $this->buildResult(
			'critical',
			$testId,
			sprintf(
				/* translators: %s: feature name. */
				__( '%s cron is overdue', '404-to-301' ),
				$displayName
			),
			sprintf(
				/* translators: 1: feature name, 2: scheduled-for timestamp. */
				__(
					'The %1$s cron event was due at %2$s but has not run. WP-Cron only fires when your site receives traffic; if traffic is low or DISABLE_WP_CRON is set without a real cron job, scheduled tasks will stall. Configure a real system cron, or check for a plugin blocking WP-Cron.', // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					'404-to-301'
				),
				$displayName,
				esc_html( $this->formatTimestamp( $next ) )
			),
			__( 'Open Settings', '404-to-301' ),
			$settingsUrl
		);
	}

	/**
	 * Detect other active plugins that also handle redirects.
	 *
	 * @since 4.0.0
	 *
	 * @return string[] Plugin display names that conflict.
	 */
	private function detectConflictingPlugins(): array {
		$candidates = [
			'redirection/redirection.php'             => 'Redirection',
			'safe-redirect-manager/safe-redirect-manager.php' => 'Safe Redirect Manager',
			'simple-301-redirects/wpsimple301redirects.php' => 'Simple 301 Redirects',
			'eps-301-redirects/eps-301-redirects.php' => '301 Redirects',
			'quick-pagepost-redirect-plugin/page_post_redirect_plugin.php' => 'Quick Page/Post Redirect',
		];

		$this->ensurePluginApi();

		return array_values(
			array_filter(
				$candidates,
				fn( string $basename ): bool => is_plugin_active( $basename ),
				ARRAY_FILTER_USE_KEY
			)
		);
	}

	/**
	 * Comma-separated list of detected conflicts, or "None".
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	private function conflictingPluginsLabel(): string {
		$conflicts = $this->detectConflictingPlugins();

		if ( empty( $conflicts ) ) {
			return __( 'None detected', '404-to-301' );
		}

		return implode( ', ', $conflicts );
	}

	/**
	 * Is the Logs Cleaner feature switched on?
	 *
	 * @since 4.0.0
	 *
	 * @return bool
	 */
	private function isCleanerActive(): bool {
		return 'none' !== (string) aioseo404To301()->options->cleaner->method;
	}

	/**
	 * Make sure `is_plugin_active()` is loaded.
	 *
	 * The Plugin API is only auto-loaded on `wp-admin/plugins.php`; Site
	 * Health renders from every admin screen, so we may hit this code
	 * path before WP has pulled the file in.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	private function ensurePluginApi(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Whether anything is watching for the broken links that cause these 404s.
	 *
	 * Only reports a recommendation when the log actually holds 404s that were reached
	 * from this site's own pages — otherwise there is nothing to diagnose, and Site
	 * Health is no place for a suggestion the data doesn't support.
	 *
	 * @since 4.0.4
	 *
	 * @return array
	 */
	public function testBrokenLinkMonitoring(): array {
		$data = aioseo404To301()->helpers->getPluginData();

		if ( ! empty( $data['brokenLinkChecker']['activated'] ) ) {
			return $this->buildResult(
				'good',
				'd404_broken_links',
				__( 'Broken links are being monitored', '404-to-301' ),
				__( 'Broken Link Checker is active, so the links causing these 404s are being tracked.', '404-to-301' )
			);
		}

		$internal = $this->internalReferrerCount();

		if ( 0 === $internal ) {
			return $this->buildResult(
				'good',
				'd404_broken_links',
				__( 'No broken internal links detected', '404-to-301' ),
				__( 'None of the logged 404s were reached from a link on this site.', '404-to-301' )
			);
		}

		return $this->buildResult(
			'recommended',
			'd404_broken_links',
			__( 'Some 404s come from links on this site', '404-to-301' ),
			sprintf(
				// Translators: 1 - A number of logged 404s.
				_n(
					'%1$s logged 404 was reached from a link on one of your own pages, which means a link somewhere on the site points at a URL that no longer exists. Redirecting hides it; fixing the link removes it. Broken Link Checker finds those links for you.', // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					'%1$s logged 404s were reached from links on your own pages, which means links somewhere on the site point at URLs that no longer exist. Redirecting hides them; fixing the links removes them. Broken Link Checker finds those links for you.', // phpcs:ignore Generic.Files.LineLength.MaxExceeded
					$internal,
					'404-to-301'
				),
				number_format_i18n( $internal )
			),
			__( 'Get Broken Link Checker', '404-to-301' ),
			Plugin::getUrl( 'about' )
		);
	}

	/**
	 * Logged 404s whose referrer is a page on this site.
	 *
	 * @since 4.0.4
	 *
	 * @return int
	 */
	private function internalReferrerCount(): int {
		global $wpdb;

		$table = $wpdb->prefix . '404_to_301_logs';
		$like  = $wpdb->esc_like( untrailingslashit( (string) home_url() ) ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterised; it is built from a hard-coded literal plus `$wpdb->prefix`.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE ref LIKE %s", $like ) );

		return (int) $count;
	}

	/**
	 * Row count for one of our tables.
	 *
	 * @since 4.0.0
	 *
	 * @param string $unprefixed Unprefixed table name, e.g. `404_to_301_logs`.
	 *
	 * @return int
	 */
	private function rowCount( string $unprefixed ): int {
		global $wpdb;

		// Allow-listed rather than trusted: the value is interpolated into the query because a table
		// name can't be parameterised, so it must not be able to come from anywhere but this list.
		if ( ! in_array( $unprefixed, [ '404_to_301_logs', '404_to_301_redirects' ], true ) ) {
			return 0;
		}

		$table = $wpdb->prefix . $unprefixed;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name cannot be parameterised; it is built from a hard-coded literal plus `$wpdb->prefix`.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Total disk size (data + indexes) of one of our tables, in bytes.
	 *
	 * Queries `information_schema.TABLES`; returns 0 on hosts that
	 * restrict access to the schema.
	 *
	 * @since 4.0.0
	 *
	 * @param string $unprefixed Unprefixed table name.
	 *
	 * @return int
	 */
	private function tableSizeBytes( string $unprefixed ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT (data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$wpdb->prefix . $unprefixed
			)
		);

		return (int) $size;
	}

	/**
	 * Render `wp_next_scheduled()` as a localised timestamp or a dash.
	 *
	 * @since 4.0.0
	 *
	 * @param string $hook Cron hook name.
	 *
	 * @return string
	 */
	private function formatNextRun( string $hook ): string {
		$next = wp_next_scheduled( $hook );

		return $next ? $this->formatTimestamp( (int) $next ) : __( 'Not scheduled', '404-to-301' );
	}

	/**
	 * Format a UTC unix timestamp in the site's timezone.
	 *
	 * @since 4.0.0
	 *
	 * @param int $timestamp Unix timestamp.
	 *
	 * @return string
	 */
	private function formatTimestamp( int $timestamp ): string {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return (string) wp_date( $format, $timestamp );
	}

	/**
	 * Localised Yes / No label.
	 *
	 * @since 4.0.0
	 *
	 * @param bool $flag Boolean to render.
	 *
	 * @return string
	 */
	private function yesNo( bool $flag ): string {
		return $flag ? __( 'Yes', '404-to-301' ) : __( 'No', '404-to-301' );
	}

	/**
	 * Build a Site Health test result array.
	 *
	 * The badge colour is derived from the status — Site Health uses
	 * blue for healthy results, orange for warnings, red for critical —
	 * so callers only ever pick the status, never the colour.
	 *
	 * @since 4.0.0
	 *
	 * @param string $status       One of `good`, `recommended`, `critical`.
	 * @param string $testId      Test identifier.
	 * @param string $label        Short label.
	 * @param string $description  Long description (plain text — will be escaped).
	 * @param string $actionLabel Optional CTA label.
	 * @param string $actionUrl   Optional CTA URL.
	 *
	 * @return array
	 */
	private function buildResult( string $status, string $testId, string $label, string $description, string $actionLabel = '', string $actionUrl = '' ): array {
		$colors = [
			'good'        => 'blue',
			'recommended' => 'orange',
			'critical'    => 'red',
		];

		$result = [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( '404 to 301', '404-to-301' ),
				'color' => $colors[ $status ] ?? 'blue',
			],
			'description' => sprintf( '<p>%s</p>', esc_html( $description ) ),
			'test'        => $testId,
		];

		if ( '' !== $actionLabel && '' !== $actionUrl ) {
			$result['actions'] = sprintf(
				'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
				esc_url( $actionUrl ),
				esc_html( $actionLabel )
			);
		}

		return $result;
	}
}