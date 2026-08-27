<?php
/**
 * The actual prune logic.
 *
 * Dispatched from cron — picks the configured strategy, collects the
 * log rows it intends to delete, optionally deletes the linked
 * redirect rows, and then deletes the logs.
 *
 * All three strategies funnel through {@see Cleaner::purge()} so the
 * "also delete linked redirects" toggle has exactly one place to
 * enforce its behaviour.
 *
 * @package AIOSEO\FourNotFour\Cleaner
 */

namespace AIOSEO\FourNotFour\Main\Cleaner;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cleaner
 *
 * @since 4.0.3
 */
final class Cleaner {

	/**
	 * Batch size used when walking the table to collect victims.
	 *
	 * Picked to be large enough to be cheap (one query per ~1k rows)
	 * but small enough not to balloon memory on hosts with tight PHP
	 * limits.
	 *
	 * @since 4.0.3
	 */
	const BATCH = 500;

	/**
	 * Cron entry point.
	 *
	 * Static so it can be referenced as `array( Cleaner::class, 'run' )`
	 * without instantiating — keeps the cron callable resolvable even
	 * if the feature's other singletons haven't been touched yet.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public static function run(): void {
		( new self() )->dispatch();
	}

	/**
	 * Dispatch to the configured strategy.
	 *
	 * No-op when the parent's classes aren't loaded (parent disabled)
	 * or the method is `none`.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function dispatch(): void {
		if ( ! class_exists( \AIOSEO\FourNotFour\Models\Log::class ) ) {
			return;
		}

		$method = (string) aioseo404To301()->options->cleaner->method;

		switch ( $method ) {
			case 'age':
				$this->runAge();
				break;
			case 'count':
				$this->runCount();
				break;
			case 'periodic':
				$this->runPeriodic();
				break;
			default:
				// 'none' or unknown — nothing to do.
				return;
		}
	}

	/**
	 * Delete log rows older than the retention window.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	private function runAge(): void {
		$days   = max( 1, (int) aioseo404To301()->options->cleaner->ageDays );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$this->purgeWhere(
			[
				'date_query' => [
					[
						'column' => 'created_at',
						'before' => $cutoff,
					],
				],
			]
		);
	}

	/**
	 * Trim the table once it grows past the configured threshold.
	 *
	 * Reads the current row count cheaply via a 1-row paginate (BerlinDB
	 * returns the matching `total` alongside `items`), then dispatches
	 * to the chosen sub-strategy.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	private function runCount(): void {
		$threshold = max( 1, (int) aioseo404To301()->options->cleaner->countThreshold );

		$page  = \AIOSEO\FourNotFour\Models\Log::paginate( [ 'number' => 1 ] );
		$total = (int) ( $page['total'] ?? 0 );

		if ( $total < $threshold ) {
			return;
		}

		$strategy = (string) aioseo404To301()->options->cleaner->countStrategy;

		if ( 'all' === $strategy ) {
			$this->purgeAll();

			return;
		}

		if ( 'percent' === $strategy ) {
			$percent = min( 100, max( 1, (int) aioseo404To301()->options->cleaner->trimPercent ) );
			$delete  = (int) floor( $total * ( $percent / 100 ) );
		} else { // 'count'.
			$delete = max( 1, (int) aioseo404To301()->options->cleaner->trimCount );
		}

		if ( $delete <= 0 ) {
			return;
		}

		$this->purgeOldest( $delete );
	}

	/**
	 * Wipe the entire log table on every periodic tick.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	private function runPeriodic(): void {
		$this->purgeAll();
	}

	/**
	 * Delete every log row, optionally taking linked redirects with them.
	 *
	 * Walks the table in batches so we can collect distinct redirect ids
	 * before issuing deletes — the model's `delete_where(array())`
	 * shortcut would otherwise leave the redirects orphaned.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	private function purgeAll(): void {
		$this->purgeWhere( [] );
	}

	/**
	 * Delete the oldest N rows.
	 *
	 * @since 4.0.3
	 *
	 * @param int $limit Maximum rows to delete.
	 *
	 * @return void
	 */
	private function purgeOldest( int $limit ): void {
		$keepRedirects   = $this->keepRedirects();
		$redirectVictims = [];
		$remaining       = $limit;

		while ( $remaining > 0 ) {
			$batch = min( self::BATCH, $remaining );
			$page  = \AIOSEO\FourNotFour\Models\Log::paginate(
				[
					'number'  => $batch,
					'orderby' => 'created_at',
					'order'   => 'ASC',
				]
			);

			$items = (array) ( $page['items'] ?? [] );
			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $row ) {
				if ( ! $keepRedirects && ! empty( $row->redirect_id ) ) {
					$redirectVictims[ (int) $row->redirect_id ] = true;
				}
				( new \AIOSEO\FourNotFour\Models\Log( (int) $row->id ) )->delete();
				--$remaining;
			}

			// Fewer items than asked means we've drained the queue.
			if ( count( $items ) < $batch ) {
				break;
			}
		}

		$this->purgeRedirects( array_keys( $redirectVictims ) );
	}

	/**
	 * Delete every log row matching `$args`.
	 *
	 * The model's own `delete_where()` does the same outer loop, but
	 * doesn't expose the rows it's about to delete — and we need them
	 * so the keep-redirects toggle can do its job. So we walk batched
	 * paginates ourselves, deleting as we go.
	 *
	 * @since 4.0.3
	 *
	 * @param array $args Query args (date_query, status, etc.).
	 *
	 * @return void
	 */
	private function purgeWhere( array $args ): void {
		$keepRedirects   = $this->keepRedirects();
		$redirectVictims = [];

		// Page from the start every iteration. As we delete rows the
		// table shrinks underneath us, so "page 1" keeps returning
		// fresh victims until the matching set is empty.
		while ( true ) {
			$page  = \AIOSEO\FourNotFour\Models\Log::paginate( array_merge( $args, [ 'number' => self::BATCH ] ) );
			$items = (array) ( $page['items'] ?? [] );
			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $row ) {
				if ( ! $keepRedirects && ! empty( $row->redirect_id ) ) {
					$redirectVictims[ (int) $row->redirect_id ] = true;
				}
				( new \AIOSEO\FourNotFour\Models\Log( (int) $row->id ) )->delete();
			}
		}

		$this->purgeRedirects( array_keys( $redirectVictims ) );
	}

	/**
	 * Delete the collected redirect rows, if any.
	 *
	 * Bails when the Redirects model isn't reachable, which protects us
	 * from a parent-plugin upgrade that renames or relocates the class.
	 *
	 * @since 4.0.3
	 *
	 * @param int[] $ids Redirect row ids.
	 *
	 * @return void
	 */
	private function purgeRedirects( array $ids ): void {
		if ( empty( $ids ) || ! class_exists( \AIOSEO\FourNotFour\Models\Redirect::class ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			( new \AIOSEO\FourNotFour\Models\Redirect( (int) $id ) )->delete();
		}
	}

	/**
	 * Read the keep-redirects toggle, defaulting to "keep".
	 *
	 * @since 4.0.3
	 *
	 * @return bool
	 */
	private function keepRedirects(): bool {
		return (bool) aioseo404To301()->options->cleaner->keepRedirects;
	}
}