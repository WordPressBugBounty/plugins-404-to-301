<?php
/**
 * Statistics computation for a report period.
 *
 * Reads the parent's Logs model and rolls the rows up into the small
 * set of numbers the email body shows:
 *
 *   - Total logs in the table (lifetime count).
 *   - New 404 URLs since the previous report (uses the recorded
 *     `email_reports_last_sent_id` so the count is cheap even on
 *     million-row tables).
 *   - Total 404 hits in the period (sum of `hits` across the new rows).
 *   - Top N most-hit URLs in the period.
 *
 * Kept separate from {@see Reporter} so it can be exercised in tests
 * without going near `wp_mail()`.
 *
 * @package AIOSEO\FourNotFour\Reports
 */

namespace AIOSEO\FourNotFour\Main\Reports;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Stats
 *
 * @since 4.0.3
 */
final class Stats {

	/**
	 * Number of top-hit URLs to include in the report body.
	 *
	 * Filterable via `404_to_301_email_reports_top_count`.
	 *
	 * @since 4.0.3
	 */
	const TOP_COUNT = 5;

	/**
	 * Batch size used when walking the table to compute period totals.
	 *
	 * @since 4.0.3
	 */
	const BATCH = 500;

	/**
	 * Compute the stats snapshot for the current report.
	 *
	 * @since 4.0.3
	 *
	 * @param int $sinceId Highest log id covered by the previous
	 *                      report. `0` means "no previous report —
	 *                      include everything".
	 *
	 * @return array{
	 *     total_logs:int,
	 *     new_logs:int,
	 *     new_hits:int,
	 *     top:array<int,array{url:string,hits:int}>,
	 *     max_id:int
	 * }
	 */
	public function compute( int $sinceId ): array {
		$empty = [
			'total_logs' => 0,
			'new_logs'   => 0,
			'new_hits'   => 0,
			'top'        => [],
			'max_id'     => $sinceId,
		];

		if ( ! class_exists( \AIOSEO\FourNotFour\Models\Log::class ) ) {
			return $empty;
		}

		// Lifetime total — one-row paginate is the cheapest way to read
		// it from the model without exposing the underlying COUNT(*).
		$head      = \AIOSEO\FourNotFour\Models\Log::paginate( [ 'number' => 1 ] );
		$totalLogs = (int) ( $head['total'] ?? 0 );

		$newLogs = 0;
		$newHits = 0;
		$maxId   = $sinceId;
		$byUrl   = [];

		$page = 1;
		while ( true ) {
			$result = \AIOSEO\FourNotFour\Models\Log::paginate(
				[
					'number'  => self::BATCH,
					'offset'  => ( $page - 1 ) * self::BATCH,
					'orderby' => 'id',
					'order'   => 'ASC',
				]
			);

			$items = (array) ( $result['items'] ?? [] );
			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $row ) {
				$id = (int) ( $row->id ?? 0 );
				if ( $id <= $sinceId ) {
					// Predates the previous report — skip but keep the
					// max_id climbing so we still set a sensible
					// boundary even when nothing qualifies as new.
					if ( $id > $maxId ) {
						$maxId = $id;
					}
					continue;
				}

				$url  = (string) ( $row->url ?? '' );
				$hits = (int) ( $row->hits ?? 0 );

				++$newLogs;
				$newHits      += $hits;
				$maxId         = $id;
				$byUrl[ $url ] = ( $byUrl[ $url ] ?? 0 ) + $hits;
			}

			if ( count( $items ) < self::BATCH ) {
				break;
			}

			++$page;
		}

		arsort( $byUrl );

		/**
		 * Filter the number of top URLs included in the report body.
		 *
		 * @since 4.0.3
		 *
		 * @param int $count Default `Stats::TOP_COUNT`.
		 */
		$topCount = (int) apply_filters( '404_to_301_email_reports_top_count', self::TOP_COUNT );
		$topCount = max( 1, $topCount );

		$top = [];
		foreach ( array_slice( $byUrl, 0, $topCount, true ) as $url => $hits ) {
			$top[] = [
				'url'  => (string) $url,
				'hits' => (int) $hits,
			];
		}

		return [
			'total_logs' => $totalLogs,
			'new_logs'   => $newLogs,
			'new_hits'   => $newHits,
			'top'        => $top,
			'max_id'     => $maxId,
		];
	}
}