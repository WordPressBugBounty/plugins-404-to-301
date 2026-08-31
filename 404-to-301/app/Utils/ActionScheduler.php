<?php
namespace AIOSEO\FourNotFour\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This class makes sure the Action Scheduler tables always exist.
 *
 * @since 1.0.0
 */
class ActionScheduler {
	/**
	 * The Action Scheduler group.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $actionSchedulerGroup = 'aioseo_404_to_301';

	/**
	 * Intervals we've minted a WP-Cron recurrence for during this request.
	 *
	 * @since 4.0.4
	 *
	 * @var array
	 */
	private $mintedCronIntervals = [];

	/**
	 * WP-Cron hook prefixes this plugin owns.
	 *
	 * @since 4.0.4
	 *
	 * @var array
	 */
	private $cronHookPrefixes = [ 'aioseo_404_to_301_', 'd404_', '404_to_301_' ];

	/**
	 * Prefixes that match one of ours but belong to a plugin running its own scheduler.
	 *
	 * NOTE: only non-empty for the main plugin, whose `aioseo_` also matches every addon. Addons
	 * without their own copy of this file schedule into the main plugin's group, so draining their
	 * hooks is correct - it's the ones that track their own mode that must be left alone.
	 *
	 * @since 4.0.4
	 *
	 * @var array
	 */
	private $foreignCronHookPrefixes = [];

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		add_filter( 'cron_schedules', [ $this, 'addCronSchedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

		// The drain deletes rows, so it's kept off front-end requests and out of useCron(), which
		// callers treat as a cheap read and hit in loops.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'init', [ $this, 'maybeDrainOnSwitch' ], 5 );
		}

		// Cron-only sites never touch Action Scheduler, so neither of these is wanted: cleanup()
		// queries its tables and maybeRecreateTables() creates them.
		if ( self::isCronOnly() ) {
			return;
		}

		add_action( 'action_scheduler_after_execute', [ $this, 'cleanup' ], 1000, 2 );

		// Note: \ActionScheduler is first loaded on `plugins_loaded` action hook.
		add_action( 'plugins_loaded', [ $this, 'maybeRecreateTables' ] );
	}

