<?php
namespace AIOSEO\FourNotFour\Db;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database schema for the 404 to 301 tables.
 *
 * NOTE: the logs and redirects tables predate this plugin joining AIOSEO. They keep their original
 * `404_to_301_*` names and index names — the `aioseo_` prefix and `ndx__*` convention are not applied,
 * because renaming would orphan every existing install's data and make dbDelta add duplicate indexes.
 *
 * @since 4.0.4
 */
class Schema {
	/**
	 * Returns every table schema, for dbDelta().
	 *
	 * @since 4.0.4
	 *
	 * @return array Array of SQL CREATE TABLE statements.
	 */
	public function getSchema() {
		return [
			$this->getCacheTableSchema(),
			$this->getRedirectsTableSchema(),
			$this->getLogsTableSchema()
		];
	}

	/**
	 * Returns the schema for the aioseo_404_to_301_cache table.
	 *
	 * @since 4.0.4
	 *
	 * @return string SQL CREATE TABLE statement.
	 */
	public function getCacheTableSchema() {
		$tableName      = aioseo404To301()->core->db->db->prefix . 'aioseo_404_to_301_cache';
		$charsetCollate = aioseo404To301()->core->db->db->get_charset_collate();

		return "CREATE TABLE {$tableName} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(80) NOT NULL,
			value longtext NOT NULL,
			is_object TINYINT(1) DEFAULT 0,
			expiration datetime DEFAULT NULL,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ndx_aioseo_404_to_301_cache_name (name),
			KEY ndx_aioseo_404_to_301_cache_expiration (expiration)
		) {$charsetCollate};";
	}

	/**
	 * Returns the schema for the 404_to_301_redirects table.
	 *
	 * NOTE: mirrors the table BerlinDB created before the port, down to the index names.
	 *
	 * @since 4.0.4
	 *
	 * @return string SQL CREATE TABLE statement.
	 */
	private function getRedirectsTableSchema() {
		$tableName      = aioseo404To301()->core->db->db->prefix . '404_to_301_redirects';
		$charsetCollate = aioseo404To301()->core->db->db->get_charset_collate();

		return "CREATE TABLE {$tableName} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(2048) NOT NULL,
			source_hash char(40) NOT NULL,
			match_type varchar(10) NOT NULL DEFAULT 'exact',
			target_type varchar(10) NOT NULL DEFAULT 'link',
			target_url varchar(2048) NOT NULL DEFAULT '',
			target_page_id bigint(20) unsigned DEFAULT NULL,
			redirect_type smallint(5) unsigned NOT NULL DEFAULT 301,
			is_active tinyint(3) unsigned NOT NULL DEFAULT 1,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			last_hit_at datetime DEFAULT NULL,
			notes text,
			modified_by bigint(20) unsigned DEFAULT NULL,
			query_handling varchar(10) NOT NULL DEFAULT 'ignore',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash),
			KEY is_active (is_active),
			KEY redirect_type (redirect_type),
			KEY match_type (match_type),
			KEY modified_by (modified_by)
		) {$charsetCollate};";
	}

	/**
	 * Returns the schema for the 404_to_301_logs table.
	 *
	 * NOTE: mirrors the table BerlinDB created before the port, down to the index names.
	 * `ip` is varbinary so both IPv4 and IPv6 can be stored in packed form.
	 *
	 * @since 4.0.4
	 *
	 * @return string SQL CREATE TABLE statement.
	 */
	private function getLogsTableSchema() {
		$tableName      = aioseo404To301()->core->db->db->prefix . '404_to_301_logs';
		$charsetCollate = aioseo404To301()->core->db->db->get_charset_collate();

		return "CREATE TABLE {$tableName} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(2048) NOT NULL,
			url_hash char(40) NOT NULL,
			ref varchar(2048) NOT NULL DEFAULT '',
			ip varbinary(16) NOT NULL DEFAULT '',
			ua varchar(512) NOT NULL DEFAULT '',
			method varchar(10) NOT NULL DEFAULT 'GET',
			hits int(10) unsigned NOT NULL DEFAULT 1,
			redirect_id bigint(20) unsigned DEFAULT NULL,
			status tinyint(3) unsigned NOT NULL DEFAULT 0,
			override_redirect tinyint(3) unsigned NOT NULL DEFAULT 0,
			override_email tinyint(3) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY status (status),
			KEY created_at (created_at),
			KEY redirect_id (redirect_id),
			KEY ndx_404_to_301_logs_updated_at (updated_at),
			KEY ndx_404_to_301_logs_status_hits (status, hits)
		) {$charsetCollate};";
	}
}