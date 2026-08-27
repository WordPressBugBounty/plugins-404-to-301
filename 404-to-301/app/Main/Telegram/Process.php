<?php
/**
 * Background alert worker.
 *
 * One scheduled job per alert (a 404 or a redirect event): the payload
 * is formatted and handed to the Telegram client out-of-band, so the
 * visitor-facing request that triggered the event never blocks on the
 * Bot API round-trip.
 *
 * Delivery is best-effort. A failed send is recorded in
 * `internal.telegramLastError` and the job completes rather than
 * throwing, because a missed broken-link ping isn't worth a retry
 * storm against a misconfigured bot token.
 *
 * @package AIOSEO\FourNotFour\Telegram
 */

namespace AIOSEO\FourNotFour\Main\Telegram;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Process
 *
 * @since 4.0.3
 */
final class Process {

	/**
	 * Hook the alert jobs run on.
	 *
	 * Scheduled through {@see \AIOSEO\FourNotFour\Utils\ActionScheduler::scheduleAsync()},
	 * so it resolves to either an Action Scheduler action or a WP-Cron
	 * single event depending on what's available.
	 *
	 * @since 4.0.3
	 * @var string
	 */
	const ACTION = 'd404_telegram_alerts';

	/**
	 * Send a single alert.
	 *
	 * @since 4.0.3
	 *
	 * @param mixed $item Event payload built by the Listener.
	 *
	 * @return void
	 */
	public static function run( $item ): void {
		if ( ! is_array( $item ) ) {
			return;
		}

		$result = ( new Client() )->send(
			(string) aioseo404To301()->options->telegram->botToken,
			(string) aioseo404To301()->options->telegram->chatId,
			self::format( $item )
		);

		if ( $result['ok'] ) {
			// Stamp the last successful delivery and clear any stale
			// error so the settings box reflects a healthy integration.
			aioseo404To301()->internalOptions->internal->telegramLastSentAt = gmdate( 'Y-m-d H:i:s' );
			aioseo404To301()->internalOptions->internal->telegramLastError  = '';
		} else {
			aioseo404To301()->internalOptions->internal->telegramLastError = $result['error'];
		}
	}

	/**
	 * Render an event payload into an HTML-formatted Telegram message.
	 *
	 * Uses Telegram's HTML parse mode, so every interpolated value is
	 * escaped for the `&`, `<` and `>` entities Telegram recognises.
	 *
	 * @since 4.0.3
	 *
	 * @param array $item Event payload.
	 *
	 * @return string
	 */
	private static function format( array $item ): string {
		$type = ( 'redirect' === ( $item['type'] ?? '' ) ) ? 'redirect' : '404';

		$site = self::escape( (string) ( $item['site'] ?? '' ) );

		if ( 'redirect' === $type ) {
			/* translators: %s: site name. */
			$heading = sprintf( __( '↪️ <b>Redirect served on %s</b>', '404-to-301' ), $site );
		} else {
			/* translators: %s: site name. */
			$heading = sprintf( __( '⚠️ <b>404 detected on %s</b>', '404-to-301' ), $site );
		}

		$lines = [ $heading, '' ];

		$lines[] = self::line( __( 'URL', '404-to-301' ), (string) ( $item['url'] ?? '' ) );

		if ( 'redirect' === $type ) {
			$lines[] = self::line( __( 'Redirected to', '404-to-301' ), (string) ( $item['target'] ?? '' ) );
			$lines[] = self::line( __( 'Status', '404-to-301' ), (string) ( $item['status'] ?? '' ) );
		} else {
			$lines[] = self::line( __( 'Hits', '404-to-301' ), (string) ( $item['hits'] ?? 1 ) );
		}

		$lines[] = self::line( __( 'Referer', '404-to-301' ), (string) ( $item['referer'] ?? '' ) );
		$lines[] = self::line( __( 'IP', '404-to-301' ), (string) ( $item['ip'] ?? '' ) );
		$lines[] = self::line( __( 'User-Agent', '404-to-301' ), (string) ( $item['ua'] ?? '' ) );
		$lines[] = self::line( __( 'Method', '404-to-301' ), (string) ( $item['method'] ?? '' ) );
		$lines[] = self::line( __( 'Time', '404-to-301' ), (string) ( $item['time'] ?? '' ) );

		// Drop empty rows so a missing referer / IP doesn't leave a
		// dangling "Referer:" label in the message.
		$lines = array_filter(
			$lines,
			static function ( $line ) {
				return '' !== $line;
			}
		);

		return implode( "\n", $lines );
	}

	/**
	 * Build a single `Label: value` line, escaped for HTML parse mode.
	 *
	 * Returns an empty string when the value is empty so the caller can
	 * filter the row out entirely.
	 *
	 * @since 4.0.3
	 *
	 * @param string $label Field label (already translated, no markup).
	 * @param string $value Field value.
	 *
	 * @return string
	 */
	private static function line( string $label, string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		return '<b>' . self::escape( $label ) . ':</b> ' . self::escape( $value );
	}

	/**
	 * Escape a value for Telegram's HTML parse mode.
	 *
	 * Telegram only requires `&`, `<` and `>` to be encoded; quotes and
	 * other characters are passed through literally.
	 *
	 * @since 4.0.3
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private static function escape( string $value ): string {
		return str_replace(
			[ '&', '<', '>' ],
			[ '&amp;', '&lt;', '&gt;' ],
			$value
		);
	}
}