	/**
	 * Maybe register the `{$table_prefix}_actionscheduler_{$suffix}` tables with WordPress and create them if needed.
	 * Hooked into `plugins_loaded` action hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybeRecreateTables() {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Action Scheduler's own filter.
		if ( ! apply_filters( 'action_scheduler_enable_recreate_data_store', true ) ) {
			return;
		}

		if (
			! class_exists( 'ActionScheduler' ) ||
			! class_exists( 'ActionScheduler_HybridStore' ) ||
			! class_exists( 'ActionScheduler_StoreSchema' ) ||
			! class_exists( 'ActionScheduler_LoggerSchema' )
		) {
			return;
		}

		$store = \ActionScheduler::store();

		if ( ! is_a( $store, 'ActionScheduler_HybridStore' ) ) {
			$store = new \ActionScheduler_HybridStore();
		}

		$tableList = [
			'actionscheduler_actions',
			'actionscheduler_logs',
			'actionscheduler_groups',
			'actionscheduler_claims',
		];

		foreach ( $tableList as $tableName ) {
			if ( ! aioseo404To301()->core->db->tableExists( $tableName ) ) {
				add_action( 'action_scheduler/created_table', [ $store, 'set_autoincrement' ], 10, 2 );

				$storeSchema  = new \ActionScheduler_StoreSchema();
				$loggerSchema = new \ActionScheduler_LoggerSchema();
				$storeSchema->register_tables( true );
				$loggerSchema->register_tables( true );

				remove_action( 'action_scheduler/created_table', [ $store, 'set_autoincrement' ] );

				break;
			}
		}
	}

	/**
	 * Cleans up the Action Scheduler tables after one of our actions completes.
	 * Hooked into `action_scheduler_after_execute` action hook.
	 *
	 * @since 1.0.0
	 *
	 * @param  int                     $actionId The action ID processed.
	 * @param  \ActionScheduler_Action $action   Class instance.
	 * @return void
	 */
	public function cleanup( $actionId, $action ) {
		if (
			// Bail if this isn't one of our actions or if we're in a dev environment.
			$this->actionSchedulerGroup !== $action->get_group() ||
			defined( 'AIOSEO_404_TO_301_DEV_VERSION' ) ||
			// Bail if the tables don't exist.
			! aioseo404To301()->core->db->tableExists( 'actionscheduler_actions' ) ||
			! aioseo404To301()->core->db->tableExists( 'actionscheduler_groups' )
		) {
			return;
		}

		$prefix = aioseo404To301()->core->db->db->prefix;

		// Clean up logs associated with entries in the actions table.
		aioseo404To301()->core->db->execute(
			"DELETE al FROM {$prefix}actionscheduler_logs as al
			JOIN {$prefix}actionscheduler_actions as aa on `aa`.`action_id` = `al`.`action_id`
			JOIN {$prefix}actionscheduler_groups as ag on `ag`.`group_id` = `aa`.`group_id`
			WHERE `ag`.`slug` = '{$this->actionSchedulerGroup}'
			AND `aa`.`status` IN ('complete', 'failed', 'canceled');"
		);

		// Clean up actions.
		aioseo404To301()->core->db->execute(
			"DELETE aa FROM {$prefix}actionscheduler_actions as aa
			JOIN {$prefix}actionscheduler_groups as ag on `ag`.`group_id` = `aa`.`group_id`
			WHERE `ag`.`slug` = '{$this->actionSchedulerGroup}'
			AND `aa`.`status` IN ('complete', 'failed', 'canceled');"
		);

		// Clean up logs where there was no group.
		aioseo404To301()->core->db->execute(
			"DELETE al FROM {$prefix}actionscheduler_logs as al
			JOIN {$prefix}actionscheduler_actions as aa on `aa`.`action_id` = `al`.`action_id`
			WHERE `aa`.`hook` LIKE '{$this->actionSchedulerGroup}_%'
			AND `aa`.`group_id` = 0
			AND `aa`.`status` IN ('complete', 'failed', 'canceled');"
		);

		// Clean up actions that start with aioseo_ and have no group.
		aioseo404To301()->core->db->execute(
			"DELETE aa FROM {$prefix}actionscheduler_actions as aa
			WHERE `aa`.`hook` LIKE '{$this->actionSchedulerGroup}_%'
			AND `aa`.`group_id` = 0
			AND `aa`.`status` IN ('complete', 'failed', 'canceled');"
		);
	}

	/**
	 * Schedules a single action at a specific time in the future.
	 * @NOTE: This method differs from the one in the main plugin!
	 *
	 * @since   1.0.0
	 * @version 4.0.4 Added the WP-Cron fallback.
	 *
	 * @param  string  $actionName The action name.
	 * @param  int     $time       The time to add to the current time.
	 * @param  array   $args       Args passed down to the action.
	 * @return boolean             Whether the action was scheduled.
	 */
	public function scheduleSingle( $actionName, $time, $args = [] ) {
		try {
			if ( empty( $this->getPendingActions( $actionName, $args ) ) ) {
				if ( $this->useCron() ) {
					return (bool) wp_schedule_single_event( time() + $time, $actionName, $args );
				}

				as_schedule_single_action( time() + $time, $actionName, $args, $this->actionSchedulerGroup );

				return true;
			}
		} catch ( \RuntimeException $e ) {
			// Nothing needs to happen.
		}

		return false;
	}

	/**
	 * Checks if a given action is already scheduled.
	 *
	 * @since   1.0.0
	 * @version 4.0.4 Now delegates to hasScheduled(), so it honours the WP-Cron fallback.
	 *
	 * @param  string  $actionName The action name.
	 * @param  array   $args       Args passed down to the action.
	 * @return boolean             Whether the action is already scheduled.
	 */
	public function isScheduled( $actionName, $args = [] ) {
		return $this->hasScheduled( $actionName, $args );
	}

	/**
	 * Returns the running actions for a given action.
	 *
	 * @since   1.0.0
	 * @version 4.0.4 Reports nothing under the WP-Cron fallback, which has no running state.
	 *
	 * @param  string $actionName The action name.
	 * @param  array  $args       Args passed down to the action.
	 * @return array              The actions.
	 */
	public function getRunningActions( $actionName, $args = [] ) {
		// WP-Cron unschedules an event before it fires, so it has no running state at all - an empty
		// result here is accurate rather than unknown.
		if ( $this->useCron() || ! class_exists( 'ActionScheduler_Store' ) ) {
			return [];
		}

		$runningArgs = [
			'args'     => $args,
			'hook'     => $actionName,
			'status'   => \ActionScheduler_Store::STATUS_RUNNING,
			'per_page' => 1
		];

		return $this->getScheduledActions( $runningArgs );
	}

