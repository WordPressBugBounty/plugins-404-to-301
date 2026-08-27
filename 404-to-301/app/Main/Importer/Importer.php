<?php
/**
 * Source-agnostic import pipeline.
 *
 * The Importer no longer knows how data was sourced — it just consumes
 * shaped rows from a {@see Sources\Source} instance and writes them
 * through the parent's Redirects model so hashing, audit events and
 * dedupe rules stay consistent with single-row creates.
 *
 * Two entry points:
 *
 *   `preview()`  — read a sample of mapped rows + total counts without
 *                   touching the database. Used by the preview modal.
 *
 *   `run_batch()` — process one offset/limit slice and report what
 *                   happened. The REST layer drives the loop; the
 *                   client polls between batches so the UI can render
 *                   a progress bar.
 *
 * @package AIOSEO\FourNotFour\Importer
 */

namespace AIOSEO\FourNotFour\Main\Importer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use AIOSEO\FourNotFour\Models\Redirect as RedirectQuery;
use AIOSEO\FourNotFour\Models\Redirect as RedirectRow;
use AIOSEO\FourNotFour\Models\Redirect as RedirectsModel;
use AIOSEO\FourNotFour\Utils\Helpers;

/**
 * Class Importer
 *
 * @since 4.0.3
 */
final class Importer {

	/**
	 * Number of sample rows returned in a preview response.
	 *
	 * Small enough that the modal stays scannable and the response
	 * payload stays under a few KB even with a long `notes` column.
	 *
	 * @since 4.0.3
	 */
	const PREVIEW_SAMPLE_SIZE = 5;

	/**
	 * Dry-run summary used by the preview modal.
	 *
	 * Walks the source once over the first N rows, classifies each as
	 * "importable" or "skipped" (without writing anything), and
	 * returns the resulting counts + sample for display.
	 *
	 * @since 4.0.3
	 *
	 * @param Sources\Source $source Source to inspect.
	 *
	 * @return array{
	 *   total:int,
	 *   importable:int,
	 *   skipped:int,
	 *   sample:array<int,array<string,mixed>>,
	 *   errors:array<int,array{row:int,message:string}>
	 * }
	 */
	public function preview( Sources\Source $source ): array {
		$total      = $source->count();
		$importable = 0;
		$skipped    = 0;
		$sample     = [];
		$errors     = [];

		// Cap the dry-run at a manageable slice. On a 100k-row source
		// counting every importable vs. skipped row up-front is more
		// work than the user needs to make a decision — they care
		// about "does this look right?" plus the totals.
		$cap = (int) min( $total > 0 ? $total : 1000, 1000 );

		foreach ( $source->read( 0, $cap ) as $row ) {
			$error = $this->validate( $row );

			if ( null !== $error ) {
				++$skipped;
				$errors[] = [
					'row'     => (int) ( $row['_csv_row'] ?? 0 ),
					'message' => $error,
				];
				continue;
			}

			++$importable;
			if ( count( $sample ) < self::PREVIEW_SAMPLE_SIZE ) {
				$sample[] = $this->shapeSample( $row );
			}
		}

		// Merge in source-side skip reasons (eg. Redirection's
		// conditional matches) so the UI's "needs attention" list is
		// the complete story, not just our validator's contribution.
		foreach ( $source->skipSummary() as $skip ) {
			++$skipped;
			$errors[] = $skip;
		}

		return [
			'total'      => $total,
			'importable' => $importable,
			'skipped'    => $skipped,
			'sample'     => $sample,
			'errors'     => $errors,
		];
	}

