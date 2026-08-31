<?php
/**
 * Redirect 404 requests to a destination URL.
 *
 * Per-row redirects (from the redirects table) win over the global
 * default. The action fires before `Log` / `Email` are scheduled
 * because a successful redirect terminates the request (via `exit`).
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Main\Front\Actions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Models\Redirect as RedirectRow;
use AIOSEO\FourNotFour\Main\Front\Request;
use AIOSEO\FourNotFour\Models\Log as LogModel;
use AIOSEO\FourNotFour\Models\Redirect as RedirectModel;
use AIOSEO\FourNotFour\Utils\Helpers;

/**
 * Class Redirect
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Front\Actions
 */
class Redirect extends Action {

	/**
	 * Whether this action should fire for the current request.
	 *
	 * Note that `redirect_enabled` is intentionally NOT checked here.
	 * That setting gates the *global* 404 fallback only — per-row
	 * redirects in the redirects table are explicit admin choices
	 * (with their own `is_active` flag) and should keep working
	 * regardless of the global toggle. The gate is applied inside
	 * `run()` on the fallback branch instead.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 *
	 * @return bool
	 */
	protected function shouldRun( Request $request ): bool {
		if ( $request->isExcluded() ) {
			return false;
		}

		return true;
	}

	/**
	 * Run the redirect (when one is resolved).
	 *
	 * Note: when a redirect fires, this method calls `wp_safe_redirect`
	 * followed by `exit` — no further actions run. The Controller
	 * orders us first for that reason.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 *
	 * @return void
	 */
	public function run( Request $request ): void {
		if ( ! $this->shouldRun( $request ) ) {
			return;
		}

		// Per-row first.
		$row    = $request->redirect();
		$target = '';
		$status = (int) $this->setting( 'redirects.statusCode', '301' );

		if ( $row instanceof RedirectRow ) {
			$target = $row->resolveTarget();
			$status = (int) $row->redirect_type;

			// `preserve` rows carry the request's query string across
			// to the destination so tracking params (utm_*, gclid, …)
			// survive the redirect. `require` rows already include the
			// query in the match — preserving it again would double
			// up; `ignore` is the explicit opt-out.
			if ( '' !== $target && 'preserve' === (string) $row->query_handling ) {
				$target = $this->appendRequestQuery( $target, $request->url() );
			}

			// Terminal status codes (410 Gone, 451 Unavailable for
			// Legal Reasons) don't redirect — they emit the status
			// header and end the request. We branch here so the rest
			// of the redirect plumbing (target resolution, filters,
			// `wp_safe_redirect`) doesn't have to special-case empty
			// targets.
			if ( $this->isTerminalStatus( $status ) ) {
				if ( $row instanceof RedirectRow ) {
					RedirectModel::recordHit( (int) $row->id );
				}
				$this->emitTerminalStatus( $status, $request );
				// `emit_terminal_status()` calls `exit`; we never get
				// here, but keep the early return for the static
				// analyser.
				return;
			}
		} elseif ( $request->is404() ) {
			/*
			 * No per-row match: fall back to the global default. Two
			 * gates: the log row's `override_redirect` (per-URL admin
			 * decision from the Configure modal) and the global
			 * `redirect_enabled` master toggle. DISABLE on the log
			 * silences the fallback even when the global toggle is on.
			 * ENABLE force-fires the fallback for this URL even when
			 * the master toggle is off — the "this one URL I do want
			 * redirected" lever.
			 */
			$override = $this->logOverrideRedirect( $request );
			$globalOn = (bool) $this->setting( 'redirects.enabled', true );

			$shouldFire = LogModel::OVERRIDE_DISABLE !== $override
				&& ( LogModel::OVERRIDE_ENABLE === $override || $globalOn );

			if ( $shouldFire ) {
				$type = (string) $this->setting( 'redirects.target', 'link' );

				// A target mode core can't turn into a redirect URL (eg.
				// an add-on's `page_404`) is a "serve in place"
				// disposition: don't redirect — announce the decision and
				// let a handler render the response while WordPress
				// carries on to template loading. Mirrors how 410/451
				// branch away from `wp_safe_redirect()`, except this path
				// keeps the request alive instead of calling `exit`.
				if ( $this->isServeTarget( $type ) ) {
					$this->serveInPlace( $request, $type );

					return;
				}

				$target = $this->resolveGlobalTarget();
			}
		}

		/**
		 * Filter the resolved target URL and status code.
		 *
		 * Returning an empty `url` aborts the redirect.
		 *
		 * @since 4.0.0
		 *
		 * @param array{url:string,status:int} $payload Resolved redirect.
		 * @param Request                      $request Current request.
		 */
		$payload = (array) apply_filters(
			'404_to_301_redirect_target',
			[
				'url'    => $target,
				'status' => $status,
			],
			$request
		);

		$url    = (string) ( $payload['url'] ?? '' );
		$status = (int) ( $payload['status'] ?? 301 );

		if ( '' === $url ) {
			return;
		}

		/**
		 * Fires immediately before the redirect headers are sent.
		 *
		 * @since 4.0.0
		 *
		 * @param string  $url     Target URL.
		 * @param int     $status  HTTP status code.
		 * @param Request $request Current request.
		 */
		do_action( '404_to_301_pre_redirect', $url, $status, $request );

		// Bump the hits counter on the row if we used one, and link
		// the just-written log entry to it so the Logs UI can show
		// which 404 URLs have already been "fixed" by a redirect.
		if ( $row instanceof RedirectRow ) {
			RedirectModel::recordHit( (int) $row->id );

			$log = $request->log();
			if ( $log && (int) $log->redirect_id !== (int) $row->id ) {
				LogModel::linkRedirect(
					(int) $log->id,
					(int) $row->id
				);
			}
		}

		// Redirect destinations here are admin-configured (a redirect
		// row's target, or the global fallback) — not visitor-supplied —
		// so we use `wp_redirect()` rather than `wp_safe_redirect()`.
		// `wp_safe_redirect()` restricts the destination to the site's
		// own host and silently rewrites anything external to wp-admin,
		// which would break the (very common) "send this old URL to an
		// external site" use case. Security-conscious sites can opt back
		// into host-restricted redirects via the filter below.
		//
		// 410/451 never reach here — they're short-circuited above via
		// `emit_terminal_status()`.
		$url = esc_url_raw( $url );

		/**
		 * Whether to restrict redirects to the site's own host.
		 *
		 * Defaults to `false` so admin-configured external targets work.
		 * Return `true` to fall back to `wp_safe_redirect()` semantics.
		 *
		 * @since 4.0.0
		 *
		 * @param bool    $safe    Whether to use host-restricted redirects.
		 * @param string  $url     Resolved destination URL.
		 * @param Request $request Current request.
		 */
		if ( apply_filters( '404_to_301_use_safe_redirect', false, $url, $request ) ) {
			wp_safe_redirect( $url, $status );
		} else {
			wp_redirect( $url, $status ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		}
		exit;
	}

	/**
	 * HTTP status codes that don't redirect — they signal a final
	 * disposition (Gone, Unavailable for Legal Reasons) and end the
	 * request without a `Location` header.
	 *
	 * Delegates to the canonical catalogue in
	 * {@see aioseo404To301()->helpers->isTerminalStatus()}, so a code registered as
	 * terminal via the `404_to_301_redirect_statuses` filter is honoured
	 * here too.
	 *
	 * @since 4.0.0
	 *
	 * @param int $status HTTP status code.
	 *
	 * @return bool
	 */
	private function isTerminalStatus( int $status ): bool {
		return aioseo404To301()->helpers->isTerminalStatus( $status );
	}

	/**
	 * Emit a status-only response for a terminal code and exit.
	 *
	 * Used by 410 Gone and 451 Unavailable for Legal Reasons rows —
	 * both communicate "this URL has no replacement" rather than
	 * sending the visitor somewhere else. We keep the body tiny on
	 * purpose: nothing visible to humans needs to be there, and a
	 * minimal body keeps the response cacheable by intermediaries
	 * that respect `Cache-Control`.
	 *
	 * @since 4.0.0
	 *
	 * @param int     $status  Terminal HTTP status (410 or 451).
	 * @param Request $request Current request.
	 *
	 * @return void
	 */
	private function emitTerminalStatus( int $status, Request $request ): void {
		/**
		 * Fires immediately before a terminal status header is sent.
		 *
		 * Mirrors `404_to_301_pre_redirect` — addons that want to
		 * log / audit the response can hook the same point.
		 *
		 * @since 4.0.0
		 *
		 * @param int     $status  HTTP status code.
		 * @param Request $request Current request.
		 */
		do_action( '404_to_301_pre_terminal_status', $status, $request );

		nocache_headers();
		status_header( $status );

		// A blank body is valid for 410/451 and avoids any chance of a
		// theme-rendered template leaking into the response.
		exit;
	}

	/**
	 * Whether a global target mode is a "serve in place" disposition.
	 *
	 * Core knows how to turn `link` and `page` into a redirect URL and
	 * `none` into a no-op. Any other registered mode (see
	 * {@see aioseo404To301()->helpers->redirectTargets()}) means "don't redirect — render
	 * the response in place and keep the 404 status", which core hands
	 * off to a handler via {@see Redirect::serveInPlace()}.
	 *
	 * @since 4.0.0
	 *
	 * @param string $type Configured `redirect_target` value.
	 *
	 * @return bool
	 */
	private function isServeTarget( string $type ): bool {
		return ! in_array( $type, [ 'link', 'page', 'none' ], true );
	}

	/**
	 * Hand a "serve in place" 404 disposition off to its handler.
	 *
	 * Unlike the redirect and terminal paths, this does NOT call `exit`:
	 * control returns to WordPress so normal template loading proceeds.
	 * The intended handler (an add-on, eg. "Custom 404 Page") hooks
	 * `404_to_301_serve_404`, swaps `template_include` to load its
	 * chosen content, and re-asserts the 404 status header.
	 *
	 * When nothing handles the action the request simply falls through
	 * to the theme's own 404 template — still with a 404 status — so the
	 * disposition degrades safely if the handler is deactivated.
	 *
	 * The Log and Email actions have already run by this point (the
	 * Controller orders Redirect last), so the broken URL is logged and
	 * any threshold email has fired regardless of how the response is
	 * ultimately rendered.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 * @param string  $type    Configured global target mode (eg. `page_404`).
	 *
	 * @return void
	 */
	private function serveInPlace( Request $request, string $type ): void {
		/**
		 * Fires immediately before a "serve in place" disposition is
		 * handed off — the audit-trail twin of `404_to_301_pre_redirect`
		 * and `404_to_301_pre_terminal_status`.
		 *
		 * @since 4.0.0
		 *
		 * @param Request $request Current request.
		 * @param string  $type    Configured global target mode.
		 */
		do_action( '404_to_301_pre_serve_404', $request, $type );

		/**
		 * Fires when the global 404 fallback should be rendered in place
		 * rather than redirected.
		 *
		 * Handlers should swap `template_include` to load their content
		 * and re-assert `status_header( 404 )` (rendering a published
		 * page would otherwise reset the status to 200). This action
		 * does not `exit`; WordPress proceeds to template loading after
		 * it returns.
		 *
		 * @since 4.0.0
		 *
		 * @param Request $request Current request.
		 * @param string  $type    Configured global target mode (eg. `page_404`).
		 */
		do_action( '404_to_301_serve_404', $request, $type );
	}

	/**
	 * Resolve the `override_redirect` value from the (possibly absent)
	 * log row for this URL.
	 *
	 * Centralised so the global-fallback gate doesn't have to peek at
	 * the Request and Logs constants in two places. Returns
	 * `OVERRIDE_GLOBAL` (the no-op default) when no log row exists.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 *
	 * @return int One of the {@see Logs}::OVERRIDE_* constants.
	 */
	private function logOverrideRedirect( Request $request ): int {
		$log = $request->log();

		return $log ? (int) $log->override_redirect : LogModel::OVERRIDE_GLOBAL;
	}

	/**
	 * Resolve the global default redirect target.
	 *
	 * @since   4.0.0
	 * @version 4.0.4 Made public for the redirect-test ability.
	 *
	 * @return string Empty when no default is configured.
	 */
	public function resolveGlobalTarget(): string {
		$targetType = (string) $this->setting( 'redirects.target', 'link' );

		switch ( $targetType ) {
			case 'link':
				$link = trim( (string) $this->setting( 'redirects.link', '' ) );

				// The stored default is empty rather than a baked-in URL so a site that changes its
				// address isn't left pointing at the old one. Resolving it here is what makes the
				// shipped "send 404s to the home page" behaviour actually fire.
				return '' !== $link ? $link : (string) home_url( '/' );

			case 'page':
				$pageId = (int) $this->setting( 'redirects.pageId', 0 );

				if ( $pageId <= 0 ) {
					return '';
				}

				$permalink = get_permalink( $pageId );

				return is_string( $permalink ) ? $permalink : '';

			case 'none':
			default:
				return '';
		}
	}

	/**
	 * Append the request's query string to a destination URL.
	 *
	 * Used by `query_handling = 'preserve'` redirect rows. Merges
	 * sensibly when the destination already carries its own query —
	 * the destination's keys win on collision so an admin's explicit
	 * `?source=campaign` isn't silently overwritten by a request-side
	 * `?source=user`.
	 *
	 * @since 4.0.0
	 *
	 * @param string $target      Destination URL (may already have a query).
	 * @param string $requestUrl Raw request URI (path + query).
	 *
	 * @return string
	 */
	private function appendRequestQuery( string $target, string $requestUrl ): string {
		$qpos = strpos( $requestUrl, '?' );
		if ( false === $qpos ) {
			return $target;
		}

		$incoming = substr( $requestUrl, $qpos + 1 );
		if ( '' === $incoming ) {
			return $target;
		}

		$args = [];
		wp_parse_str( $incoming, $args );

		if ( empty( $args ) ) {
			return $target;
		}

		// Resolve collisions key-by-key so the destination's own query
		// args (which an admin set deliberately when configuring the
		// row) win over any incoming key of the same name. We pre-fill
		// from the incoming request, then layer the destination's
		// existing args on top.
		$existing = [];
		$destQ    = strpos( $target, '?' );
		if ( false !== $destQ ) {
			wp_parse_str( substr( $target, $destQ + 1 ), $existing );
		}

		$merged = array_merge( $args, $existing );

		return add_query_arg( $merged, $target );
	}
}