	/**
	 * Returns the pending actions for a given action.
	 *
	 * @since   1.0.0
	 * @version 4.0.4 Now honours the WP-Cron fallback.
	 *
	 * @param  string $actionName The action name.
	 * @param  array  $args       Args passed down to the action.
	 * @return array              The actions.
	 */
	public function getPendingActions( $actionName, $args = [] ) {
		if ( $this->useCron() ) {
			return $this->getScheduledActions( [
				'hook' => $actionName,
				'args' => $args
			] );
		}

		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			return [];
		}

		$pendingArgs = [
			'args'     => $args,
			'hook'     => $actionName,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1
		];

		return $this->getScheduledActions( $pendingArgs );
	}

	/**
	 * Unschedule an action.
	 *
	 * @since   1.0.0
	 * @version 4.0.4 Now delegates to unscheduleAll(), so it honours the WP-Cron fallback.
	 *
	 * @param  string $actionName The action name to unschedule.
	 * @param  array  $args       Args passed down to the action.
	 * @return void
	 */
	public function unschedule( $actionName, $args = [] ) {
		$this->unscheduleAll( $actionName, $args );
	}

	/**
	 * Schedules a recurring action.
	 *
	 * @since   1.0.0
	 * @version 4.0.4 Added the WP-Cron fallback.
	 *
	 * @param  string  $actionName The action name.
	 * @param  int     $time       The seconds to add to the current time.
	 * @param  int     $interval   The interval in seconds.
	 * @param  array   $args       Args passed down to the action.
	 * @return boolean             Whether the action was scheduled.
	 */
	public function scheduleRecurrent( $actionName, $time, $interval = 60, $args = [] ) {
		try {
			if ( ! $this->isScheduled( $actionName ) ) {
				if ( $this->useCron() ) {
					return (bool) wp_schedule_event( time() + $time, $this->cronSchedule( $interval ), $actionName, $args );
				}

				as_schedule_recurring_action( time() + $time, $interval, $actionName, $args, $this->actionSchedulerGroup );

				return true;
			}
		} catch ( \RuntimeException $e ) {
			// Nothing needs to happen.
		}

		return false;
	}

	/**
	 * Schedule a single async action.
	 *
	 * @since   1.0.0
	 * @version 4.0.4 Added the WP-Cron fallback.
	 *
	 * @param  string $actionName The name of the action.
	 * @param  array  $args       Any relevant arguments.
	 * @return void
	 */
	public function scheduleAsync( $actionName, $args = [] ) {
		try {
			if ( $this->useCron() ) {
				// Cron has no async dispatch, so the nearest equivalent is a single event one
				// second out - it fires on the next request that triggers cron.
				wp_schedule_single_event( time() + 1, $actionName, $args );
			} else {
				// Run the task immediately using an async action.
				as_enqueue_async_action( $actionName, $args, $this->actionSchedulerGroup );
			}
		} catch ( \Exception $e ) {
			// Do nothing.
		}
	}

	/**
	 * Whether to use WP-Cron instead of Action Scheduler.
	 *
	 * Off by default. Support can switch a single plugin over when a site's Action Scheduler is
	 * misbehaving - a half-migrated store, a disabled runner, or object-cache interference.
	 *
	 * NOTE: this is not a like-for-like swap. WP-Cron has no retries, no claim locking and no
	 * queryable action store, and it fires on page views rather than on its own runner. It also does
	 * not help when loopback or WP-Cron itself is broken, since Action Scheduler leans on the same
	 * machinery - reach for it when the store or runner is at fault, not when cron is dead.
	 *
	 * @since 4.0.4
	 *
	 * @return bool Whether the WP-Cron fallback is active.
	 */
	public function useCron() {
		if ( self::isCronOnly() ) {
			return true;
		}

		/**
		 * Filters whether to fall back to WP-Cron instead of Action Scheduler.
		 *
		 * Shared by every AIOSEO plugin, so returning true moves all of them over at once. Check the
		 * group argument to narrow it to one:
		 *
		 *     add_filter( 'aioseo_use_cron_instead_of_action_scheduler', function ( $useCron, $group ) {
		 *         return 'aioseo_404_to_301' === $group ? true : $useCron;
		 *     }, 10, 2 );
		 *
		 * NOTE: must return the same value on every request. One that varies by context - is_admin(),
		 * the current user, the request type - reads as a switch each time, and every switch drains
		 * whatever the abandoned scheduler still has queued. See maybeDrainOnSwitch().
		 *
		 * NOTE: not consulted at all when the constant is set - that decides whether the library is
		 * even loaded, so a filter can't undo it.
		 *
		 * @since 4.0.4
		 *
		 * @param bool   $useCron Whether to use WP-Cron.
		 * @param string $group   The Action Scheduler group of the plugin asking.
		 */
		$useCron = apply_filters( 'aioseo_use_cron_instead_of_action_scheduler', false, $this->actionSchedulerGroup );

		return (bool) $useCron;
	}

	/**
	 * Drains the abandoned scheduler when the site has switched between the two.
	 * Hooked into `init` action hook.
	 *
	 * Anything still queued in the system we've left would otherwise keep firing - a double run when
	 * the same job ends up in both, or an orphan that fires late if the switch is flipped back.
	 *
	 * NOTE: the new mode is recorded before the drain, not after. Draining twice is harmless if a
	 * request dies before the internal options are written - both drains only remove what's pending.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	public function maybeDrainOnSwitch() {
		$useCron  = $this->useCron();
		$previous = (string) aioseo404To301()->internalOptions->internal->schedulerMode;
		$current  = $useCron ? 'cron' : 'action-scheduler';

		if ( $current === $previous ) {
			return;
		}

		aioseo404To301()->internalOptions->internal->schedulerMode = $current;

		// Nothing ran under the other scheduler on a site that has never recorded a mode, so there's
		// nothing to drain - and draining would cancel legitimate work.
		if ( '' === $previous ) {
			return;
		}

		$useCron ? $this->drainActionScheduler() : $this->drainCron();
	}

	/**
	 * Returns the timestamp of the next scheduled run for an action.
	 *
	 * NOTE: Action Scheduler reports `true` instead of a timestamp when the action is already running,
	 * or is pending without a schedule of its own (an async action). Check `is_int()` before doing any
	 * date math on the result. The WP-Cron branch always resolves to a timestamp.
	 *
	 * @since 4.0.4
	 *
	 * @param  string   $actionName The action name.
	 * @param  array    $args       Args passed down to the action.
	 * @return int|bool             The timestamp, true when scheduled without a resolvable date, or false.
	 */
	public function nextScheduled( $actionName, $args = [] ) {
		if ( $this->useCron() ) {
			return wp_next_scheduled( $actionName, $args );
		}

		try {
			return as_next_scheduled_action( $actionName, $args, $this->actionSchedulerGroup );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Whether an action has a pending or running instance.
	 *
	 * NOTE: deliberately not nextScheduled() cast to a bool. Resolving the next run date costs a second
	 * query and a row hydration that this throws away; as_has_scheduled_action() just tests existence.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $actionName The action name.
	 * @param  array  $args       Args passed down to the action.
	 * @return bool               Whether the action is scheduled.
	 */
	public function hasScheduled( $actionName, $args = [] ) {
		if ( $this->useCron() ) {
			return (bool) wp_next_scheduled( $actionName, $args );
		}

		try {
			// as_has_scheduled_action() landed in Action Scheduler 3.3.0, hence the guard.
			if ( function_exists( 'as_has_scheduled_action' ) ) {
				return (bool) as_has_scheduled_action( $actionName, $args, $this->actionSchedulerGroup );
			}

			return (bool) as_next_scheduled_action( $actionName, $args, $this->actionSchedulerGroup );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Unschedules every pending instance of an action.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $actionName The action name.
	 * @param  array  $args       Args passed down to the action.
	 * @return void
	 */
	public function unscheduleAll( $actionName, $args = [] ) {
		if ( $this->useCron() ) {
			// wp_clear_scheduled_hook() only matches on args when they're passed, and wipes every
			// instance of the hook when they aren't - which is what "unschedule all" means here.
			if ( empty( $args ) ) {
				wp_clear_scheduled_hook( $actionName );

				return;
			}

			wp_clear_scheduled_hook( $actionName, $args );

			return;
		}

		try {
			as_unschedule_all_actions( $actionName, $args, $this->actionSchedulerGroup );
		} catch ( \Exception $e ) {
			// Do nothing.
		}
	}

	/**
	 * Returns scheduled actions matching a query.
	 *
	 * NOTE: under the WP-Cron fallback this is a shim. Cron has no queryable store, so status and
	 * paging are ignored and at most one pending entry is reported per hook. Callers that need real
	 * action metadata should treat an empty result as "unknown" rather than "none".
	 *
	 * @since 4.0.4
	 *
	 * @param  array $args Query args, as accepted by as_get_scheduled_actions().
	 * @return array       The matching actions.
	 */
	public function getScheduledActions( $args = [] ) {
		if ( $this->useCron() ) {
			$hook = isset( $args['hook'] ) ? $args['hook'] : '';
			if ( '' === $hook ) {
				return [];
			}

			$timestamp = wp_next_scheduled( $hook, isset( $args['args'] ) ? $args['args'] : [] );

			return $timestamp ? [ $hook => $timestamp ] : [];
		}

		try {
			return (array) as_get_scheduled_actions( $args );
		} catch ( \Exception $e ) {
			return [];
		}
	}

	/**
	 * Returns the WP-Cron recurrence to schedule an arbitrary interval on.
	 *
	 * Action Scheduler takes a raw number of seconds; WP-Cron needs a named schedule. This picks or
	 * mints one so scheduleRecurrent() can honour any interval under the fallback.
	 *
	 * @since 4.0.4
	 *
	 * @param  int    $interval The interval in seconds.
	 * @return string           The schedule name.
	 */
	private function cronSchedule( $interval ) {
		$interval = max( 60, (int) $interval );

		// Reuse a recurrence that already covers this interval - WP ships hourly, twicedaily, daily
		// and weekly, and other plugins add their own. Registering a duplicate just clutters the list.
		foreach ( wp_get_schedules() as $existingName => $schedule ) {
			if ( isset( $schedule['interval'] ) && (int) $schedule['interval'] === $interval ) {
				return $existingName;
			}
		}

		// addCronSchedules() derives the intervals from the events using them, and the event this is
		// for doesn't exist yet - so carry it for the rest of the request.
		$this->mintedCronIntervals[] = $interval;

		return self::cronScheduleName( $interval );
	}

	/**
	 * Adds a WP-Cron recurrence for every interval we have events scheduled on.
	 * Hooked into `cron_schedules` filter hook.
	 *
	 * Registered on every request, not just the one that mints a recurrence. A name that resolves to
	 * nothing leaves the event with an unrecognised schedule in WP Crontrol, and leaves WP falling back
	 * to the interval stored on the event to reschedule it.
	 *
	 * @since 4.0.4
	 *
	 * @param  array $schedules The registered schedules.
	 * @return array            The schedules, with ours added.
	 */
	public function addCronSchedules( $schedules ) {
		foreach ( $this->getOwnedCronIntervals() as $interval ) {
			$name = self::cronScheduleName( $interval );

			if ( isset( $schedules[ $name ] ) ) {
				continue;
			}

			$schedules[ $name ] = [
				'interval' => $interval,
				'display'  => sprintf(
					// Translators: 1 - A number of seconds.
					__( 'Every %1$s seconds', '404-to-301' ),
					$interval
				)
			];
		}

		return $schedules;
	}

	/**
	 * Returns the intervals we need a WP-Cron recurrence for.
	 *
	 * Read off the events using them rather than stored, so a recurrence can never outlive its events
	 * or go missing while they're still queued.
	 *
	 * NOTE: reads the option directly instead of _get_cron_array(), which calls wp_get_schedules() to
	 * upgrade a legacy cron array and would recurse back into the filter this feeds.
	 *
	 * @since 4.0.4
	 *
	 * @return array The intervals in seconds.
	 */
	private function getOwnedCronIntervals() {
		$cron = get_option( 'cron' );
		$cron = is_array( $cron ) ? $cron : [];

		unset( $cron['version'] );

		$intervals = $this->mintedCronIntervals;
		foreach ( $cron as $events ) {
			foreach ( (array) $events as $instances ) {
				foreach ( (array) $instances as $instance ) {
					if (
						is_array( $instance ) &&
						! empty( $instance['schedule'] ) &&
						preg_match( '/^aioseo_every_(\d+)_seconds$/', $instance['schedule'], $matches )
					) {
						$intervals[] = (int) $matches[1];
					}
				}
			}
		}

		return array_unique( $intervals );
	}

	/**
	 * Returns the WP-Cron recurrence name for an interval.
	 *
	 * Deliberately not namespaced per plugin - the name says what it does, so any of our plugins can
	 * resolve a recurrence another one minted.
	 *
	 * @since 4.0.4
	 *
	 * @param  int    $interval The interval in seconds.
	 * @return string           The schedule name.
	 */
	private static function cronScheduleName( $interval ) {
		return 'aioseo_every_' . (int) $interval . '_seconds';
	}

	/**
	 * Whether this site is cron-only, decided early enough to skip loading Action Scheduler.
	 *
	 * NOTE: a constant, not the filter, and deliberately so. The library is required while the plugin
	 * file is still executing - before `plugins_loaded` - so an add_filter() in another plugin's body
	 * may not be registered yet, depending on plugin load order. A constant set in wp-config.php is
	 * always available. AIOSEO_USE_CRON does this for every AIOSEO plugin at once;
	 * AIOSEO_404_TO_301_USE_CRON does it for this one alone. Either keeps Action Scheduler out of
	 * the process entirely: no library load, no store, no tables, no cleanup hook.
	 *
	 * The filter still governs scheduling behaviour for sites that only need that much.
	 *
	 * @since 4.0.4
	 *
	 * @return bool Whether Action Scheduler should be skipped outright.
	 */
	public static function isCronOnly() {
		if ( defined( 'AIOSEO_USE_CRON' ) && AIOSEO_USE_CRON ) {
			return true;
		}

		return defined( 'AIOSEO_404_TO_301_USE_CRON' ) && AIOSEO_404_TO_301_USE_CRON;
	}


	/**
	 * Cancels every pending action in our group.
	 *
	 * NOTE: goes straight at the tables rather than calling as_unschedule_all_actions(), because the
	 * switch that triggers this may be the same one that stopped the library from loading - in which
	 * case those functions don't exist. Only pending and in-progress rows are removed; completed
	 * history is left for Action Scheduler to retire on its own schedule.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	private function drainActionScheduler() {
		$db = aioseo404To301()->core->db;

		if ( ! $db->tableExists( 'actionscheduler_actions' ) || ! $db->tableExists( 'actionscheduler_groups' ) ) {
			return;
		}

		$prefix = $db->db->prefix;

		$db->execute(
			"DELETE aa FROM {$prefix}actionscheduler_actions as aa
			JOIN {$prefix}actionscheduler_groups as ag on `ag`.`group_id` = `aa`.`group_id`
			WHERE `ag`.`slug` = '{$this->actionSchedulerGroup}'
			AND `aa`.`status` IN ('pending', 'in-progress');"
		);
	}

	/**
	 * Clears every WP-Cron event whose hook carries one of our prefixes.
	 *
	 * The cron array has no notion of which plugin queued an event, so the hook name is the only thing
	 * to go on. Every hook scheduled through this class is a literal starting with a prefix we own.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	private function drainCron() {
		$crons = _get_cron_array();

		if ( empty( $crons ) ) {
			return;
		}

		$hooks = [];
		foreach ( $crons as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( $this->ownsCronHook( $hook ) ) {
					$hooks[ $hook ] = true;
				}
			}
		}

		foreach ( array_keys( $hooks ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Whether a WP-Cron hook is ours to clear.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $hook The hook name.
	 * @return bool         Whether we own it.
	 */
	private function ownsCronHook( $hook ) {
		foreach ( $this->foreignCronHookPrefixes as $prefix ) {
			if ( 0 === strpos( $hook, $prefix ) ) {
				return false;
			}
		}

		foreach ( $this->cronHookPrefixes as $prefix ) {
			if ( 0 === strpos( $hook, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}