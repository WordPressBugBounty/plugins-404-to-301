<?php
namespace AIOSEO\FourNotFour\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the build manifest `@wordpress/scripts` emits next to each bundle.
 *
 * NOTE: the admin app is React on wp-scripts, not Vue on vite like the other AIOSEO plugins, so it
 * reads a `*.asset.php` manifest rather than a vite manifest. Falls back to safe defaults when the
 * bundle hasn't been built, so a fresh checkout doesn't fatal the admin.
 *
 * @since 4.0.3
 */
class Assets {
	/**
	 * Directory the bundles are emitted into, relative to the plugin root.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const BUILD_DIR = 'build/';

	/**
	 * Returns the dependency list and version hash for a compiled bundle.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $handle Asset handle, e.g. `settings`.
	 * @return array          Keys `dependencies` and `version`.
	 */
	public static function manifest( $handle ) {
		$file = AIOSEO_404_TO_301_DIR . '/' . self::BUILD_DIR . $handle . '.asset.php';
		$data = is_readable( $file ) ? require $file : [];

		return wp_parse_args(
			(array) $data,
			[
				'dependencies' => [],
				'version'      => AIOSEO_404_TO_301_VERSION
			]
		);
	}
}