<?php
namespace AIOSEO\FourNotFour\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin (de)activation.
 *
 * @since 1.0.0
 */
class Activate {
	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		register_activation_hook( AIOSEO_404_TO_301_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( AIOSEO_404_TO_301_FILE, [ $this, 'deactivate' ] );
	}

	/**
	 * Runs on activation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function activate() {
		aioseo404To301()->access->addCapabilities();

		if ( ! aioseo404To301()->internalOptions->internal->firstActivated ) {
			aioseo404To301()->internalOptions->internal->firstActivated = time();
		}

		// The tables have to exist before the migrator queues anything against them, and dbDelta is
		// normally deferred to an init hook that hasn't fired during an activation request.
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( aioseo404To301()->dbSchema->getSchema() );

		// Schedule the crons for whatever is already configured - on a reactivation that's whatever the
		// user had set, since the settings live in the options and survive deactivation.
		if ( 'none' !== (string) aioseo404To301()->options->cleaner->method ) {
			Cleaner\Cron::activate();
		}

		if ( aioseo404To301()->options->reports->enabled ) {
			Reports\Cron::activate();
		}

		aioseo404To301()->core->cache->clear();
	}

	/**
	 * Runs on deactivation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function deactivate() {
		aioseo404To301()->access->removeCapabilities();

		// Cleared unconditionally, whatever each feature's toggle says: an orphan event would keep
		// firing a handler that is no longer registered.
		Cleaner\Cron::deactivate();
		Reports\Cron::deactivate();

		// Pause any in-flight log migration so reactivating resumes cleanly. No data is removed.
		aioseo404To301()->main->migrator->pause();
	}
}