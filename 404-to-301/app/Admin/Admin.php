<?php
namespace AIOSEO\FourNotFour\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Utils\Plugin;

/**
 * Boots the admin surface.
 *
 * @since 4.0.4
 */
class Admin {
	/**
	 * The admin page renderer.
	 *
	 * @since 4.0.4
	 *
	 * @var Page
	 */
	public $page;

	/**
	 * Class constructor.
	 *
	 * @since 4.0.4
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		// Page is assigned before Menu, which registers callbacks against it.
		$this->page = new Page();

		new Menu();
		new Assets();
		new Links();
		new SiteHealth();
		new Notices\RedundantAddons();
		new Notices\Review();
		new DashboardWidget();

		// Late enough to catch notices registered after `current_screen`: `in_admin_header` fires
		// immediately before `admin_notices` renders.
		add_action( 'in_admin_header', [ $this, 'suppressForeignNotices' ] );

		add_filter( 'admin_footer_text', [ $this, 'footerText' ] );
		add_filter( 'update_footer', [ $this, 'footerVersions' ], 11 );
	}

	/**
	 * Ask for a review in the admin footer, on this plugin's screens only.
	 *
	 * Links through the aioseo.com redirect rather than straight to wp.org, so the
	 * click is measurable and the destination can be changed without a release.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $text The existing footer text.
	 * @return string       Our text on our screens, the original everywhere else.
	 */
	public function footerText( $text ) {
		if ( ! Plugin::isPluginScreen() ) {
			return $text;
		}

		$href  = 'https://aioseo.com/404-to-301-rating';
		$title = esc_attr__( 'Give us a 5-star rating!', '404-to-301' );

		$stars = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer" title="%2$s">&#9733;&#9733;&#9733;&#9733;&#9733;</a>',
			esc_url( $href ),
			$title
		);

		$wporg = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer" title="%2$s">WordPress.org</a>',
			esc_url( $href ),
			$title
		);

		return sprintf(
			// Translators: 1 - The plugin name, 2 - Five star icons, 3 - "WordPress.org".
			esc_html__( 'Please rate %1$s %2$s on %3$s to help us spread the word.', '404-to-301' ),
			sprintf( '<strong>%s</strong>', esc_html( Plugin::name() ) ),
			$stars,
			$wporg
		);
	}

	/**
	 * Replace the right-hand footer slot with the WordPress and plugin versions.
	 *
	 * Uses the `update_footer` slot rather than printing into `admin_footer_text` and
	 * unhooking core's callback, so nothing has to be removed and other screens are
	 * left alone.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $text The existing footer version text.
	 * @return string       Our versions on our screens, the original everywhere else.
	 */
	public function footerVersions( $text ) {
		if ( ! Plugin::isPluginScreen() ) {
			return $text;
		}

		global $wp_version; // phpcs:ignore Squiz.NamingConventions.ValidVariableName

		return sprintf(
			// Translators: 1 - The WordPress version, 2 - The plugin name, 3 - The plugin version.
			esc_html__( 'WordPress %1$s | %2$s %3$s', '404-to-301' ),
			esc_html( $wp_version ), // phpcs:ignore Squiz.NamingConventions.ValidVariableName
			esc_html( Plugin::name() ),
			esc_html( Plugin::version() )
		);
	}

	/**
	 * Strip other plugins' admin notices from this plugin's own screens.
	 *
	 * A settings screen full of unrelated upsells and review nags isn't usable, so - as the parent
	 * plugin does on its screens - foreign notices are dropped here. This plugin's own notices are
	 * kept: they're the ones that say something about the page the user is looking at.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	public function suppressForeignNotices() {
		if ( ! Plugin::isPluginScreen() ) {
			return;
		}

		foreach ( [ 'admin_notices', 'network_admin_notices', 'all_admin_notices', 'user_admin_notices' ] as $hook ) {
			$this->removeForeignCallbacks( $hook );
		}
	}

	/**
	 * Remove every callback on a hook that doesn't belong to this plugin.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $hook The notice hook to filter.
	 * @return void
	 */
	private function removeForeignCallbacks( $hook ) {
		global $wp_filter; // phpcs:ignore Squiz.NamingConventions.ValidVariableName -- core global.

		if ( empty( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName -- core global.
			return;
		}

		// Collected first: remove_action() mutates the array this would otherwise be iterating.
		$foreign = [];
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) { // phpcs:ignore Squiz.NamingConventions.ValidVariableName -- core global.
			foreach ( $callbacks as $callback ) {
				if ( ! $this->isOwnCallback( $callback['function'] ) ) {
					$foreign[] = [ $callback['function'], $priority ];
				}
			}
		}

		foreach ( $foreign as $callback ) {
			remove_action( $hook, $callback[0], $callback[1] );
		}
	}

	/**
	 * Whether a callback belongs to this plugin.
	 *
	 * NOTE: a closure can't be attributed to a plugin, so it counts as foreign. Ours are all methods.
	 *
	 * @since 4.0.4
	 *
	 * @param  mixed $callback The registered callback.
	 * @return bool            True when the callback is this plugin's.
	 */
	private function isOwnCallback( $callback ) {
		$namespace = 'AIOSEO\\FourNotFour';

		if ( is_string( $callback ) ) {
			return 0 === strpos( $callback, $namespace );
		}

		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

			return 0 === strpos( $class, $namespace );
		}

		return false;
	}
}