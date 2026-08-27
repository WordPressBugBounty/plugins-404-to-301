<?php
namespace AIOSEO\FourNotFour\Main\Migrations;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Utils\Sanitizer;

/**
 * Folds the v3 `i4t3_gnrl_options` option into the nested options.
 *
 * Sites that ran 4.0.x had their v3 settings converted on activation by that release, so by the time
 * they reach this version {@see MigrateFlatSettings} has everything it needs. A site jumping straight
 * from 3.x has never run that code — without this migration its redirect type, fallback target,
 * exclusions and email notifications all silently revert to defaults, and {@see Migrator} then
 * deletes the v3 option, taking the only copy with it.
 *
 * The value mapping is deliberately identical to the 4.0.x conversion so both upgrade paths land on
 * the same settings.
 *
 * @since 4.0.3
 */
class MigrateV3Settings implements Migration {
	/**
	 * The v3 options blob.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const LEGACY_OPTION = 'i4t3_gnrl_options';

	/**
	 * The 4.0.x flat option. Its presence means the conversion already happened.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const FLAT_OPTION = '404_to_301_settings';

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	public function name() {
		return 'migrate_v3_settings';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	public function version() {
		return '4.0.3';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function up() {
		$legacy = get_option( self::LEGACY_OPTION, null );

		// A site that already carries the 4.0.x flat option has been converted once; converting again
		// from v3 would overwrite whatever the user has changed since.
		if ( ! is_array( $legacy ) || false !== get_option( self::FLAT_OPTION, false ) ) {
			aioseo404To301()->internalOptions->internal->v3SettingsMigrated = true;

			return;
		}

		$options = aioseo404To301()->options;

		if ( isset( $legacy['redirect_type'] ) ) {
			$options->redirects->statusCode = Sanitizer::enum(
				(string) $legacy['redirect_type'],
				[ '301', '302', '303', '307', '308', '410', '451' ],
				'301'
			);
		}

		if ( isset( $legacy['redirect_to'] ) ) {
			// The v3 vocabulary was page, link, or zero meaning don't redirect at all.
			switch ( (string) $legacy['redirect_to'] ) {
				case 'page':
					$target = 'page';
					break;
				case '0':
					$target = 'none';
					break;
				default:
					$target = 'link';
			}

			$options->redirects->target  = $target;
			$options->redirects->enabled = 'none' !== $target;
		}

		if ( isset( $legacy['redirect_link'] ) ) {
			$options->redirects->link = Sanitizer::url( (string) $legacy['redirect_link'] );
		}

		if ( isset( $legacy['redirect_page'] ) ) {
			$options->redirects->pageId = Sanitizer::integer( $legacy['redirect_page'], 0 );
		}

		if ( isset( $legacy['redirect_log'] ) ) {
			$options->logs->enabled = Sanitizer::boolean( $legacy['redirect_log'] );
		}

		if ( isset( $legacy['email_notify'] ) ) {
			$options->notifications->email->enabled = Sanitizer::boolean( $legacy['email_notify'] );
		}

		if ( isset( $legacy['email_notify_address'] ) ) {
			$options->notifications->email->recipients = Sanitizer::emailList( (string) $legacy['email_notify_address'] );
		}

		if ( isset( $legacy['disable_guessing'] ) ) {
			// v3 stored a single boolean. Its "on" bypassed canonical guessing entirely, which is what
			// `strict` does here — `light` would quietly weaken the setting the user chose.
			$options->general->disableGuessing = Sanitizer::boolean( $legacy['disable_guessing'] ) ? 'strict' : 'off';
		}

		if ( isset( $legacy['exclude_paths'] ) ) {
			$options->general->excludePaths = Sanitizer::stringList( $legacy['exclude_paths'] );
		}

		aioseo404To301()->internalOptions->internal->v3SettingsMigrated = true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return bool
	 */
	public function verify() {
		return (bool) aioseo404To301()->internalOptions->internal->v3SettingsMigrated;
	}
}