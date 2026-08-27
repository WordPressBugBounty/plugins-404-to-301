<?php
/**
 * CSV streaming logic.
 *
 * Reads the 404 log table in batches and writes each row as a CSV
 * record straight to `php://output`. Batching keeps memory flat on
 * tables with millions of rows — the entire result set never has to
 * live in PHP memory at once.
 *
 * @package AIOSEO\FourNotFour\Exporter
 */

namespace AIOSEO\FourNotFour\Main\Exporter;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Utils\Sanitizer;

/**
 * Class Exporter
 *
 * @since 4.0.3
 */
final class Exporter {

	/**
	 * Batch size used when paging through the log table.
	 *
	 * Large enough that the per-batch query overhead amortises, small
	 * enough that one batch comfortably fits in a 64M PHP process.
	 *
	 * @since 4.0.3
	 */
	const BATCH = 500;

	/**
	 * Columns written to the CSV, in order. The keys map onto the
	 * shape produced by {@see self::row()} so adding a column is a
	 * one-line change.
	 *
	 * @since 4.0.3
	 *
	 * @return array<string,string> Map of `column key → header label`.
	 */
	public function columns(): array {
		return [
			'id'           => __( 'ID', '404-to-301' ),
			'url'          => __( '404 Path', '404-to-301' ),
			'ref'          => __( 'Referrer', '404-to-301' ),
			'ip'           => __( 'IP Address', '404-to-301' ),
			'ua'           => __( 'User Agent', '404-to-301' ),
			'method'       => __( 'HTTP Method', '404-to-301' ),
			'hits'         => __( 'Hits', '404-to-301' ),
			'status'       => __( 'Status', '404-to-301' ),
			'status_label' => __( 'Status Label', '404-to-301' ),
			'redirect_id'  => __( 'Linked Redirect ID', '404-to-301' ),
			'created_at'   => __( 'First Seen', '404-to-301' ),
			'updated_at'   => __( 'Last Hit', '404-to-301' ),
		];
	}

