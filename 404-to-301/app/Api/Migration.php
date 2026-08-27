<?php
/**
 * Migration REST endpoint.
 *
 * Three routes:
 *  - `GET    /migration` — status snapshot (used by the banner to poll).
 *  - `POST   /migration` — start phase 2.
 *  - `DELETE /migration` — abort.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Main\Migration\Migrator;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Migration
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Api
 */
class Migration extends Endpoint {

	/**
	 * Register the routes.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/migration',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'status' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'start' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'abort' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
			]
		);

		// Dedicated route for the polling loop — distinct from `start`
		// so abort/POST semantics stay clean.
		register_rest_route(
			self::NAMESPACE,
			'/migration/tick',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'tick' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
			]
		);
	}

	/**
	 * Return the current migration status.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond( aioseo404To301()->main->migrator->status() );
	}

	/**
	 * Kick off phase 2.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond( aioseo404To301()->main->migrator->startPhase2() );
	}

	/**
	 * Process a single migration chunk on demand.
	 *
	 * Drives the React polling loop — each POST processes a chunk and
	 * returns updated status.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function tick( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond( aioseo404To301()->main->migrator->tick() );
	}

	/**
	 * Abort an in-flight migration.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function abort( WP_REST_Request $request ): WP_REST_Response {
		return $this->respond( aioseo404To301()->main->migrator->abort() );
	}
}