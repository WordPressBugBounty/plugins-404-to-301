<?php
/**
 * 404 logs REST endpoint.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Models\Log as LogRow;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Logs
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Api
 */
class Logs extends Endpoint {


	/**
	 * Register routes.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/logs',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list' ],
					'permission_callback' => [ $this, 'requireAccess' ],
					'args'                => $this->listArgs(),
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'bulkDelete' ],
					'permission_callback' => [ $this, 'requireAccess' ],
					'args'                => [
						'ids' => [
							'type'     => 'array',
							'required' => true,
							'items'    => [ 'type' => 'integer' ],
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs/summary',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'summary' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs/purge',
			[
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'purge' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
			]
		);

		// Dedicated bulk-update endpoint. Keeps `PATCH /logs/{id}`
		// for single-item updates and gives bulk operations a single
		// round-trip instead of N concurrent requests.
		register_rest_route(
			self::NAMESPACE,
			'/logs/bulk-update',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'bulkUpdate' ],
					'permission_callback' => [ $this, 'requireAccess' ],
					'args'                => [
						'ids'    => [
							'type'     => 'array',
							'required' => true,
							'items'    => [ 'type' => 'integer' ],
						],
						'status' => [
							'type' => 'integer',
							'enum' => [ 0, 1, 2 ],
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ $this, 'requireAccess' ],
					'args'                => [
						'status'            => [
							'type' => 'integer',
							'enum' => [ 0, 1, 2 ],
						],
						'redirect_id'       => [ 'type' => 'integer' ],
						'override_redirect' => [
							'type' => 'integer',
							'enum' => [ 0, 1, 2 ],
						],
						'override_email'    => [
							'type' => 'integer',
							'enum' => [ 0, 1, 2 ],
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
			]
		);
	}

	/**
	 * GET /logs.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function list( WP_REST_Request $request ): WP_REST_Response {
		$args = $this->paging( $request, 'updated_at' );

		$status = $request->get_param( 'status' );
		if ( null !== $status && '' !== $status ) {
			$args['status'] = (int) $status;
		}

		$referrer = (string) $request->get_param( 'referrer' );
		if ( '' !== $referrer ) {
			$args['referrer'] = $referrer;
		}

		$kind = (string) $request->get_param( 'kind' );
		if ( '' !== $kind ) {
			$args['kind'] = $kind;
		}

		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$dateFrom = (string) $request->get_param( 'date_from' );
		$dateTo   = (string) $request->get_param( 'date_to' );
		if ( '' !== $dateFrom || '' !== $dateTo ) {
			$range = [ 'column' => 'created_at' ];
			if ( '' !== $dateFrom ) {
				$range['after'] = $dateFrom;
			}
			if ( '' !== $dateTo ) {
				$range['before'] = $dateTo;
			}
			$args['date_query'] = [ $range ];
		}

		$page = LogRow::paginate( $args );

		return $this->collection(
			array_map( [ $this, 'shape' ], $page['items'] ),
			$page['total'],
			$args['number']
		);
	}

	/**
	 * GET /logs/{id}.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get( WP_REST_Request $request ) {
		$row = new LogRow( (int) $request['id'] );

		if ( ! $row->exists() ) {
			return $this->notFound( __( 'Log not found.', '404-to-301' ) );
		}

		return $this->respond( $this->shape( $row ) );
	}

	/**
	 * PATCH /logs/{id} — set the row status, or link to a redirect.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$row = new LogRow( $id );

		if ( ! $row->exists() ) {
			return $this->notFound( __( 'Log not found.', '404-to-301' ) );
		}

		$status     = $request->get_param( 'status' );
		$redirectId = $request->get_param( 'redirect_id' );

		if ( null !== $redirectId ) {
			LogRow::linkRedirect( $id, (int) $redirectId );
		}

		if ( null !== $status ) {
			LogRow::setStatus( $id, (int) $status );
		}

		// Per-row override toggles. We accept any subset — anything not
		// in the payload is left untouched on the row.
		$overrideKeys = [ 'override_redirect', 'override_email' ];
		$overrides    = [];

		foreach ( $overrideKeys as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$overrides[ $key ] = (int) $value;
			}
		}

		if ( ! empty( $overrides ) ) {
			$fresh = new LogRow( $id );
			LogRow::setOverrides(
				$id,
				[
					'override_redirect' => $overrides['override_redirect'] ?? (int) $fresh->override_redirect,
					'override_email'    => $overrides['override_email'] ?? (int) $fresh->override_email,
				]
			);
		}

		return $this->respond( $this->shape( new LogRow( $id ) ) );
	}

	/**
	 * DELETE /logs/{id}.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$row = new LogRow( $id );

		// Model::delete() returns null by design, so existence before the call is the signal.
		$deleted = $row->exists();

		if ( $deleted ) {
			$row->delete();
		}

		if ( ! $deleted ) {
			return $this->error( 'rest_delete_failed', __( 'Could not delete the log.', '404-to-301' ), 500 );
		}

		return $this->respond(
			[
				'id'      => $id,
				'deleted' => true,
			]
		);
	}

	/**
	 * GET /logs/summary — aggregate counts for the dashboard strip.
	 *
	 * @since 4.0.1
	 *
	 * @return WP_REST_Response
	 */
	public function summary(): WP_REST_Response {
		return $this->respond( LogRow::summary() );
	}

