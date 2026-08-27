<?php
/**
 * Admin notices.
 *
 * Currently hosts a single notice: the 4.0.3 upgrade tells the user
 * which of the old addon plugins are now redundant, because their
 * capabilities ship inside the plugin as opt-in features.
 *
 * We deliberately don't deactivate those plugins ourselves — silently
 * switching off something the user installed is the kind of thing that
 * generates support tickets. The features are already switched on by
 * {@see \AIOSEO\FourNotFour\Main\Migrations\MigrateFlatSettings}, so the site
 * keeps working either way and removing the plugin is pure tidying.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Admin\Notices;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Utils\Permission;

/**
 * Class RedundantAddons
 *
 * @since   4.0.3
 * @package AIOSEO\FourNotFour\Admin
 */
class RedundantAddons {

	/**
	 * User-meta key recording that this user dismissed the notice.
	 *
	 * Per-user rather than site-wide: on a multi-admin site the person
	 * who dismisses it isn't necessarily the person who can act on it.
	 *
	 * @since 4.0.3
	 */
	const DISMISSED_META = '404_to_301_dismissed_redundant_addons';

	/**
	 * Query arg used by the dismiss link.
	 *
	 * @since 4.0.3
	 */
	const DISMISS_ARG = '404_to_301_dismiss_redundant';

	/**
	 * Nonce action guarding the dismiss link.
	 *
	 * @since 4.0.3
	 */
	const DISMISS_NONCE = '404_to_301_dismiss_redundant_nonce';

	/**
	 * Register hooks.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'maybeDismiss' ] );
		add_action( 'admin_notices', [ $this, 'redundantAddons' ] );
	}

	/**
	 * Handle a click on the notice's dismiss link.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function maybeDismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) ) {
			return;
		}

		if ( ! Permission::hasAccess() ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::DISMISS_NONCE ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::DISMISSED_META, 1 );

		// Strip our args so a refresh doesn't re-run the handler and the
		// URL the user is left on is clean.
		wp_safe_redirect(
			remove_query_arg( [ self::DISMISS_ARG, '_wpnonce' ] )
		);
		exit;
	}

	/**
	 * Render the "these addon plugins are now redundant" notice.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function redundantAddons(): void {
		if ( ! Permission::hasAccess() ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::DISMISSED_META, true ) ) {
			return;
		}

		$redundant = $this->redundant();

		if ( empty( $redundant ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>%1$s</strong></p><p>%2$s</p><p>%3$s</p><p>%4$s &nbsp; <a href="%5$s">%6$s</a></p></div>',
			esc_html__( '404 to 301: your add-ons are now built in', '404-to-301' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of addon plugin names. */
					_n(
						'The %s add-on is now included in 404 to 301 itself, and the matching feature has been switched on for you.',
						'The following add-ons are now included in 404 to 301 itself, and the matching features have been switched on for you: %s.',
						count( $redundant ),
						'404-to-301'
					),
					implode( ', ', $redundant )
				)
			),
			esc_html__( 'You can safely deactivate and delete the separate add-on plugin(s) — nothing will stop working.', '404-to-301' ),
			sprintf(
				'<a href="%1$s" class="button button-secondary">%2$s</a>',
				esc_url( admin_url( 'plugins.php' ) ),
				esc_html__( 'Go to Plugins', '404-to-301' )
			),
			esc_url( $this->dismissUrl() ),
			esc_html__( 'Dismiss', '404-to-301' )
		);
	}

	/**
	 * Names of the redundant addon plugins that are still active.
	 *
	 * Re-checked against `active_plugins` on every render rather than
	 * trusted from the stored option, so the notice disappears on its own
	 * once the user has removed the plugins.
	 *
	 * @since 4.0.3
	 *
	 * @return array<int, string> Human-readable addon names.
	 */
	private function redundant(): array {
		// The 4.0.3 adoption folds the legacy `404_to_301_redundant_addons` option into here.
		$stored = aioseo404To301()->internalOptions->internal->redundantAddons;

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return [];
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$names = [];

		foreach ( $stored as $basename => $name ) {
			if ( is_plugin_active( (string) $basename ) ) {
				$names[] = (string) $name;
			}
		}

		// Everything has been removed - clear the list so we stop looking on every admin page load.
		if ( empty( $names ) ) {
			aioseo404To301()->internalOptions->internal->redundantAddons = [];
		}

		return $names;
	}

	/**
	 * Build the nonced dismiss URL for the current screen.
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	private function dismissUrl(): string {
		return wp_nonce_url(
			add_query_arg( self::DISMISS_ARG, '1' ),
			self::DISMISS_NONCE
		);
	}
}