	/**
	 * Process one offset/limit batch.
	 *
	 * Returns a per-batch summary; the REST layer accumulates these
	 * client-side as the loop progresses so the UI can render a
	 * progress bar.
	 *
	 * @since 4.0.3
	 *
	 * @param Sources\Source $source          Bound source.
	 * @param int    $offset          Row offset.
	 * @param int    $limit           Max rows in this batch.
	 * @param bool   $updateExisting Overwrite an existing row when
	 *                                its `source_hash` collides.
	 *
	 * @return array{
	 *   created:int,
	 *   updated:int,
	 *   skipped:int,
	 *   processed:int,
	 *   errors:array<int,array{row:int,message:string}>
	 * }
	 */
	public function runBatch( Sources\Source $source, int $offset, int $limit, bool $updateExisting ): array {
		$summary = [
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'processed' => 0,
			'errors'    => [],
		];

		if ( ! class_exists( RedirectsModel::class ) ) {
			$summary['errors'][] = [
				'row'     => 0,
				'message' => __( 'Parent plugin is not active.', '404-to-301' ),
			];

			return $summary;
		}

		foreach ( $source->read( $offset, $limit ) as $row ) {
			++$summary['processed'];

			$error = $this->validate( $row );
			if ( null !== $error ) {
				++$summary['skipped'];
				$summary['errors'][] = [
					'row'     => (int) ( $row['_csv_row'] ?? 0 ),
					'message' => $error,
				];
				continue;
			}

			// Internal hints we use for validation/dedupe but the model
			// doesn't accept — strip them before the write so they
			// don't end up in `wpdb->insert()`'s column list.
			$rowId = (int) ( $row['_csv_row'] ?? 0 );
			unset( $row['_csv_row'] );

			$existing = $this->findExisting(
				(string) $row['source'],
				(string) ( $row['query_handling'] ?? 'ignore' )
			);

			if ( $existing instanceof RedirectRow ) {
				if ( ! $updateExisting ) {
					++$summary['skipped'];
					continue;
				}

				$target = new RedirectsModel( (int) $existing->id );

				if ( $target->exists() ) {
					$target->fill( $row )->save();
					++$summary['updated'];
				} else {
					++$summary['skipped'];
					$summary['errors'][] = [
						'row'     => $rowId,
						'message' => __( 'Could not update the existing redirect.', '404-to-301' ),
					];
				}
				continue;
			}

			global $wpdb;
			// Clear any pre-existing error so we can attribute the next
			// one (if any) to this insert specifically.
			$wpdb->last_error = '';

			$created = ( new RedirectsModel() )->fill( $row );
			$created->save();

			$id = (int) ( $created->id ?? 0 );
			if ( 0 < $id ) {
				++$summary['created'];
			} else {
				++$summary['skipped'];

				// Surface the real reason when one is available — a
				// bare "could not create" leaves the user guessing
				// between "duplicate source", "too long", "regex
				// invalid" and so on.
				$dbError             = (string) $wpdb->last_error;
				$summary['errors'][] = [
					'row'     => $rowId,
					'message' => '' !== $dbError
						? sprintf(
							/* translators: %s: database error message. */
							__( 'Could not create the redirect: %s', '404-to-301' ),
							$dbError
						)
						: __( 'Could not create the redirect (no DB error reported — likely a duplicate source).', '404-to-301' ),
				];
			}
		}

		// Source-side skip reasons (Redirection's conditional matches,
		// empty targets, etc.) — fold them into this batch's error
		// list so the UI's progress feed reflects them as they arrive.
		foreach ( $source->skipSummary() as $skip ) {
			$summary['skipped'] += 1;
			$summary['errors'][] = $skip;
		}

		return $summary;
	}

	/**
	 * Validate a shaped row before handing it to the model.
	 *
	 * Returns null on success or a human-readable error string for the
	 * per-row report.
	 *
	 * @since 4.0.3
	 *
	 * @param array<string,mixed> $data Shaped row data.
	 *
	 * @return string|null
	 */
	private function validate( array $data ): ?string {
		if ( empty( $data['source'] ) ) {
			return __( 'Missing `source` value.', '404-to-301' );
		}

		$targetType = (string) ( $data['target_type'] ?? 'link' );
		$status     = (int) ( $data['redirect_type'] ?? 301 );

		// 410/451 are terminal — they don't need a destination.
		$isTerminal = in_array( $status, [ 410, 451 ], true );

		if ( ! $isTerminal && 'link' === $targetType && empty( $data['target_url'] ) ) {
			return __( '`target_url` is required when target_type is "link".', '404-to-301' );
		}

		if ( ! $isTerminal && 'page' === $targetType && empty( $data['target_page_id'] ) ) {
			return __( '`target_page_id` is required when target_type is "page".', '404-to-301' );
		}

		// An imported file is third-party input, and the admin table renders the destination as a
		// clickable link — a `javascript:` target would run in the admin's own session.
		if ( 'link' === $targetType && ! aioseo404To301()->helpers->isAllowedRedirectTarget( (string) ( $data['target_url'] ?? '' ) ) ) {
			return __( '`target_url` must be a relative path or an http(s) URL.', '404-to-301' );
		}

		// Regex must be a syntactically valid pattern — otherwise the row
		// would silently never match anything at request time.
		if ( 'regex' === (string) ( $data['match_type'] ?? '' ) && ! aioseo404To301()->helpers->isValidRegex( (string) $data['source'] ) ) {
			return __( '`source` is not a valid regex pattern.', '404-to-301' );
		}

		return null;
	}

	/**
	 * Look up an existing row with the same `source_hash`.
	 *
	 * Matches the hashing rule used by the model's `create()` — `require`
	 * rows hash with the query string, every other mode hashes path-only.
	 *
	 * @since 4.0.3
	 *
	 * @param string $source Source URL / pattern.
	 * @param string $mode   `query_handling` value.
	 *
	 * @return RedirectRow|null
	 */
	private function findExisting( string $source, string $mode ): ?RedirectRow {
		return RedirectQuery::findBySource( $source, $mode );
	}

	/**
	 * Shape a sample row for the preview modal — strips internal hints
	 * and keeps only what's worth showing to the user.
	 *
	 * @since 4.0.3
	 *
	 * @param array<string,mixed> $row Shaped row.
	 *
	 * @return array<string,mixed>
	 */
	private function shapeSample( array $row ): array {
		unset( $row['_csv_row'] );

		return [
			'source'        => (string) ( $row['source'] ?? '' ),
			'target_url'    => (string) ( $row['target_url'] ?? '' ),
			'match_type'    => (string) ( $row['match_type'] ?? 'exact' ),
			'redirect_type' => (int) ( $row['redirect_type'] ?? 301 ),
			'is_active'     => (int) ( $row['is_active'] ?? 1 ),
		];
	}
}