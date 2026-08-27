<?php
/**
 * Base class for the plugin's WP-CLI subcommands.
 *
 * Each concrete subcommand extends this class and implements the
 * actual `wp 404-to-301 <thing> <verb>` operations. The base provides:
 *
 *  - A shared output-format resolver (table / csv / json).
 *  - A small helper to print rows via WP-CLI's table formatter.
 *
 * Implementations register themselves via the static `register()`
 * method declared on {@see Runnable}, which {@see CLI} calls once at
 * boot. Keeping registration on each subcommand class means adding a
 * new top-level verb is a one-class change.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Cli;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Contracts\Runnable;
use WP_CLI;
use WP_CLI\Formatter;

/**
 * Class Command
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\CLI
 */
abstract class Command implements Runnable {

	/**
	 * Resolve the desired output format from the user-supplied assoc args.
	 *
	 * @since 4.0.0
	 *
	 * @param array $assoc Assoc args from WP-CLI.
	 *
	 * @return string One of `table`, `csv`, `json`, `yaml`.
	 */
	protected function format( array $assoc ): string {
		$format  = (string) ( $assoc['format'] ?? 'table' );
		$allowed = [ 'table', 'csv', 'json', 'yaml' ];

		return in_array( $format, $allowed, true ) ? $format : 'table';
	}

	/**
	 * Print rows in the user-requested format.
	 *
	 * @since 4.0.0
	 *
	 * @param array    $assoc   Assoc args from WP-CLI.
	 * @param array    $rows    Rows to print.
	 * @param string[] $columns Column keys to display.
	 *
	 * @return void
	 */
	protected function printRows( array $assoc, array $rows, array $columns ): void {
		$assocArgs = [
			'format' => $this->format( $assoc ),
			'fields' => implode( ',', $columns ),
		];

		$formatter = new Formatter( $assocArgs );
		$formatter->display_items( $rows );
	}

	/**
	 * Convenience accessor for `WP_CLI::log()` that respects the
	 * `--quiet` flag.
	 *
	 * @since 4.0.0
	 *
	 * @param string $message Message to log.
	 *
	 * @return void
	 */
	protected function log( string $message ): void {
		if ( class_exists( '\\WP_CLI' ) ) {
			WP_CLI::log( $message );
		}
	}
}