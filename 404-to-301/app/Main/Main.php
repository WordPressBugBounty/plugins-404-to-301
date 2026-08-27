<?php
namespace AIOSEO\FourNotFour\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the core features.
 *
 * Anything gated on an opt-in feature is wired in {@see self::features()}, so a site that hasn't
 * opted in registers no cron events, request listeners or admin-post handlers for it.
 *
 * @since 4.0.3
 */
class Main {
	/**
	 * The v3 -> v4 log migrator.
	 *
	 * @since 4.0.3
	 *
	 * @var Migration\Migrator
	 */
	public $migrator;

	/**
	 * Class constructor.
	 *
	 * @since 4.0.3
	 */
	public function __construct() {
		new Activate();

		$this->migrator = new Migration\Migrator();

		// Auto-creates a 301 when a published post's permalink changes. Self-gates on its option, and
		// runs on every request so it catches the block editor, classic editor and WP-CLI alike.
		new SlugMonitor();

		$this->front();
		$this->features();
	}

	/**
	 * Boots the front-end 404 pipeline.
	 *
	 * NOTE: inside wp-admin this only boots when "track admin 404s" is on. A request to a
	 * non-existent path under /wp-admin/ doesn't resolve to an admin PHP file, so it falls through
	 * here with is_admin() false - the controller re-checks, this is just the cheap early exit.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	private function front() {
		if ( is_admin() && ! aioseo404To301()->options->general->trackAdmin404 ) {
			return;
		}

		new Front\Controller();
	}

	/**
	 * Boots the parts that only run once configured.
	 *
	 * Each of these is gated on its own setting rather than a separate opt-in flag, so "off" is
	 * expressed once, in the panel where the thing is configured.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	private function features() {
		// The exporter is a download handler behind a capability check - nothing to schedule and
		// nothing to gate, so it's always available.
		Exporter\Download::instance()->register();

		if ( 'none' !== (string) aioseo404To301()->options->cleaner->method ) {
			Cleaner\Cron::instance()->register();
		}

		if ( aioseo404To301()->options->reports->enabled ) {
			Reports\Cron::instance()->register();
		}

		/*
		 * Telegram listens on the log-insert and pre-redirect actions, which fire on the front end and
		 * - when trackAdmin404 is on - inside wp-admin too, so it boots here rather than with the
		 * front controller.
		 */
		if ( Telegram\Connection::exists() ) {
			Telegram\Listener::instance()->register();
		}
	}
}