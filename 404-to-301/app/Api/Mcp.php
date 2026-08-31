<?php
/**
 * MCP setup REST endpoint.
 *
 * Backs the two actions the MCP page offers: installing the MCP Adapter plugin, and minting an
 * Application Password an AI client can sign in with.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Mcp
 *
 * @since   4.0.4
 * @package AIOSEO\FourNotFour\Api
 */
class Mcp extends Endpoint {

	/**
	 * GitHub Releases API endpoint for the canonical MCP Adapter plugin.
	 *
	 * @since 4.0.4
	 */
	const GITHUB_LATEST_RELEASE_URL = 'https://api.github.com/repos/WordPress/mcp-adapter/releases/latest';

	/**
	 * Transient key for the cached GitHub release payload.
	 *
	 * @since 4.0.4
	 */
	const RELEASE_CACHE_KEY = 'aioseo_404_to_301_mcp_adapter_release';

	/**
	 * Identifier stored on the Application Passwords this plugin creates.
	 *
	 * Lets the MCP page tell "the user already generated one here" from "the user has Application
	 * Passwords for other apps", without reading any password material.
	 *
	 * @since 4.0.4
	 */
	const APP_PASSWORD_ID = '404-to-301-mcp';

	/**
	 * Register routes.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/mcp/install-adapter',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'installAdapter' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/mcp/app-password',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'generateAppPassword' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
			]
		);
	}

	/**
	 * Every installed copy of the MCP Adapter plugin.
	 *
	 * @since 4.0.4
	 *
	 * @return string[] Plugin files relative to the plugins directory.
	 */
	private static function adapterFiles(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$files = [];

		foreach ( get_plugins() as $pluginFile => $pluginData ) {
			// A repackaged or hand-renamed copy lands outside `mcp-adapter/`; its text domain doesn't.
			$isAdapter = 0 === strpos( (string) $pluginFile, 'mcp-adapter/' )
				|| 'mcp-adapter' === (string) ( $pluginData['TextDomain'] ?? '' );

			if ( $isAdapter ) {
				$files[] = (string) $pluginFile;
			}
		}

		return $files;
	}

	/**
	 * The plugin file of an installed MCP Adapter, if there is one.
	 *
	 * @since 4.0.4
	 *
	 * @return string Plugin file relative to the plugins directory, or '' when not installed.
	 */
	public static function installedAdapterFile(): string {
		$files = self::adapterFiles();

		return $files ? (string) reset( $files ) : '';
	}

	/**
	 * The plugin file of an active MCP Adapter, if there is one.
	 *
	 * NOTE: deliberately not a class lookup. `WP\MCP\Core\McpAdapter` also exists when another
	 * plugin ships the library as a Composer dependency, and that gives the site no MCP server -
	 * keying on it reported the setup complete on a site an AI client could not reach.
	 *
	 * @since 4.0.4
	 *
	 * @return string Plugin file relative to the plugins directory, or '' when none is active.
	 */
	public static function activeAdapterFile(): string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( self::adapterFiles() as $pluginFile ) {
			if ( is_plugin_active( $pluginFile ) ) {
				return $pluginFile;
			}
		}

