<?php
namespace AIOSEO\FourNotFour\Main\Migrations;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes the storage left behind by the retired `duckdev/wp-queue-process` alert queue.
 *
 * Telegram alerts used to ride an option-backed queue with its own self-healing cron event; they now
 * go through {@see \AIOSEO\FourNotFour\Utils\ActionScheduler}. Anything still parked in that queue is
 * discarded rather than replayed — the payloads are timestamped snapshots of a 404 that has long
 * since stopped being news, and the cron event would otherwise keep firing against a hook nothing
 * listens on.
 *
 * Key names are spelled out here because the library that derived them is gone.
 *
 * @since 4.0.3
 */
class DiscardLegacyTelegramQueue implements Migration {
	/**
	 * The retired queue's fully-qualified identifier.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const LEGACY_IDENTIFIER = 'duckdev_d404_telegram_alerts';

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	public function name() {
		return 'discard_legacy_telegram_queue';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	public function version() {
		return '4.0.3';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function up() {
		// Deleted through the API the queue wrote with, so a persistent object cache doesn't keep
		// serving batches a raw DELETE had already removed from the table.
		foreach ( $this->legacyKeys() as $key ) {
			delete_site_option( $key );
		}

		delete_site_transient( self::LEGACY_IDENTIFIER . '_process_lock' );
		wp_clear_scheduled_hook( self::LEGACY_IDENTIFIER . '_cron' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return bool
	 */
	public function verify() {
		if ( wp_next_scheduled( self::LEGACY_IDENTIFIER . '_cron' ) ) {
			return false;
		}

		return [] === $this->legacyKeys();
	}

	/**
	 * Every batch key the retired queue still has on disk.
	 *
	 * Read straight off the table because each key carries a random suffix. The table mirrors the
	 * library's own resolution: network installs kept the queue in sitemeta, single sites in options.
	 *
	 * @since 4.0.3
	 *
	 * @return array
	 */
	private function legacyKeys() {
		$db     = aioseo404To301()->core->db->db;
		$prefix = $db->esc_like( self::LEGACY_IDENTIFIER . '_batch_' ) . '%';

		// Spelled out per branch rather than interpolated: the batch keys are the only thing that
		// varies, and a literal table/column keeps the query legible to the wp.org scanners.
		if ( is_multisite() ) {
			$keys = $db->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$db->prepare( "SELECT meta_key FROM {$db->sitemeta} WHERE meta_key LIKE %s", $prefix )
			);
		} else {
			$keys = $db->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$db->prepare( "SELECT option_name FROM {$db->options} WHERE option_name LIKE %s", $prefix )
			);
		}

		return array_map( 'strval', (array) $keys );
	}
}