<?php
/**
 * Front-end request dispatcher.
 *
 * Hooked into `template_redirect`. Builds the {@see Request} once,
 * then hands it to an ordered list of {@see Actionable}s. The default
 * chain is:
 *
 *   1. Redirect — may exit the request if it fires
 *   2. Log
 *   3. Email
 *
 * The chain is filterable via `404_to_301_actions`, so add-ons can
 * inject their own actions (for example, a webhook notifier) without
 * forking the Controller.
 *
 * Also hooks the WordPress `redirect_canonical` filter to honour the
 * "disable URL guessing" setting.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Main\Front;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Contracts\Actionable;
use AIOSEO\FourNotFour\Models\Redirect as RedirectModel;

/**
 * Class Controller
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Front
 */
class Controller {

	/**
	 * Register hooks.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function __construct() {
		// Both canonical filters are always added — the mode is read
		// inside the callbacks so a runtime settings change (REST
		// PATCH, WP-CLI, test fixtures) takes effect without
		// re-registration.
		add_filter( 'redirect_canonical', [ $this, 'disableCanonicalGuessing' ] );
		add_filter( 'do_redirect_guess_404_permalink', [ $this, 'maybeBlock404Guess' ] );
		add_action( 'template_redirect', [ $this, 'dispatch' ], 1 );
	}

	/**
	 * Build the request, run the action chain.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function dispatch(): void {
		// Cheap bail-outs first.
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			return;
		}

		$request = new Request();

		// Honour "Track admin 404s". `is_admin()` alone is not enough:
		// a request to a non-existent path under `/wp-admin/` (eg.
		// `/wp-admin/broken`) doesn't resolve to an admin PHP file, so it
		// falls through to the front controller where `is_admin()` is
		// false — and would otherwise be logged regardless of the
		// setting. We therefore also match the request path against the
		// admin base path. See `is_admin_request()`.
		if ( is_admin() || $this->isAdminRequest( $request ) ) {
			$track = aioseo404To301()->options->general->trackAdmin404;

			if ( ! $track ) {
				return;
			}
		}

		// `is_404()` runs through `$request` so the test filter
		// (`404_to_301_request_is_404`) still works.
		$is404 = $request->is404();

		// Healthy (non-404) page: the only thing we do is honour an
		// explicit per-row redirect the admin created for this URL —
		// they're deliberate rules and should fire even for a still-live
		// page the admin has chosen to retire (eg. `/sample-page` -> home).
		// Logging and the global 404 fallback stay reserved for genuine
		// 404s, handled by the action chain below.
		//
		// Guarded by the cached `has_active()` flag so a site with no
		// redirects pays only a single warm cache read here — no
		// per-request redirect-table query on healthy pages, preserving
		// the original "don't touch the redirect table on healthy pages"
		// optimisation. `Redirect::run()` exits when it actually fires; if
		// it doesn't (no match, or an unresolvable target), we simply
		// return — there's nothing to log for a page that resolved fine.
		if ( ! $is404 ) {
			if ( RedirectModel::hasActive() && null !== $request->redirect() ) {
				( new Actions\Redirect() )->run( $request );
			}

			return;
		}

		/**
		 * Allow short-circuiting the whole pipeline.
		 *
		 * @since 4.0.0
		 *
		 * @param bool    $proceed Whether to run the action chain.
		 * @param Request $request Current request.
		 */
		if ( ! apply_filters( '404_to_301_should_process', true, $request ) ) {
			return;
		}

		foreach ( $this->actions( $request ) as $action ) {
			if ( $action instanceof Actionable ) {
				$action->run( $request );
			}
		}

		/**
		 * Fires after every action in the chain has run (and the
		 * request hasn't been redirected away).
		 *
		 * @since 4.0.0
		 *
		 * @param Request $request Current request.
		 */
		do_action( '404_to_301_request', $request );

