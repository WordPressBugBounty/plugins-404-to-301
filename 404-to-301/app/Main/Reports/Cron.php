<?php
/**
 * Cron scheduling for the digest dispatcher.
 *
 * A single cron event drives the report — its recurrence is taken from
 * the `email_reports_frequency` setting and resolved by
 * {@see self::desiredRecurrence()}.
 *
 * Scheduling is owned by two explicit entry points — plugin activation
 * and the settings-save listener — so the hot `init` path doesn't write
 * to the cron option on every request. A lightweight self-heal on
 * `init` only fires when state has actually drifted (event missing,
 * wrong recurrence, or duplicate entries detected), and is gated by a
 * short transient lock so concurrent requests can't race each other
 * into {@see wp_schedule_event()}.
 *
 * Every reschedule starts with {@see wp_clear_scheduled_hook()} so
 * stale duplicates left behind by an earlier race (or by a third-party
 * tool) are wiped before the fresh event is written.
 *
 * @package AIOSEO\FourNotFour\Reports
 */

namespace AIOSEO\FourNotFour\Main\Reports;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron
 *
 * @since 4.0.3
 */
final class Cron {

	/**
	 * Cron action name for the report tick.
	 *
	 * The string is unchanged from the standalone Email Reports addon so
	 * an event already sitting in the cron option on an upgraded site
	 * keeps firing (and {@see self::deactivate()} can still clear it).
	 *
	 * @since 4.0.3
	 */
	const HOOK = 'd404_email_reports_tick';

	/**
	 * Allowed digest frequencies.
	 *
	 * @since 4.0.4
	 *
	 * @var array
	 */
	const FREQUENCIES = [ 'daily', 'weekly', 'monthly' ];

	/**
	 * Transient key used to serialise concurrent self-heals.
	 *
	 * Short-lived (30s) on purpose — long enough to cover the option
	 * read / clear / write sequence, short enough that a crashed
	 * request can't lock scheduling out for long.
	 *
	 * @since 4.0.3
	 */
	const LOCK = 'd404_email_reports_cron_lock';

