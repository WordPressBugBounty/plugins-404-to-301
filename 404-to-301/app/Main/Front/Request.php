<?php
/**
 * The request object that travels through the front-end action chain.
 *
 * Wraps the current HTTP request (URL, headers, IP, UA, method) and
 * the two lookups that depend on it: the matching custom redirect and
 * the matching error log. Built once per request by the Controller
 * and passed into every Actionable so the actions don't need to peek
 * at `$_SERVER` themselves.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Main\Front;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Models\Log as LogRow;
use AIOSEO\FourNotFour\Models\Redirect as RedirectRow;
use AIOSEO\FourNotFour\Models\Log;
use AIOSEO\FourNotFour\Models\Redirect;
use AIOSEO\FourNotFour\Utils\Helpers;

/**
 * Class Request
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Front
 */
class Request {

	/**
	 * Memoised lookup of the matching custom redirect.
	 *
	 * @since 4.0.0
	 * @var RedirectRow|null|false False until first lookup.
	 */
	private $redirect = false;

	/**
	 * Memoised lookup of the matching log row.
	 *
	 * @since 4.0.0
	 * @var LogRow|null|false False until first lookup.
	 */
	private $log = false;

	/**
	 * Lazily-built header map.
	 *
	 * @since 4.0.0
	 * @var array<string, string>|null
	 */
	private $headers;

