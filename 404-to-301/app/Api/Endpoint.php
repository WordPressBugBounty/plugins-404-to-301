<?php
/**
 * Base class for REST API endpoints.
 *
 * Centralises the REST namespace and the `rest_api_init` hook wiring,
 * so every concrete endpoint only has to implement its `routes()`
 * method. Implements {@see Routable} so the route-declaration contract
 * is discoverable at the type level.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Contracts\Routable;
use AIOSEO\FourNotFour\Utils\Permission;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Endpoint
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Api
 */
abstract class Endpoint implements Routable {

	/**
	 * REST namespace shared by every plugin endpoint.
	 *
	 * Concrete endpoints register routes under this namespace, so the
	 * full route looks like `/404-to-301/v1/<name>`.
	 *
	 * @since 4.0.0
	 */
	const NAMESPACE = '404-to-301/v1';

	/**
	 * Hook the route registration into `rest_api_init`.
	 *
	 * Subclasses can call `parent::__construct()` to inherit the
	 * hooking, or skip it entirely when they need to override the
	 * timing (for example, to register routes lazily).
	 *
	 * @since 4.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	/**
	 * Permission callback that requires the plugin's manage capability.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return bool
	 */
	public function requireAccess( WP_REST_Request $request ): bool {
		/**
		 * Filter the per-request access check.
		 *
		 * @since 4.0.0
		 *
		 * @param bool            $allowed Default check result.
		 * @param WP_REST_Request $request REST request.
		 */
		$allowed = apply_filters(
			'404_to_301_rest_has_access',
			Permission::hasAccess(),
			$request
		);

		return (bool) $allowed;
	}

	/**
	 * Wrap response data with a HTTP status code.
	 *
	 * @since 4.0.0
	 *
	 * @param mixed $data    Response data.
	 * @param int   $status  HTTP status code.
	 *
	 * @return WP_REST_Response
	 */
	protected function respond( $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Build a list response that matches WP core's collection convention:
	 *  - body: a bare array of items.
	 *  - headers: `X-WP-Total` + `X-WP-TotalPages`.
	 *
	 * `@wordpress/api-fetch` consumers (and the reference DataView
	 * hooks) read these headers to drive pagination.
	 *
	 * @since 4.0.0
	 *
	 * @param array $items    List of items to return.
	 * @param int   $total    Total number of items across all pages.
	 * @param int   $perPage Items per page (used to derive total pages).
	 *
	 * @return WP_REST_Response
	 */
	protected function collection( array $items, int $total, int $perPage ): WP_REST_Response {
		$response = new WP_REST_Response( array_values( $items ), 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / max( 1, $perPage ) ) ) );

		return $response;
	}

	/**
	 * Build a REST error carrying an HTTP status code.
	 *
	 * Centralises the `new WP_Error( …, array( 'status' => … ) )` shape
	 * every endpoint repeats, so failures return a consistent body and
	 * the right status reaches `apiFetch` on the React side.
	 *
	 * @since 4.0.0
	 *
	 * @param string $code    Machine error code (e.g. `rest_not_found`).
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status code.
	 *
	 * @return WP_Error
	 */
	protected function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * Shorthand for the common 404 "resource not found" error.
	 *
	 * @since 4.0.0
	 *
	 * @param string $message Human-readable message.
	 *
	 * @return WP_Error
	 */
	protected function notFound( string $message ): WP_Error {
		return $this->error( 'rest_not_found', $message, 404 );
	}


	/**
	 * Translate paging / ordering request params into model query args.
	 *
	 * Clamps `page` to a minimum of 1 and `per_page` to the 1–100
	 * range, then derives the offset and normalises ordering. The
	 * shared shape (`number` / `offset` / `orderby` / `order`) matches
	 * what the BerlinDB-backed models' `paginate()` expects, so list
	 * endpoints only have to layer their own filters on top.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request         REST request.
	 * @param string          $defaultOrderby Column to sort by when the
	 *                                         request doesn't specify one.
	 *
	 * @return array Base query args: `number`, `offset`, `orderby`, `order`.
	 */
	protected function paging( WP_REST_Request $request, string $defaultOrderby ): array {
		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$perPage = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		return [
			'number'  => $perPage,
			'offset'  => ( $page - 1 ) * $perPage,
			'orderby' => (string) ( $request->get_param( 'orderby' ) ? $request->get_param( 'orderby' ) : $defaultOrderby ),
			'order'   => strtoupper( (string) ( $request->get_param( 'order' ) ? $request->get_param( 'order' ) : 'DESC' ) ),
		];
	}
}