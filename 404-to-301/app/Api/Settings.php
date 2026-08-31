<?php
/**
 * Settings import/export REST endpoint.
 *
 * Lets admins (and the React UI) export the entire user-facing
 * settings object as a JSON envelope, and apply that envelope back
 * onto another site for staging-to-prod sync.
 *
 * The day-to-day `GET /settings` + `PATCH /settings` round-trip is
 * already served by core's `/wp/v2/settings` bridge (set up by
 * {@see \AIOSEO\FourNotFour\Settings::register()}) — this class is
 * deliberately scoped to the import/export workflow so the two paths
 * don't compete for the same route.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Utils\SettingsMap;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Settings
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Api
 */
class Settings extends Endpoint {

	/**
	 * Envelope schema version. Bumped only when the shape of the JSON
	 * payload changes in a way the importer needs to be aware of.
	 *
	 * @since 4.0.0
	 */
	const ENVELOPE_VERSION = 1;

	/**
	 * Keys that are scoped to a specific install and must never round-
	 * trip through an export/import cycle. `plugin_version` is excluded
	 * because the envelope already carries it at the top level; the
	 * others track installer/migration state that's meaningless on a
	 * different site.
	 *
	 * @since 4.0.0
	 * @var string[]
	 */
	const INTERNAL_KEYS = [
		'plugin_version',
		'db_version',
		'logs_migrated',
		'migration_started',
		'phase1_done',
		'legacy_table_dropped'
	];

	/**
	 * Dot paths that must never leave the site, or be accepted from an envelope.
	 *
	 * An export file gets attached to support tickets, committed to repos and copied between
	 * environments, so a live credential must not ride along in it. The same path is refused on
	 * import: an envelope from an untrusted source could otherwise point the Telegram feature at
	 * someone else's chat and quietly stream every 404 - URLs and visitor IPs included - to them.
	 *
	 * @since 4.0.4
	 *
	 * @var array
	 */
	const SECRET_PATHS = [
		[ 'telegram', 'botToken' ],
		[ 'telegram', 'chatId' ]
	];

	/**
	 * Register routes.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'read' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'requireAccess' ],
					'args'                => [
						'settings' => [
							'type'        => 'object',
							'required'    => true,
							'description' => 'Flat settings payload — only known keys are written.',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/export',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'export' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/import',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'import' ],
					'permission_callback' => [ $this, 'requireAccess' ],
					'args'                => [
						'settings' => [
							'type'        => 'object',
							'required'    => true,
							'description' => 'Settings payload — the `settings` object from a prior export envelope.',
						],
					],
				],
			]
		);
	}

	/**
	 * GET /settings.
	 *
	 * Returns the settings in the flat shape the admin app's fields are keyed by.
	 *
	 * @since 4.0.4
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->respond( [ 'settings' => SettingsMap::flatten() ] );
	}

	/**
	 * POST /settings.
	 *
	 * Accepts a partial flat payload, so a panel can save just the fields it owns.
	 *
	 * @since 4.0.4
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response {
		$incoming = (array) $request->get_param( 'settings' );
		$incoming = $this->guardTelegram( $incoming );

		$nested = SettingsMap::expand( $incoming );

		if ( ! empty( $nested ) ) {
			aioseo404To301()->options->sanitizeAndSave( $nested );
		}

		return $this->respond( [ 'settings' => SettingsMap::flatten() ] );
	}

	/**
	 * Refuse to switch Telegram Alerts back on.
	 *
	 * The feature is deprecated: a site with a live connection keeps working, but nothing may turn it
	 * on. Since the toggle is the only record of that, the rule is directional — true to false is
	 * allowed, false to true never is.
	 *
	 * @since 4.0.4
	 *
	 * @param  array $incoming Flat payload.
	 * @return array           The payload, with any attempt to re-enable Telegram dropped.
	 */
	private function guardTelegram( array $incoming ): array {
		if ( ! array_key_exists( 'telegram_alerts_enabled', $incoming ) ) {
			return $incoming;
		}

		$wanted = (bool) $incoming['telegram_alerts_enabled'];
		$isOn   = (bool) aioseo404To301()->options->telegram->enabled;

		if ( $wanted && ! $isOn ) {
			unset( $incoming['telegram_alerts_enabled'] );
		}

		return $incoming;
	}

