<?php
/**
 * Redirection plugin source.
 *
 * Reads rules from John Godley's "Redirection" plugin
 * ({@link https://wordpress.org/plugins/redirection/}). The data lives
 * in `{prefix}redirection_items` — we read it directly with `$wpdb` so
 * the import works even when Redirection itself is deactivated (a
 * common "migrate then turn off" flow).
 *
 * Mapping cheat-sheet:
 *
 *   url              → source
 *   action_data      → target_url   (only when action_type='url')
 *   regex=1          → match_type='regex' (else 'exact')
 *   action_code      → redirect_type (clamped to our supported set)
 *   status='enabled' → is_active=1
 *   title + group    → notes        (so origin is auditable later)
 *
 * Rows the parent's model can't represent are skipped with a reason
 * string so the user sees what didn't come over. Specifically:
 *
 *   - `match_type` other than `url` (cookie/agent/referrer/role/…)
 *   - `action_type` other than `url` (pass/error/random/login/…),
 *     except `error` rows with `action_code` 410 — those map cleanly
 *     onto our new 410 Gone status.
 *
 * @package AIOSEO\FourNotFour\Importer\Sources
 */

namespace AIOSEO\FourNotFour\Main\Importer\Sources;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RedirectionSource
 *
 * @since 4.0.3
 */
final class RedirectionSource implements Source {

	/**
	 * Cached `wp_redirection_items` existence flag.
	 *
	 * `is_available()` is called on every preview request — memoise
	 * the `SHOW TABLES LIKE` so we don't re-query.
	 *
	 * @since 4.0.3
	 *
	 * @var bool|null
	 */
	private $available = null;

	/**
	 * Accumulated per-row skip messages from the most recent `read()`.
	 *
	 * @since 4.0.3
	 *
	 * @var array<int,array{row:int,message:string}>
	 */
	private $skips = [];

	/**
	 * Cached group-id → name map.
	 *
	 * Loaded once on first `read()` so we can include the source
	 * group's name in the imported row's `notes` field without
	 * re-querying for every row.
	 *
	 * @since 4.0.3
	 *
	 * @var array<int,string>|null
	 */
	private $groups = null;

