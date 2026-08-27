<?php
/**
 * Event listener.
 *
 * Bridges the parent plugin's front-end event hooks to the background
 * worker. When a 404 is logged or a redirect is served, it builds a
 * compact payload and schedules one async {@see Process} job for it, so
 * the visitor's request never waits on the Bot API.
 *
 * @package AIOSEO\FourNotFour\Telegram
 */

namespace AIOSEO\FourNotFour\Main\Telegram;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use AIOSEO\FourNotFour\Main\Front\Request;

/**
 * Class Listener
 *
 * @since 4.0.3
 */
final class Listener {

	/**
	 * Singleton instance.
	 *
	 * @since 4.0.3
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the shared instance.
	 *
	 * @since 4.0.3
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the WordPress hooks owned by this class.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function register(): void {
		// Registered ahead of the enabled check so jobs scheduled before the
		// feature was switched off still find a handler and drain.
		add_action( Process::ACTION, [ Process::class, 'run' ] );

		// Nothing to listen for while the feature is switched off — saves
		// the per-event work on every front-end request.
		if ( ! aioseo404To301()->options->telegram->enabled ) {
			return;
		}

		add_action( '404_to_301_post_log_insert', [ $this, 'on404' ], 10, 3 );
		add_action( '404_to_301_pre_redirect', [ $this, 'onRedirect' ], 10, 3 );
	}

	/**
	 * Queue an alert for a freshly-logged 404.
	 *
	 * Honours the `telegram_alerts_threshold` anti-spam setting the
	 * same way the parent's Email action does: the message fires only
	 * on the exact hit that reaches the threshold (or every hit when
	 * the threshold is 1), so a single hammered URL can't flood the
	 * chat.
	 *
	 * @since 4.0.3
	 *
	 * @param int     $id      Log row id (0 on failure).
	 * @param array   $data    Row data that was written.
	 * @param Request $request Current request.
	 *
	 * @return void
	 */
	public function on404( int $id, array $data, Request $request ): void {
		unset( $id, $data );

		if ( ! aioseo404To301()->options->telegram->on404 ) {
			return;
		}

		$threshold = max( 1, (int) aioseo404To301()->options->telegram->threshold );
		$log       = $request->log();
		$hits      = $log ? (int) $log->hits : 1;

		// Below the threshold — stay quiet.
		if ( $hits < $threshold ) {
			return;
		}

		// Past the threshold — only the threshold-th hit alerts, so we
		// don't re-fire on every subsequent hit (unless threshold is 1,
		// which means "every hit").
		if ( $hits > $threshold && 1 !== $threshold ) {
			return;
		}

		$payload         = $this->payload( $request );
		$payload['type'] = '404';
		$payload['hits'] = $hits;

		$this->push( $payload );
	}

	/**
	 * Queue an alert for a redirect about to be served.
	 *
	 * @since 4.0.3
	 *
	 * @param string  $url     Target URL.
	 * @param int     $status  HTTP status code.
	 * @param Request $request Current request.
	 *
	 * @return void
	 */
	public function onRedirect( string $url, int $status, Request $request ): void {
		if ( ! aioseo404To301()->options->telegram->onRedirect ) {
			return;
		}

		$payload           = $this->payload( $request );
		$payload['type']   = 'redirect';
		$payload['target'] = $url;
		$payload['status'] = $status;

		$this->push( $payload );
	}

	/**
	 * Schedule the alert to be sent out-of-band.
	 *
	 * @since 4.0.3
	 *
	 * @param array $payload Event payload.
	 *
	 * @return void
	 */
	private function push( array $payload ): void {
		aioseo404To301()->actionScheduler->scheduleAsync( Process::ACTION, [ $payload ] );
	}

	/**
	 * Build the shared payload fields from the current request.
	 *
	 * The caller layers the event-specific keys (`type`, `hits`,
	 * `target`, `status`) on top of this.
	 *
	 * @since 4.0.3
	 *
	 * @param Request $request Current request.
	 *
	 * @return array
	 */
	private function payload( Request $request ): array {
		return [
			'site'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'     => $request->url(),
			'referer' => $request->referer(),
			'ip'      => $request->ip(),
			'ua'      => $request->userAgent(),
			'method'  => $request->method(),
			// Localised stamp in the site's configured timezone, matching
			// how the parent renders timestamps elsewhere in the admin.
			'time'    => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		];
	}
}