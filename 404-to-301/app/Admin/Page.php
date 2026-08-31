<?php
/**
 * Admin page renderers.
 *
 * Each method outputs the matching React mount-point div. The actual
 * UI lives in `assets/src/{settings,logs,redirects,features}.js`, which
 * are loaded by {@see Assets} on the matching screen only.
 *
 * The mount-point markup is literally one div per page, so we emit it
 * inline rather than load a template file — see {@see self::mount()}.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Page
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Admin
 */
class Page {

	/**
	 * Mount point ID used by the Logs page React app.
	 *
	 * @since 4.0.0
	 */
	const MOUNT_LOGS = '404-to-301-logs';

	/**
	 * Mount point ID used by the Redirects page React app.
	 *
	 * @since 4.0.0
	 */
	const MOUNT_REDIRECTS = '404-to-301-redirects';

	/**
	 * Mount point ID used by the Settings page React app.
	 *
	 * @since 4.0.0
	 */
	const MOUNT_SETTINGS = '404-to-301-settings';

	/**
	 * Mount id for the About Us app.
	 *
	 * @since 4.0.4
	 */
	const MOUNT_ABOUT = '404-to-301-about';

	/**
	 * Mount point shared by every cross-promotion landing page.
	 *
	 * One mount for all five: which plugin is being pitched comes from the localised payload, so
	 * the rotation needs no new React entry per plugin.
	 *
	 * @since 4.0.4
	 */
	const MOUNT_PROMO = '404-to-301-promo';

	/**
	 * Render the Logs admin page.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function renderLogs(): void {
		$this->mount( self::MOUNT_LOGS );
	}

	/**
	 * Render the Redirects admin page.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function renderRedirects(): void {
		$this->mount( self::MOUNT_REDIRECTS );
	}

	/**
	 * Render the Settings admin page.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function renderSettings(): void {
		$this->mount( self::MOUNT_SETTINGS );
	}

	/**
	 * Render a cross-promotion landing page.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	public function renderPromo(): void {
		$this->mount( self::MOUNT_PROMO );
	}

	/**
	 * Render the About Us page.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	public function renderAbout(): void {
		$this->mount( self::MOUNT_ABOUT );
	}

	/**
	 * Emit the React mount-point div.
	 *
	 * Every admin screen rendered by this plugin is just an empty div
	 * the React app attaches to — printing the markup here is cheaper
	 * (and easier to audit) than including a one-line template file.
	 *
	 * @since 4.0.0
	 *
	 * @param string $mountId DOM id the React app attaches to.
	 *
	 * @return void
	 */
	private function mount( string $mountId ): void {
		printf(
			'<div id="%s" class="d404-wrap"></div>',
			esc_attr( $mountId )
		);
	}
}