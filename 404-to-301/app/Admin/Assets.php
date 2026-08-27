<?php
/**
 * Admin assets enqueue.
 *
 * Each plugin page mounts its own React app, so we enqueue only the
 * bundle that matches the current screen. The build artefacts come
 * from `npm run build` and their dependency lists live next to them
 * in `*.asset.php`, read via {@see Assets\Utils\Assets::manifest()}.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Main\Telegram\Connection;
use AIOSEO\FourNotFour\Utils\Plugin;

use AIOSEO\FourNotFour\Api\Endpoint;
use AIOSEO\FourNotFour\Main\Exporter\Download;
use AIOSEO\FourNotFour\Utils\Assets as AssetManifest;
use AIOSEO\FourNotFour\Utils\Helpers;

/**
 * Class Assets
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Admin
 */
class Assets {

	/**
	 * Map of admin screen id => entry handle.
	 *
	 * The keys come from `Plugin::screens()`; the values match the
	 * `assets/src/<name>.js` entry files emitted into `build/`.
	 *
	 * @since 4.0.0
	 * @var array<string, string>
	 */
	private const HANDLES = [
		'toplevel_page_404-to-301-redirects' => 'redirects',
		'redirects_page_404-to-301-logs'     => 'logs',
		'redirects_page_404-to-301-settings' => 'settings',
		'redirects_page_404-to-301-about'    => 'about',
		'admin_page_404-to-301-blc'          => 'blc',
	];

