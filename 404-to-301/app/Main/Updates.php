<?php
namespace AIOSEO\FourNotFour\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles update migrations.
 *
 * @since 1.0.0
 */
class Updates {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		add_action( 'init', [ $this, 'runUpdates' ], 1002 );
		add_action( 'init', [ $this, 'updateLatestVersion' ], 3000 );
	}

	/**
	 * Runs our migrations.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function runUpdates() {
		$lastActiveVersion = aioseo404To301()->internalOptions->internal->lastActiveVersion;
		// Don't run updates if the last active version is the same as the current version.
		if ( aioseo404To301()->version === $lastActiveVersion ) {
			return;
		}

		// dbDelta first: the migrations below read and write through the options and models, which
		// expect their tables to exist.
		$this->updateDbSchema();

		$this->runMigrations();

		// Re-sync role capabilities so plugin caps are only granted to administrators,
		// stripping them from any other role they were previously added to.
		aioseo404To301()->access->removeCapabilities();
	}

	/**
	 * Updates the latest version after all migrations and updates have run.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function updateLatestVersion() {
		if ( aioseo404To301()->internalOptions->internal->lastActiveVersion === aioseo404To301()->version ) {
			return;
		}

		aioseo404To301()->internalOptions->internal->lastActiveVersion = aioseo404To301()->version;

		aioseo404To301()->core->db->bustCache();
		aioseo404To301()->core->cache->delete( 'db_schema' );
	}

	/**
	 * Runs the registered migrations.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	private function runMigrations() {
		$runner = new Migrations\MigrationRunner();

		// v3 first: a 3.x site has no flat option for MigrateFlatSettings to read.
		$runner->register( new Migrations\MigrateV3Settings() );
		$runner->register( new Migrations\MigrateFlatSettings() );
		$runner->register( new Migrations\DiscardLegacyTelegramQueue() );

		// After the settings migrations: it reconciles what those wrote.
		$runner->register( new Migrations\FoldFeatureFlags() );

		$runner->run();
	}

	/**
	 * Synchronizes database schema with defined schema using dbDelta.
	 *
	 * This method uses WordPress's dbDelta() function to automatically:
	 * - Create tables that don't exist
	 * - Add missing columns to existing tables
	 * - Modify column definitions that have changed
	 *
	 * Note: dbDelta CANNOT drop columns or rename columns. Those operations
	 * must be handled separately with custom SQL in version-gated migrations.
	 *
	 * @since 202607
	 *
	 * @return void
	 */
	private function updateDbSchema() {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// dbDelta runs first so the cache table exists before we clear it - on a fresh install the
		// DELETE would otherwise hit a table that hasn't been created yet.
		$schemas = aioseo404To301()->dbSchema->getSchema();
		dbDelta( $schemas );

		$tableName = aioseo404To301()->core->db->db->prefix . 'aioseo_404_to_301_cache';
		aioseo404To301()->core->db->execute(
			"DELETE FROM {$tableName}"
		);

		// Clear the schema cache so columnExists/tableExists reflect the new state.
		aioseo404To301()->core->cache->delete( 'db_schema' );
	}
}