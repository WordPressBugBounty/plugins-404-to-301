<?php
namespace AIOSEO\FourNotFour\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The custom redirect DB model class.
 *
 * @since 4.0.3
 */
class Redirect extends Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * NOTE: no `aioseo_` prefix - the table predates the plugin joining AIOSEO and renaming it would
	 * orphan every existing install's data.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	protected $table = '404_to_301_redirects';

	/**
	 * Fields set to null when empty on save.
	 *
	 * @since 4.0.3
	 *
	 * @var array
	 */
	protected $nullFields = [ 'target_page_id', 'last_hit_at', 'notes', 'modified_by' ];

	/**
	 * Capture groups from the regex that matched this request.
	 *
	 * Populated by {@see self::pickRegex()} and consumed by {@see self::resolveTarget()} so a target
	 * can reference `$1`. Not a table column, so `save()` ignores it; it lives only for the request
	 * that matched.
	 *
	 * @since 4.0.3
	 *
	 * @var array
	 */
	public $regexCaptures = [];

	/**
	 * Object cache group for redirect lookups.
	 *
	 * NOTE: the object cache is used here rather than `core->cache`, which is backed by a DB table.
	 * These lookups sit on the 404 request path, where the whole point of caching is to avoid a
	 * query - a table-backed hit would just trade one SELECT for another. With a persistent object
	 * cache (Redis/Memcached) these become genuinely free across requests; without one they still
	 * de-duplicate within a request, which is the case the dispatcher actually hits.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const CACHE_GROUP = '404_to_301_redirects_lookup';

	/**
	 * Cache key holding the lookup cache version.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const CACHE_VERSION_KEY = 'lookup_version';

	/**
	 * Option backing the lookup cache version, so it survives an object-cache flush.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const CACHE_VERSION_OPTION = '404_to_301_lookup_cache_version';

	/**
	 * Autoloaded option holding the "any active rule?" flag.
	 *
	 * Stored as '1'/'0' rather than a bool so a sentinel default can tell "never computed" apart
	 * from "computed, and there were none" - bool false collides with get_option()'s not-set return.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const HAS_ACTIVE_OPTION = '404_to_301_has_active';

	/**
	 * The zero date MySQL hands back as the datetime column default.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const ZERO_DATE = '0000-00-00 00:00:00';

	/**
	 * Saves the redirect, keeping the source hash, timestamps and audit trail in sync.
	 *
	 * NOTE: the template's Model::save() auto-stamps `created`/`updated`, which our tables don't
	 * have - they use `created_at`/`updated_at`, so those are set here instead.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function save() {
		$isInsert = ! $this->exists();
		$now      = gmdate( 'Y-m-d H:i:s' );

		// Either column changing invalidates the hash, so it is always recomputed from the current
		// pair rather than trusted from the caller.
		if ( ! empty( $this->source ) ) {
			$mode              = empty( $this->query_handling ) ? 'ignore' : (string) $this->query_handling;
			$this->source_hash = self::hashForMode( (string) $this->source, $mode );
		}

		// NOTE: the column default is the zero date, which is not empty() - so a bare empty() check
		// would leave a new row stamped 0000-00-00.
		if ( $isInsert && ( empty( $this->created_at ) || self::ZERO_DATE === $this->created_at ) ) {
			$this->created_at = $now;
		}

		$this->updated_at = $now;

		// Stamp the author only when the caller hasn't - WP-CLI and importers pass an explicit value.
		if ( empty( $this->modified_by ) ) {
			$userId = get_current_user_id();
			if ( 0 < $userId ) {
				$this->modified_by = $userId;
			}
		}

		// isset() rather than a direct read - the pk is unset on a model that hasn't been saved yet.
		$id = isset( $this->id ) ? (int) $this->id : 0;

		parent::save();

		if ( 0 >= $id ) {
			$id = isset( $this->id ) ? (int) $this->id : 0;
		}

		$modifiedBy = isset( $this->modified_by ) ? $this->modified_by : null;

		self::dispatchAudit( $isInsert ? 'created' : 'updated', $id, [ 'modified_by' => $modifiedBy ] );
	}

	/**
	 * Deletes the redirect, unlinks its logs and emits the audit event.
	 *
	 * @since 4.0.3
	 *
	 * @return null
	 */
	public function delete() {
		$id = isset( $this->id ) ? (int) $this->id : 0;

		parent::delete();

		// Logs pointing at a redirect that no longer exists would keep a stale redirect_id and stay
		// marked Fixed, hiding a 404 that needs attention again.
		Log::unlinkRedirect( $id );

		self::dispatchAudit( 'deleted', $id );

		return null;
	}

	/**
	 * Returns the redirect matching a 404 URL, if any.
	 *
	 * Resolution order is fixed: exact hash, then prefix, then regex. Only active rows are
	 * considered, and the first match wins.
	 *
	 * @since 4.0.3
	 *
	 * @param  string        $url Raw URL.
	 * @return Redirect|null      The matching redirect, or null.
	 */
	public static function findMatch( $url ) {
		$candidates = self::candidatesForUrl( $url );

		$exact = self::pickExact( $url, $candidates['exact'] );
		if ( $exact ) {
			return $exact;
		}

		$prefix = self::pickPrefix( $url, $candidates['prefix'] );
		if ( $prefix ) {
			return $prefix;
		}

		return self::pickRegex( $url, $candidates['regex'] );
	}

	/**
	 * Fetches every redirect row that could match this URL, in one query.
	 *
	 * NOTE: one scan gets all three buckets. Walking exact, then prefix, then regex as separate
	 * queries would cost three statements on every 404. `ORDER BY source DESC` gives the
	 * longer-pattern-first semantics the prefix and regex walks rely on.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $url Raw request URL.
	 * @return array       Rows bucketed by match type.
	 */
	private static function candidatesForUrl( $url ) {
		$hashWithQuery = aioseo404To301()->helpers->urlHashWithQuery( $url );
		$hashPathOnly  = aioseo404To301()->helpers->urlHash( $url );

		$cacheKey = 'candidates_' . self::cacheVersion() . '_' . $hashWithQuery;
		$cached   = wp_cache_get( $cacheKey, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$rows = aioseo404To301()->core->db
			->start( '404_to_301_redirects' )
			->where( 'is_active', 1 )
			->whereRaw(
				aioseo404To301()->core->db->db->prepare(
					"( ( match_type = 'exact' AND source_hash IN ( %s, %s ) ) OR match_type = 'prefix' OR match_type = 'regex' )",
					$hashWithQuery,
					$hashPathOnly
				)
			)
			->orderBy( 'source DESC' )
			->output( 'ARRAY_A' )
			->run()
			->result();

		$buckets = [
			'exact'  => [],
			'prefix' => [],
			'regex'  => []
		];

		foreach ( (array) $rows as $row ) {
			$type = isset( $row['match_type'] ) ? (string) $row['match_type'] : '';
			if ( isset( $buckets[ $type ] ) ) {
				$buckets[ $type ][] = new self( $row );
			}
		}

		wp_cache_set( $cacheKey, $buckets, self::CACHE_GROUP );

		return $buckets;
	}

	/**
	 * Picks the exact-match winner from a candidate list.
	 *
	 * A `require` row, which hashes the query string too, beats an `ignore` row that only stores the
	 * path.
	 *
	 * @since 4.0.3
	 *
	 * @param  string        $url        Raw request URL.
	 * @param  array         $candidates Active exact rows.
	 * @return Redirect|null             The winner, or null.
	 */
	private static function pickExact( $url, $candidates ) {
		if ( empty( $candidates ) ) {
			return null;
		}

		$hashWithQuery = aioseo404To301()->helpers->urlHashWithQuery( $url );
		$hashPathOnly  = aioseo404To301()->helpers->urlHash( $url );

		$fallback = null;
		foreach ( $candidates as $row ) {
			$hash = (string) $row->source_hash;

			if ( $hash === $hashWithQuery ) {
				return $row;
			}

			if ( null === $fallback && $hash === $hashPathOnly ) {
				$fallback = $row;
			}
		}

		return $fallback;
	}

	/**
	 * Picks the first prefix rule whose source is a prefix of the URL.
	 *
	 * @since 4.0.3
	 *
	 * @param  string        $url        Raw request URL.
	 * @param  array         $candidates Active prefix rows, longest first.
	 * @return Redirect|null             The winner, or null.
	 */
	private static function pickPrefix( $url, $candidates ) {
		if ( empty( $candidates ) ) {
			return null;
		}

		$normalized = aioseo404To301()->helpers->normalizeUrl( $url );

		foreach ( $candidates as $row ) {
			$source = aioseo404To301()->helpers->normalizeUrl( (string) $row->source );

			if ( '' !== $source && 0 === strpos( $normalized, $source ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Picks the first regex rule whose pattern matches the URL.
	 *
	 * @since 4.0.3
	 *
	 * @param  string        $url        Raw request URL.
	 * @param  array         $candidates Active regex rows.
	 * @return Redirect|null             The winner, or null.
	 */
	private static function pickRegex( $url, $candidates ) {
		if ( empty( $candidates ) ) {
			return null;
		}

		foreach ( $candidates as $row ) {
			$pattern = (string) $row->source;
			if ( '' === $pattern ) {
				continue;
			}

			// Accept both a bare pattern (`^/old/.*$`) and a delimited one (`#^/old/.*$#i`).
			$wrapped = ( '/' === $pattern[0] || '#' === $pattern[0] ) ? $pattern : '#' . $pattern . '#';

			$matches = [];
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed pattern should skip the row, not warn.
			if ( 1 === @preg_match( $wrapped, $url, $matches ) ) {
				$row->regexCaptures = $matches;

				return $row;
			}
		}

		return null;
	}

	/**
	 * Returns an existing row that would collide with the given source and query-handling mode.
	 *
	 * The table has a UNIQUE index on source_hash, so the API layer uses this to reject duplicates
	 * with a specific message instead of letting the insert fail silently.
	 *
	 * @since 4.0.3
	 *
	 * @param  string        $source        Raw source URL or path.
	 * @param  string        $queryHandling ignore | preserve | require.
	 * @param  int           $excludeId     Row id to ignore, so a row doesn't collide with itself.
	 * @return Redirect|null                The colliding row, or null.
	 */
	public static function findBySource( $source, $queryHandling = 'ignore', $excludeId = 0 ) {
		if ( '' === (string) $source ) {
			return null;
		}

		$row = aioseo404To301()->core->db
			->start( '404_to_301_redirects' )
			->where( 'source_hash', self::hashForMode( $source, $queryHandling ) )
			->limit( 1 )
			->output( 'ARRAY_A' )
			->run()
			->result();

		if ( empty( $row ) ) {
			return null;
		}

		$redirect = new self( $row[0] );

		if ( 0 < (int) $excludeId && (int) $redirect->id === (int) $excludeId ) {
			return null;
		}

		return $redirect;
	}

	/**
	 * Bumps the hit counter and last-hit timestamp.
	 *
	 * NOTE: deliberately does not emit an audit event. Hits come from public 404s, not admin edits,
	 * so stamping them as user actions would pollute the audit trail.
	 *
	 * @since 4.0.3
	 *
	 * @param  int  $id Row id.
	 * @return bool     Whether the row existed.
	 */
	public static function recordHit( $id ) {
		$redirect = new self( $id );
		if ( ! $redirect->exists() ) {
			return false;
		}

		aioseo404To301()->core->db
			->update( '404_to_301_redirects' )
			->where( 'id', (int) $id )
			->set(
				[
					'hits'        => (int) $redirect->hits + 1,
					'last_hit_at' => gmdate( 'Y-m-d H:i:s' )
				]
			)
			->run();

		return true;
	}

	/**
	 * Columns a caller may order by.
	 *
	 * An allow-list: `orderby` reaches this from a query string and a WP-CLI flag, and the builder
	 * escapes column names but won't reject one that doesn't exist.
	 *
	 * @since 4.0.3
	 *
	 * @var array
	 */
	const SORTABLE = [ 'id', 'source', 'target_url', 'match_type', 'redirect_type', 'is_active', 'hits', 'last_hit_at', 'created_at', 'updated_at' ];

	/**
	 * Copies a column => value map onto the row, leaving anything absent untouched.
	 *
	 * @since 4.0.3
	 *
	 * @param  array $data Column values.
	 * @return $this       For chaining onto save().
	 */
	public function fill( $data ) {
		foreach ( (array) $data as $column => $value ) {
			$this->$column = $value;
		}

		return $this;
	}

	/**
	 * A filtered, ordered page of redirects plus the unpaged total.
	 *
	 * The one place the filter vocabulary lives - the REST collection, the cleaner and the WP-CLI
	 * list command all pass the same args.
	 *
	 * @since 4.0.3
	 *
	 * @param  array $args number, offset, orderby, order, match_type, target_type, redirect_type, is_active, search.
	 * @return array       [ 'items' => Redirect[], 'total' => int ].
	 */
	public static function paginate( $args = [] ) {
		$total = self::filtered( $args )->count();

		$rows = self::filtered( $args )
			->orderBy( self::orderClause( $args ) )
			->limit( max( 1, (int) ( $args['number'] ?? 20 ) ), max( 0, (int) ( $args['offset'] ?? 0 ) ) )
			->run()
			->models( self::class );

		return [
			'items' => array_values( $rows ),
			'total' => $total
		];
	}

	/**
	 * An unexecuted query with the caller's filters applied.
	 *
	 * Built fresh per call because count() rewrites the SELECT, so counting and fetching can't share
	 * one instance.
	 *
	 * @since 4.0.3
	 *
	 * @param  array $args Query args.
	 * @return \AIOSEO\FourNotFour\Core\Database The query.
	 */
	private static function filtered( $args ) {
		$db    = aioseo404To301()->core->db;
		$query = $db->start( '404_to_301_redirects' );

		foreach ( [ 'match_type', 'target_type', 'redirect_type' ] as $column ) {
			if ( isset( $args[ $column ] ) ) {
				$query->where( $column, $args[ $column ] );
			}
		}

		if ( isset( $args['is_active'] ) ) {
			$query->where( 'is_active', (int) $args['is_active'] );
		}

		if ( ! empty( $args['search'] ) ) {
			// Either side of the redirect is worth matching on.
			$like = '%' . $db->db->esc_like( $args['search'] ) . '%';
			$query->whereRaw( $db->db->prepare( '( `source` LIKE %s OR `target_url` LIKE %s )', $like, $like ) );
		}

		return $query;
	}

	/**
	 * A safe ORDER BY clause from the caller's args.
	 *
	 * @since 4.0.3
	 *
	 * @param  array  $args Query args.
	 * @return string       The clause.
	 */
	private static function orderClause( $args ) {
		$column = isset( $args['orderby'] ) && in_array( $args['orderby'], self::SORTABLE, true )
			? (string) $args['orderby']
			: 'id';

		$order = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		return $column . ' ' . $order;
	}

	/**
	 * Returns aggregate counts for the summary cards.
	 *
	 * @since 4.0.3
	 *
	 * @return array Counts keyed total, active, inactive, hits.
	 */
	public static function summary() {
		$row = aioseo404To301()->core->db
			->start( '404_to_301_redirects' )
			->select( 'COUNT(*) as total, SUM(is_active = 1) as active, SUM(is_active = 0) as inactive, SUM(hits) as hits' )
			->output( 'ARRAY_A' )
			->run()
			->result();

		$row = empty( $row ) ? [] : $row[0];

		return [
			'total'    => (int) ( isset( $row['total'] ) ? $row['total'] : 0 ),
			'active'   => (int) ( isset( $row['active'] ) ? $row['active'] : 0 ),
			'inactive' => (int) ( isset( $row['inactive'] ) ? $row['inactive'] : 0 ),
			'hits'     => (int) ( isset( $row['hits'] ) ? $row['hits'] : 0 )
		];
	}

	/**
	 * Whether at least one log row is linked to the given redirect.
	 *
	 * @since 4.0.3
	 *
	 * @param  int  $redirectId Redirect row id.
	 * @return bool             Whether a linked log exists.
	 */
	public static function hasLinkedLog( $redirectId ) {
		if ( 0 >= (int) $redirectId ) {
			return false;
		}

		$count = aioseo404To301()->core->db
			->start( '404_to_301_logs' )
			->where( 'redirect_id', (int) $redirectId )
			->limit( 1 )
			->count();

		return 0 < (int) $count;
	}

	/**
	 * Whether the site has at least one active redirect rule.
	 *
	 * NOTE: backed by an autoloaded option so the front router can short-circuit at zero query cost -
	 * WordPress already fetches every autoloaded option in one bulk read per request, whatever the
	 * object-cache state. The flag is rewritten on every mutation, and bootstrapped lazily the first
	 * time it's read on a site that hasn't computed it yet.
	 *
	 * @since 4.0.3
	 *
	 * @return bool Whether any active rule exists.
	 */
	public static function hasActive() {
		$cached = get_option( self::HAS_ACTIVE_OPTION, 'unset' );

		if ( 'unset' === $cached ) {
			$cached = self::refreshHasActiveFlag();
		}

		return '1' === (string) $cached;
	}

	/**
	 * Recomputes the has-active flag from the table and persists it.
	 *
	 * @since 4.0.3
	 *
	 * @return string '1' when any active row exists, otherwise '0'.
	 */
	public static function refreshHasActiveFlag() {
		$count = aioseo404To301()->core->db
			->start( '404_to_301_redirects' )
			->where( 'is_active', 1 )
			->limit( 1 )
			->count();

		$value = 0 < (int) $count ? '1' : '0';

		// autoload = true keeps subsequent reads zero-query.
		update_option( self::HAS_ACTIVE_OPTION, $value, true );

		return $value;
	}

	/**
	 * Drops every cached redirect lookup and resyncs the has-active flag.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public static function flushCache() {
		// NOTE: a version bump rather than wp_cache_flush_group(). That function exists on our
		// minimum WP but silently no-ops on object-cache backends that don't advertise
		// flush_group support, which would leave stale redirect lookups serving after an edit.
		// Folding a version into the key sidesteps backend support entirely.
		wp_cache_set( self::CACHE_VERSION_KEY, self::cacheVersion() + 1, self::CACHE_GROUP );
		update_option( self::CACHE_VERSION_OPTION, self::cacheVersion() + 1, false );

		// The flag has to be observable on the very next request even with no persistent object
		// cache, so it's refreshed rather than merely invalidated.
		self::refreshHasActiveFlag();
	}

	/**
	 * Returns the current cache version used to scope lookup keys.
	 *
	 * Read from the object cache first, falling back to the option so the version survives a cache
	 * flush - otherwise a restarted Redis would reset the version and resurrect pre-edit entries
	 * that happened to still be in a second cache tier.
	 *
	 * @since 4.0.3
	 *
	 * @return int The current version.
	 */
	private static function cacheVersion() {
		$version = wp_cache_get( self::CACHE_VERSION_KEY, self::CACHE_GROUP );

		if ( false === $version ) {
			$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
			wp_cache_set( self::CACHE_VERSION_KEY, $version, self::CACHE_GROUP );
		}

		return (int) $version;
	}

	/**
	 * Resolves the row's destination URL.
	 *
	 * Returns an empty string for terminal and no-redirect rows, and for a page target whose post has
	 * since been deleted - the caller treats "no destination" as "don't redirect".
	 *
	 * @since 4.0.3
	 *
	 * @return string The destination URL, or '' when there isn't one.
	 */
	public function resolveTarget() {
		switch ( (string) $this->target_type ) {
			case 'link':
				return $this->applyCaptures( (string) $this->target_url );

			case 'page':
				if ( ! empty( $this->target_page_id ) ) {
					$url = get_permalink( (int) $this->target_page_id );

					return is_string( $url ) ? $url : '';
				}

				return '';

			case 'none':
			default:
				return '';
		}
	}

	/**
	 * Substitute regex capture groups into a destination.
	 *
	 * A `regex` rule advertises PCRE in the UI, so `/old/([0-9]+)` -> `/new/$1` is what users expect
	 * (and what every other redirect plugin does). Placeholders with no matching group resolve to an
	 * empty string rather than being left in the URL — core's `wp_sanitize_redirect()` strips `$` from
	 * the Location header anyway, so a leftover `$1` would silently ship as `1`.
	 *
	 * Returns the target untouched for non-regex rules, which have no captures.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $target Raw destination as configured.
	 * @return string         Destination with `$n` / `${n}` replaced.
	 */
	private function applyCaptures( string $target ): string {
		if ( empty( $this->regexCaptures ) || false === strpos( $target, '$' ) ) {
			return $target;
		}

		$captures = $this->regexCaptures;

		return (string) preg_replace_callback(
			'/\$(?:\{(\d+)\}|(\d+))/',
			static function ( $matches ) use ( $captures ) {
				// The `${n}` branch leaves group 2 unset, and vice versa.
				$braced = $matches[1] ?? '';
				$index  = (int) ( '' !== $braced ? $braced : ( $matches[2] ?? 0 ) );

				if ( ! isset( $captures[ $index ] ) ) {
					return '';
				}

				/*
				 * Strip leading slashes and backslashes from the capture. A capture is
				 * substituted into a path, so it must never be able to introduce an
				 * authority: a target of `/$1` with a request of `/old//evil.test`
				 * would otherwise produce the protocol-relative `//evil.test`, which
				 * browsers resolve off-site. Inner slashes are kept so a multi-segment
				 * capture still rebuilds its path.
				 */
				return ltrim( (string) $captures[ $index ], '/\\' );
			},
			$target
		);
	}

	/**
	 * Returns the active exact-match row for a URL, if any.
	 *
	 * Tries the query-aware hash first so a `require` row wins, then falls back to the query-stripped
	 * hash so `/foo?utm=x` still matches an `ignore` row stored as `/foo`. When the URL has no query
	 * the two hashes are identical and the single lookup serves both.
	 *
	 * @since 4.0.3
	 *
	 * @param  string        $url Raw URL.
	 * @return Redirect|null      The matching row, or null.
	 */
	public static function findExact( $url ) {
		$withQuery = aioseo404To301()->helpers->urlHashWithQuery( $url );

		$row = self::findExactByHash( $withQuery );
		if ( $row ) {
			return $row;
		}

		$pathOnly = aioseo404To301()->helpers->urlHash( $url );
		if ( $pathOnly === $withQuery ) {
			return null;
		}

		return self::findExactByHash( $pathOnly );
	}

	/**
	 * Returns the active exact-match row for a source hash.
	 *
	 * @since 4.0.3
	 *
	 * @param  string        $hash SHA1 of the normalized URL, with or without the query.
	 * @return Redirect|null       The matching row, or null.
	 */
	private static function findExactByHash( $hash ) {
		$row = aioseo404To301()->core->db
			->start( '404_to_301_redirects' )
			->where( 'source_hash', $hash )
			->where( 'match_type', 'exact' )
			->where( 'is_active', 1 )
			->limit( 1 )
			->output( 'ARRAY_A' )
			->run()
			->result();

		return empty( $row ) ? null : new self( $row[0] );
	}

	/**
	 * Deletes every row matching a set of column conditions.
	 *
	 * NOTE: rows are deleted one at a time through the model rather than in a single DELETE, so each
	 * one emits its audit event and unlinks its logs. Used by the CLI purge commands, where
	 * correctness matters more than the round-trip count.
	 *
	 * @since 4.0.3
	 *
	 * @param  array $where Column => value conditions. Empty deletes every row.
	 * @return int          Number of rows deleted.
	 */
	public static function deleteWhere( $where = [] ) {
		$query = aioseo404To301()->core->db->start( '404_to_301_redirects' )->select( 'id' );

		foreach ( (array) $where as $column => $value ) {
			$query->where( $column, $value );
		}

		$rows  = $query->output( 'ARRAY_A' )->run()->result();
		$count = 0;

		foreach ( (array) $rows as $row ) {
			$redirect = new self( (int) $row['id'] );

			if ( $redirect->exists() ) {
				$redirect->delete();
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Hashes a source URL according to the row's query-handling mode.
	 *
	 * `require` rows include the query string so several rows can share a path with different query
	 * requirements; every other mode hashes the path only.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $source The source column value.
	 * @param  string $mode   ignore | preserve | require.
	 * @return string         40-char hex SHA1.
	 */
	public static function hashForMode( $source, $mode ) {
		return 'require' === $mode
			? aioseo404To301()->helpers->urlHashWithQuery( $source )
			: aioseo404To301()->helpers->urlHash( $source );
	}

	/**
	 * Fires the audit action for a row mutation and invalidates the lookup cache.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $action One of created, updated, deleted.
	 * @param  int    $id     Affected row id.
	 * @param  array  $data   Payload that was written. Empty on delete.
	 * @return void
	 */
	public static function dispatchAudit( $action, $id, $data = [] ) {
		// Any mutation invalidates the lookup cache. Kept here so this stays the single seam future
		// write paths have to remember.
		self::flushCache();

		$userId = (int) ( isset( $data['modified_by'] ) ? $data['modified_by'] : get_current_user_id() );

		/**
		 * Fires after a redirect row is created, updated or deleted.
		 *
		 * @since 4.0.3
		 *
		 * @param string $action One of created, updated, deleted.
		 * @param int    $id     Redirect row id.
		 * @param int    $userId User responsible, or 0 for CLI and cron.
		 * @param array  $data   Payload that was written. Empty on delete.
		 */
		do_action( '404_to_301_redirect_audit', $action, $id, $userId, $data );
	}
}