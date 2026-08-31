<?php
/**
 * V3 → V4 data migrator.
 *
 * Migrates the legacy `wp_404_to_301` table (single table holding both
 * log rows and the custom-redirect column) into the two new BerlinDB
 * tables (`logs` + `redirects`).
 *
 * Two phases:
 *
 *   1. Auto — rows that carry a non-empty `redirect` column become
 *      `404_to_301_redirects` rows. Runs unattended (no admin
 *      consent) because moving a handful of custom redirects is the
 *      kind of thing users expect to "just work".
 *
 *   2. Opt-in — every remaining row migrates into `404_to_301_logs`.
 *      Can be thousands of rows on busy sites, so processed in chunks
 *      via {@see Scheduler::queueNextChunk()} until the legacy
 *      table is empty. Triggered by the admin clicking "Start
 *      migration" in the Logs page banner.
 *
 * The legacy table is dropped only when phase 2 completes — until
 * then it is the source of truth, so we can resume cleanly after a
 * deactivation / fatal / crash.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Main\Migration;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Models\Log as LogsModel;
use AIOSEO\FourNotFour\Models\Redirect as RedirectsModel;
use AIOSEO\FourNotFour\Utils\Helpers;

/**
 * Class Migrator
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Migration
 */
class Migrator {

	/**
	 * Rows processed per chunk when phase 2 is running.
	 *
	 * Conservative default that finishes in well under PHP's
	 * `max_execution_time` even on shared hosts.
	 *
	 * @since 4.0.0
	 */
	const CHUNK_SIZE = 200;

	/**
	 * Wire the scheduler hooks.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( Scheduler::ACTION, [ $this, 'runChunk' ] );

		// Self-heal users hit by the early-activation install bug:
		// when `phase1_done` is true but the redirects table still
		// has no rows (or only just got installed), re-run Phase 1
		// once on `admin_init`. Hooked late so BerlinDB has had a
		// chance to install / upgrade the tables earlier in the
		// same `admin_init` cycle.
		add_action( 'admin_init', [ $this, 'selfHealPhase1' ], 99 );
	}

	/**
	 * Bootstrap migration state during activation.
	 *
	 * Called from {@see \AIOSEO\FourNotFour\Setup\Activator::run()}.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function bootstrapOnActivation(): void {
		// Fresh install: nothing to migrate.
		if ( ! $this->legacyTableExists() ) {
			aioseo404To301()->internalOptions->internal->logsMigrated       = true;
			aioseo404To301()->internalOptions->internal->phase1Done         = true;
			aioseo404To301()->internalOptions->internal->legacyTableDropped = true;

			return;
		}

		// Phase 1 — move the custom-redirect rows now (small, cheap).
		// We only flip `phase1_done` when the run reports the v4
		// tables were available; otherwise the `self_heal_phase1()`
		// hook on `admin_init` will retry once they exist.
		if ( ! aioseo404To301()->internalOptions->internal->phase1Done ) {
			$result = $this->runPhase1();

			if ( -1 !== $result ) {
				aioseo404To301()->internalOptions->internal->phase1Done = true;
			}
		}
	}

	/**
	 * Whether both v4 tables are installed.
	 *
	 * The migration writes into them, and an external caller (WP-CLI, a REST hit) can reach this
	 * before the schema has been laid down.
	 *
	 * @since 4.0.4
	 *
	 * @return bool Whether both tables exist.
	 */
	private function tablesExist(): bool {
		$db = aioseo404To301()->core->db;

		return $db->tableExists( '404_to_301_redirects' ) && $db->tableExists( '404_to_301_logs' );
	}

