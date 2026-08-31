<?php
/**
 * Abstract base for front-end actions.
 *
 * Implements {@see Actionable} and gives every concrete action two
 * small helpers: a `setting()` accessor that reads a plugin setting
 * with a fallback, and a no-op default `should_run()` that subclasses
 * can override for short-circuit checks.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Main\Front\Actions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Contracts\Actionable;
use AIOSEO\FourNotFour\Main\Front\Request;

/**
 * Class Action
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Front\Actions
 */
abstract class Action implements Actionable {

	/**
	 * Run the action.
	 *
	 * Implementations decide whether to skip via {@see Action::shouldRun()}.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 *
	 * @return void
	 */
	abstract public function run( Request $request ): void;

	/**
	 * Whether the action should run for the given request.
	 *
	 * Default: always run. Subclasses override.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 *
	 * @return bool
	 */
	protected function shouldRun( Request $request ): bool {
		return true;
	}

	/**
	 * Reads an option by dot-delimited path, with a fallback.
	 *
	 * Lets action subclasses ask for `logs.skipBots` rather than walking the accessor chain.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $path     Dot-delimited option path.
	 * @param  mixed  $fallback Returned when the path doesn't resolve.
	 * @return mixed            The option value, or the fallback.
	 */
	protected function setting( $path, $fallback = null ) {
		$node = aioseo404To301()->options;

		foreach ( explode( '.', (string) $path ) as $segment ) {
			if ( ! is_object( $node ) ) {
				return $fallback;
			}

			$node = $node->$segment;
		}

		return null === $node ? $fallback : $node;
	}
}