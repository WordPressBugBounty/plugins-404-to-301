<?php
/**
 * Plugin identity and URL helpers.
 *
 * Holds the small set of constants and helpers that describe the
 * plugin to the outside world (slug, page slugs, admin URLs, admin
 * screen IDs).
 *
 * The class is intentionally static — there is only one plugin, so
 * carrying around an instance just to read its name would be noise.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Admin\PromoMenu;

/**
 * Class Plugin
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour
 */
class Plugin {

	/**
	 * Plugin slug.
	 *
	 * Matches the WordPress.org slug and is used as a prefix for
	 * options, REST namespaces and admin page slugs.
	 *
	 * @since 4.0.0
	 */
	const SLUG = '404-to-301';

	/**
	 * Top-level admin menu slug (the Logs page).
	 *
	 * @since 4.0.0
	 */
	const PAGE_LOGS = '404-to-301-logs';

	/**
	 * Redirects sub-page slug.
	 *
	 * @since 4.0.0
	 */
	const PAGE_REDIRECTS = '404-to-301-redirects';

	/**
	 * Settings sub-page slug.
	 *
	 * @since 4.0.0
	 */
	const PAGE_SETTINGS = '404-to-301-settings';

	/**
	 * The About Us page slug.
	 *
	 * @since 4.0.4
	 *
	 * @var string
	 */
	const PAGE_ABOUT = '404-to-301-about';

	/**
	 * Landing page that installs Broken Link Checker.
	 *
	 * One of the rotating promo pages ({@see \AIOSEO\FourNotFour\Admin\PromoMenu}); named because
	 * the dashboard widget and the Site Health check link straight to it.
	 *
	 * @since 4.0.4
	 */
	const PAGE_BLC = '404-to-301-blc';

	/**
	 * Admin screen IDs of every plugin page.
	 *
	 * Keyed by short name so callers can look up either the ID or the
	 * URL without having to remember how WordPress prefixes screen IDs
	 * for top-level vs sub-menu pages.
	 *
	 * @since 4.0.0
	 *
	 * @var array<string, string>
	 */
	private static $screens = [
		'redirects' => 'toplevel_page_404-to-301-redirects',
		'logs'      => 'redirects_page_404-to-301-logs',
		'settings'  => 'redirects_page_404-to-301-settings',
		'about'     => 'redirects_page_404-to-301-about',
	];

	/**
	 * Get the plugin slug.
	 *
	 * Matches the wp.org slug and is used as the text domain.
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public static function slug(): string {
		return self::SLUG;
	}

	/**
	 * Get the human-readable plugin name.
	 *
	 * Intentionally not translated — used in places where the brand
	 * name needs to stay consistent (admin menu, plugin row).
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public static function name(): string {
		return '404 to 301';
	}

	/**
	 * Get the current plugin version.
	 *
	 * Resolves to whatever the `Version:` header in the main plugin
	 * file declares (loaded into `AIOSEO_404_TO_301_VERSION` at boot).
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public static function version(): string {
		return AIOSEO_404_TO_301_VERSION;
	}

	/**
	 * Get every plugin admin screen ID.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string, string> Map of short name => screen ID.
	 */
	public static function screens(): array {
		return self::$screens;
	}

	/**
	 * Get every plugin admin page as { id, url, slug } triples.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string, array{id: string, url: string, slug: string}>
	 */
	public static function pages(): array {
		$pages = [];

		foreach ( self::$screens as $key => $id ) {
			$slug          = '404-to-301-' . $key;
			$pages[ $key ] = [
				'id'   => $id,
				'url'  => admin_url( 'admin.php?page=' . $slug ),
				'slug' => $slug,
			];
		}

		/*
		 * The promo landing pages carry no fixed screen id: whichever one is currently in the menu
		 * is registered under the plugin's parent and the rest are hidden, so the id depends on the
		 * rotation. Their URLs don't, which is all a caller needs.
		 */
		foreach ( PromoMenu::keys() as $key ) {
			$slug          = '404-to-301-' . $key;
			$pages[ $key ] = [
				'id'   => '',
				'url'  => admin_url( 'admin.php?page=' . $slug ),
				'slug' => $slug,
			];
		}

		return $pages;
	}

	/**
	 * Get the admin URL of one of the plugin pages.
	 *
	 * @since 4.0.0
	 *
	 * @param string $page Page key (logs, settings, redirects, about).
	 *
	 * @return string Absolute admin URL, or an empty string for an unknown page key.
	 */
	public static function getUrl( string $page = 'settings' ): string {
		$pages = self::pages();

		return $pages[ $page ]['url'] ?? '';
	}

	/**
	 * Build the admin URL of the settings page.
	 *
	 * Convenience accessor used by plugin row links and CTAs.
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public static function settingsUrl(): string {
		return self::getUrl( 'settings' );
	}

	/**
	 * Get the admin screen ID of a plugin page.
	 *
	 * @since 4.0.0
	 *
	 * @param string $page Page key (logs, settings, redirects, about).
	 *
	 * @return string
	 */
	public static function screenId( string $page = 'logs' ): string {
		return self::$screens[ $page ] ?? '';
	}

	/**
	 * Whether the current admin screen is one of the plugin's pages.
	 *
	 * @since 4.0.0
	 *
	 * @param string|null $page Optional page key to match against.
	 *
	 * @return bool
	 */
	public static function isPluginScreen( ?string $page = null ): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}

		if ( null === $page ) {
			return in_array( $screen->id, self::$screens, true ) || self::isPromoScreen( $screen->id );
		}

		if ( PromoMenu::has( $page ) ) {
			return self::isPromoScreen( $screen->id, $page );
		}

		return self::screenId( $page ) === $screen->id;
	}

	/**
	 * Whether a screen id belongs to a promo landing page.
	 *
	 * Matched by suffix because the prefix moves with the rotation: WordPress derives it from the
	 * parent menu, and only the promoted page of the moment has one.
	 *
	 * @since 4.0.4
	 *
	 * @param  string      $screenId Screen id to test.
	 * @param  string|null $page     Optional single page key to match; all of them otherwise.
	 * @return bool
	 */
	private static function isPromoScreen( string $screenId, ?string $page = null ): bool {
		foreach ( ( null === $page ? PromoMenu::keys() : [ $page ] ) as $key ) {
			$slug = '404-to-301-' . $key;

			if ( "admin_page_{$slug}" === $screenId || "redirects_page_{$slug}" === $screenId ) {
				return true;
			}
		}

		return false;
	}
}