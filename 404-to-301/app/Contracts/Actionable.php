<?php
/**
 * Contract for front-end request actions.
 *
 * Each "thing the plugin does on a 404" — logging, email alerts,
 * redirects — is implemented as an Actionable. The {@see
 * \AIOSEO\FourNotFour\Front\Controller} hands the current request
 * to a chain of Actionables and runs each one in turn.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Contracts;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Main\Front\Request;

/**
 * Interface Actionable
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Contracts
 */
interface Actionable {

	/**
	 * Run the action for the current request.
	 *
	 * @since 4.0.0
	 *
	 * @param Request $request Current request.
	 *
	 * @return void
	 */
	public function run( Request $request ): void;
}