<?php
/**
 * Background-job scheduler abstraction.
 *
 * Detects Action Scheduler at runtime. When available, the migration
 * job is enqueued on AS for retries + persistence + observability;
 * otherwise the WordPress cron event registered by the Migrator does
 * the work in the foreground as a fallback.
 *
 * The class is intentionally tiny — Migrator does the actual chunk
 * processing; Scheduler only decides *who* runs it.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Main\Migration;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Scheduler
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Migration
 */
class Scheduler {

	/**
	 * Action hook AS fires (and that wp-cron also uses) when a
	 * migration chunk needs to run.
	 *
	 * @since 4.0.0
	 */
	const ACTION = '404_to_301_run_migration_chunk';

	/**
	 * Cron schedule key used by wp-cron when AS isn't available.
	 *
	 * @since 4.0.0
	 */
	const CRON_SCHEDULE = 'd404_migration';

	/**
	 * Whether Action Scheduler is loaded and ready.
	 *
	 * @since 4.0.0
	 *
	 * @return bool
	 */
	public static function hasActionScheduler(): bool {
		return function_exists( 'as_enqueue_async_action' )
			|| function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Queues the next migration chunk.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public static function queueNextChunk(): void {
		// Routed through the shared util so the `aioseo_use_cron_instead_of_action_scheduler`
		// filter applies here too. Action Scheduler is bundled, so it's always available.
		aioseo404To301()->actionScheduler->scheduleAsync( self::ACTION );
	}

	/**
	 * Cancel every queued chunk — used on deactivate.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public static function cancelAll(): void {
		aioseo404To301()->actionScheduler->unscheduleAll( self::ACTION );

		// Also clear any cron event left behind by a run under the fallback, or by a pre-port install.
		wp_clear_scheduled_hook( self::ACTION );
	}

	/**
	 * Build the URL that, when visited, installs Action Scheduler
	 * via WordPress's own plugin installer.
	 *
	 * Used by the migration banner when AS isn't loaded but the
	 * current user has `install_plugins`. Includes the nonce
	 * `update.php` expects.
	 *
	 * @since 4.0.0
	 *
	 * @return string Empty string when the current user lacks the cap.
	 */
	public static function installAsUrl(): string {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return '';
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return '';
		}

		// Build the URL with raw `&` separators. `wp_nonce_url()` would
		// run the result through `esc_html()` (turning `&` into `&amp;`),
		// which is right for a PHP-echoed HTML attribute but wrong here:
		// this URL is serialised into JSON and assigned to a React `href`,
		// where the entities are NOT decoded and the link breaks. Assemble
		// it with `add_query_arg()` so the separators stay literal.
		return add_query_arg(
			[
				'action'   => 'install-plugin',
				'plugin'   => 'action-scheduler',
				'_wpnonce' => wp_create_nonce( 'install-plugin_action-scheduler' )
			],
			self_admin_url( 'update.php' )
		);
	}
}