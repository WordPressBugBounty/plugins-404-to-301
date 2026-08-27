<?php
/**
 * `wp 404-to-301 settings ...` subcommands.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Cli;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use WP_CLI;

/**
 * Read, update and reset plugin settings.
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\CLI
 */
class Settings extends Command {

	/**
	 * Register the subcommand.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( '404-to-301 settings', static::class );
	}

	/**
	 * Get one or all settings.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : Setting key. Omit to dump every key.
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
	public function get( array $args, array $assoc ): void {
		if ( empty( $args ) ) {
			$rows = [];
			foreach ( $this->flatten( aioseo404To301()->options->all() ) as $key => $value ) {
				$rows[] = [
					'key'   => $key,
					'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
				];
			}
			$this->printRows( $assoc, $rows, [ 'key', 'value' ] );

			return;
		}

		$key   = (string) $args[0];
		$flat  = $this->flatten( aioseo404To301()->options->all() );
		$value = array_key_exists( $key, $flat ) ? $flat[ $key ] : null;

		if ( null === $value ) {
			/* translators: %s: setting key. */
			WP_CLI::error( sprintf( __( 'Setting "%s" not found.', '404-to-301' ), $key ) );
		}

		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::log( wp_json_encode( $value ) );
		} else {
			WP_CLI::log( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
		}
	}

	/**
	 * Update a single setting.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Setting key.
	 *
	 * <value>
	 * : New value. JSON-decoded when possible so arrays/objects come
	 *   through correctly; otherwise stored as a string.
	 *
	 * @since 4.0.0
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 *
	 * @return void
	 */
	public function update( array $args, array $assoc ): void {
		$key   = (string) ( $args[0] ?? '' );
		$value = $args[1] ?? '';

		if ( '' === $key ) {
			WP_CLI::error( __( 'Provide a setting key.', '404-to-301' ) );
		}

		$decoded = json_decode( (string) $value, true );
		if ( null !== $decoded || 'null' === $value ) {
			$value = $decoded;
		}

		$segments = explode( '.', $key );
		$leaf     = array_pop( $segments );
		$node     = aioseo404To301()->options;

		foreach ( $segments as $segment ) {
			$node = $node->$segment;
		}

		$node->$leaf = $value;

		WP_CLI::success(
			sprintf(
				/* translators: %s: setting key */
				__( 'Updated setting "%s".', '404-to-301' ),
				$key
			)
		);
	}

	/**
	 * Reset every setting to its default.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @since 4.0.0
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 *
	 * @return void
	 */
	public function reset( array $args, array $assoc ): void {
		WP_CLI::confirm( __( 'Reset every setting to its default?', '404-to-301' ), $assoc );

		delete_option( 'aioseo_404_to_301_options' );
		aioseo404To301()->core->optionsCache->resetDb();

		WP_CLI::success( __( 'Settings reset.', '404-to-301' ) );
	}

	/**
	 * Flattens the nested option tree into dot-delimited keys for CLI output.
	 *
	 * @since 4.0.3
	 *
	 * @param  array  $options The option tree.
	 * @param  string $prefix  Accumulated key prefix.
	 * @return array           Flat map of dotted key => value.
	 */
	private function flatten( $options, $prefix = '' ) {
		$flat = [];

		foreach ( (array) $options as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( is_array( $value ) ) {
				$flat = array_merge( $flat, $this->flatten( $value, $path ) );
				continue;
			}

			$flat[ $path ] = $value;
		}

		return $flat;
	}
}