	/**
	 * Singleton instance.
	 *
	 * @since 4.0.3
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the shared instance.
	 *
	 * @since 4.0.3
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the WordPress hooks owned by this class.
	 *
	 * Scheduling itself is driven by activation and by the settings
	 * listener. The `init` callback only self-heals drift and is
	 * deliberately cheap on the happy path.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK, [ Reporter::class, 'run' ] );

		// Cheap self-heal: only acts when state is actually wrong, and
		// takes a transient lock before touching the cron option.
		add_action( 'init', [ $this, 'selfHeal' ], 20 );

		// Authoritative reschedule when the user saves settings.
		add_action( 'updated_option', [ $this, 'maybeReschedule' ] );
	}

	/**
	 * Register the custom `weekly` and `monthly` schedules.
	 *
	 * WP-Cron ships hourly / twicedaily / daily out of the box; we add
	 * weekly and monthly so the digest frequency picker can offer a
	 * full set of round intervals without a third-party schedule plugin.
	 *
	 * "Monthly" is normalised to a flat 30-day interval rather than a
	 * calendar month so the cron arithmetic stays portable across DST
	 * and short / long months — close enough for a digest.
	 *
	 * @since 4.0.3
	 *
	 * @param array $schedules Existing schedule map.
	 *
	 * @return array
	 */
	public function schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once Weekly', '404-to-301' ),
			];
		}

		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = [
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly', '404-to-301' ),
			];
		}

		return $schedules;
	}

	/**
	 * Activation handler — wired from the bootstrap.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::instance()->reschedule();
	}

	/**
	 * Deactivation handler — wired from the bootstrap.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Wipes every entry for this hook in one shot — safer than the
		// older `wp_unschedule_event( $timestamp, $hook )` form, which
		// only removes the single entry at a specific timestamp.
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Self-heal the cron event on `init`.
	 *
	 * Cheap on the happy path: just a few constant-time lookups against
	 * the already-loaded cron array. Only enters the locked rewrite
	 * branch when state has genuinely drifted — event missing when one
	 * is expected, wrong recurrence, an event present when reports are
	 * disabled, or duplicate entries left behind by an earlier race.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function selfHeal(): void {
		$desired = $this->desiredRecurrence();
		$next    = wp_next_scheduled( self::HOOK );

		if ( null === $desired ) {
			// Reports disabled — there shouldn't be any event at all.
			if ( false === $next ) {
				return;
			}
			$this->lockedReschedule();

			return;
		}

		$current = wp_get_schedule( self::HOOK );
		$count   = $this->countScheduled();

		$drifted = ( false === $next )
			|| ( $current !== $desired )
			|| ( $count > 1 );

		if ( $drifted ) {
			$this->lockedReschedule();
		}
	}

	/**
	 * React to settings being saved.
	 *
	 * The parent plugin stores all settings under a single option, so
	 * we only need to listen for changes to that option name and only
	 * act when one of the keys we care about actually changed.
	 *
	 * @since 4.0.3
	 *
	 * @param string $option Option name being updated.
	 *
	 * @return void
	 */
	public function maybeReschedule( string $option ): void {
		if ( 'aioseo_404_to_301_options' !== $option ) {
			return;
		}

		// Compare the recurrence we want against the one that's scheduled rather than diffing the raw
		// option: the stored shape is the options trait's values array, and this also avoids pushing
		// the next run back every time an unrelated setting is saved.
		if ( (string) $this->desiredRecurrence() === (string) wp_get_schedule( self::HOOK ) ) {
			return;
		}

		$this->reschedule();
	}

	/**
	 * Rewrite the cron entry from a known-clean state.
	 *
	 * Always clears every existing entry for the hook before writing,
	 * so duplicates from any source (race, third-party tool, manual DB
	 * edit) are eliminated rather than just incremented.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function reschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );

		$desired = $this->desiredRecurrence();
		if ( null === $desired ) {
			// Reports disabled — leaving the hook unscheduled is the
			// correct end state.
			return;
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, $desired, self::HOOK );
	}

	/**
	 * Reschedule under a short transient lock.
	 *
	 * The lock isn't a hard mutex (transients aren't atomic across all
	 * object-cache backends), but it dramatically narrows the window
	 * where two concurrent `init` requests can both race into
	 * `wp_schedule_event`. Combined with the `wp_clear_scheduled_hook`
	 * inside {@see self::reschedule()}, the worst residual case is
	 * "rewrite executed twice with the same end state" rather than
	 * "two entries persisted forever".
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	private function lockedReschedule(): void {
		if ( false !== get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, 30 );

		try {
			$this->reschedule();
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Count how many entries exist for our hook in the cron array.
	 *
	 * Reads `_get_cron_array()` directly because there's no public WP
	 * API that exposes a hook's full multiplicity — `wp_next_scheduled`
	 * collapses everything down to a single timestamp and hides dupes.
	 *
	 * The leading underscore on `_get_cron_array` flags it as internal,
	 * but it's the canonical reader used throughout core itself and
	 * has been stable since 2.1.0 — safer than reading the `cron`
	 * option directly and re-implementing its versioning rules.
	 *
	 * @since 4.0.3
	 *
	 * @return int
	 */
	private function countScheduled(): int {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return 0;
		}

		$crons = _get_cron_array();
		if ( empty( $crons ) || ! is_array( $crons ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $crons as $events ) {
			if ( isset( $events[ self::HOOK ] ) && is_array( $events[ self::HOOK ] ) ) {
				$count += count( $events[ self::HOOK ] );
			}
		}

		return $count;
	}

	/**
	 * Resolve the recurrence the cron event should be running at.
	 *
	 * Returns `null` when reports are disabled so callers can
	 * distinguish "no event wanted" from "event wanted at a specific
	 * recurrence".
	 *
	 * @since 4.0.3
	 *
	 * @return string|null A registered WP-Cron recurrence slug, or
	 *                     `null` when no event should be scheduled.
	 */
	private function desiredRecurrence(): ?string {
		if ( ! aioseo404To301()->options->reports->enabled ) {
			return null;
		}

		$frequency = (string) aioseo404To301()->options->reports->frequency;
		if ( in_array( $frequency, self::FREQUENCIES, true ) ) {
			return $frequency;
		}

		return 'weekly';
	}
}