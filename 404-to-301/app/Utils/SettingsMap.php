<?php
namespace AIOSEO\FourNotFour\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates between the flat setting names the admin app speaks and the nested options behind them.
 *
 * The React app was written against the pre-port flat settings option and still uses those names as
 * its field keys. Rather than rename every field, this maps each flat name onto a dotted path in the
 * nested options, in both directions.
 *
 * The same map drives the one-time migration of the flat option ({@see
 * \AIOSEO\FourNotFour\Main\Migrations\MigrateFlatSettings}) and the live settings endpoint, so the two
 * can't drift apart.
 *
 * @since 4.0.3
 */
class SettingsMap {
	/**
	 * Flat key => dot path in the user-facing options.
	 *
	 * @since 4.0.3
	 *
	 * @var array
	 */
	const OPTIONS = [
		'disable_guessing'               => 'general.disableGuessing',
		'exclude_paths'                  => 'general.excludePaths',
		'monitor_post_slug'              => 'general.monitorPostSlug',
		'mask_ip'                        => 'general.maskIp',
		'track_admin_404'                => 'general.trackAdmin404',
		'redirect_enabled'               => 'redirects.enabled',
		'redirect_type'                  => 'redirects.statusCode',
		'redirect_target'                => 'redirects.target',
		'redirect_link'                  => 'redirects.link',
		'redirect_page'                  => 'redirects.pageId',
		'logs_enabled'                   => 'logs.enabled',
		'logs_skip_bots'                 => 'logs.skipBots',
		'logs_skip_duplicates'           => 'logs.skipDuplicates',
		'email_enabled'                  => 'notifications.email.enabled',
		'email_recipient'                => 'notifications.email.recipients',
		'email_threshold'                => 'notifications.email.threshold',
		'logs_cleaner_method'            => 'cleaner.method',
		'logs_cleaner_age_days'          => 'cleaner.ageDays',
		'logs_cleaner_count_threshold'   => 'cleaner.countThreshold',
		'logs_cleaner_count_strategy'    => 'cleaner.countStrategy',
		'logs_cleaner_trim_percent'      => 'cleaner.trimPercent',
		'logs_cleaner_trim_count'        => 'cleaner.trimCount',
		'logs_cleaner_periodic_schedule' => 'cleaner.periodicSchedule',
		'logs_cleaner_keep_redirects'    => 'cleaner.keepRedirects',
		'email_reports_enabled'          => 'reports.enabled',
		'email_reports_frequency'        => 'reports.frequency',
		'email_reports_recipient'        => 'reports.recipients',
		'email_reports_attach_csv'       => 'reports.attachCsv',
		'telegram_alerts_enabled'        => 'telegram.enabled',
		'telegram_alerts_bot_token'      => 'telegram.botToken',
		'telegram_alerts_chat_id'        => 'telegram.chatId',
		'telegram_alerts_on_404'         => 'telegram.on404',
		'telegram_alerts_on_redirect'    => 'telegram.onRedirect',
		'telegram_alerts_threshold'      => 'telegram.threshold'
	];

	/**
	 * Flat key => dot path in the internal options.
	 *
	 * Read-only: these are written by the workers, and the admin app only displays them.
	 *
	 * @since 4.0.3
	 *
	 * @var array
	 */
	const INTERNAL = [
		'plugin_version'               => 'internal.lastActiveVersion',
		'db_version'                   => 'internal.lastSchemaVersion',
		'logs_migrated'                => 'internal.logsMigrated',
		'migration_started'            => 'internal.migrationStarted',
		'phase1_done'                  => 'internal.phase1Done',
		'legacy_table_dropped'         => 'internal.legacyTableDropped',
		'features_migrated'            => 'internal.featuresMigrated',
		'email_reports_last_sent_at'   => 'internal.reportsLastSentAt',
		'email_reports_last_sent_id'   => 'internal.reportsLastSentId',
		'telegram_alerts_last_sent_at' => 'internal.telegramLastSentAt',
		'telegram_alerts_last_error'   => 'internal.telegramLastError'
	];

	/**
	 * Build the flat settings array the admin app reads.
	 *
	 * @since 4.0.3
	 *
	 * @return array Flat key => current value.
	 */
	public static function flatten() {
		$flat = [];

		foreach ( self::OPTIONS as $flatKey => $path ) {
			$flat[ $flatKey ] = self::read( aioseo404To301()->options, $path );
		}

		foreach ( self::INTERNAL as $flatKey => $path ) {
			$flat[ $flatKey ] = self::read( aioseo404To301()->internalOptions, $path );
		}

		return $flat;
	}

	/**
	 * Turn a flat payload into the nested shape the options layer saves.
	 *
	 * Unknown keys are dropped, and so is anything from {@see self::INTERNAL} — the admin app displays
	 * those but must not write them.
	 *
	 * @since 4.0.3
	 *
	 * @param  array $flat Flat key => value, as sent by the admin app.
	 * @return array       Nested options tree, ready for sanitizeAndSave().
	 */
	public static function expand( array $flat ) {
		$nested = [];

		foreach ( self::OPTIONS as $flatKey => $path ) {
			if ( ! array_key_exists( $flatKey, $flat ) ) {
				continue;
			}

			$nested = self::write( $nested, $path, $flat[ $flatKey ] );
		}

		return $nested;
	}

	/**
	 * Read a dotted path off an options object.
	 *
	 * @since 4.0.3
	 *
	 * @param  object $options Options instance.
	 * @param  string $path    Dotted path, eg. `notifications.email.enabled`.
	 * @return mixed           The value, or null when the path doesn't resolve.
	 */
	private static function read( $options, $path ) {
		$node = $options;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_object( $node ) ) {
				return null;
			}

			$node = $node->$segment;
		}

		return $node;
	}

	/**
	 * Set a dotted path on an array.
	 *
	 * @since 4.0.3
	 *
	 * @param  array  $target The array to write into.
	 * @param  string $path   Dotted path.
	 * @param  mixed  $value  Value to set.
	 * @return array          The array, with the path set.
	 */
	private static function write( $target, $path, $value ) {
		$segments = explode( '.', $path );
		$cursor   = &$target;

		foreach ( $segments as $index => $segment ) {
			if ( count( $segments ) - 1 === $index ) {
				$cursor[ $segment ] = $value;

				break;
			}

			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = [];
			}

			$cursor = &$cursor[ $segment ];
		}

		unset( $cursor );

		return $target;
	}
}