		return '';
	}

	/**
	 * Whether the current user already generated an Application Password here.
	 *
	 * @since 4.0.4
	 *
	 * @return bool
	 */
	public static function currentUserHasAppPassword(): bool {
		$userId = get_current_user_id();

		if ( ! $userId || ! class_exists( '\WP_Application_Passwords' ) ) {
			return false;
		}

		foreach ( \WP_Application_Passwords::get_user_application_passwords( $userId ) as $appPassword ) {
			if ( self::APP_PASSWORD_ID === ( $appPassword['app_id'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an Application Password can be generated for the current user.
	 *
	 * False when the feature is off for any reason - the HTTPS/local-env gate, a security plugin, or
	 * the `wp_is_application_passwords_available[_for_user]` filter. The per-user core function calls
	 * the global one internally, so this covers every cause.
	 *
	 * @since 4.0.4
	 *
	 * @return bool
	 */
	public static function appPasswordsAvailable(): bool {
		if ( ! function_exists( 'wp_is_application_passwords_available' ) ) {
			return false;
		}

		$userId = get_current_user_id();

		if ( $userId && function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
			return wp_is_application_passwords_available_for_user( $userId );
		}

		return wp_is_application_passwords_available();
	}

	/**
	 * POST /mcp/install-adapter — install and activate the MCP Adapter plugin.
	 *
	 * Three cases, in this order. An already-active copy is a no-op - activating a second one
	 * redeclares the library's bootstrap functions and takes the request down with a fatal. Files
	 * already on disk only need activating: on multisite the plugins directory is shared across the
	 * network, so a subsite reaches this branch whenever any other site installed the adapter, and
	 * re-installing over the existing folder is exactly what the upgrader fails on. Only a genuinely
	 * absent adapter is downloaded, which on multisite is a network-administrator operation.
	 *
	 * @since 4.0.4
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function installAdapter( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		if ( '' !== self::activeAdapterFile() ) {
			return $this->respond(
				[
					'success' => true,
					'message' => __( 'The MCP Adapter is already active on this site.', '404-to-301' ),
				]
			);
		}

		$installed = self::installedAdapterFile();
		if ( '' !== $installed ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return $this->respond(
					[
						'success' => false,
						'message' => is_multisite()
							? __( 'The MCP Adapter is installed but not active on this site. Ask a network administrator to network-activate it.', '404-to-301' )
							: __( 'You do not have permission to activate plugins.', '404-to-301' ),
					],
					403
				);
			}

			return $this->activate( $installed, __( 'MCP Adapter activated on this site.', '404-to-301' ) );
		}

		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			return $this->respond(
				[
					'success' => false,
					'message' => is_multisite()
						? __( 'On multisite, the MCP Adapter must be installed and network-activated by a network administrator before it is available on this site.', '404-to-301' )
						: __( 'You do not have permission to install plugins.', '404-to-301' ),
				],
				403
			);
		}

		$release = $this->latestRelease();
		if ( empty( $release['download_url'] ) ) {
			return $this->respond(
				[
					'success' => false,
					'message' => (string) ( $release['message'] ?? __( 'Could not resolve the MCP Adapter download URL.', '404-to-301' ) ),
				],
				502
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $release['download_url'] );

		if ( is_wp_error( $result ) ) {
			return $this->respond(
				[
					'success' => false,
					'message' => $result->get_error_message(),
				],
				500
			);
		}

		if ( false === $result || ! $upgrader->plugin_info() ) {
			return $this->respond(
				[
					'success' => false,
					'message' => __( 'The MCP Adapter install failed. Check the site\'s write permissions and try again.', '404-to-301' ),
				],
				500
			);
		}

		return $this->activate(
			(string) $upgrader->plugin_info(),
			sprintf(
				/* translators: %s: installed MCP Adapter version. */
				__( 'MCP Adapter %s installed and activated.', '404-to-301' ),
				(string) ( $release['version'] ?? '' )
			)
		);
	}

	/**
	 * POST /mcp/app-password — mint an Application Password for the current user.
	 *
	 * The password is returned once and never recoverable, so the caller has to surface it
	 * immediately.
	 *
	 * @since 4.0.4
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function generateAppPassword( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return $this->respond(
				[
					'success' => false,
					'message' => __( 'Application Passwords are not available on this site.', '404-to-301' ),
				],
				500
			);
		}

		$userId = get_current_user_id();
		if ( ! $userId ) {
			return $this->respond(
				[
					'success' => false,
					'message' => __( 'You must be logged in to generate an Application Password.', '404-to-301' ),
				],
				403
			);
		}

		// The same per-user gate core's own Application Passwords controller enforces. 2FA and
		// security plugins use it to switch the feature off for specific users, and this must not
		// become a way around that.
		if ( ! self::appPasswordsAvailable() ) {
			return $this->respond(
				[
					'success' => false,
					'message' => __( 'Application Passwords are not available for your account. Please contact a site administrator.', '404-to-301' ),
				],
				403
			);
		}

		$created = \WP_Application_Passwords::create_new_application_password(
			$userId,
			[
				'name'   => '404 to 301 MCP',
				'app_id' => self::APP_PASSWORD_ID,
			]
		);

		if ( is_wp_error( $created ) ) {
			return $this->respond(
				[
					'success' => false,
					'message' => $created->get_error_message(),
				],
				500
			);
		}

		$user = wp_get_current_user();

		return $this->respond(
			[
				'success'  => true,
				'username' => $user ? (string) $user->user_login : '',
				'password' => isset( $created[0] ) ? (string) $created[0] : '',
				'message'  => __( 'Application Password generated. Copy it now — it will not be shown again.', '404-to-301' ),
			]
		);
	}

	/**
	 * Activate a plugin file and shape the response.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $pluginFile Plugin file relative to the plugins directory.
	 * @param  string $message    Message to return on success.
	 * @return WP_REST_Response
	 */
	private function activate( string $pluginFile, string $message ): WP_REST_Response {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$activated = activate_plugin( $pluginFile );

		if ( is_wp_error( $activated ) ) {
			return $this->respond(
				[
					'success' => false,
					'message' => $activated->get_error_message(),
				],
				500
			);
		}

		return $this->respond(
			[
				'success' => true,
				'plugin'  => $pluginFile,
				'message' => $message,
			]
		);
	}

	/**
	 * The latest MCP Adapter release metadata, cached for an hour.
	 *
	 * @since 4.0.4
	 *
	 * @return array Keys: version, download_url — or message when the lookup failed.
	 */
	private function latestRelease(): array {
		$cached = get_transient( self::RELEASE_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::GITHUB_LATEST_RELEASE_URL,
			[
				'timeout' => 10,
				'headers' => [ 'Accept' => 'application/vnd.github+json' ],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [ 'message' => __( 'Could not fetch the latest MCP Adapter release from GitHub.', '404-to-301' ) ];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) || empty( $body['assets'][0]['browser_download_url'] ) ) {
			return [ 'message' => __( 'The GitHub response did not include a downloadable release asset.', '404-to-301' ) ];
		}

		$release = [
			'version'      => ltrim( (string) $body['tag_name'], 'v' ),
			'download_url' => esc_url_raw( (string) $body['assets'][0]['browser_download_url'] ),
		];

		set_transient( self::RELEASE_CACHE_KEY, $release, HOUR_IN_SECONDS );

		return $release;
	}
}