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
use AIOSEO\FourNotFour\Api\Mcp;
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
	 * `assets/src/<name>.js` entry files emitted into `build/`. The promo
	 * landing pages are matched separately - their screen id moves with the
	 * rotation, so {@see self::promoKey()} reads it off the hook suffix.
	 *
	 * @since 4.0.0
	 * @var array<string, string>
	 */
	private const HANDLES = [
		'toplevel_page_404-to-301-redirects' => 'redirects',
		'redirects_page_404-to-301-logs'     => 'logs',
		'redirects_page_404-to-301-settings' => 'settings',
		'redirects_page_404-to-301-about'    => 'about',
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
		$promoKey = $this->promoKey( $hook );

		if ( ! isset( self::HANDLES[ $hook ] ) && '' === $promoKey ) {
			return;
		}

		$entry  = '' !== $promoKey ? 'promo' : self::HANDLES[ $hook ];
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

		$vars = $this->scriptVars( $entry );

		if ( '' !== $promoKey ) {
			$vars['promo'] = $promoKey;
		}

		wp_localize_script( $handle, 'd404', $vars );

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
	 * The promo landing page a screen hook belongs to.
	 *
	 * WordPress builds the hook from the parent menu, and only the promoted page of the moment has
	 * one - so the prefix is `redirects_page_` for that page and `admin_page_` for the other four.
	 * Matching on the suffix covers both without the rotation leaking into this map.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $hook Current admin screen hook.
	 * @return string       Promo page key, or '' when the screen is not one.
	 */
	private function promoKey( string $hook ): string {
		foreach ( PromoMenu::keys() as $key ) {
			$slug = '404-to-301-' . $key;

			if ( "admin_page_{$slug}" === $hook || "redirects_page_{$slug}" === $hook ) {
				return $key;
			}
		}

		return '';
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

			// The header lockup links out; the UTM is built here so the campaign naming stays in
			// one place rather than being reassembled in JS.
			'logoUrl'          => aioseo404To301()->helpers->utmUrl( AIOSEO_404_TO_301_MARKETING_URL, 'header-logo' ),

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
			// Only the Settings page carries the MCP tab, and building this walks the plugin
			// list and the current user's Application Passwords - nothing another screen
			// should pay for.
			'mcp'              => 'settings' === $entry ? $this->mcpData() : null,
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
	 * @since 4.0.4
	 *
	 * @param  string $entry Entry handle.
	 * @return array         Plugin key => status, empty when the screen promotes nothing.
	 */
	private function pluginData( string $entry ): array {
		// Keys each screen's CTA can offer, plus the Pro builds that count as satisfying
		// them - the JS treats an active Pro build as "already handled".
		$promoted = [
			'about'     => [],
			// One entry per landing page; the page reads the one it is pitching.
			'promo'     => array_merge(
				array_column( PromoMenu::items(), 'plugin' ),
				[ 'aioseoPro' ]
			),
			'settings'  => [ 'wpMail', 'wpMailPro', 'brokenLinkChecker', 'wpVibe' ],
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
	 * Server-side state the MCP settings tab renders its setup steps from.
	 *
	 * Every flag is read fresh on each page load rather than remembered client-side: an
	 * Application Password can be revoked and a plugin deactivated between two visits, and a
	 * stored "you're connected" flag would keep claiming otherwise.
	 *
	 * @since 4.0.4
	 *
	 * @return array
	 */
	private function mcpData(): array {
		$user = wp_get_current_user();

		return [
			'abilitiesApiAvailable' => function_exists( 'wp_register_ability' ),
			// Counted across all plugins: on a WP version that has the Abilities API, zero means
			// something is suppressing it, since core registers abilities of its own.
			'totalAbilities'        => function_exists( 'wp_get_abilities' ) ? count( wp_get_abilities() ) : 0,
			'abilities'             => $this->registeredAbilities(),
			'adapterActive'         => '' !== Mcp::activeAdapterFile(),
			'adapterInstalled'      => '' !== Mcp::installedAdapterFile(),
			'hasAppPassword'        => Mcp::currentUserHasAppPassword(),
			// `supported` is core's HTTPS/local-environment gate; `available` also accounts for a
			// security plugin or filter switching the feature off. Each drives its own guidance.
			'appPasswordsSupported' => is_ssl() || 'local' === wp_get_environment_type(),
			'appPasswordsAvailable' => Mcp::appPasswordsAvailable(),
			'username'              => $user ? (string) $user->user_login : '',
			'profileUrl'            => admin_url( 'profile.php#application-passwords-section' ),
			'updateCoreUrl'         => admin_url( 'update-core.php' ),
			'abilitiesRestUrl'      => rest_url( 'wp-abilities/v1/abilities' ),
			'serverUrl'             => rest_url( 'mcp/mcp-adapter-default-server' ),
		];
	}

	/**
	 * This plugin's abilities, grouped for display.
	 *
	 * @since 4.0.4
	 *
	 * @return array<int, array{slug: string, label: string, items: array}>
	 */
	private function registeredAbilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}

		$categoryLabels = [];
		if ( function_exists( 'wp_get_ability_categories' ) ) {
			foreach ( wp_get_ability_categories() as $category ) {
				$categoryLabels[ $category->get_slug() ] = $category->get_label();
			}
		}

		$groups = [];
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();

			if ( 0 !== strpos( $name, '404-to-301/' ) ) {
				continue;
			}

			$slug = $ability->get_category();

			if ( ! isset( $groups[ $slug ] ) ) {
				$groups[ $slug ] = [
					'slug'  => $slug,
					// The category label carries the plugin name for the global abilities list;
					// on this page every row is ours, so the prefix is noise.
					'label' => preg_replace( '/^404 to 301\s*[—–-]+\s*/u', '', (string) ( $categoryLabels[ $slug ] ?? $slug ) ),
					'items' => [],
				];
			}

			$groups[ $slug ]['items'][] = [
				'name'        => $name,
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
			];
		}

		return array_values( $groups );
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