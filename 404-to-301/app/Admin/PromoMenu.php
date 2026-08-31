<?php
/**
 * The rotating cross-promotion menu item.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Utils\PluginUpgraderSilentAjax;

/**
 * Class PromoMenu
 *
 * One submenu item that promotes a different plugin from the family each week, each with its own
 * landing page. Five items in a fixed order, so a site works through the whole set rather than
 * seeing the same pitch forever.
 *
 * The choice is derived from the install date and the elapsed period, not stored state, so it is
 * the same for every admin on the site and survives the option being deleted. It is cached per
 * period all the same: recomputing is cheap, but a menu item that changed the moment someone
 * activated a plugin elsewhere would move under the cursor.
 *
 * @since   4.0.4
 * @package AIOSEO\FourNotFour\Admin
 */
class PromoMenu {

	/**
	 * How long one plugin holds the slot.
	 *
	 * @since 4.0.4
	 */
	const PERIOD_DAYS = 7;

	/**
	 * Option caching the current period's choice.
	 *
	 * @since 4.0.4
	 */
	const OPTION = 'aioseo_404_to_301_promo_menu';

	/**
	 * Fallback rotation epoch (2025-01-01 UTC) for a site with no recorded install date.
	 *
	 * Without one the elapsed-period count would be enormous and the same item would win forever.
	 *
	 * @since 4.0.4
	 */
	const EPOCH = 1735689600;

	/**
	 * Memoized catalogue. Built once per request: the labels are translated, and every other
	 * accessor here reads this array.
	 *
	 * @since 4.0.4
	 *
	 * @var array|null
	 */
	private static $items = null;

	/**
	 * The rotation, in order. Keyed by the page suffix, which is also the URL: the page slug is
	 * `404-to-301-<suffix>`.
	 *
	 * `plugin` is the key the installer, the wp.org links and the basename map all use.
	 *
	 * @since 4.0.4
	 *
	 * @return array<string, array{plugin: string, label: string}>
	 */
	public static function items(): array {
		if ( null !== self::$items ) {
			return self::$items;
		}

		self::$items = [
			'aioseo'      => [
				'plugin' => 'aioseo',
				'label'  => __( 'SEO', '404-to-301' ),
			],
			'blc'         => [
				'plugin' => 'brokenLinkChecker',
				'label'  => __( 'Broken Links', '404-to-301' ),
			],
			'wpconsent'   => [
				'plugin' => 'wpConsent',
				'label'  => __( 'Privacy Compliance', '404-to-301' ),
			],
			'duplicator'  => [
				'plugin' => 'duplicator',
				'label'  => __( 'Backups', '404-to-301' ),
			],
			'activelayer' => [
				'plugin' => 'activeLayer',
				'label'  => __( 'Spam Protection', '404-to-301' ),
			],
		];

		return self::$items;
	}

	/**
	 * Page suffixes of every landing page.
	 *
	 * @since 4.0.4
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::items() );
	}

	/**
	 * Whether a page suffix belongs to this menu.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $key Page suffix.
	 * @return bool
	 */
	public static function has( string $key ): bool {
		return array_key_exists( $key, self::items() );
	}

	/**
	 * The plugin key a landing page promotes.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $key Page suffix.
	 * @return string      Installer plugin key, or '' for an unknown page.
	 */
	public static function pluginFor( string $key ): string {
		return (string) ( self::items()[ $key ]['plugin'] ?? '' );
	}

	/**
	 * The page suffix showing in the menu right now.
	 *
	 * @since 4.0.4
	 *
	 * @return string The suffix, or '' when every promoted plugin is already active.
	 */
	public function visibleKey(): string {
		$period = $this->period();
		$cached = get_option( self::OPTION, [] );

		if ( is_array( $cached ) && (int) ( $cached['period'] ?? -1 ) === $period ) {
			$key = (string) ( $cached['key'] ?? '' );

			/*
			 * An empty key is a real answer - it means every promoted plugin is already active -
			 * so it has to satisfy the cache too, or the choice is recomputed on every load and
			 * never settles.
			 *
			 * A key whose plugin has since been activated does not: the visitor most likely just
			 * installed it from the landing page, and leaving it in the slot for the rest of the
			 * week would sell them something they now have. Recomputing here moves the item on at
			 * the next page load rather than under the cursor mid-install.
			 */
			if ( '' === $key || ( self::has( $key ) && ! self::isActive( self::pluginFor( $key ) ) ) ) {
				return $key;
			}
		}

		$key = $this->pick( $period );

		update_option(
			self::OPTION,
			[
				'period' => $period,
				'key'    => $key,
			],
			false
		);

		return $key;
	}

	/**
	 * Elapsed rotation periods since this install started.
	 *
	 * @since 4.0.4
	 *
	 * @return int
	 */
	private function period(): int {
		$start = (int) aioseo404To301()->internalOptions->internal->firstActivated;

		if ( 1 > $start ) {
			$start = self::EPOCH;
		}

		return (int) max( 0, floor( ( time() - $start ) / ( self::PERIOD_DAYS * DAY_IN_SECONDS ) ) );
	}

	/**
	 * The item whose turn it is, out of the plugins the site doesn't already run.
	 *
	 * Indexes the filtered list rather than skipping forward through the full one. Skipping forward
	 * keeps the sequence identical whatever is installed, but it also collapses several periods onto
	 * the same item: on a site already running the first two, periods 0, 1 and 2 all land on the
	 * third, so it holds the slot for three weeks while the last two wait. Filtering first spreads
	 * the periods evenly over what is actually left to promote, at the cost of the order shifting
	 * when a plugin is activated - and since the choice is cached per period, that shift only ever
	 * takes effect at the next boundary.
	 *
	 * @since 4.0.4
	 *
	 * @param  int    $period Elapsed periods.
	 * @return string         Page suffix, or '' when there is nothing left to promote.
	 */
	private function pick( int $period ): string {
		$available = [];

		foreach ( self::items() as $key => $item ) {
			if ( ! self::isActive( $item['plugin'] ) ) {
				$available[] = $key;
			}
		}

		if ( empty( $available ) ) {
			return '';
		}

		return (string) $available[ $period % count( $available ) ];
	}

	/**
	 * Whether the site already runs a promoted plugin, in any edition.
	 *
	 * Reads the active-plugins option rather than {@see \AIOSEO\FourNotFour\Traits\Helpers\Wp::getPluginData()},
	 * which walks the plugins directory - too much for something that runs on every admin page load.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $plugin Installer plugin key.
	 * @return bool
	 */
	private static function isActive( string $plugin ): bool {
		if ( '' === $plugin ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$slugs = ( new PluginUpgraderSilentAjax() )->pluginSlugs;

		/*
		 * A Pro build satisfies the pitch for the free one. In practice this only covers AIOSEO:
		 * `aioseoPro` is the one premium basename the map carries, so a site running Duplicator Pro
		 * or WPConsent Pro is still pitched the free build. Closing that needs their real plugin
		 * files, which neither this plugin nor AIOSEO records - a guessed basename would just never
		 * match, silently.
		 */
		foreach ( [ $plugin, $plugin . 'Pro' ] as $candidate ) {
			if ( ! empty( $slugs[ $candidate ] ) && is_plugin_active( $slugs[ $candidate ] ) ) {
				return true;
			}
		}

		return false;
	}
}