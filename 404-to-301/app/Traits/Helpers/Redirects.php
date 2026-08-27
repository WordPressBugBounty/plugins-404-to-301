<?php
namespace AIOSEO\FourNotFour\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The catalogues of redirect status codes and fallback target modes.
 *
 * NOTE: these are the single source of truth for both REST enum validation and option sanitization,
 * so the allowed values can never drift from what the UI offers.
 *
 * @since 4.0.3
 */
trait Redirects {
	/**
	 * Returns the supported HTTP status codes, keyed by code.
	 *
	 * Terminal codes end the request with a status header instead of redirecting.
	 *
	 * @since 4.0.3
	 *
	 * @return array Code => [ label, terminal ].
	 */
	public function redirectStatuses() {
		$statuses = [
			301 => [
				'label'    => __( '301 — Moved Permanently (SEO)', '404-to-301' ),
				'terminal' => false
			],
			302 => [
				'label'    => __( '302 — Found', '404-to-301' ),
				'terminal' => false
			],
			303 => [
				'label'    => __( '303 — See Other', '404-to-301' ),
				'terminal' => false
			],
			307 => [
				'label'    => __( '307 — Temporary Redirect', '404-to-301' ),
				'terminal' => false
			],
			308 => [
				'label'    => __( '308 — Permanent Redirect', '404-to-301' ),
				'terminal' => false
			],
			410 => [
				'label'    => __( '410 — Gone', '404-to-301' ),
				'terminal' => true
			],
			451 => [
				'label'    => __( '451 — Unavailable for Legal Reasons', '404-to-301' ),
				'terminal' => true
			]
		];

		/**
		 * Filters the catalogue of supported HTTP status codes.
		 *
		 * @since 4.0.3
		 *
		 * @param array $statuses Code => [ label, terminal ].
		 */
		return (array) apply_filters( '404_to_301_redirect_statuses', $statuses );
	}

	/**
	 * Returns a flat list of supported status codes.
	 *
	 * @since 4.0.3
	 *
	 * @param  bool  $redirectingOnly Exclude terminal codes, for the global fallback which always
	 *                                redirects to a destination.
	 * @return array                  List of integer status codes.
	 */
	public function redirectStatusCodes( $redirectingOnly = false ) {
		$codes = [];

		foreach ( $this->redirectStatuses() as $code => $meta ) {
			if ( $redirectingOnly && ! empty( $meta['terminal'] ) ) {
				continue;
			}

			$codes[] = (int) $code;
		}

		return $codes;
	}

	/**
	 * Whether a status code is terminal - emitted as a status header with no redirect.
	 *
	 * Reads the flag off the catalogue so a code registered through the filter is honored at runtime
	 * without touching the front controller.
	 *
	 * @since 4.0.3
	 *
	 * @param  int  $status HTTP status code.
	 * @return bool         Whether the code is terminal.
	 */
	public function isTerminalStatus( $status ) {
		$statuses = $this->redirectStatuses();

		return ! empty( $statuses[ (int) $status ]['terminal'] );
	}

	/**
	 * Returns the global 404-fallback target modes.
	 *
	 * Core ships `link` (a custom URL), `page` (an existing page) and `none` (let the theme render
	 * its 404). Anything registered beyond those three is treated as a serve-in-place disposition:
	 * the redirect action fires `404_to_301_serve_404` and keeps the 404 status.
	 *
	 * @since 4.0.3
	 *
	 * @return array Stored value => translated label.
	 */
	public function redirectTargets() {
		$targets = [
			'link' => __( 'A custom URL', '404-to-301' ),
			'page' => __( 'An existing page', '404-to-301' ),
			'none' => __( 'No redirect', '404-to-301' )
		];

		/**
		 * Filters the catalogue of global 404-fallback target modes.
		 *
		 * @since 4.0.3
		 *
		 * @param array $targets Stored value => label.
		 */
		return (array) apply_filters( '404_to_301_redirect_targets', $targets );
	}
}