	/**
	 * Re-run Phase 1 on `admin_init` if the activation run failed
	 * before the v4 tables existed.
	 *
	 * Hooked at priority 99 so BerlinDB's own `admin_init` install
	 * hook (priority 10) has already created the tables for us in
	 * the same request.
	 *
	 * No-ops on the common path: when `phase1_done` is true, when
	 * there's nothing legacy to migrate, or when the v4 tables still
	 * aren't installed for some other reason.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function selfHealPhase1(): void {
		if ( aioseo404To301()->internalOptions->internal->phase1Done ) {
			return;
		}

		if ( ! $this->legacyTableExists() ) {
			aioseo404To301()->internalOptions->internal->phase1Done = true;

			return;
		}

		// Tables not yet installed even on this request — try again next time.
		if ( ! $this->tablesExist() ) {
			return;
		}

		$result = $this->runPhase1();

		if ( -1 !== $result ) {
			aioseo404To301()->internalOptions->internal->phase1Done = true;
		}
	}

	/**
	 * Pause queued background jobs without forgetting our state.
	 *
	 * Called from the deactivation handler. Phase 2 resumes on next
	 * activation by checking `logs_migrated` again.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function pause(): void {
		Scheduler::cancelAll();
	}

	/**
	 * Phase 1 — copy every legacy row with a non-empty `redirect`
	 * column into the new redirects table.
	 *
	 * Idempotent: rows whose source already exists are skipped.
	 *
	 * @since 4.0.0
	 *
	 * @return int Number of redirects inserted, or `-1` when the v4
	 *             tables don't exist yet and the caller should retry.
	 */
	public function runPhase1(): int {
		global $wpdb;

		// Bail without flipping `phase1_done`: the v4 redirects table
		// has to exist before we can write into it, and BerlinDB
		// installs it lazily on `admin_init`. The Activator force-
		// installs, but external callers (eg. WP-CLI) may not.
		if ( ! $this->tablesExist() ) {
			return -1;
		}

		// Nothing to read from — fresh installs and sites that have
		// already finished the migration have no legacy table. Without
		// this guard, the SELECT below errors with "table doesn't exist"
		// when a CLI user runs `migrate run` on a clean install.
		if ( ! $this->legacyTableExists() ) {
			return 0;
		}

		$table = $wpdb->prefix . '404_to_301';
		// Table name is built from `$wpdb->prefix` + a fixed literal —
		// no user input ever reaches the SQL string, so safe to interpolate.
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( "SELECT url, redirect, options FROM {$table} WHERE redirect IS NOT NULL AND redirect <> ''" );

		if ( $this->readFailed( 'runPhase1' ) ) {
			return -1;
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		$inserted = 0;

		foreach ( (array) $rows as $row ) {
			$source = (string) $row->url;
			$target = (string) $row->redirect;

			if ( '' === $source || '' === $target ) {
				continue;
			}

			// Skip if the source has already been migrated.
			if ( RedirectsModel::findExact( $source ) ) {
				continue;
			}

			$options = $row->options ? self::unserializeSafely( (string) $row->options ) : [];
			$type    = (int) ( $options['type'] ?? 301 );

			$created = ( new RedirectsModel() )->fill(
				[
					'source'        => $source,
					'match_type'    => 'exact',
					'target_type'   => 'link',
					'target_url'    => $target,
					'redirect_type' => in_array( $type, aioseo404To301()->helpers->redirectStatusCodes(), true ) ? $type : 301,
					'is_active'     => 1,
				]
			);
			$created->save();

			$id = (int) ( $created->id ?? 0 );

			if ( $id > 0 ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Start phase 2 — opt-in chunked log migration.
	 *
	 * Processes one chunk synchronously so the React UI sees immediate
	 * progress, then also queues a background continuation. The React
	 * UI polls `/migration` (which calls {@see Migrator::tick()})
	 * until `remaining` reaches 0 — that means the migration finishes
	 * even on dev environments where wp-cron / AS aren't firing.
	 *
	 * @since 4.0.0
	 *
	 * @return array Migration status snapshot.
	 */
	public function startPhase2(): array {
		if ( ! $this->legacyTableExists() ) {
			aioseo404To301()->internalOptions->internal->logsMigrated = true;

			return $this->status();
		}

		// Mark the migration as actively in progress. `status()` reads
		// this to report `running` — until it's set, the banner stays in
		// its idle "Start migration" state (which is where the optional
		// "Install Action Scheduler" offer lives). This is the single
		// place a phase-2 run is ever kicked off, so it's the canonical
		// point to flip the flag.
		aioseo404To301()->internalOptions->internal->migrationStarted = true;

		// Best-effort: schedule a background continuation so the
		// migration completes even if the admin closes the tab.
		Scheduler::queueNextChunk();

		// Process one chunk inline so the polling loop has fresh
		// numbers to display straight away — and so the migration
		// keeps moving even when wp-cron is broken.
		$this->runChunk();

		return $this->status();
	}

	/**
	 * Process one chunk on demand.
	 *
	 * Driven by the React UI's poll loop. Each POST `/migration`
	 * (after the initial start) calls this, processes one chunk and
	 * returns the updated status.
	 *
	 * @since 4.0.0
	 *
	 * @return array Migration status snapshot after the tick.
	 */
	public function tick(): array {
		$this->runChunk();

		// Keep the background continuation primed in case the tab
		// closes before the next React tick.
		if ( ! aioseo404To301()->internalOptions->internal->logsMigrated && $this->remainingRows() > 0 ) {
			Scheduler::queueNextChunk();
		}

		return $this->status();
	}

	/**
	 * Abort an in-flight migration.
	 *
	 * Cancels the queue and marks the migration as complete (the
	 * legacy table is left in place for forensic / re-try purposes).
	 *
	 * @since 4.0.0
	 *
	 * @return array Status snapshot.
	 */
	public function abort(): array {
		Scheduler::cancelAll();
		aioseo404To301()->internalOptions->internal->logsMigrated = true;

		return $this->status();
	}

	/**
	 * Run a single chunk. Hooked into {@see Scheduler::ACTION}.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function runChunk(): void {
		global $wpdb;

		if ( aioseo404To301()->internalOptions->internal->logsMigrated ) {
			return;
		}

		if ( ! $this->legacyTableExists() ) {
			aioseo404To301()->internalOptions->internal->logsMigrated = true;

			return;
		}

		$table = $wpdb->prefix . '404_to_301';
		// Table name is built from `$wpdb->prefix` + a fixed literal — the
		// LIMIT placeholder is prepared, no user input lands in the SQL.
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, url, ref, ip, ua, date, options FROM {$table} ORDER BY id ASC LIMIT %d", self::CHUNK_SIZE ) );

		// An unreadable table also comes back as an empty result, so the error has to be ruled out
		// before "no rows" can be read as "finished" — finalise() drops the table.
		if ( $this->readFailed( 'runChunk' ) ) {
			return;
		}

		if ( empty( $rows ) ) {
			$this->finalise();

			return;
		}

		$processedIds = [];

		foreach ( (array) $rows as $row ) {
			// The legacy plugin stored "N/A" (any casing) when the
			// referrer header was missing. Normalise that back to an
			// empty string so the React layer can render its standard
			// em-dash placeholder instead of a literal "N/A".
			$ref = (string) $row->ref;
			if ( 'n/a' === strtolower( trim( $ref ) ) ) {
				$ref = '';
			}

			// v3 persisted per-log overrides in a serialised `options`
			// blob with keys `redirect` / `log` / `alert` and tri-state
			// values `-1` (use global), `1` (enable), `0` (disable). v4
			// only carries `redirect` and `email` overrides — the `log`
			// override is dropped in v4, so we silently discard it.
			$overrides = $this->mapLegacyOverrides( $row->options ?? null );

			LogsModel::recordHit(
				array_merge(
					[
						'url'        => (string) $row->url,
						'ref'        => $ref,
						'ip'         => aioseo404To301()->helpers->packIp( (string) $row->ip ),
						'ua'         => (string) $row->ua,
						'method'     => 'GET',
						'created_at' => $row->date ? $row->date : current_time( 'mysql', true ),
					],
					$overrides
				)
			);

			$processedIds[] = (int) $row->id;
		}

		// Delete the rows we just migrated so the next chunk picks
		// up the next batch and we resume cleanly across restarts.
		if ( ! empty( $processedIds ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $processedIds ), '%d' ) );
			// `$placeholders` is a hand-built list of `%d` markers — each id
			// then flows through `$wpdb->prepare`, so the final SQL is fully
			// parameterised even though the sniff can't see through the
			// dynamic IN(…) string.
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $processedIds ) );

			// Without this the same chunk is read, re-inserted and re-deleted forever.
			if ( $this->readFailed( 'runChunk delete' ) ) {
				return;
			}
		}

		// More rows to process? Queue the next chunk; otherwise tidy up. A negative count means the
		// table couldn't be read, so neither branch is safe — stop and let the retry pick it up.
		$remaining = $this->remainingRows();

		if ( $remaining < 0 ) {
			return;
		}

		if ( $remaining > 0 ) {
			Scheduler::queueNextChunk();
		} else {
			$this->finalise();
		}
	}

	/**
	 * Build a snapshot of the migration state for the React banner.
	 *
	 * @since 4.0.0
	 *
	 * @return array
	 */
	public function status(): array {
		// `running` means a phase-2 migration has actually been started
		// and hasn't finished yet — NOT merely that legacy data is
		// present. Conflating the two (the old behaviour) made the idle
		// banner state unreachable, hiding the "Start migration" /
		// "Install Action Scheduler" offer, because the banner only
		// shows at all when legacy data is present and unmigrated.
		$running = (bool) aioseo404To301()->internalOptions->internal->migrationStarted
			&& ! aioseo404To301()->internalOptions->internal->logsMigrated;

		return [
			'phase1_done'    => (bool) aioseo404To301()->internalOptions->internal->phase1Done,
			'logs_migrated'  => (bool) aioseo404To301()->internalOptions->internal->logsMigrated,
			'legacy_present' => $this->legacyTableExists(),
			'remaining'      => $this->remainingRows(),
			'running'        => $running,
			'has_as'         => Scheduler::hasActionScheduler(),
			'can_install_as' => current_user_can( 'install_plugins' )
				&& ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
			'install_as_url' => Scheduler::installAsUrl(),
			'last_error'     => (string) aioseo404To301()->internalOptions->internal->migrationLastError,
		];
	}

	/**
	 * Whether the legacy v3 table is in the database.
	 *
	 * @since 4.0.0
	 *
	 * @return bool
	 */
	public function legacyTableExists(): bool {
		global $wpdb;

		$table = $wpdb->prefix . '404_to_301';

		// Probe the table with a real `SELECT` and treat "no error" as
		// "exists". We deliberately avoid the obvious alternatives.
		// `SHOW TABLES LIKE` needs `esc_like()` for the underscores in
		// the name, and the round-trip through `wpdb::prepare()`
		// re-escapes the backslashes — the pattern then fails to match
		// on the MySQL 8 image CI uses, reporting the table missing when
		// it's there. `information_schema` had its own failure mode on
		// the same runner. A bare `DESCRIBE` works on MySQL but is not
		// portable: under SQLite (WordPress Playground, the SQLite
		// Database Integration plugin) it isn't translated into something
		// that errors on a missing table, so `last_error` stays empty and
		// the table is reported present on a fresh install — the
		// migration banner then shows with "0 rows" on every Playground
		// install. A real `SELECT ... LIMIT 1` errors on a missing table
		// on BOTH MySQL and SQLite, so the `last_error` check is reliable
		// on each. Errors are suppressed so a missing table doesn't leak
		// a notice.
		$previousSuppress = $wpdb->suppress_errors( true );
		$previousShow     = $wpdb->show_errors( false );
		$wpdb->last_error = '';

		// `$table` is built from `$wpdb->prefix` + a fixed literal — no
		// user input, safe to interpolate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 1" );

		$exists = '' === (string) $wpdb->last_error;

		$wpdb->suppress_errors( $previousSuppress );
		$wpdb->show_errors( $previousShow );
		$wpdb->last_error = '';

		return $exists;
	}

	/**
	 * How many rows are left in the legacy table.
	 *
	 * NOTE: returns -1 when the count can't be read, so a failure isn't reported as "nothing left".
	 *
	 * @since 4.0.0
	 *
	 * @return int
	 */
	public function remainingRows(): int {
		global $wpdb;

		if ( ! $this->legacyTableExists() ) {
			return 0;
		}

		$table = $wpdb->prefix . '404_to_301';

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( null === $count || '' !== (string) $wpdb->last_error ) {
			return -1;
		}

		return (int) $count;
	}

	/**
	 * Whether the last legacy-table read errored, recording the reason when it did.
	 *
	 * `wpdb::get_results()` returns an empty array both for "no rows" and for a failed query, and the
	 * migration treats "no rows" as "done" — which drops the legacy table. A crashed index, a schema
	 * variant missing a column or a restrictive grant would therefore destroy the data it was meant
	 * to move, so every read has to rule the error out first.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $context Method name, recorded with the error for support.
	 * @return bool            True when the read failed and the caller must bail.
	 */
	private function readFailed( string $context ): bool {
		global $wpdb;

		$error = (string) $wpdb->last_error;

		if ( '' === $error ) {
			$stored = (string) aioseo404To301()->internalOptions->internal->migrationLastError;
			if ( '' !== $stored ) {
				aioseo404To301()->internalOptions->internal->migrationLastError = '';
			}

			return false;
		}

		aioseo404To301()->internalOptions->internal->migrationLastError = sprintf( '%s: %s', $context, $error );

		return true;
	}

	/**
	 * Map a v3 `options` blob onto v4's `override_*` columns.
	 *
	 * V3 stored `redirect` / `log` / `alert` keys with tri-state values
	 * `-1` (use global / unset sentinel), `1` (enable), `0` (disable).
	 * V4 keeps `redirect` (→ `override_redirect`) and `alert`
	 * (→ `override_email`) and drops `log` entirely (the per-log "stop
	 * logging" override is gone in v4 — `exclude_paths` covers that
	 * use case, and the override was never wired to anything at
	 * runtime in v4 pre-release).
	 *
	 * Anything outside `{-1, 0, 1}` collapses to GLOBAL — matches v3's
	 * own runtime check (`in_array($val, [0, 1])`) and keeps unknown
	 * future values from persisting as garbage.
	 *
	 * @since 4.0.0
	 *
	 * @param mixed $raw Raw value from the legacy `options` column —
	 *                   typically a serialised PHP array, possibly
	 *                   already unserialised, or null/empty.
	 *
	 * @return array Subset of `override_redirect` / `override_email`
	 *               keys, omitted entirely when the input is empty so
	 *               the row falls back to the column defaults.
	 */
	private function mapLegacyOverrides( $raw ): array {
		if ( empty( $raw ) ) {
			return [];
		}

		$options = is_array( $raw ) ? $raw : self::unserializeSafely( (string) $raw );

		if ( ! is_array( $options ) ) {
			return [];
		}

		$map = static function ( $value ): int {
			$value = (int) $value;
			if ( 1 === $value ) {
				return LogsModel::OVERRIDE_ENABLE;
			}
			if ( 0 === $value ) {
				return LogsModel::OVERRIDE_DISABLE;
			}

			return LogsModel::OVERRIDE_GLOBAL;
		};

		$mapped = [];

		if ( array_key_exists( 'redirect', $options ) ) {
			$mapped['override_redirect'] = $map( $options['redirect'] );
		}

		if ( array_key_exists( 'alert', $options ) ) {
			$mapped['override_email'] = $map( $options['alert'] );
		}

		return $mapped;
	}

	/**
	 * Finish the migration: drop the legacy table, flag completion,
	 * fire the completion action.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	private function finalise(): void {
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}404_to_301" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Legacy options become safe to delete once their data has
		// been migrated.
		delete_option( 'i4t3_gnrl_options' );
		delete_option( 'i4t3_db_version' );
		delete_option( 'i4t3_version_no' );
		delete_option( 'i4t3_review_notice' );
		delete_option( 'i4t3_activated_time' );

		aioseo404To301()->internalOptions->internal->logsMigrated       = true;
		aioseo404To301()->internalOptions->internal->legacyTableDropped = true;

		/**
		 * Fires once the migration finishes successfully.
		 *
		 * @since 4.0.0
		 */
		do_action( '404_to_301_migration_complete' );
	}

	/**
	 * Unserialize a legacy `options` value without allowing object instantiation.
	 *
	 * The column only ever held scalars and arrays, so refusing classes closes the
	 * object-injection path a poisoned legacy row would otherwise open.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $raw The stored value.
	 * @return mixed       The decoded value, or an empty array when it isn't usable.
	 */
	private static function unserializeSafely( string $raw ) {
		if ( ! is_serialized( $raw ) ) {
			return $raw;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$value = @unserialize( $raw, [ 'allowed_classes' => false ] );

		return false === $value && 'b:0;' !== $raw ? [] : $value;
	}
}