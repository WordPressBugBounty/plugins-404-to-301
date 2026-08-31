<?php
namespace AIOSEO\FourNotFour\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The 404 log DB model class.
 *
 * @since 4.0.4
 */
class Log extends Model {
	/**
	 * The name of the table in the database, without the prefix.
	 *
	 * NOTE: no `aioseo_` prefix - the table predates the plugin joining AIOSEO and renaming it would
	 * orphan every existing install's data.
	 *
	 * @since 4.0.4
	 *
	 * @var string
	 */
	protected $table = '404_to_301_logs';

	/**
	 * Fields set to null when empty on save.
	 *
	 * @since 4.0.4
	 *
	 * @var array
	 */
	protected $nullFields = [ 'redirect_id' ];

	/**
	 * Status values, mirroring the `status` column.
	 *
	 * @since 4.0.4
	 *
	 * @var int
	 */
	const STATUS_OPEN    = 0;
	const STATUS_IGNORED = 1;
	const STATUS_FIXED   = 2;

	/**
	 * Per-row override values, mirroring the `override_*` columns.
	 *
	 * @since 4.0.4
	 *
	 * @var int
	 */
	const OVERRIDE_GLOBAL  = 0;
	const OVERRIDE_ENABLE  = 1;
	const OVERRIDE_DISABLE = 2;

	/**
	 * The zero date MySQL hands back as the datetime column default.
	 *
	 * @since 4.0.4
	 *
	 * @var string
	 */
	const ZERO_DATE = '0000-00-00 00:00:00';

	/**
	 * The request IP in readable form.
	 *
	 * The column is varbinary so IPv4 and IPv6 both fit in packed form; every reader wants the text
	 * version. Named alongside the `$ip` column deliberately - the callers all ask for `$row->ip()`.
	 *
	 * @since 4.0.4
	 *
	 * @return string The IP, or an empty string when it was masked or never captured.
	 */
	public function ip() {
		return (string) aioseo404To301()->helpers->unpackIp( $this->ip );
	}

	/**
	 * Saves the log, setting the timestamps the template's Model::save() doesn't know about.
	 *
	 * NOTE: Model::save() stamps `created`/`updated`; our table uses `created_at`/`updated_at`.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	public function save() {
		$now = gmdate( 'Y-m-d H:i:s' );

		// Derived here rather than trusted from the caller: the column is NOT NULL and carries a UNIQUE
		// key, so a caller that forgets it silently loses every row after the first.
		if ( ! empty( $this->url ) ) {
			$this->url_hash = aioseo404To301()->helpers->urlHash( (string) $this->url );
		}

		// NOTE: the column default is the zero date, which is not empty() - so a bare empty() check
		// would leave a new row stamped 0000-00-00.
		if ( ! $this->exists() && ( empty( $this->created_at ) || self::ZERO_DATE === $this->created_at ) ) {
			$this->created_at = $now;
		}

		if ( empty( $this->updated_at ) || self::ZERO_DATE === $this->updated_at ) {
			$this->updated_at = $now;
		}

		parent::save();
	}

	/**
	 * Returns the log row for a normalized URL, if any.
	 *
	 * @since 4.0.4
	 *
	 * @param  string   $url Raw URL.
	 * @return Log|null      The log, or null when the URL has never 404'd.
	 */
	public static function getByUrl( $url ) {
		$row = aioseo404To301()->core->db
			->start( '404_to_301_logs' )
			->where( 'url_hash', aioseo404To301()->helpers->urlHash( $url ) )
			->limit( 1 )
			->output( 'ARRAY_A' )
			->run()
			->result();

		return empty( $row ) ? null : new self( $row[0] );
	}

