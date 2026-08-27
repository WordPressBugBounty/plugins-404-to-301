<?php
/**
 * Plugin row links on the Plugins screen.
 *
 * Adds a "Settings" action link, a link promoting whichever of our plugins
 * the site is missing, and a "Support" row-meta link to the plugin's entry
 * in `wp-admin/plugins.php`.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use AIOSEO\FourNotFour\Utils\Plugin;


/**
 * Class Links
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Admin
 */
class Links {

	/**
	 * Hook the plugin row filters.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_filter( 'plugin_action_links_' . AIOSEO_404_TO_301_PLUGIN_BASENAME, [ $this, 'actionLinks' ] );
		add_filter( 'plugin_row_meta', [ $this, 'rowMeta' ], 10, 2 );
	}

	/**
	 * Prepend the Settings link and append the promo link to the plugin actions.
	 *
	 * @since   4.0.0
	 * @version 4.0.3 Dropped the Logs and Redirects links; added the promo link.
	 *
	 * @param array $links Existing action links.
	 *
	 * @return array
	 */
	public function actionLinks( $links ): array {
		$links = is_array( $links ) ? $links : [];

		// Settings only. Logs and Redirects are one click away in the admin menu, and three
		// of our own links crowded out WordPress' own row actions.
		$ours = [
			sprintf( '<a href="%s">%s</a>', esc_url( Plugin::getUrl( 'settings' ) ), esc_html__( 'Settings', '404-to-301' ) ),
		];

		$promo = $this->promoLink();
		if ( '' !== $promo ) {
			$links[] = $promo;
		}

		return array_merge( $ours, $links );
	}

	/**
	 * Action link promoting whichever of our plugins this site is missing.
	 *
	 * Points at the About page rather than wp.org, because the card there installs
	 * in place. Empty once the site runs both.
	 *
	 * @since 4.0.3
	 *
	 * @return string Anchor markup, or an empty string.
	 */
	private function promoLink(): string {
		$data = aioseo404To301()->helpers->getPluginData();

		$active = static function ( array $keys ) use ( $data ): bool {
			foreach ( $keys as $key ) {
				if ( ! empty( $data[ $key ]['activated'] ) ) {
					return true;
				}
			}

			return false;
		};

		// AIOSEO leads; a site already running it gets pointed at the next gap.
		if ( ! $active( [ 'aioseo', 'aioseoPro' ] ) ) {
			$label = __( 'Get AIOSEO', '404-to-301' );
		} elseif ( ! $active( [ 'brokenLinkChecker' ] ) ) {
			$label = __( 'Get Broken Link Checker', '404-to-301' );
		} else {
			return '';
		}

		return sprintf(
			'<a href="%s" class="aioseo-404-to-301-promo-link">%s</a>',
			esc_url( Plugin::getUrl( 'about' ) ),
			esc_html( $label )
		);
	}

	/**
	 * Append the support link to the plugin's row meta.
	 *
	 * @since   4.0.0
	 * @version 4.0.3 Dropped the documentation link.
	 *
	 * @param string[] $meta Existing row-meta links.
	 * @param string   $file Plugin basename of the row currently being rendered.
	 *
	 * @return array
	 */
	public function rowMeta( $meta, $file ): array {
		$meta = is_array( $meta ) ? $meta : [];

		if ( AIOSEO_404_TO_301_PLUGIN_BASENAME === $file ) {
			$meta['support'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				'https://wordpress.org/support/plugin/404-to-301/',
				esc_html__( 'Support', '404-to-301' )
			);
		}

		return $meta;
	}
}