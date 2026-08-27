<?php
namespace AIOSEO\FourNotFour\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Traits\Helpers as TraitHelpers;

/**
 * Contains helper functions
 *
 * @since 1.0.0
 */
class Helpers {
	use TraitHelpers\Api;
	use TraitHelpers\Redirects;
	use TraitHelpers\Urls;
	use TraitHelpers\Arrays;
	use TraitHelpers\Constants;
	use TraitHelpers\DateTime;
	use TraitHelpers\Strings;
	use TraitHelpers\ThirdParty;
	use TraitHelpers\Vue;
	use TraitHelpers\Wp;
	use TraitHelpers\WpContext;
	use TraitHelpers\WpMultisite;
	use TraitHelpers\WpUri;

	/**
	 * Checks if we are in a dev environment or not.
	 *
	 * @since 1.0.0
	 *
	 * @return boolean True if we are, false if not.
	 */
	public function isDev() {
		return aioseo404To301()->isDev || isset( $_REQUEST['aioseo-dev'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, HM.Security.NonceVerification.Recommended
	}

	/**
	 * Generates a UTM URL from the URL and medium/content that are passed in.
	 *
	 * @since 1.0.0
	 *
	 * @param  string      $url     The URL to parse.
	 * @param  string      $medium  The UTM medium parameter.
	 * @param  string|null $content The UTM content parameter or null.
	 * @param  boolean     $esc     Whether or not to escape the URL.
	 * @return string               The new URL.
	 */
	public function utmUrl( $url, $medium, $content = null, $esc = true ) {
		// First, remove any existing utm parameters on the URL.
		$url = remove_query_arg(
			[
				'utm_source',
				'utm_medium',
				'utm_campaign',
				'utm_content'
			],
			$url
		);

		// Generate the new arguments.
		$args = [
			'utm_source'   => 'WordPress',
			'utm_campaign' => 'toc-plugin',
			'utm_medium'   => $medium
		];

		// Content is not used by default.
		if ( $content ) {
			$args['utm_content'] = $content;
		}

		// Return the new URL.
		$url = add_query_arg( $args, $url );

		return $esc ? esc_url( $url ) : $url;
	}

	/**
	 * Checks if the given string is serialized, and if so, unserializes it.
	 * If the serialized string contains an object, we abort to prevent PHP object injection.
	 *
	 * @since 1.2.0
	 *
	 * @param  string       $string The string.
	 * @return string|array         The string or unserialized data.
	 */
	public function maybeUnserialize( $string ) {
		if ( ! is_string( $string ) ) {
			return $string;
		}

		$string = trim( $string );

		// NOTE: `;O:` only catches a nested object - a serialized object at the root starts with `O:`.
		// `allowed_classes => false` is what actually closes object injection here; the prefix checks
		// just avoid the work. The plugin requires PHP 7.4, so there is no unguarded fallback.
		if (
			! is_serialized( $string ) ||
			$this->stringContains( $string, ';O:' ) ||
			0 === strpos( $string, 'O:' )
		) {
			return $string;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, PHPCompatibility.FunctionUse.NewFunctionParameters.unserialize_optionsFound
		$unserialized = @unserialize( $string, [ 'allowed_classes' => false ] );

		return $unserialized;
	}
}