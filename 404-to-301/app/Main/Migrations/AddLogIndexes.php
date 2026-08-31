<?php
/**
 * Drops the zero-date column defaults and adds the indexes the admin list sorts on.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Main\Migrations;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AddLogIndexes
 *
 * Two problems, in this order because the first blocks the second.
 *
 * The tables were created with `created_at`/`updated_at` defaulting to '0000-00-00 00:00:00'. That
 * is only accepted while the session's sql_mode allows it, which WordPress's own connection does -
 * but MySQL re-validates every column default when it rebuilds a table, so on a server running the
 * stock `NO_ZERO_DATE` any later ALTER against these tables fails with "Invalid default value for
 * 'created_at'". Both models have always written both timestamps themselves, so the default was
 * never load-bearing and simply dropping it is enough.
 *
 * With that out of the way: the logs list sorts by last hit by default and the dashboard widget
 * sorts by hits, and neither column was indexed. Both queries were a full scan plus a filesort to
 * return a handful of rows - measured at 50ms and 88ms over 80k rows, against 0.23ms and 0.46ms
 * once indexed.
 *
 * @since 4.0.4
 * @package AIOSEO\FourNotFour\Main\Migrations
 */
class AddLogIndexes implements Migration {

	/**
	 * Indexes to add, keyed by the index name.
	 *
	 * @since 4.0.4
	 *
	 * @var array<string, string>
	 */
	const INDEXES = [
		'ndx_404_to_301_logs_updated_at'  => 'updated_at',
		'ndx_404_to_301_logs_status_hits' => 'status, hits'
	];

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.4
	 *
	 * @return string
	 */
	public function name() {
		return 'add_log_indexes';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.4
	 *
	 * @return string
	 */
	public function version() {
		return '4.0.4';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	public function up() {
		$this->dropZeroDateDefaults();

		$table    = $this->logsTable();
		$existing = $this->existingIndexes( $table );

		foreach ( self::INDEXES as $index => $columns ) {
			if ( in_array( $index, $existing, true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- names are internal constants.
			$this->query( "ALTER TABLE {$table} ADD INDEX {$index} ({$columns})" );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.4
	 *
	 * @return bool
	 */
	public function verify() {
		$existing = $this->existingIndexes( $this->logsTable() );

		foreach ( array_keys( self::INDEXES ) as $index ) {
			if ( ! in_array( $index, $existing, true ) ) {
				return false;
			}
		}

		// Checked as well as the indexes: a DDL statement that fails through wpdb returns false
		// rather than raising, so verifying only the indexes would report a half-applied run as done.
		return ! $this->hasZeroDateDefault();
	}

	/**
	 * Whether either table still declares a zero-date default.
	 *
	 * @since 4.0.4
	 *
	 * @return bool
	 */
	private function hasZeroDateDefault() {
		$db = aioseo404To301()->core->db->db;

		foreach ( [ '404_to_301_logs', '404_to_301_redirects' ] as $suffix ) {
			$table = aioseo404To301()->core->db->prefix . $suffix;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL introspection, table name is internal.
			$rows = $db->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );

			foreach ( (array) $rows as $row ) {
				if ( ! in_array( ( $row['Field'] ?? '' ), [ 'created_at', 'updated_at' ], true ) ) {
					continue;
				}

				if ( '0000-00-00 00:00:00' === ( $row['Default'] ?? null ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Replace the zero-date defaults with no default at all.
	 *
	 * Runs against both tables: an ALTER on either one is validated the same way, so leaving the
	 * redirects table alone would only defer the problem to the next migration that touches it.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	private function dropZeroDateDefaults() {
		foreach ( [ '404_to_301_logs', '404_to_301_redirects' ] as $suffix ) {
			$table = aioseo404To301()->core->db->prefix . $suffix;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$this->query( "ALTER TABLE {$table} MODIFY created_at datetime NOT NULL, MODIFY updated_at datetime NOT NULL" );
		}
	}

	/**
	 * Index names already on a table.
	 *
	 * @since 4.0.4
	 *
	 * @param  string   $table Prefixed table name.
	 * @return string[]        Index names.
	 */
	private function existingIndexes( $table ) {
		$db = aioseo404To301()->core->db->db;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL introspection, table name is internal.
		$rows = $db->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		return array_values(
			array_unique(
				array_map(
					static function ( $row ) {
						return isset( $row['Key_name'] ) ? (string) $row['Key_name'] : '';
					},
					(array) $rows
				)
			)
		);
	}

	/**
	 * The prefixed logs table.
	 *
	 * @since 4.0.4
	 *
	 * @return string
	 */
	private function logsTable() {
		return aioseo404To301()->core->db->prefix . '404_to_301_logs';
	}

	/**
	 * Run one DDL statement.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $sql The statement.
	 * @return void
	 */
	private function query( $sql ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL.
		aioseo404To301()->core->db->db->query( $sql );
	}
}