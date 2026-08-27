<?php
namespace AIOSEO\FourNotFour\Main\Migrations;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Utils\SettingsMap;

/**
 * Folds the pre-port flat `404_to_301_settings` option into the nested options.
 *
 * Before joining AIOSEO the plugin kept every setting as a flat snake_case key in a single option.
 * The template splits that into user-facing options and internal bookkeeping, both nested and
 * camelCase, so the values have to be carried across once.
 *
 * NOTE: the old option is deliberately left on disk. It costs one row, and keeping it means a site
 * that hits a problem after upgrading can be recovered by hand. `uninstall.php` removes it, and a
 * later release can drop it outright.
 *
 * @since 4.0.3
 */
class MigrateFlatSettings implements Migration {
	/**
	 * The pre-port option name.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const LEGACY_OPTION = '404_to_301_settings';

	/**
	 * The pre-port option holding the addon plugins 4.0.3 made redundant.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const LEGACY_REDUNDANT_OPTION = '404_to_301_redundant_addons';

	/**
	 * Stable identifier.
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	public function name() {
		return 'migrate_flat_settings';
	}

	/**
	 * Release this migration was introduced in.
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	public function version() {
		return '4.0.3';
	}

	/**
	 * Carries the flat option across into the nested options.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function up() {
		$legacy = get_option( self::LEGACY_OPTION, [] );
		$legacy = is_array( $legacy ) ? $legacy : [];

		// Nothing stored means a fresh install, so the defaults already stand. Still flag the
		// migration as done so the runner doesn't retry it on every request.
		if ( ! empty( $legacy ) ) {
			$options = [];
			foreach ( SettingsMap::OPTIONS as $flatKey => $path ) {
				if ( array_key_exists( $flatKey, $legacy ) ) {
					$options = $this->setPath( $options, $path, $legacy[ $flatKey ] );
				}
			}

			$internal = [];
			foreach ( SettingsMap::INTERNAL as $flatKey => $path ) {
				if ( array_key_exists( $flatKey, $legacy ) ) {
					$internal = $this->setPath( $internal, $path, $legacy[ $flatKey ] );
				}
			}

			$redundant = get_option( self::LEGACY_REDUNDANT_OPTION, [] );
			if ( ! empty( $redundant ) && is_array( $redundant ) ) {
				$internal = $this->setPath( $internal, 'internal.redundantAddons', $redundant );
			}

			if ( ! empty( $options ) ) {
				aioseo404To301()->options->sanitizeAndSave( $options );
			}

			if ( ! empty( $internal ) ) {
				aioseo404To301()->internalOptions->sanitizeAndSave( $internal );
			}
		}

		aioseo404To301()->internalOptions->sanitizeAndSave(
			[
				'internal' => [ 'settingsMigrated' => true ]
			]
		);
	}

	/**
	 * Whether the flat option has already been folded in.
	 *
	 * @since 4.0.3
	 *
	 * @return bool
	 */
	public function verify() {
		return (bool) aioseo404To301()->internalOptions->internal->settingsMigrated;
	}

	/**
	 * Writes a value into a nested array at a dot-delimited path.
	 *
	 * @since 4.0.3
	 *
	 * @param  array  $target The array to write into.
	 * @param  string $path   Dot-delimited path.
	 * @param  mixed  $value  The value to set.
	 * @return array          The array with the value set.
	 */
	private function setPath( $target, $path, $value ) {
		$keys    = explode( '.', $path );
		$pointer = &$target;

		foreach ( $keys as $key ) {
			if ( ! isset( $pointer[ $key ] ) || ! is_array( $pointer[ $key ] ) ) {
				$pointer[ $key ] = [];
			}
			$pointer = &$pointer[ $key ];
		}

		$pointer = $value;

		return $target;
	}
}