		if ( $request->is404() ) {
			/**
			 * Fires on every 404 request, after every action has run.
			 *
			 * @since 4.0.0
			 *
			 * @param Request $request Current request.
			 */
			do_action( '404_to_301_caught_404', $request );
		}
	}

	/**
	 * Build the ordered action list for the current request.
	 *
	 * Order is deliberate:
	 *
	 *   1. Log    — records the 404 hit and the request context. Runs
	 *               first so the row exists before Redirect terminates
	 *               the request with `exit`; the global fallback would
	 *               otherwise redirect every 404 away unlogged.
	 *               NOTE: a URL with its own active redirect row and no
	 *               existing log is deliberately NOT logged — see
	 *               {@see Actions\Log::shouldRun()}. Those are routing
	 *               rules, not errors to triage.
	 *   2. Email  — reads the just-written `hits` counter for the
	 *               threshold check.
	 *   3. Redirect — fires last; calls `wp_safe_redirect` + `exit`
	 *               so anything after it would not run.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 *
	 * @return Actionable[]
	 */
	private function actions( Request $request ): array {
		$actions = [
			new Actions\Log(),
			new Actions\Email(),
			new Actions\Redirect(),
		];

		/**
		 * Filter the action chain for this request.
		 *
		 * Add-ons that want to inject custom actions should hook here
		 * and return a modified array. Each element must implement
		 * {@see Actionable}.
		 *
		 * @since 4.0.0
		 *
		 * @param Actionable[] $actions Default action chain.
		 * @param Request      $request Current request.
		 */
		return (array) apply_filters( '404_to_301_actions', $actions, $request );
	}

	/**
	 * Strict-mode handler for the `redirect_canonical` filter.
	 *
	 * `redirect_canonical` is the top-level filter WP runs on the URL
	 * its canonicalisation function would have redirected to — covers
	 * post-name guessing, trailing slashes, case folding, and the
	 * attachment fallback. Returning `false` short-circuits the whole
	 * function, so we only do that on the `strict` mode. The lighter
	 * mode targets only the 404-guessing portion via
	 * {@see maybe_block_404_guess()}.
	 *
	 * The `?p=` short-circuit is preserved from the v3 behaviour —
	 * `wp_safe_redirect` on a numeric `?p=42` is genuinely useful for
	 * sites that paste old links around.
	 *
	 * @since 4.0.0
	 *
	 * @param string|bool $guess Current redirect target (false to disable).
	 *
	 * @return string|bool
	 */
	public function disableCanonicalGuessing( $guess ) {
		if ( isset( $_GET['p'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, HM.Security.NonceVerification.Recommended
			return $guess;
		}

		if ( 'strict' === $this->guessingMode() ) {
			return false;
		}

		return $guess;
	}

	/**
	 * Light-mode handler for `do_redirect_guess_404_permalink`.
	 *
	 * WP's `redirect_guess_404_permalink()` only walks posts when this
	 * filter returns true. Returning false here keeps the rest of
	 * `redirect_canonical()` (trailing slash, case folding) intact
	 * while killing the "find a similar post by slug" lookup that's
	 * the main source of unexpected redirects.
	 *
	 * Both `light` and `strict` block the guess — strict reaches it
	 * via the top-level filter, but covering both modes here means
	 * the behaviour is consistent even if a plugin re-enables
	 * `redirect_canonical()`.
	 *
	 * @since 4.0.0
	 *
	 * @param bool $shouldGuess Whether WP intends to attempt the guess.
	 *
	 * @return bool
	 */
	public function maybeBlock404Guess( $shouldGuess ) {
		if ( isset( $_GET['p'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, HM.Security.NonceVerification.Recommended
			return $shouldGuess;
		}

		$mode = $this->guessingMode();

		if ( 'light' === $mode || 'strict' === $mode ) {
			return false;
		}

		return $shouldGuess;
	}

	/**
	 * Resolve the current `disable_guessing` mode, defending against
	 * stale boolean values that may still be on disk from earlier
	 * pre-release builds.
	 *
	 * @since 4.0.0
	 *
	 * @return string One of `off`, `light`, `strict`.
	 */
	private function guessingMode(): string {
		$mode = aioseo404To301()->options->general->disableGuessing;

		if ( is_bool( $mode ) ) {
			return $mode ? 'strict' : 'off';
		}

		$mode = is_string( $mode ) ? $mode : 'light';

		return in_array( $mode, [ 'off', 'light', 'strict' ], true ) ? $mode : 'light';
	}

	/**
	 * Whether the current request targets the WordPress admin area by
	 * path, even when `is_admin()` is false.
	 *
	 * A 404 for a non-existent path under `/wp-admin/` is served by the
	 * front controller (the file doesn't exist, so WordPress handles the
	 * request as a normal 404), and `is_admin()` returns false there.
	 * Comparing the request path against the admin base path lets the
	 * "Track admin 404s" gate cover those requests too.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 *
	 * @return bool
	 */
	private function isAdminRequest( Request $request ): bool {
		$adminPath = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
		if ( '' === $adminPath ) {
			return false;
		}

		$requestPath = (string) wp_parse_url( $request->url(), PHP_URL_PATH );
		if ( '' === $requestPath ) {
			return false;
		}

		// Case-insensitive prefix match — `/wp-admin/` is canonical, but
		// a path can arrive case-folded by an upstream proxy.
		return 0 === stripos( $requestPath, rtrim( $adminPath, '/' ) . '/' );
	}
}