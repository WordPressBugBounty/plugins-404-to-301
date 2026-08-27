<?php
/**
 * Telegram Bot API client.
 *
 * A thin wrapper over the single Bot API method this feature needs —
 * `sendMessage`. Kept deliberately small: it builds the request,
 * performs it with `wp_remote_post()`, and normalises the response
 * into a predictable result array the Process worker can branch on.
 *
 * @package AIOSEO\FourNotFour\Telegram
 */

namespace AIOSEO\FourNotFour\Main\Telegram;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Client
 *
 * @since 4.0.3
 */
final class Client {

	/**
	 * Base URL of the Telegram Bot API.
	 *
	 * The bot token is appended as a path segment per Telegram's
	 * convention: `https://api.telegram.org/bot<token>/<method>`.
	 *
	 * @since 4.0.3
	 */
	const API_BASE = 'https://api.telegram.org/bot';

	/**
	 * Send a message to a chat.
	 *
	 * Returns a normalised result the caller can branch on without
	 * having to know anything about the HTTP layer or Telegram's
	 * envelope:
	 *
	 *  - `ok`          (bool)   — message accepted by Telegram.
	 *  - `error`       (string) — human-readable failure description
	 *                             ('' on success).
	 *  - `retry_after` (int)    — seconds Telegram asked us to wait
	 *                             before retrying (0 when not rate-limited).
	 *
	 * @since 4.0.3
	 *
	 * @param string $token   Bot token from @BotFather.
	 * @param string $chatId Destination chat id or `@channel` handle.
	 * @param string $text    Message body (HTML parse mode).
	 *
	 * @return array{ok:bool,error:string,retry_after:int}
	 */
	public function send( string $token, string $chatId, string $text ): array {
		if ( '' === $token || '' === $chatId ) {
			return $this->result( false, __( 'Bot token or chat id is not configured.', '404-to-301' ) );
		}

		$url = self::API_BASE . $token . '/sendMessage';

		$response = wp_remote_post(
			$url,
			[
				'timeout' => 15,
				'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
				'body'    => wp_json_encode(
					[
						'chat_id'                  => $chatId,
						'text'                     => $text,
						'parse_mode'               => 'HTML',
						'disable_web_page_preview' => true,
					]
				),
			]
		);

		// Transport-level failure (DNS, timeout, TLS). Treat as a
		// transient error but, per the feature's "don't hot-loop the
		// queue" policy, the Process worker still drops the item — it
		// just records this description.
		if ( is_wp_error( $response ) ) {
			return $this->result( false, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		// Happy path: HTTP 200 and Telegram's own `ok` flag set.
		if ( 200 === $code && is_array( $body ) && ! empty( $body['ok'] ) ) {
			return $this->result( true, '' );
		}

		// Telegram reports failures in the JSON envelope: `description`
		// carries the reason, and a 429 includes `parameters.retry_after`.
		$description = is_array( $body ) && isset( $body['description'] )
			? (string) $body['description']
			/* translators: %d: HTTP status code. */
			: sprintf( __( 'Unexpected HTTP %d from Telegram.', '404-to-301' ), $code );

		$retryAfter = is_array( $body ) && isset( $body['parameters']['retry_after'] )
			? (int) $body['parameters']['retry_after']
			: 0;

		return $this->result( false, $description, $retryAfter );
	}

	/**
	 * Build the normalised result array.
	 *
	 * @since 4.0.3
	 *
	 * @param bool   $ok          Whether the message was accepted.
	 * @param string $error       Failure description ('' on success).
	 * @param int    $retryAfter Seconds Telegram asked us to wait.
	 *
	 * @return array{ok:bool,error:string,retry_after:int}
	 */
	private function result( bool $ok, string $error, int $retryAfter = 0 ): array {
		return [
			'ok'          => $ok,
			'error'       => $error,
			'retry_after' => $retryAfter,
		];
	}
}