<?php
/**
 * Source registry.
 *
 * Single home for the list of import sources the plugin knows about.
 * Bundled sources (CSV / Redirection / 301 Redirects) register
 * themselves at boot, and the `404_to_301_import_sources` filter lets
 * any third-party plugin slot another source in without touching the
 * plugin's own code — implement {@see Source} and append the instance
 * to the array.
 *
 * @package AIOSEO\FourNotFour\Importer\Sources
 */

namespace AIOSEO\FourNotFour\Main\Importer\Sources;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Registry
 *
 * @since 4.0.3
 */
final class Registry {

	/**
	 * Registered sources, keyed by their `id()`.
	 *
	 * Memoised after the first `all()` call so the `404_to_301_import_sources`
	 * filter only runs once per request — third-party callbacks that do
	 * expensive work (eg. `class_exists`, DB lookups) don't get re-run
	 * for every REST call.
	 *
	 * @since 4.0.3
	 *
	 * @var array<string,Source>|null
	 */
	private static $sources = null;

	/**
	 * All registered sources, regardless of availability.
	 *
	 * @since 4.0.3
	 *
	 * @return array<string,Source> Map of `id => Source`.
	 */
	public static function all(): array {
		if ( null !== self::$sources ) {
			return self::$sources;
		}

		$bundled = [
			new CsvSource(),
			new RedirectionSource(),
			new WebFactorySource(),
		];

		/**
		 * Filter the registered import sources.
		 *
		 * Append your own {@see Source} implementation to the array to
		 * add support for another redirect plugin. Returning a non-array
		 * is treated as "no change" — the bundled set is used as-is.
		 *
		 * Example:
		 *
		 *     add_filter( '404_to_301_import_sources', function ( $sources ) {
		 *         $sources[] = new My_Custom_Source();
		 *         return $sources;
		 *     } );
		 *
		 * @since 4.0.3
		 *
		 * @param Source[] $bundled The default source instances.
		 */
		$registered = apply_filters( '404_to_301_import_sources', $bundled );

		if ( ! is_array( $registered ) ) {
			$registered = $bundled;
		}

		$byId = [];
		foreach ( $registered as $source ) {
			if ( $source instanceof Source ) {
				$byId[ $source->id() ] = $source;
			}
		}

		self::$sources = $byId;

		return self::$sources;
	}

	/**
	 * Only sources whose data store is actually present.
	 *
	 * Used by the picker in the UI — there's no point listing
	 * "Redirection" when the table doesn't exist.
	 *
	 * Note: CSV is always available (the user uploads the file) so it
	 * stays in the list regardless of what else is detected.
	 *
	 * @since 4.0.3
	 *
	 * @return array<string,Source>
	 */
	public static function available(): array {
		$available = [];
		foreach ( self::all() as $id => $source ) {
			if ( $source->isAvailable() ) {
				$available[ $id ] = $source;
			}
		}

		return $available;
	}

	/**
	 * Resolve a source by id, or null when it isn't registered.
	 *
	 * @since 4.0.3
	 *
	 * @param string $id Source id.
	 *
	 * @return Source|null
	 */
	public static function get( string $id ): ?Source {
		$all = self::all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Reset the cache.
	 *
	 * Not part of the public API; it exists so code that swaps the
	 * source filter at runtime can drop the memoised list.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$sources = null;
	}
}