	/**
	 * Stream the export to `php://output`.
	 *
	 * The caller is responsible for emitting the headers + flushing
	 * any output buffers before calling — see {@see Api::download()}.
	 *
	 * @since 4.0.3
	 *
	 * @param array $filters Sanitised filter set (search/status/date range).
	 *
	 * @return int Number of data rows written (excludes header).
	 */
	public function stream( array $filters ): int {
		// Bail when the parent plugin's classes aren't reachable — eg.
		// the parent was deactivated mid-request. The handler will
		// still emit the headers, so the client gets an empty file
		// rather than a fatal.
		if ( ! class_exists( \AIOSEO\FourNotFour\Models\Log::class ) ) {
			return 0;
		}

		// `WP_Filesystem` is not appropriate for this method — we
		// stream to `php://output` so the browser can save the file as
		// it arrives. Buffering the entire export through a Filesystem
		// abstraction would force every row into memory first, which
		// defeats the point of a streaming CSV export.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$handle = fopen( 'php://output', 'w' );
		if ( false === $handle ) {
			return 0;
		}

		$columns = $this->columns();

		// UTF-8 BOM so Excel for Windows opens accented characters
		// cleanly without a manual encoding pick.
		fwrite( $handle, "\xEF\xBB\xBF" );
		// Pass an empty $escape explicitly: PHP 8.4 deprecates the
		// implicit default, and the legacy backslash escaping isn't
		// part of RFC 4180 — spreadsheets expect the standard
		// "double the quote" behaviour, which is what '' selects.
		fputcsv( $handle, Sanitizer::csvRow( array_values( $columns ) ), ',', '"', '' );

		$page    = 1;
		$written = 0;

		while ( true ) {
			$args = array_merge(
				$this->queryArgs( $filters ),
				[
					'number' => self::BATCH,
					'offset' => ( $page - 1 ) * self::BATCH,
				]
			);

			$result = \AIOSEO\FourNotFour\Models\Log::paginate( $args );
			$items  = (array) ( $result['items'] ?? [] );

			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $row ) {
				$record = $this->row( $row, array_keys( $columns ) );
				fputcsv( $handle, Sanitizer::csvRow( $record ), ',', '"', '' );
				++$written;
			}

			// Push the batch down the wire so the browser shows
			// progress on its download indicator and intermediaries
			// (eg. nginx) don't buffer the whole CSV before forwarding.
			// `flush()` after `fflush()` covers both PHP's own write
			// buffer and the SAPI's.
			fflush( $handle );
			if ( function_exists( 'flush' ) ) {
				flush();
			}

			// Bail if the user closed the tab — `ignore_user_abort(true)`
			// keeps the script alive long enough for us to notice and
			// exit cleanly, instead of running to completion on a dead
			// connection.
			if ( connection_aborted() ) {
				break;
			}

			// Drained the result set.
			if ( count( $items ) < self::BATCH ) {
				break;
			}

			++$page;
		}

		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $written;
	}

	/**
	 * Translate the request's filter payload into model query args.
	 *
	 * Mirrors the shape used by {@see \AIOSEO\FourNotFour\Api\Logs::list()}
	 * so the export honours whatever the user is currently looking at.
	 *
	 * @since 4.0.3
	 *
	 * @param array $filters Sanitised filter set.
	 *
	 * @return array
	 */
	private function queryArgs( array $filters ): array {
		$args = [
			'orderby' => $filters['orderby'] ?? 'updated_at',
			'order'   => $filters['order'] ?? 'DESC',
		];

		if ( isset( $filters['status'] ) && '' !== $filters['status'] ) {
			$args['status'] = (int) $filters['status'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$args['search'] = (string) $filters['search'];
		}

		$dateFrom = (string) ( $filters['date_from'] ?? '' );
		$dateTo   = (string) ( $filters['date_to'] ?? '' );
		if ( '' !== $dateFrom || '' !== $dateTo ) {
			$range = [ 'column' => 'created_at' ];
			if ( '' !== $dateFrom ) {
				$range['after'] = $dateFrom;
			}
			if ( '' !== $dateTo ) {
				$range['before'] = $dateTo;
			}
			$args['date_query'] = [ $range ];
		}

		return $args;
	}

	/**
	 * Shape one BerlinDB row into an ordered list of CSV cell values.
	 *
	 * @since 4.0.3
	 *
	 * @param object   $row  Log row.
	 * @param string[] $keys Column keys, in CSV order.
	 *
	 * @return array<int,string>
	 */
	private function row( $row, array $keys ): array {
		$statusLabel = [
			0 => __( 'Open', '404-to-301' ),
			1 => __( 'Ignored', '404-to-301' ),
			2 => __( 'Fixed', '404-to-301' ),
			3 => __( 'Custom redirect', '404-to-301' ),
		];

		$values = [
			'id'           => (string) ( $row->id ?? '' ),
			'url'          => (string) ( $row->url ?? '' ),
			'ref'          => (string) ( $row->ref ?? '' ),
			// The model exposes IP through an accessor that masks the
			// last octet on hosts with the "anonymise IPs" toggle on,
			// so we read it the same way the REST shape does.
			'ip'           => method_exists( $row, 'ip' ) ? (string) $row->ip() : (string) ( $row->ip ?? '' ),
			'ua'           => (string) ( $row->ua ?? '' ),
			'method'       => (string) ( $row->method ?? '' ),
			'hits'         => (string) ( (int) ( $row->hits ?? 0 ) ),
			'status'       => (string) ( (int) ( $row->status ?? 0 ) ),
			'status_label' => $statusLabel[ (int) ( $row->status ?? 0 ) ] ?? '',
			'redirect_id'  => null === ( $row->redirect_id ?? null ) ? '' : (string) (int) $row->redirect_id,
			'created_at'   => (string) ( $row->created_at ?? '' ),
			'updated_at'   => (string) ( $row->updated_at ?? '' ),
		];

		$ordered = [];
		foreach ( $keys as $key ) {
			$ordered[] = $values[ $key ] ?? '';
		}

		return $ordered;
	}
}