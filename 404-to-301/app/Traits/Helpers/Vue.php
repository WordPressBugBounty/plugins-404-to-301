<?php
namespace AIOSEO\FourNotFour\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Generates the data we need for Vue.
 *
 * @since 1.0.0
 */
trait Vue {
	/**
	 * The data to pass to Vue.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $vueData = [];

	/**
	 * Returns the data for Vue.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $currentPage The current page.
	 * @return array               The data.
	 */
	public function getVueData( $currentPage = null ) {
		global $wp_version; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		$this->vueData = [
			// The following data is needed on all screens.
			'wpVersion'           => $wp_version, // phpcs:ignore Squiz.NamingConventions.ValidVariableName
			'page'                => $currentPage,
			'screen'              => aioseo404To301()->helpers->getCurrentScreen(),
			'internalOptions'     => aioseo404To301()->internalOptions->all(),
			'options'             => aioseo404To301()->options->all(),
			'settings'            => aioseo404To301()->vueSettings->all(),
			'helpPanel'           => [],
			'urls'                => [
				'domain'        => $this->getSiteDomain(),
				'mainSiteUrl'   => $this->getSiteUrl(),
				'home'          => home_url(),
				'restUrl'       => rest_url(),
				'editScreen'    => admin_url( 'edit.php' ),
				'publicPath'    => aioseo404To301()->core->assets->normalizeAssetsHost( plugin_dir_url( AIOSEO_404_TO_301_FILE ) ),
				'assetsPath'    => aioseo404To301()->core->assets->getAssetsPath(),
				'marketingSite' => $this->getMarketingSiteUrl(),
				'connect'       => admin_url( 'index.php?page=404-to-301-connect' )
			],
			'isDev'               => $this->isDev(),
			'isAioseoActive'      => function_exists( 'aioseo' ),
			'isSsl'               => is_ssl(),
			'isMultisite'         => is_multisite(),
			'isNetworkAdmin'      => is_network_admin(),
			'mainSite'            => is_main_site(),
			'hasUrlTrailingSlash' => '/' === user_trailingslashit( '' ),
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'translations'        => $this->getJedLocaleData( '404-to-301' )
		];

		// In multisite, super admins may not have explicit roles on subsites.
		// Ensure they have administrator role and capabilities for proper access.
		$userData     = wp_get_current_user();
		$roles        = $userData->roles;
		$capabilities = $userData->allcaps;

		// If the user is a network admin, and doesn't have a user on the subsite, give him admin role/caps.
		if ( is_multisite() && is_super_admin() && empty( $roles ) ) {
			$roles     = [ 'administrator' ];
			$adminRole = get_role( 'administrator' );
			if ( is_a( $adminRole, 'WP_Role' ) ) {
				$capabilities = $adminRole->capabilities;
			}
		}

		$this->vueData['user'] = [
			'canManage'    => aioseo404To301()->access->canManage(),
			'roles'        => $roles,
			'capabilities' => $capabilities,
			'locale'       => function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale()
		];

		switch ( $currentPage ) {
			case 'about':
				$this->addAboutData();
				break;
			default:
				break;
		}

		return $this->vueData;
	}

	/**
	 * Adds the data for the About Us screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function addAboutData() {
		$this->vueData['plugins'] = $this->getPluginData();
	}

	/**
	 * Returns Jed-formatted localization data.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $domain The text domain.
	 * @return array          The information of the locale.
	 */
	private function getJedLocaleData( $domain ) {
		$translations = get_translations_for_domain( $domain );

		$locale = [
			'' => [
				'domain' => $domain,
				'lang'   => is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale(),
			],
		];

		if ( ! empty( $translations->headers['Plural-Forms'] ) ) {
			$locale['']['plural_forms'] = $translations->headers['Plural-Forms'];
		}

		foreach ( $translations->entries as $msgid => $entry ) {
			if ( empty( $entry->translations ) || ! is_array( $entry->translations ) ) {
				continue;
			}

			$locale[ $msgid ] = $entry->translations;
		}

		return $locale;
	}

	/**
	 * Returns the marketing site URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string The marketing site URL.
	 */
	private function getMarketingSiteUrl() {
		if ( defined( 'AIOSEO_MARKETING_SITE_URL' ) && AIOSEO_MARKETING_SITE_URL ) {
			return AIOSEO_MARKETING_SITE_URL;
		}

		return 'https://aioseo.com/';
	}
}