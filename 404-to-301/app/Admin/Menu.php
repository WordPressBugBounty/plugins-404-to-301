<?php
/**
 * Admin menu registration.
 *
 * Builds the 4-item menu under "404 to 301":
 *   Redirects (top level)
 *   ├ Redirects  (alias of top level)
 *   ├ 404 Logs
 *   ├ Settings
 *   └ Features
 *
 * Each sub-menu callback delegates to {@see Page::render()} — the
 * admin pages are just React mount-points; the real UI lives in
 * `assets/src/`.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use AIOSEO\FourNotFour\Models\Log;
use AIOSEO\FourNotFour\Utils\Plugin;

use AIOSEO\FourNotFour\Utils\Permission;

/**
 * Class Menu
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Admin
 */
class Menu {

	/**
	 * Register hooks.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register' ] );
		add_action( 'admin_menu', [ $this, 'renameTopLevel' ], 11 );
	}

	/**
	 * Register every plugin menu entry.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		$cap = Permission::getCap();

		// Top-level page — defaults to the Redirects view.
		add_menu_page(
			__( 'Custom Redirects — 404 to 301', '404-to-301' ),
			__( 'Redirects', '404-to-301' ),
			$cap,
			Plugin::PAGE_REDIRECTS,
			[ aioseo404To301()->admin->page, 'renderRedirects' ],
			'dashicons-redo',
			89
		);

		// Sub-menu pages, in display order.
		add_submenu_page(
			Plugin::PAGE_REDIRECTS,
			__( 'Custom Redirects', '404-to-301' ),
			__( 'Redirects', '404-to-301' ),
			$cap,
			Plugin::PAGE_REDIRECTS,
			[ aioseo404To301()->admin->page, 'renderRedirects' ]
		);

		add_submenu_page(
			Plugin::PAGE_REDIRECTS,
			__( '404 Error Logs', '404-to-301' ),
			$this->logsMenuTitle(),
			$cap,
			Plugin::PAGE_LOGS,
			[ aioseo404To301()->admin->page, 'renderLogs' ]
		);

		add_submenu_page(
			Plugin::PAGE_REDIRECTS,
			__( '404 to 301 Settings', '404-to-301' ),
			__( 'Settings', '404-to-301' ),
			$cap,
			Plugin::PAGE_SETTINGS,
			[ aioseo404To301()->admin->page, 'renderSettings' ]
		);

		add_submenu_page(
			Plugin::PAGE_REDIRECTS,
			__( 'About Us', '404-to-301' ),
			__( 'About Us', '404-to-301' ),
			$cap,
			Plugin::PAGE_ABOUT,
			[ aioseo404To301()->admin->page, 'renderAbout' ]
		);

		/*
		 * Hidden page (no menu item) that installs Broken Link Checker. Reached from the
		 * dashboard widget and the Site Health check; redirects straight to Broken Link
		 * Checker once it is active, so a stale bookmark never lands on a dead pitch.
		 */
		if ( current_user_can( 'install_plugins' ) ) {
			$hook = add_submenu_page(
				'',
				__( 'Get Broken Link Checker', '404-to-301' ),
				__( 'Get Broken Link Checker', '404-to-301' ),
				'install_plugins',
				Plugin::PAGE_BLC,
				[ aioseo404To301()->admin->page, 'renderBlc' ]
			);

			add_action( "load-{$hook}", [ $this, 'maybeRedirectToBlc' ] );
		}
	}

	/**
	 * Rename the top-level menu label to the brand name.
	 *
	 * WordPress uses the first sub-menu's label as the parent label
	 * by default. We register the parent with "Redirects" so the
	 * first submenu reads cleanly, then swap the parent label here.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function renameTopLevel(): void {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return;
		}

		foreach ( $menu as $position => $data ) {
			if ( isset( $data[2] ) && Plugin::PAGE_REDIRECTS === $data[2] ) {
				// Rewriting our own row's display label in the global
				// `$menu` is the documented WP way to override the
				// auto-derived top-level title (default is the first
				// submenu page's name). This is the only touch we make.
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$menu[ $position ][0] = Plugin::name();

				return;
			}
		}
	}

	/**
	 * "404 Logs" with a count bubble for unresolved rows.
	 *
	 * Mirrors the Comments menu, so a site collecting 404s shows it without the
	 * admin having to open the page.
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	private function logsMenuTitle(): string {
		$label = __( '404 Logs', '404-to-301' );
		$open  = Log::openCount();

		if ( 1 > $open ) {
			return $label;
		}

		return sprintf(
			'%1$s <span class="update-plugins count-%2$d"><span class="plugin-count">%3$s</span></span>',
			$label,
			$open,
			esc_html( number_format_i18n( $open ) )
		);
	}

	/**
	 * Send the visitor to Broken Link Checker when it is already running.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function maybeRedirectToBlc(): void {
		$plugins = aioseo404To301()->helpers->getPluginData();

		if ( empty( $plugins['brokenLinkChecker']['activated'] ) ) {
			return;
		}

		$target = ! empty( $plugins['brokenLinkChecker']['adminUrl'] )
			? (string) $plugins['brokenLinkChecker']['adminUrl']
			: admin_url();

		wp_safe_redirect( $target );
		exit;
	}
}