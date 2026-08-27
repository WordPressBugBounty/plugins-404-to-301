<?php
/**
 * Log 404 hits to the database.
 *
 * Inserts a new row in `404_to_301_logs` for each fresh 404, or bumps
 * the `hits` counter on the existing row when the URL is already
 * tracked. Honours the plugin's "skip bots" and "skip duplicates"
 * settings.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Main\Front\Actions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Models\Log as LogRow;
use AIOSEO\FourNotFour\Main\Front\Request;
use AIOSEO\FourNotFour\Models\Log as LogModel;
use AIOSEO\FourNotFour\Utils\Helpers;

/**
 * Class Log
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Front\Actions
 */
class Log extends Action {

	/**
	 * Whether this action should fire for the current request.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 *
	 * @return bool
	 */
	protected function shouldRun( Request $request ): bool {
		if ( ! $this->setting( 'logs.enabled', true ) ) {
			return false;
		}

		if ( $request->isExcluded() ) {
			return false;
		}

		// A cron spawn is the same 404 arriving a second time, not a second visit.
		if ( $request->isCronSpawn() ) {
			return false;
		}

		// Skip obvious bots when configured to do so.
		if ( $this->setting( 'logs.skipBots', true ) && ! aioseo404To301()->helpers->isHuman( $request->userAgent() ) ) {
			return false;
		}

		// `logs_skip_duplicates` means: when a row already exists for
		// this URL, do NOT bump the counter — leave it alone entirely.
		if ( $this->setting( 'logs.skipDuplicates', false ) && null !== $request->log() ) {
			return false;
		}

		// Standalone custom redirects "consume" the request: if there's
		// a matching active redirect row that's NOT linked back to an
		// existing log, the URL is treated as routed (not a 404 to
		// catalogue). Linked redirects keep logging because the log row
		// is the triage record — we want hit counts there.
		$existing = $request->log();
		$row      = $request->redirect();
		if ( null === $existing && $row && 1 === (int) $row->is_active ) {
			return false;
		}

		return true;
	}

	/**
	 * Run the action — insert or bump the log row.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 *
	 * @return void
	 */
	public function run( Request $request ): void {
		if ( ! $this->shouldRun( $request ) ) {
			return;
		}

		$data = [
			'url'    => $request->url(),
			'ref'    => $request->referer(),
			'ip'     => aioseo404To301()->helpers->packIp( $request->ip() ),
			'ua'     => $request->userAgent(),
			'method' => $request->method(),
		];

		/**
		 * Filter the log row data before it is written to the DB.
		 *
		 * @since 4.0.0
		 *
		 * @param array   $data    Column => value.
		 * @param Request $request Current request.
		 */
		$data = (array) apply_filters( '404_to_301_pre_log_insert', $data, $request );

		// Reuse the row already memoised on the Request (looked up in
		// `should_run()` for `logs_skip_duplicates`, or by Email's
		// threshold check) so `record_hit` doesn't re-fetch it. The
		// `prefetched=true` flag matters when the lookup legitimately
		// returned null — without it, `record_hit` can't distinguish
		// "no row exists" from "caller didn't fetch" and re-queries.
		$existing = $request->log();
		$id       = LogModel::recordHit( $data, $existing, true );

		// Keep the Request's memoised log in sync with what's now on
		// disk, without forcing Email's `should_run()` to re-SELECT.
		if ( $existing instanceof LogRow ) {
			++$existing->hits;
			$request->setLog( $existing );
		} else {
			// New row — refresh once so downstream actions see the
			// real id / hits / timestamps.
			$request->refreshLog();
		}

		/**
		 * Fires after a log row has been written.
		 *
		 * @since 4.0.0
		 *
		 * @param int     $id      Log row id (0 on failure).
		 * @param array   $data    Row data that was written.
		 * @param Request $request Current request.
		 */
		do_action( '404_to_301_post_log_insert', $id, $data, $request );
	}
}