	/**
	 * Source id used on the wire and as the React state key.
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	public function id(): string {
		return 'redirection';
	}

	/**
	 * Human-readable name shown in the picker.
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Redirection (John Godley)', '404-to-301' );
	}

	/**
	 * Whether the Redirection plugin's items table is installed.
	 *
	 * @since 4.0.3
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		if ( null !== $this->available ) {
			return $this->available;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'redirection_items';
		// Detection runs once per request and the schema doesn't change
		// often enough to warrant an object-cache round-trip — the cost
		// of a cache miss + warm is higher than the bare `SHOW TABLES`.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->available = ( $found === $table );

		return $this->available;
	}

	/**
	 * Total number of rows in the Redirection items table.
	 *
	 * @since 4.0.3
	 *
	 * @return int
	 */
	public function count(): int {
		if ( ! $this->isAvailable() ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}redirection_items`" );

		return (int) $total;
	}

	/**
	 * Yield one batch of mapped rows from the Redirection items table.
	 *
	 * @since 4.0.3
	 *
	 * @param int $offset Row offset.
	 * @param int $limit  Maximum rows per batch.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	public function read( int $offset, int $limit ): iterable {
		if ( ! $this->isAvailable() ) {
			return;
		}

		global $wpdb;

		$this->skips  = [];
		$this->groups = $this->groups ?? $this->loadGroups();

		// Order by id so paginated calls return a stable, repeatable
		// slice — `position`/`group_id` orderings would shuffle as
		// rules get edited mid-import.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, regex, status, action_type, action_code, action_data, match_type, title, group_id
				 FROM {$wpdb->prefix}redirection_items
				 ORDER BY id ASC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$mapped = $this->mapRow( $row );
			if ( null === $mapped ) {
				continue;
			}
			$mapped['_csv_row'] = (int) $row->id;
			yield $mapped;
		}
	}

	/**
	 * Per-row skip reasons accumulated by the most recent read() pass.
	 *
	 * @since 4.0.3
	 *
	 * @return array<int,array{row:int,message:string}>
	 */
	public function skipSummary(): array {
		return $this->skips;
	}

	/**
	 * Translate one Redirection row into our column shape.
	 *
	 * Returns null when the row can't be represented — and records a
	 * human-readable reason in `$this->skips` so the UI can show it.
	 *
	 * @since 4.0.3
	 *
	 * @param object $row Raw `$wpdb->get_results()` row.
	 *
	 * @return array<string,mixed>|null
	 */
	private function mapRow( $row ): ?array {
		$matchType  = (string) ( $row->match_type ?? 'url' );
		$actionType = (string) ( $row->action_type ?? 'url' );

		// Match types other than `url` carry extra criteria (cookie /
		// agent / referrer / role / …) that our model can't express.
		if ( 'url' !== $matchType ) {
			$this->skip(
				(int) $row->id,
				sprintf(
					/* translators: %s: Redirection match_type value, e.g. "cookie". */
					__( 'Skipped: conditional match type "%s" is not supported.', '404-to-301' ),
					$matchType
				)
			);

			return null;
		}

		$code = (int) ( $row->action_code ?? 0 );

		// `error` rows with code 410 map cleanly onto our new 410 Gone
		// status — they signal "this URL is gone" rather than send a
		// redirect, which is exactly what we now support.
		if ( 'error' === $actionType ) {
			if ( 410 !== $code ) {
				$this->skip(
					(int) $row->id,
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Skipped: error response with code %d is not supported.', '404-to-301' ),
						$code
					)
				);

				return null;
			}

			return $this->buildTerminalRow( $row, 410 );
		}

		if ( 'url' !== $actionType ) {
			$this->skip(
				(int) $row->id,
				sprintf(
					/* translators: %s: Redirection action_type value, e.g. "pass". */
					__( 'Skipped: action type "%s" is not supported.', '404-to-301' ),
					$actionType
				)
			);

			return null;
		}

		$target = (string) ( $row->action_data ?? '' );
		if ( '' === $target ) {
			$this->skip( (int) $row->id, __( 'Skipped: target URL is empty.', '404-to-301' ) );

			return null;
		}

		return [
			'source'        => sanitize_text_field( (string) $row->url ),
			'target_url'    => sanitize_text_field( $target ),
			'target_type'   => 'link',
			'match_type'    => ( 1 === (int) $row->regex ) ? 'regex' : 'exact',
			'redirect_type' => $this->mapStatusCode( $code ),
			'is_active'     => ( 'enabled' === (string) $row->status ) ? 1 : 0,
			'notes'         => $this->buildNote( $row ),
		];
	}

	/**
	 * Build a 410 Gone row (no target).
	 *
	 * @since 4.0.3
	 *
	 * @param object $row    Raw `$wpdb->get_results()` row.
	 * @param int    $status Terminal status to write (410).
	 *
	 * @return array<string,mixed>
	 */
	private function buildTerminalRow( $row, int $status ): array {
		return [
			'source'        => sanitize_text_field( (string) $row->url ),
			'target_type'   => 'none',
			'match_type'    => ( 1 === (int) $row->regex ) ? 'regex' : 'exact',
			'redirect_type' => $status,
			'is_active'     => ( 'enabled' === (string) $row->status ) ? 1 : 0,
			'notes'         => $this->buildNote( $row ),
		];
	}

	/**
	 * Map a Redirection `action_code` to our `redirect_type` enum.
	 *
	 * Anything we don't recognise falls back to 301 — that's what
	 * Redirection's own UI defaults to, so the user's intent is
	 * preserved.
	 *
	 * @since 4.0.3
	 *
	 * @param int $code Redirection's `action_code`.
	 *
	 * @return int
	 */
	private function mapStatusCode( int $code ): int {
		$supported = [ 301, 302, 303, 307, 308 ];

		return in_array( $code, $supported, true ) ? $code : 301;
	}

	/**
	 * Compose the `notes` value for a mapped row.
	 *
	 * Bakes in a "[Imported from Redirection]" tag plus the source
	 * row's `title` and group name (when present) so the origin of
	 * each redirect is auditable from the Redirects table later.
	 *
	 * @since 4.0.3
	 *
	 * @param object $row Raw `$wpdb->get_results()` row.
	 *
	 * @return string
	 */
	private function buildNote( $row ): string {
		$parts = [ '[Imported from Redirection]' ];

		$title = trim( (string) ( $row->title ?? '' ) );
		if ( '' !== $title ) {
			$parts[] = $title;
		}

		$groupId = (int) ( $row->group_id ?? 0 );
		$group   = $this->groups[ $groupId ] ?? '';
		if ( '' !== $group ) {
			$parts[] = 'Group: ' . $group;
		}

		return sanitize_text_field( implode( ' — ', $parts ) );
	}

	/**
	 * Load the group id → name map once per request.
	 *
	 * @since 4.0.3
	 *
	 * @return array<int,string>
	 */
	private function loadGroups(): array {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'redirection_groups' );
		// Same rationale as `is_available()` — schema-probe call, not
		// worth caching.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return [];
		}

		// `$table` is the validated `{prefix}redirection_groups` literal
		// from above — `prepare()` doesn't support table-name placeholders,
		// so the interpolation is intentional and the sniff is silenced.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, name FROM `{$table}`" );

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->id ] = (string) $row->name;
		}

		return $map;
	}

	/**
	 * Record a per-row skip reason for the final UI summary.
	 *
	 * @since 4.0.3
	 *
	 * @param int    $row     Source row id (mapped to the UI's "row" column).
	 * @param string $message Human-readable reason.
	 *
	 * @return void
	 */
	private function skip( int $row, string $message ): void {
		$this->skips[] = [
			'row'     => $row,
			'message' => $message,
		];
	}
}