	/**
	 * Records a 404 hit, either inserting a row or bumping the existing hit counter.
	 *
	 * @since 4.0.4
	 *
	 * @param  array    $data       Column => value, must contain at least `url`.
	 * @param  Log|null $existing   Pre-fetched row for this URL, when the caller already looked.
	 * @param  bool     $prefetched Whether $existing represents a completed lookup. Without it a
	 *                              legitimate "no row exists" null is indistinguishable from
	 *                              "the caller hasn't looked yet".
	 * @return int                  Row id of the inserted or updated log.
	 */
	public static function recordHit( $data, $existing = null, $prefetched = false ) {
		$url = (string) ( isset( $data['url'] ) ? $data['url'] : '' );

		if ( '' === $url ) {
			return 0;
		}

		if ( ! $prefetched ) {
			$existing = self::getByUrl( $url );
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		if ( $existing instanceof self && $existing->exists() ) {
			return self::bumpHit( $existing, $data, $now );
		}

		$log             = new self();
		$log->url        = $url;
		$log->url_hash   = aioseo404To301()->helpers->urlHash( $url );
		$log->ref        = (string) ( isset( $data['ref'] ) ? $data['ref'] : '' );
		$log->ip         = (string) ( isset( $data['ip'] ) ? $data['ip'] : '' );
		$log->ua         = (string) ( isset( $data['ua'] ) ? $data['ua'] : '' );
		$log->method     = (string) ( isset( $data['method'] ) ? $data['method'] : 'GET' );
		$log->hits       = (int) ( isset( $data['hits'] ) ? $data['hits'] : 1 );
		$log->status     = (int) ( isset( $data['status'] ) ? $data['status'] : self::STATUS_OPEN );
		$log->created_at = $now;
		$log->updated_at = $now;
		$log->save();

		self::forgetOpenCount();

		return (int) $log->id;
	}

	/**
	 * Bumps the hit counter on an existing log row.
	 *
	 * NOTE: written as a single UPDATE rather than through the model's save(), because this runs on
	 * every 404 for a URL that's already been seen - the hottest write path the plugin has.
	 * Contextual columns fall back to their current value so a caller that omits them doesn't blank
	 * them.
	 *
	 * @since 4.0.4
	 *
	 * @param  Log    $existing The row whose counter is being bumped.
	 * @param  array  $data     Latest request context (ref, ip, ua, method).
	 * @param  string $now      MySQL-format timestamp for updated_at.
	 * @return int              The row id.
	 */
	private static function bumpHit( $existing, $data, $now ) {
		$id = (int) $existing->id;

		aioseo404To301()->core->db
			->update( '404_to_301_logs' )
			->where( 'id', $id )
			->set(
				[
					'hits'       => (int) $existing->hits + 1,
					'updated_at' => $now,
					'ref'        => (string) ( isset( $data['ref'] ) ? $data['ref'] : $existing->ref ),
					'ip'         => (string) ( isset( $data['ip'] ) ? $data['ip'] : $existing->ip ),
					'ua'         => (string) ( isset( $data['ua'] ) ? $data['ua'] : $existing->ua ),
					'method'     => (string) ( isset( $data['method'] ) ? $data['method'] : $existing->method )
				]
			)
			->run();

		aioseo404To301()->core->db->bustCache( '404_to_301_logs' );

		return $id;
	}

	/**
	 * Sets the status column on a log row.
	 *
	 * @since 4.0.4
	 *
	 * @param  int  $id     Row id.
	 * @param  int  $status One of the STATUS_* constants.
	 * @return bool         Whether the status was valid and saved.
	 */
	public static function setStatus( $id, $status ) {
		if ( ! in_array( (int) $status, [ self::STATUS_OPEN, self::STATUS_IGNORED, self::STATUS_FIXED ], true ) ) {
			return false;
		}

		$log = new self( $id );
		if ( ! $log->exists() ) {
			return false;
		}

		$log->status     = (int) $status;
		$log->updated_at = gmdate( 'Y-m-d H:i:s' );
		$log->save();

		self::forgetOpenCount();

		return true;
	}

	/**
	 * Links a log row to a redirect, or clears the link when $redirectId is 0.
	 *
	 * Status follows the redirect's active state: Fixed when the redirect is live, Open when it
	 * isn't, so the admin can still see the 404 needs attention.
	 *
	 * @since 4.0.4
	 *
	 * @param  int  $id         Log row id.
	 * @param  int  $redirectId Redirect row id, or 0 to clear.
	 * @return bool             Whether the log existed and was saved.
	 */
	public static function linkRedirect( $id, $redirectId ) {
		$log = new self( $id );
		if ( ! $log->exists() ) {
			return false;
		}

		$redirectId = (int) $redirectId;
		$status     = self::STATUS_OPEN;

		if ( 0 < $redirectId ) {
			$redirect = new Redirect( $redirectId );
			$status   = ( $redirect->exists() && $redirect->is_active ) ? self::STATUS_FIXED : self::STATUS_OPEN;

			// Reset the per-log redirect override. Once a custom redirect exists for the URL, the
			// redirect row's is_active owns the on/off decision - a stale DISABLE here would
			// silently re-apply if the admin later deleted the redirect.
			$log->override_redirect = self::OVERRIDE_GLOBAL;
		}

		$log->redirect_id = 0 < $redirectId ? $redirectId : null;
		$log->status      = $status;
		$log->updated_at  = gmdate( 'Y-m-d H:i:s' );
		$log->save();

		return true;
	}

	/**
	 * Clears the redirect link from every log referencing a deleted redirect.
	 *
	 * @since 4.0.4
	 *
	 * @param  int  $redirectId Redirect row id that was just deleted.
	 * @return void
	 */
	public static function unlinkRedirect( $redirectId ) {
		if ( 0 >= (int) $redirectId ) {
			return;
		}

		aioseo404To301()->core->db
			->update( '404_to_301_logs' )
			->where( 'redirect_id', (int) $redirectId )
			->set(
				[
					'redirect_id' => null,
					'status'      => self::STATUS_OPEN,
					'updated_at'  => gmdate( 'Y-m-d H:i:s' )
				]
			)
			->run();

		aioseo404To301()->core->db->bustCache( '404_to_301_logs' );
	}

	/**
	 * Syncs the status of every log linked to a redirect when its active flag changes.
	 *
	 * @since 4.0.4
	 *
	 * @param  int  $redirectId Redirect row id.
	 * @param  bool $isActive   New active state.
	 * @return void
	 */
	public static function syncStatusForRedirect( $redirectId, $isActive ) {
		if ( 0 >= (int) $redirectId ) {
			return;
		}

		aioseo404To301()->core->db
			->update( '404_to_301_logs' )
			->where( 'redirect_id', (int) $redirectId )
			->set(
				[
					'status'     => $isActive ? self::STATUS_FIXED : self::STATUS_OPEN,
					'updated_at' => gmdate( 'Y-m-d H:i:s' )
				]
			)
			->run();

		aioseo404To301()->core->db->bustCache( '404_to_301_logs' );
	}

	/**
	 * Sets the per-row override toggles in one shot.
	 *
	 * Anything outside the OVERRIDE_* range is coerced to OVERRIDE_GLOBAL, so an unexpected payload
	 * never persists a junk value.
	 *
	 * @since 4.0.4
	 *
	 * @param  int   $id        Log row id.
	 * @param  array $overrides Override values keyed by column.
	 * @return bool             Whether the log existed and was saved.
	 */
	public static function setOverrides( $id, $overrides ) {
		$log = new self( $id );
		if ( ! $log->exists() ) {
			return false;
		}

		$allowed = [ self::OVERRIDE_GLOBAL, self::OVERRIDE_ENABLE, self::OVERRIDE_DISABLE ];

		$normalize = function ( $value ) use ( $allowed ) {
			$value = (int) $value;

			return in_array( $value, $allowed, true ) ? $value : self::OVERRIDE_GLOBAL;
		};

		$log->override_redirect = $normalize( isset( $overrides['override_redirect'] ) ? $overrides['override_redirect'] : 0 );
		$log->override_email    = $normalize( isset( $overrides['override_email'] ) ? $overrides['override_email'] : 0 );
		$log->updated_at        = gmdate( 'Y-m-d H:i:s' );
		$log->save();

		return true;
	}

	/**
	 * Columns a caller may order by.
	 *
	 * An allow-list: `orderby` reaches this from a query string and a WP-CLI flag, and the builder
	 * escapes column names but won't reject one that doesn't exist.
	 *
	 * @since 4.0.4
	 *
	 * @var array
	 */
	const SORTABLE = [ 'id', 'url', 'hits', 'status', 'created_at', 'updated_at' ];

	/**
	 * Copies a column => value map onto the row, leaving anything absent untouched.
	 *
	 * @since 4.0.4
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
	 * A filtered, ordered page of logs plus the unpaged total.
	 *
	 * The one place the filter vocabulary lives - the REST collection, the CSV exporter, the cleaner,
	 * the reporter, the stats roll-up and the WP-CLI list command all pass the same args.
	 *
	 * @since 4.0.4
	 *
	 * @param  array $args number, offset, orderby, order, status, search, date_query.
	 * @return array       [ 'items' => Log[], 'total' => int ].
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
	 * Static file extensions treated as an asset request rather than a page.
	 *
	 * Deliberately excludes `xml`, `json` and `txt`: a 404 on `sitemap.xml` or
	 * `robots.txt` is a content problem, not a missing file.
	 *
	 * @since 4.0.4
	 */
	const ASSET_EXTENSIONS = [
		'png',
		'jpg',
		'jpeg',
		'gif',
		'svg',
		'webp',
		'avif',
		'ico',
		'bmp',
		'css',
		'js',
		'mjs',
		'map',
		'woff',
		'woff2',
		'ttf',
		'eot',
		'otf',
		'mp4',
		'webm',
		'mov',
		'mp3',
		'wav',
		'ogg',
		'pdf',
		'zip',
		'gz',
		'rar',
		'doc',
		'docx',
		'xls',
		'xlsx',
		'ppt',
		'pptx'
	];

	/**
	 * Narrow a query to 404s by where the visitor came from.
	 *
	 * @since 4.0.4
	 *
	 * @param  \AIOSEO\FourNotFour\Core\Database $query The query to narrow.
	 * @param  string                             $source internal, external or none.
	 * @return void
	 */
	private static function whereReferrer( $query, string $source ): void {
		$db = aioseo404To301()->core->db;

		// Match on host rather than the full home URL, so a site reachable over both
		// schemes still counts its own pages as internal.
		$host  = (string) wp_parse_url( (string) home_url(), PHP_URL_HOST );
		$path  = untrailingslashit( (string) wp_parse_url( (string) home_url(), PHP_URL_PATH ) );
		$known = $db->db->esc_like( $host . $path ) . '%';

		$mine = $db->db->prepare( '( ref LIKE %s OR ref LIKE %s )', 'http://' . $known, 'https://' . $known );

		switch ( $source ) {
			case 'internal':
				$query->whereRaw( $mine );
				break;

			case 'external':
				$query->whereRaw( "( ref <> '' AND NOT {$mine} )" );
				break;

			case 'none':
				$query->whereRaw( "ref = ''" );
				break;
			default:
				// An unrecognised source narrows nothing.
				break;

		}
	}

	/**
	 * Narrow a query to asset requests or to everything else.
	 *
	 * Compares the extension after stripping any query string, so `logo.png?v=2`
	 * still reads as an asset.
	 *
	 * @since 4.0.4
	 *
	 * @param  \AIOSEO\FourNotFour\Core\Database $query The query to narrow.
	 * @param  string                             $kind  asset or content.
	 * @return void
	 */
	private static function whereKind( $query, string $kind ): void {
		$db = aioseo404To301()->core->db;

		$placeholders = implode( ', ', array_fill( 0, count( self::ASSET_EXTENSIONS ), '%s' ) );
		$extension    = "LOWER( SUBSTRING_INDEX( SUBSTRING_INDEX( url, '?', 1 ), '.', -1 ) )";

		// A URL with no dot yields the whole string, which matches nothing in the list
		// and so correctly reads as content.
		$in = $db->db->prepare( "{$extension} IN ( {$placeholders} )", self::ASSET_EXTENSIONS );

		$query->whereRaw( 'asset' === $kind ? $in : "NOT ( {$in} )" );
	}

	/**
	 * Cache key for the unresolved-log count shown in the admin menu.
	 *
	 * @since 4.0.4
	 */
	const OPEN_COUNT_CACHE = '404_to_301_open_log_count';

	/**
	 * Number of unresolved 404s, cached for the admin menu bubble.
	 *
	 * The menu is built on every admin page load, so this must not reach the database
	 * each time. The cache is cleared whenever a row is logged or its status changes.
	 *
	 * @since 4.0.4
	 *
	 * @return int
	 */
	public static function openCount(): int {
		$cached = get_transient( self::OPEN_COUNT_CACHE );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) aioseo404To301()->core->db
			->start( '404_to_301_logs' )
			->where( 'status', self::STATUS_OPEN )
			->count();

		set_transient( self::OPEN_COUNT_CACHE, $count, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Forget the cached unresolved count.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	public static function forgetOpenCount(): void {
		delete_transient( self::OPEN_COUNT_CACHE );
	}

	/**
	 * An unexecuted query with the caller's filters applied.
	 *
	 * Built fresh per call because count() rewrites the SELECT, so counting and fetching can't share
	 * one instance.
	 *
	 * @since 4.0.4
	 *
	 * @param  array $args Query args.
	 * @return \AIOSEO\FourNotFour\Core\Database The query.
	 */
	private static function filtered( $args ) {
		$db    = aioseo404To301()->core->db;
		$query = $db->start( '404_to_301_logs' );

		if ( isset( $args['status'] ) ) {
			$query->where( 'status', (int) $args['status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$query->whereLike( 'url', '%' . $args['search'] . '%', true );
		}

		if ( ! empty( $args['referrer'] ) ) {
			self::whereReferrer( $query, (string) $args['referrer'] );
		}

		if ( ! empty( $args['kind'] ) ) {
			self::whereKind( $query, (string) $args['kind'] );
		}

		foreach ( (array) ( $args['date_query'] ?? [] ) as $range ) {
			$column = (string) ( $range['column'] ?? 'created_at' );

			if ( ! in_array( $column, [ 'created_at', 'updated_at' ], true ) ) {
				continue;
			}

			if ( ! empty( $range['after'] ) ) {
				$query->whereRaw( $db->db->prepare( "`{$column}` >= %s", (string) $range['after'] ) );
			}

			if ( ! empty( $range['before'] ) ) {
				$query->whereRaw( $db->db->prepare( "`{$column}` <= %s", (string) $range['before'] ) );
			}
		}

		return $query;
	}

	/**
	 * A safe ORDER BY clause from the caller's args.
	 *
	 * @since 4.0.4
	 *
	 * @param  array  $args Query args.
	 * @return string       The clause.
	 */
	private static function orderClause( $args ) {
		$column = isset( $args['orderby'] ) && in_array( $args['orderby'], self::SORTABLE, true )
			? (string) $args['orderby']
			: 'updated_at';

		$order = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		return $column . ' ' . $order;
	}

	/**
	 * Returns aggregate counts for the summary cards.
	 *
	 * NOTE: one GROUP BY beats four COUNT(*) round-trips. `custom` is counted separately because
	 * redirect_id IS NOT NULL is the ground truth for "has a custom redirect", independent of status.
	 *
	 * @since 4.0.4
	 *
	 * @return array Counts keyed total, open, ignored, fixed, custom.
	 */
	public static function summary() {
		$rows = aioseo404To301()->core->db
			->start( '404_to_301_logs' )
			->select( 'status, COUNT(*) as cnt' )
			->groupBy( 'status' )
			->output( 'ARRAY_A' )
			->run()
			->result();

		$counts = [
			self::STATUS_OPEN    => 0,
			self::STATUS_IGNORED => 0,
			self::STATUS_FIXED   => 0
		];

		foreach ( (array) $rows as $row ) {
			$status = (int) $row['status'];
			if ( array_key_exists( $status, $counts ) ) {
				$counts[ $status ] = (int) $row['cnt'];
			}
		}

		$custom = aioseo404To301()->core->db
			->start( '404_to_301_logs' )
			->whereRaw( 'redirect_id IS NOT NULL' )
			->count();

		return [
			'total'   => array_sum( $counts ),
			'open'    => $counts[ self::STATUS_OPEN ],
			'ignored' => $counts[ self::STATUS_IGNORED ],
			'fixed'   => $counts[ self::STATUS_FIXED ],
			'custom'  => (int) $custom
		];
	}

	/**
	 * Deletes every row matching a set of column conditions.
	 *
	 * @since 4.0.4
	 *
	 * @param  array $where Column => value conditions. Empty deletes every row.
	 * @return int          Number of rows deleted.
	 */
	public static function deleteWhere( $where = [] ) {
		$query = aioseo404To301()->core->db->delete( '404_to_301_logs' );

		foreach ( (array) $where as $column => $value ) {
			$query->where( $column, $value );
		}

		$query->run();

		$deleted = (int) aioseo404To301()->core->db->rowsAffected();

		aioseo404To301()->core->db->bustCache( '404_to_301_logs' );

		self::forgetOpenCount();

		return $deleted;
	}

	/**
	 * Truncates the logs table. Custom redirects live elsewhere and are untouched.
	 *
	 * @since 4.0.4
	 *
	 * @return bool Whether the truncate succeeded.
	 */
	public static function purgeAll() {
		$result = aioseo404To301()->core->db->truncate( '404_to_301_logs' )->run();

		aioseo404To301()->core->db->bustCache( '404_to_301_logs' );

		self::forgetOpenCount();

		return false !== $result;
	}

	/**
	 * Deletes rows older than the given number of days.
	 *
	 * @since 4.0.4
	 *
	 * @param  int $days Cut-off in days.
	 * @return int       Number of rows deleted.
	 */
	public static function prune( $days ) {
		$days = (int) $days;

		if ( 0 >= $days ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		aioseo404To301()->core->db
			->delete( '404_to_301_logs' )
			->whereRaw( aioseo404To301()->core->db->db->prepare( 'created_at < %s', $cutoff ) )
			->run();

		$deleted = (int) aioseo404To301()->core->db->rowsAffected();

		aioseo404To301()->core->db->bustCache( '404_to_301_logs' );

		return $deleted;
	}
}