	/**
	 * GET /settings/export.
	 *
	 * Returns a JSON envelope describing the current settings. The
	 * React UI turns the body into a downloadable file; CLI consumers
	 * can pipe the body straight into a file.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response {
		unset( $request ); // No args.

		// Internal state lives in its own option, so nothing internal is in here to strip.
		$settings = self::withoutSecrets( aioseo404To301()->options->all() );

		$envelope = [
			'plugin'         => '404-to-301',
			'schema_version' => self::ENVELOPE_VERSION,
			'plugin_version' => defined( 'AIOSEO_404_TO_301_VERSION' ) ? AIOSEO_404_TO_301_VERSION : '',
			'exported_at'    => gmdate( 'c' ),
			'site_url'       => home_url(),
			'settings'       => $settings,
		];

		/**
		 * Filter the settings-export envelope before it's returned.
		 *
		 * Addons that store their own settings inside the plugin's
		 * option get carried automatically — they share the same
		 * `settings` payload. Addons that store state elsewhere can
		 * append to the envelope here.
		 *
		 * @since 4.0.0
		 *
		 * @param array $envelope The export envelope.
		 */
		$envelope = (array) apply_filters( '404_to_301_settings_export', $envelope );

		return $this->respond( $envelope );
	}

	/**
	 * POST /settings/import.
	 *
	 * Accepts an object — either the full envelope or just its
	 * `settings` payload — and applies it to the current site. The
	 * write goes through {@see \AIOSEO\FourNotFour\Options\Options::sanitizeAndSave()} so every key
	 * is sanitised by the existing pipeline before it lands on disk;
	 * unknown keys are dropped by `sanitize()` rather than rejected,
	 * so an envelope produced by a newer plugin version downgrades
	 * gracefully.
	 *
	 * Per-install state ({@see self::INTERNAL_KEYS}) is stripped from
	 * the incoming payload — importing those would clobber
	 * installer/migration state on the destination site.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( WP_REST_Request $request ) {
		$body = $request->get_param( 'settings' );

		if ( ! is_array( $body ) ) {
			return $this->error(
				'rest_invalid_payload',
				__( 'Invalid import payload — expected an object.', '404-to-301' ),
				400
			);
		}

		// Accept either the raw settings object (caller pre-stripped
		// the envelope) or the full envelope (caller posted the file
		// contents verbatim). The presence of a `plugin` key is the
		// signal that we're holding an envelope.
		$incoming = isset( $body['plugin'] ) && isset( $body['settings'] ) && is_array( $body['settings'] )
			? $body['settings']
			: $body;

		// Only reachable from a pre-4.x envelope, where internal state sat alongside the settings.
		foreach ( self::INTERNAL_KEYS as $key ) {
			unset( $incoming[ $key ] );
		}

		$incoming = self::withoutSecrets( $incoming );

		/**
		 * Filter the imported payload before it's merged.
		 *
		 * @since 4.0.0
		 *
		 * @param array $incoming Sanitised candidate settings.
		 * @param array $body     Raw request body.
		 */
		$incoming = (array) apply_filters( '404_to_301_settings_import', $incoming, $body );

		if ( empty( $incoming ) ) {
			return $this->error(
				'rest_empty_payload',
				__( 'Import payload contained no usable settings.', '404-to-301' ),
				400
			);
		}

		// sanitizeAndSave() replaces recursively over the current values, so an envelope from an older
		// plugin version leaves everything it doesn't carry untouched rather than nulling it.
		aioseo404To301()->options->sanitizeAndSave( $incoming );

		return $this->respond(
			[
				'imported' => count( $incoming ),
				'settings' => aioseo404To301()->options->all(),
			]
		);
	}

	/**
	 * Strips every SECRET_PATHS entry out of a settings tree.
	 *
	 * @since 4.0.4
	 *
	 * @param  array $settings Nested settings.
	 * @return array           The same tree without the secrets.
	 */
	private static function withoutSecrets( array $settings ): array {
		foreach ( self::SECRET_PATHS as $path ) {
			$cursor = &$settings;
			$last   = array_key_last( $path );

			foreach ( $path as $i => $segment ) {
				if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
					break;
				}

				if ( $i === $last ) {
					unset( $cursor[ $segment ] );
					break;
				}

				$cursor = &$cursor[ $segment ];
			}

			unset( $cursor );
		}

		return $settings;
	}
}