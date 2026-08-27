<?php
/**
 * "Recent 404s" dashboard widget.
 *
 * The one surface that reaches someone who never opens the plugin's menu, so it
 * leads with data and keeps the cross-promotion to a footer line.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Models\Log;
use AIOSEO\FourNotFour\Utils\Permission;
use AIOSEO\FourNotFour\Utils\Plugin;

/**
 * Class DashboardWidget
 *
 * @since 4.0.3
 * @package AIOSEO\FourNotFour\Admin
 */
class DashboardWidget {

	/**
	 * How many rows the widget lists.
	 *
	 * @since 4.0.3
	 */
	const ROWS = 5;

	/**
	 * How far back the widget looks.
	 *
	 * @since 4.0.3
	 */
	const DAYS = 30;

	/**
	 * Cache key for the widget's rows.
	 *
	 * @since 4.0.3
	 */
	const CACHE = '404_to_301_dashboard_rows';

	/**
	 * Hook the widget.
	 *
	 * @since 4.0.3
	 */
	public function __construct() {
		add_action( 'wp_dashboard_setup', [ $this, 'register' ] );
	}

	/**
	 * Register the widget, for users who can see the plugin at all.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! current_user_can( Permission::getCap() ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'd404_recent_404s',
			// Translators: 1 - The plugin name.
			sprintf( __( '%1$s — Recent 404s', '404-to-301' ), Plugin::name() ),
			[ $this, 'render' ]
		);
	}

	/**
	 * Print the widget.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function render(): void {
		wp_enqueue_style(
			'd404-dashboard-widget',
			plugins_url( 'static/dashboard-widget.css', AIOSEO_404_TO_301_FILE ),
			[],
			Plugin::version()
		);

		$rows = $this->rows();

		if ( empty( $rows ) ) {
			printf(
				'<p class="d404-widget__empty">%s</p>',
				sprintf(
					// Translators: 1 - A number of days.
					esc_html__( 'No 404s in the last %1$d days.', '404-to-301' ),
					(int) self::DAYS
				)
			);
		} else {
			echo '<div class="d404-widget__list">';

			foreach ( $rows as $row ) {
				printf(
					'<div class="d404-widget__row"><span class="d404-widget__url">%1$s</span><span class="d404-widget__hits">%2$s</span></div>',
					esc_html( $row['url'] ),
					esc_html(
						sprintf(
							// Translators: 1 - A number of hits.
							_n( '%1$s hit', '%1$s hits', (int) $row['hits'], '404-to-301' ),
							number_format_i18n( (int) $row['hits'] )
						)
					)
				);
			}

			echo '</div>';
		}

		$this->footer( ! empty( $rows ) );
	}

	/**
	 * The footer: a link into the logs, plus the promo when there is something to promote.
	 *
	 * @since 4.0.3
	 *
	 * @param  bool $hasRows Whether any 404s were listed.
	 * @return void
	 */
	private function footer( bool $hasRows ): void {
		echo '<div class="d404-widget__foot">';

		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Plugin::getUrl( $hasRows ? 'logs' : 'redirects' ) ),
			$hasRows
				? esc_html__( 'View all 404s', '404-to-301' )
				// Translators: 1 - The plugin name.
				: esc_html( sprintf( __( 'Open %1$s', '404-to-301' ), Plugin::name() ) )
		);

		/*
		 * Nothing to promote when the log is empty or the plugin is already running.
		 * The `install_plugins` check mirrors the landing page's own registration: a
		 * host with DISALLOW_FILE_MODS leaves an admin holding `manage_options` but
		 * not `install_plugins`, and the link would lead to an unregistered page.
		 */
		$plugins = aioseo404To301()->helpers->getPluginData();

		if ( $hasRows && current_user_can( 'install_plugins' ) && empty( $plugins['brokenLinkChecker']['activated'] ) ) {
			printf(
				'<span class="d404-widget__promo"><img src="%1$s" alt="" /><a href="%2$s">%3$s</a></span>',
				esc_url( plugins_url( 'static/blc.svg', AIOSEO_404_TO_301_FILE ) ),
				esc_url( Plugin::getUrl( 'blc' ) ),
				esc_html__( 'Find the links causing these', '404-to-301' )
			);
		}

		echo '</div>';
	}

	/**
	 * Top 404s by hits from the last 30 days.
	 *
	 * Cached because the Dashboard is a hot page and this is decoration, not a
	 * source of truth — a few minutes of staleness costs nothing.
	 *
	 * @since 4.0.3
	 *
	 * @return array[] Rows of url and hits.
	 */
	private function rows(): array {
		$cached = get_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$logs = Log::paginate(
			[
				'number'     => self::ROWS,
				'orderby'    => 'hits',
				'order'      => 'DESC',
				'status'     => Log::STATUS_OPEN,
				'date_query' => [
					[
						'column' => 'updated_at',
						'after'  => gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::DAYS . ' days' ) ),
					],
				],
			]
		);

		$rows = [];

		foreach ( $logs['items'] as $log ) {
			$rows[] = [
				'url'  => (string) $log->url,
				'hits' => (int) $log->hits,
			];
		}

		set_transient( self::CACHE, $rows, 15 * MINUTE_IN_SECONDS );

		return $rows;
	}
}