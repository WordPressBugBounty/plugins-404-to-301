<?php
namespace AIOSEO\FourNotFour\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL normalization, hashing and request-fingerprint helpers.
 *
 * @since 4.0.3
 */
trait Urls {
	/**
	 * Reduces a URL to the canonical form used for storing and matching.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $url Raw URL or path.
	 * @return string      The normalized path.
	 */
	public function normalizeUrl( $url ) {
		$raw = (string) $url;
		$url = trim( (string) $url );

		// A full URL means an admin pasted `https://site.com/old` rather than `/old`. Reduce it to
		// the path so scheme and host don't fragment the match.
		if ( false !== strpos( $url, '://' ) ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$url  = '' === $path ? '/' : $path;
		}

		$queryPosition = strpos( $url, '?' );
		if ( false !== $queryPosition ) {
			$url = substr( $url, 0, $queryPosition );
		}

		// rawurldecode(), not urldecode() - path segments encode spaces as %20, so a literal `+`
		// must not be folded into a space.
		$url = rawurldecode( $url );

		if ( 1 < strlen( $url ) && '/' === substr( $url, -1 ) ) {
			$url = rtrim( $url, '/' );
		}

		$normalized = strtolower( $url );

		// Guarantee a leading slash so a source typed as `old-page` matches the request path
		// `/old-page`. REQUEST_URI always arrives slash-prefixed; admin-entered sources may not.
		$normalized = '/' . ltrim( $normalized, '/' );

		$normalized = $this->stripHomePath( $normalized );

		/**
		 * Filters the normalized form of a URL before it is hashed or compared.
		 *
		 * @since 4.0.3
		 *
		 * @param string $normalized Normalized URL.
		 * @param string $raw        Original input as passed in.
		 */
		$filtered = apply_filters( '404_to_301_normalize_url', $normalized, $raw );

		return is_string( $filtered ) ? $filtered : $normalized;
	}

	/**
	 * Strips the site's home path prefix from an already-normalized path.
	 *
	 * NOTE: this is what keeps subdirectory installs working. WordPress hands us
	 * `/blog/old-page` while an admin naturally types the source as `/old-page`; folding the prefix
	 * here - the single chokepoint both storage and lookup run through - keeps the two in sync.
	 * Root installs have an empty home path, so this is a no-op for them.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $path Normalized, lowercased path.
	 * @return string       The path relative to the home path.
	 */
	private function stripHomePath( $path ) {
		$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$home = strtolower( rtrim( $home, '/' ) );

		if ( '' === $home ) {
			return $path;
		}

		if ( 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		} elseif ( $path === $home ) {
			$path = '/';
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Whether a string compiles as a PCRE pattern.
	 *
	 * Accepts a bare pattern or a delimited one, matching how {@see \AIOSEO\FourNotFour\Models\Redirect}
	 * wraps it before matching — so what validates here is exactly what runs later.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $pattern Raw pattern as the user typed it.
	 * @return bool            True when the pattern compiles.
	 */
	public function isValidRegex( $pattern ) {
		$pattern = (string) $pattern;

		if ( '' === $pattern ) {
			return false;
		}

		$wrapped = ( '/' === $pattern[0] || '#' === $pattern[0] ) ? $pattern : '#' . $pattern . '#';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad pattern is the answer, not a warning.
		return false !== @preg_match( $wrapped, '' );
	}

	/**
	 * Whether a redirect destination is one a browser will actually follow.
	 *
	 * Only relative paths and http(s) URLs qualify. `javascript:` and `data:` are the ones that matter:
	 * they never redirect anything, and the admin table renders the destination as a clickable link,
	 * so storing one turns the Redirects screen into a script-execution surface.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $url Destination as entered or imported.
	 * @return bool        True when the destination is safe to store and emit.
	 */
	public function isAllowedRedirectTarget( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return true;
		}

		// Protocol-relative (`//host/path`) and root-relative paths are both fine.
		if ( 0 === strpos( $url, '/' ) ) {
			return true;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		if ( empty( $scheme ) ) {
			// No scheme and no leading slash — a relative path like `some/page`.
			return false === strpos( $url, ':' );
		}

		return in_array( strtolower( (string) $scheme ), [ 'http', 'https' ], true );
	}

	/**
	 * Returns the SHA1 of a normalized URL, used as the unique key on the logs and redirects tables.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $url Raw URL or path.
	 * @return string      40-char hexadecimal hash.
	 */
	public function urlHash( $url ) {
		return sha1( $this->normalizeUrl( $url ) );
	}

	/**
	 * Returns a query-aware SHA1, keeping the query string as part of the hash.
	 *
	 * Used by `require` query handling so `/old?promo=summer` and `/old?promo=winter` can coexist as
	 * two distinct rows. The path is normalized as usual but the query is kept verbatim, because
	 * query values can be case-sensitive.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $url Raw URL or path.
	 * @return string      40-char hexadecimal hash.
	 */
	public function urlHashWithQuery( $url ) {
		$url = trim( (string) $url );

		$queryPosition = strpos( $url, '?' );
		$path          = false === $queryPosition ? $url : substr( $url, 0, $queryPosition );
		$query         = false === $queryPosition ? '' : substr( $url, $queryPosition );

		return sha1( $this->normalizeUrl( $path ) . $query );
	}

	/**
	 * Packs an IP address into binary form for varbinary(16) storage.
	 *
	 * Returns an empty string for invalid input so callers can skip the column write without an
	 * extra check.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $ip Dotted-quad IPv4 or colon-hex IPv6.
	 * @return string     Binary packed IP, or '' when invalid.
	 */
	public function packIp( $ip ) {
		if ( '' === (string) $ip ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- warns on invalid input; we want the false return.
		$packed = @inet_pton( (string) $ip );

		return is_string( $packed ) ? $packed : '';
	}

	/**
	 * Converts a packed IP back to its printable form.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $packed Binary packed IP as stored in the DB.
	 * @return string         Printable IP, or '' when the input isn't a valid packed address.
	 */
	public function unpackIp( $packed ) {
		if ( '' === (string) $packed ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- warns on invalid input; we want the false return.
		$ip = @inet_ntop( (string) $packed );

		return is_string( $ip ) ? $ip : '';
	}

	/**
	 * Rough heuristic for whether a request came from a real browser.
	 *
	 * Filterable so the heuristic can be swapped for a real bot-detection library.
	 *
	 * @since 4.0.3
	 *
	 * @param  string $userAgent Raw User-Agent string.
	 * @return bool              True when the request looks like a human.
	 */
	public function isHuman( $userAgent ) {
		$userAgent = (string) $userAgent;
		$isHuman   = true;

		if ( '' === $userAgent ) {
			$isHuman = false;
		} elseif ( preg_match( '/(bot|crawl|spider|slurp|curl|wget|facebookexternalhit|preview|monitor|fetch|python|java|httpclient)/i', $userAgent ) ) {
			$isHuman = false;
		}

		/**
		 * Filters the human-vs-bot determination for a request.
		 *
		 * @since 4.0.3
		 *
		 * @param bool   $isHuman   Whether the request looks like a human.
		 * @param string $userAgent Raw User-Agent string.
		 */
		return (bool) apply_filters( '404_to_301_is_human', $isHuman, $userAgent );
	}
}