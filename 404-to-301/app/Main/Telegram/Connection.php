<?php
namespace AIOSEO\FourNotFour\Main\Telegram;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this site has a usable Telegram connection.
 *
 * Telegram Alerts is deprecated and deliberately invisible on sites that never wired it up, so
 * "connected" gates both the runtime listener and the settings tab. Being switched on is part of the
 * test: the toggle can go off but never back on, so a site that has retired it reads as unconnected
 * and the tab disappears with it.
 *
 * @since 4.0.3
 */
class Connection {
	/**
	 * Whether alerts can be delivered.
	 *
	 * @since 4.0.3
	 *
	 * @return bool True when the feature is on and both credentials are present.
	 */
	public static function exists() {
		// NOTE: each leaf is read through the full chain. Holding the group in a local and reading more
		// than one leaf off it returns empty for every read after the first.
		if ( ! aioseo404To301()->options->telegram->enabled ) {
			return false;
		}

		return self::hasCredentials();
	}

	/**
	 * Whether the credentials are present, regardless of the toggle.
	 *
	 * Used by the settings sanitiser to tell "never set up" apart from "set up and then retired",
	 * which is what makes switching it back on refusable rather than merely hidden.
	 *
	 * @since 4.0.3
	 *
	 * @return bool True when both credentials are stored.
	 */
	public static function hasCredentials() {
		$token  = trim( (string) aioseo404To301()->options->telegram->botToken );
		$chatId = trim( (string) aioseo404To301()->options->telegram->chatId );

		return '' !== $token && '' !== $chatId;
	}
}