	/**
	 * DELETE /logs/purge — wipe the entire logs table.
	 *
	 * Custom redirects are stored in a separate table and are untouched.
	 *
	 * @since 4.0.1
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge() {
		$ok = LogRow::purgeAll();

		if ( ! $ok ) {
			return $this->error( 'rest_purge_failed', __( 'Could not purge the logs.', '404-to-301' ), 500 );
		}

		return $this->respond( [ 'purged' => true ] );
	}

	/**
	 * DELETE /logs (bulk).
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function bulkDelete( WP_REST_Request $request ): WP_REST_Response {
		$ids   = (array) $request->get_param( 'ids' );
		$count = 0;

		foreach ( $ids as $id ) {
			$row = new LogRow( (int) $id );

			if ( $row->exists() ) {
				$row->delete();
				++$count;
			}
		}

		return $this->respond( [ 'deleted' => $count ] );
	}

	/**
	 * POST /logs/bulk-update — flip the status on every selected row
	 * in a single round-trip.
	 *
	 * The React layer used to call `PATCH /logs/{id}` once per id
	 * from `Array.prototype.forEach`, which hammered the API on
	 * larger selections and re-rendered the list after each
	 * response. This endpoint accepts an `ids` array + a single
	 * `status` value and applies them server-side.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response Body shape: `{ updated: int }`.
	 */
	public function bulkUpdate( WP_REST_Request $request ): WP_REST_Response {
		$ids    = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		$status = $request->get_param( 'status' );
		$count  = 0;

		if ( null === $status ) {
			return $this->respond( [ 'updated' => 0 ] );
		}

		foreach ( $ids as $id ) {
			if ( 0 < $id && LogRow::setStatus( $id, (int) $status ) ) {
				++$count;
			}
		}

		return $this->respond( [ 'updated' => $count ] );
	}

	/**
	 * REST argument schema for the list endpoint.
	 *
	 * @since 4.0.0
	 *
	 * @return array
	 */
	private function listArgs(): array {
		return [
			'page'      => [
				'type'    => 'integer',
				'default' => 1,
			],
			'per_page'  => [
				'type'    => 'integer',
				'default' => 20,
			],
			'orderby'   => [
				'type'    => 'string',
				'default' => 'updated_at',
			],
			'order'     => [
				'type'    => 'string',
				'enum'    => [ 'ASC', 'DESC', 'asc', 'desc' ],
				'default' => 'DESC',
			],
			'search'    => [ 'type' => 'string' ],
			'status'    => [
				'type' => 'integer',
				'enum' => [ 0, 1, 2 ],
			],
			'date_from' => [ 'type' => 'string' ],
			'date_to'   => [ 'type' => 'string' ],
			'referrer'  => [
				'type' => 'string',
				'enum' => [ 'internal', 'external', 'none' ],
			],
			'kind'      => [
				'type' => 'string',
				'enum' => [ 'content', 'asset' ],
			],
		];
	}

	/**
	 * Shape a log row for REST.
	 *
	 * @since 4.0.0
	 *
	 * @param LogRow|null $row Row to shape.
	 *
	 * @return array
	 */
	private function shape( $row ): array {
		if ( ! $row instanceof LogRow ) {
			return [];
		}

		$statusLabel = [
			LogRow::STATUS_OPEN    => __( 'Open', '404-to-301' ),
			LogRow::STATUS_IGNORED => __( 'Ignored', '404-to-301' ),
			LogRow::STATUS_FIXED   => __( 'Fixed', '404-to-301' ),
		];

		return [
			'id'                => (int) $row->id,
			'url'               => (string) $row->url,
			'ref'               => (string) $row->ref,
			'ip'                => $row->ip(),
			'ua'                => (string) $row->ua,
			'method'            => (string) $row->method,
			'hits'              => (int) $row->hits,
			'status'            => (int) $row->status,
			'status_label'      => $statusLabel[ (int) $row->status ] ?? '',
			'redirect_id'       => null === $row->redirect_id ? null : (int) $row->redirect_id,
			'override_redirect' => (int) $row->override_redirect,
			'override_email'    => (int) $row->override_email,
			'created_at'        => $row->created_at,
			'updated_at'        => $row->updated_at,
		];
	}
}