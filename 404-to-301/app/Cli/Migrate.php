<?php
/**
 * `wp 404-to-301 migrate ...` subcommands.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Cli;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Main\Migration\Migrator;
use WP_CLI;

/**
 * Inspect, run and abort the migration of pre-4.0 log data.
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\CLI
 */
class Migrate extends Command {

	/**
	 * Register the subcommand.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( '404-to-301 migrate', static::class );
	}

	/**
	 * Print the current migration status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table | csv | json | yaml.
	 *
	 * @since 4.0.0
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 *
	 * @return void
	 */
	public function status( array $args, array $assoc ): void {
		$status = aioseo404To301()->main->migrator->status();

		$rows = [];
		foreach ( $status as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}

			$rows[] = [
				'key'   => $key,
				'value' => (string) $value,
			];
		}

		$this->printRows( $assoc, $rows, [ 'key', 'value' ] );
	}

	/**
	 * Run the migration synchronously, chunk by chunk, until it
	 * finishes (or `--limit` chunks have run).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<chunks>]
	 * : Maximum number of chunks to run (default: until done).
	 *
	 * [--phase=<phase>]
	 * : 1 | 2 | all (default: all).
	 *
	 * @since 4.0.0
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 *
	 * @return void
	 */
	public function run( array $args, array $assoc ): void {
		$migrator = aioseo404To301()->main->migrator;
		$phase    = (string) ( $assoc['phase'] ?? 'all' );

		if ( 'all' === $phase || '1' === $phase ) {
			$copied = $migrator->runPhase1();
			WP_CLI::log(
				sprintf(
					/* translators: %d: redirects copied */
					__( 'Phase 1 complete — %d custom redirects migrated.', '404-to-301' ),
					$copied
				)
			);
		}

		if ( '1' === $phase ) {
			WP_CLI::success( __( 'Done.', '404-to-301' ) );

			return;
		}

		$limit  = (int) ( $assoc['limit'] ?? 0 );
		$ran    = 0;
		$status = $migrator->status();

		if ( ! $status['legacy_present'] || $status['logs_migrated'] ) {
			WP_CLI::success( __( 'Nothing to migrate.', '404-to-301' ) );

			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( __( 'Migrating logs', '404-to-301' ), $status['remaining'] );

		while ( true ) {
			$before = $migrator->remainingRows();
			$migrator->runChunk();
			$after     = $migrator->remainingRows();
			$processed = max( 0, $before - $after );

			for ( $i = 0; $i < $processed; $i++ ) {
				$progress->tick();
			}

			++$ran;

			if ( $after <= 0 ) {
				break;
			}

			if ( $limit > 0 && $ran >= $limit ) {
				break;
			}
		}

		$progress->finish();

		WP_CLI::success(
			sprintf(
				/* translators: %d: chunks */
				__( 'Migration finished after %d chunk(s).', '404-to-301' ),
				$ran
			)
		);
	}

	/**
	 * Abort an in-flight migration.
	 *
	 * @since 4.0.0
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 *
	 * @return void
	 */
	public function abort( array $args, array $assoc ): void {
		aioseo404To301()->main->migrator->abort();

		WP_CLI::success( __( 'Migration aborted. The legacy table was left in place.', '404-to-301' ) );
	}
}