	/**
	 * HTTP method of the current request, uppercase.
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public function method(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		/**
		 * Filter the resolved request method.
		 *
		 * @since 4.0.0
		 *
		 * @param string  $method  Resolved method.
		 * @param Request $request Current request.
		 */
		return (string) apply_filters( '404_to_301_request_method', $method, $this );
	}

	/**
	 * Referer URL (empty string when missing).
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public function referer(): string {
		$ref = isset( $_SERVER['HTTP_REFERER'] )
			? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';

		/** This filter is documented in {@see Request::method()}. */
		return (string) apply_filters( '404_to_301_request_referer', $ref, $this );
	}

	/**
	 * Visitor User-Agent.
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public function userAgent(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		/** This filter is documented in {@see Request::method()}. */
		return (string) apply_filters( '404_to_301_request_user_agent', $ua, $this );
	}

	/**
	 * Resolved client IP.
	 *
	 * Resolution order (first non-empty wins):
	 *  - `HTTP_X_FORWARDED_FOR` (first hop only)
	 *  - `HTTP_X_REAL_IP`
	 *  - `HTTP_CLIENT_IP`
	 *  - `REMOTE_ADDR`
	 *
	 * When the `mask_ip` setting is on, the resolved IP is replaced
	 * with an empty string before being filtered.
	 *
	 * @since 4.0.0
	 *
	 * @return string Empty when masked / unknown.
	 */
	public function ip(): string {
		$ip = '';

		// Bail to empty when the admin has opted to mask IPs.
		$mask = (bool) aioseo404To301()->options->general->maskIp;

		if ( ! $mask ) {
			$ip = $this->detectIp();
		}

		/** This filter is documented in {@see Request::method()}. */
		return (string) apply_filters( '404_to_301_request_ip', $ip, $this );
	}

	/**
	 * The request URI (path + optional query string).
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public function url(): string {
		$url = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		/** This filter is documented in {@see Request::method()}. */
		return (string) apply_filters( '404_to_301_request_url', $url, $this );
	}

	/**
	 * The host header (or SERVER_NAME fallback).
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public function host(): string {
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		} elseif ( isset( $_SERVER['SERVER_NAME'] ) ) {
			$host = sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) );
		} else {
			$host = '';
		}

		/** This filter is documented in {@see Request::method()}. */
		return (string) apply_filters( '404_to_301_request_host', $host, $this );
	}

	/**
	 * Request scheme — `http` or `https`.
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public function scheme(): string {
		return is_ssl() ? 'https' : 'http';
	}

	/**
	 * Lazily-built map of every incoming HTTP header.
	 *
	 * Names are lowercased and hyphenated so callers don't have to
	 * remember the CGI form (`HTTP_USER_AGENT` -> `user-agent`).
	 *
	 * @since 4.0.0
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		if ( null === $this->headers ) {
			$this->headers = $this->collectHeaders();
		}

		return $this->headers;
	}

	/**
	 * Whether the current request hit the 404 template.
	 *
	 * @since 4.0.0
	 *
	 * @return bool
	 */
	public function is404(): bool {
		/**
		 * Filter the 404 check.
		 *
		 * @since 4.0.0
		 *
		 * @param bool    $is404   Whether `is_404()` returned true.
		 * @param Request $request Current request.
		 */
		$is404 = apply_filters( '404_to_301_request_is_404', is_404(), $this );

		return (bool) $is404;
	}

	/**
	 * Matching redirect row for this URL (lazy).
	 *
	 * @since 4.0.0
	 *
	 * @return RedirectRow|null
	 */
	public function redirect(): ?RedirectRow {
		if ( false === $this->redirect ) {
			$this->redirect = Redirect::findMatch( $this->url() );
		}

		return $this->redirect ? $this->redirect : null;
	}

	/**
	 * Matching log row for this URL (lazy).
	 *
	 * @since 4.0.0
	 *
	 * @return LogRow|null
	 */
	public function log(): ?LogRow {
		if ( false === $this->log ) {
			$this->log = Log::getByUrl( $this->url() );
		}

		return $this->log ? $this->log : null;
	}

	/**
	 * Force a re-lookup of the log row (used after a write).
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function refreshLog(): void {
		$this->log = false;
	}

	/**
	 * Inject the memoised log row directly.
	 *
	 * Used after a write when the caller already holds the up-to-date
	 * row — avoids a follow-up SELECT that would re-fetch the same data.
	 *
	 * @since 4.0.0
	 *
	 * @param LogRow|null $log Row to memoise (null clears).
	 *
	 * @return void
	 */
	public function setLog( ?LogRow $log ): void {
		$this->log = $log ? $log : null;
	}

	/**
	 * Build the lowercased headers map from `$_SERVER`.
	 *
	 * @since 4.0.0
	 *
	 * @return array<string, string>
	 */
	private function collectHeaders(): array {
		$headers = [];

		foreach ( $_SERVER as $name => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( 0 === strpos( (string) $name, 'HTTP_' ) ) {
				$header             = strtolower( str_replace( '_', '-', substr( $name, 5 ) ) );
				$headers[ $header ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}

		// `Content-Type` and `Content-Length` aren't prefixed with `HTTP_`.
		if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
			$headers['content-type'] = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) );
		}
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) ) {
			$headers['content-length'] = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) );
		}

		return $headers;
	}

	/**
	 * Resolve the client IP from the usual server variables.
	 *
	 * Returns an empty string when no candidate produces a valid IP.
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	private function detectIp(): string {
		$candidates = [
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
			'REMOTE_ADDR',
		];

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

			// `X-Forwarded-For` can be a comma-separated chain — pick the first hop.
			if ( false !== strpos( $raw, ',' ) ) {
				$raw = trim( explode( ',', $raw )[0] );
			}

			$valid = filter_var( $raw, FILTER_VALIDATE_IP );

			if ( false !== $valid ) {
				return (string) $valid;
			}
		}

		return '';
	}

	/**
	 * Whether this request is WordPress spawning its own cron rather than a visitor.
	 *
	 * @since 4.0.4
	 *
	 * @return bool
	 */
	public function isCronSpawn(): bool {
		if ( wp_doing_cron() ) {
			return true;
		}

		/*
		 * Under ALTERNATE_WP_CRON core appends `doing_wp_cron` to the current request URI
		 * and redirects to it, so a 404 on such a site arrives twice: once bare, once with
		 * the parameter. Reading it off the URL rather than $_GET keeps the request-url
		 * filter authoritative.
		 */
		$query = (string) wp_parse_url( $this->url(), PHP_URL_QUERY );
		if ( '' === $query ) {
			return false;
		}

		$args = [];
		wp_parse_str( $query, $args );

		return isset( $args['doing_wp_cron'] );
	}

	/**
	 * Whether the path matches one of the configured exclude paths.
	 *
	 * Used by Actions to bail before doing any work.
	 *
	 * An entry is a substring: `/feed/` skips anything containing it. An entry containing `*` is a
	 * pattern instead, where `*` stands for any run of characters - `/20*\/` covers every
	 * date-based archive path in one row rather than one row per year.
	 *
	 * Entries go through the same normalisation as the request, which matters more than it sounds:
	 * the request path arrives lower-cased and with its trailing slash stripped, so before this an
	 * entry of `/Feed/` could never match anything, and `/feed/` missed a request to exactly
	 * `/blog/feed/` while matching `/blog/feed/page/2` - despite `/feed/` being the field's own
	 * placeholder. Normalising both sides is what makes an entry mean what it looks like.
	 *
	 * @since   4.0.0
	 * @version 4.0.4 Entries containing `*` are matched as patterns.
	 * @version 4.0.4 Entries are normalised like the request, so case and a trailing slash no
	 *                 longer decide whether one matches.
	 *
	 * @return bool
	 */
	public function isExcluded(): bool {
		$paths = (array) aioseo404To301()->options->general->excludePaths;
		if ( empty( $paths ) ) {
			return false;
		}

		$url = aioseo404To301()->helpers->normalizeUrl( $this->url() );

		foreach ( $paths as $path ) {
			$path = trim( (string) $path );
			if ( '' === $path ) {
				continue;
			}

			$path = aioseo404To301()->helpers->normalizeUrl( $path );

			if ( false !== strpos( $path, '*' ) ) {
				if ( $this->matchesPattern( $url, $path ) ) {
					return true;
				}

				continue;
			}

			if ( false !== strpos( $url, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a wildcard exclude entry matches a path.
	 *
	 * Deliberately unanchored, so a pattern behaves like the substring entries beside it: `/20*`
	 * matches anywhere in the path rather than having to describe the whole of it. Everything except
	 * `*` is quoted, so a pattern can't smuggle in a character class or a quantifier.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $url  Normalised request path.
	 * @param  string $path Normalised exclude entry containing at least one `*`.
	 * @return bool         Whether the entry matches.
	 */
	private function matchesPattern( string $url, string $path ): bool {
		$pattern = str_replace( '\*', '.*', preg_quote( $path, '#' ) );

		return 1 === preg_match( '#' . $pattern . '#', $url );
	}
}