	/**
	 * Register hooks.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the React bundle for the current plugin admin page.
	 *
	 * @since 4.0.0
	 *
	 * @param string $hook Current admin screen hook.
	 *
	 * @return void
	 */
	public function enqueue( $hook ): void {
		if ( ! isset( self::HANDLES[ $hook ] ) ) {
			return;
		}

		$entry  = self::HANDLES[ $hook ];
		$handle = 'd404-' . $entry;
		$asset  = AssetManifest::manifest( $entry );
		$src    = AIOSEO_404_TO_301_PLUGIN_URL . 'build/' . $entry . '.js';

		wp_enqueue_script(
			$handle,
			$src,
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( $handle, '404-to-301', AIOSEO_404_TO_301_DIR . '/languages' );

		wp_localize_script(
			$handle,
			'd404',
			$this->scriptVars( $entry )
		);

		$cssPath = AIOSEO_404_TO_301_DIR . '/build/' . $entry . '.css';
		if ( is_readable( $cssPath ) ) {
			wp_enqueue_style(
				$handle,
				AIOSEO_404_TO_301_PLUGIN_URL . 'build/' . $entry . '.css',
				[ 'wp-components' ],
				$asset['version']
			);
		}
	}

	/**
	 * Localized script vars passed into every plugin React app.
	 *
	 * Filterable so integrations can inject their own payload (e.g. a
	 * feature flag or an extra endpoint URL).
	 *
	 * @since 4.0.0
	 *
	 * @param string $entry Entry handle (logs / redirects / settings).
	 *
	 * @return array
	 */
	private function scriptVars( string $entry ): array {
		$pages = Plugin::pages();
		$vars  = [
			'version'          => Plugin::version(),
			'slug'             => Plugin::SLUG,
			'name'             => Plugin::name(),
			'page'             => $entry,
			'pages'            => array_combine(
				array_keys( $pages ),
				array_map(
					static function ( $p ) {
						return $p['url'];
					},
					$pages
				)
			),
			'restUrl'          => rest_url( Endpoint::NAMESPACE . '/' ),
			'restNonce'        => wp_create_nonce( 'wp_rest' ),

			// The plugin install/deactivate routes live on the older router, which
			// registers under its own namespace rather than Endpoint::NAMESPACE.
			'pluginsRestBase'  => '/' . aioseo404To301()->api->namespace,
			'adminUrl'         => admin_url(),

			// Logged 404s are stored as request paths, so the front end needs the site
			// root to turn one back into a link an admin can click to test the redirect.
			'homeUrl'          => home_url(),

			/*
			 * The canonical redirect-status catalogue, shaped for the
			 * React selects. The Redirects form and the global-fallback
			 * setting both build their dropdowns off this list instead
			 * of hardcoding codes, so PHP stays the single source of
			 * truth (see `aioseo404To301()->helpers->redirectStatuses()`). Each entry is
			 * `{ value, label, terminal }`; `terminal` rows (410/451)
			 * hide the destination fields in the editor.
			 */
			'redirectStatuses' => $this->redirectStatusesPayload(),

			/*
			 * Hint for the React layer: is the v3 → v4 migration
			 * still in play on this site? When false, the Logs page
			 * skips mounting the migration banner entirely and the
			 * `useMigration` hook never fires its initial `GET
			 * /migration` request — there's nothing to poll for once
			 * the legacy table has been drained.
			 *
			 * Reading the cheap `logs_migrated` option is far lighter
			 * than the alternative (every page load fetches the
			 * migration status from REST + queries the legacy table
			 * for a count).
			 */
			'migrationPending' => ! (bool) aioseo404To301()->internalOptions->internal->logsMigrated,

			/*
			 * Nonce for the Logs Exporter's `admin-post.php` download.
			 * Only minted while the feature is on — there's no handler
			 * registered to receive it otherwise.
			 */
			'exportNonce'      => $this->exportNonce(),
			// Gates the deprecated Telegram settings tab: false on any site that never wired it up.
			'telegram'         => Connection::exists(),
			// Install/activation status per promoted plugin. The About page needs the whole
			// catalogue; the other screens only need the handful their CTA can offer, and
			// get_plugins() reads the filesystem, so nothing is built for screens with none.
			'plugins'          => $this->pluginData( $entry ),
		];

		/**
		 * Filter the localised script vars for an admin page.
		 *
		 * @since 4.0.0
		 *
		 * @param array  $vars  Localised vars.
		 * @param string $entry Entry handle.
		 */
		return (array) apply_filters( '404_to_301_admin_script_vars', $vars, $entry );
	}

	/**
	 * Mint the nonce the Logs page needs to request a CSV export.
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	private function exportNonce(): string {
		return wp_create_nonce( Download::ACTION );
	}

	/**
	 * Install/activation status for the plugins a given screen can promote.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $entry Entry handle.
	 * @return array         Plugin key => status, empty when the screen promotes nothing.
	 */
	private function pluginData( string $entry ): array {
		// Keys each screen's CTA can offer, plus the Pro builds that count as satisfying
		// them - the JS treats an active Pro build as "already handled".
		$promoted = [
			'about'     => [],
			'blc'       => [ 'brokenLinkChecker' ],
			'settings'  => [ 'wpMail', 'wpMailPro', 'brokenLinkChecker' ],
			'logs'      => [ 'brokenLinkChecker' ],
			'redirects' => [ 'aioseo', 'aioseoPro' ],
		];

		if ( ! isset( $promoted[ $entry ] ) ) {
			return [];
		}

		$data = aioseo404To301()->helpers->getPluginData();

		// The About page shows the whole catalogue.
		if ( empty( $promoted[ $entry ] ) ) {
			return $data;
		}

		return array_intersect_key( $data, array_flip( $promoted[ $entry ] ) );
	}

	/**
	 * Shape {@see aioseo404To301()->helpers->redirectStatuses()} for the React selects.
	 *
	 * Flattens the code-keyed catalogue into an ordered list of
	 * `{ value, label, terminal }` objects — the shape the
	 * `EnumSelectEdit` controls and the settings dropdown consume.
	 *
	 * @since 4.0.0
	 *
	 * @return array<int, array{value: int, label: string, terminal: bool}>
	 */
	private function redirectStatusesPayload(): array {
		$payload = [];

		foreach ( aioseo404To301()->helpers->redirectStatuses() as $code => $meta ) {
			$payload[] = [
				'value'    => (int) $code,
				'label'    => (string) ( $meta['label'] ?? $code ),
				'terminal' => ! empty( $meta['terminal'] ),
			];
		}

		return $payload;
	}
}