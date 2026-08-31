<?php
/**
 * Redirects REST endpoint.
 *
 * Provides list / create / read / update / delete + bulk-delete over
 * the `404_to_301_redirects` table. The React UI on the Redirects
 * page consumes these routes via plain `fetch()` (not core-data), so
 * the response shape is intentionally compact and predictable.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Models\Redirect as RedirectRow;
use AIOSEO\FourNotFour\Models\Log as LogRow;
use AIOSEO\FourNotFour\Utils\Helpers;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Redirects
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Api
 */
class Redirects extends Endpoint {



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
			'/redirects',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list' ],
					'permission_callback' => [ $this, 'requireAccess' ],
					'args'                => $this->listArgs(),
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ $this, 'requireAccess' ],
					'args'                => $this->writableArgs( true ),
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
			'/redirects/summary',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'summary' ],
					'permission_callback' => [ $this, 'requireAccess' ],
				],
			]
		);

		// Dedicated bulk-update endpoint. Keeps `PATCH /redirects/{id}`
		// for single-item updates and gives bulk operations a single
		// round-trip instead of N concurrent requests.
		register_rest_route(
			self::NAMESPACE,
			'/redirects/bulk-update',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'bulkUpdate' ],
					'permission_callback' => [ $this, 'requireAccess' ],
					'args'                => [
						'ids'           => [
							'type'     => 'array',
							'required' => true,
							'items'    => [ 'type' => 'integer' ],
						],
						'is_active'     => [ 'type' => 'boolean' ],
						'redirect_type' => [
							'type' => 'integer',
							// Sourced from the canonical catalogue in
							// `aioseo404To301()->helpers->redirectStatuses()`. Terminal codes
							// (410/451) are included — they don't redirect; the
							// front controller emits the status header and exits.
							'enum' => aioseo404To301()->helpers->redirectStatusCodes(),
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/(?P<id>\d+)',
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
					'args'                => $this->writableArgs( false ),
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
	 * GET /redirects — paginated list.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	/**
	 * GET /redirects/summary — aggregate counts for the dashboard strip.
	 *
	 * @since 4.0.1
	 *
	 * @return WP_REST_Response
	 */
	public function summary(): WP_REST_Response {
		return $this->respond( RedirectRow::summary() );
	}

	/**
	 * GET /redirects — paginated, filtered list of redirects.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function list( WP_REST_Request $request ): WP_REST_Response {
		$args = $this->paging( $request, 'id' );

		// Optional filters.
		foreach ( [ 'match_type', 'target_type', 'redirect_type' ] as $key ) {
			$val = $request->get_param( $key );
			if ( null !== $val && '' !== $val ) {
				$args[ $key ] = $val;
			}
		}

		$active = $request->get_param( 'is_active' );
		if ( null !== $active && '' !== $active ) {
			$args['is_active'] = (int) (bool) $active;
		}

		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$page = RedirectRow::paginate( $args );

		return $this->collection(
			array_map( [ $this, 'shape' ], $page['items'] ),
			$page['total'],
			$args['number']
		);
	}

	/**
	 * GET /redirects/{id}.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get( WP_REST_Request $request ) {
		$row = new RedirectRow( (int) $request['id'] );

		if ( ! $row->exists() ) {
			return $this->notFound( __( 'Redirect not found.', '404-to-301' ) );
		}

		return $this->respond( $this->shape( $row ) );
	}

	/**
	 * POST /redirects.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$data = $this->collectWritable( $request, true );

		$invalid = $this->validate( $data );
		if ( $invalid ) {
			return $invalid;
		}

		$duplicate = RedirectRow::findBySource(
			(string) ( $data['source'] ?? '' ),
			(string) ( $data['query_handling'] ?? 'ignore' )
		);

		if ( $duplicate ) {
			return $this->duplicateError( $duplicate );
		}

		$row = new RedirectRow();
		$row->fill( $data )->save();

		if ( empty( $row->id ) ) {
			return $this->error( 'rest_create_failed', __( 'Could not create the redirect.', '404-to-301' ), 500 );
		}

		return $this->respond( $this->shape( $row ), 201 );
	}

	/**
	 * PUT / PATCH /redirects/{id}.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$row = new RedirectRow( $id );

		if ( ! $row->exists() ) {
			return $this->notFound( __( 'Redirect not found.', '404-to-301' ) );
		}

		$data = $this->collectWritable( $request, false );

		// Merged with the stored row for context, but only the fields this request actually sends are
		// judged. Validating the whole row would trap a rule whose target page has since been deleted:
		// the reason it needs editing is the reason the edit would be rejected.
		$invalid = $this->validate(
			[
				'source'         => (string) ( $data['source'] ?? $row->source ),
				'match_type'     => (string) ( $data['match_type'] ?? $row->match_type ),
				'target_type'    => (string) ( $data['target_type'] ?? $row->target_type ),
				'target_url'     => (string) ( $data['target_url'] ?? $row->target_url ),
				'target_page_id' => (int) ( $data['target_page_id'] ?? $row->target_page_id )
			],
			array_keys( $data )
		);
		if ( $invalid ) {
			return $invalid;
		}

		// When the user is changing the source / query handling, make
		// sure the new combination doesn't collide with another row.
		if ( isset( $data['source'] ) || isset( $data['query_handling'] ) ) {
			$source        = (string) ( $data['source'] ?? $row->source );
			$queryHandling = (string) ( $data['query_handling'] ?? $row->query_handling );
			$duplicate     = RedirectRow::findBySource( $source, $queryHandling, $id );

			if ( $duplicate ) {
				return $this->duplicateError( $duplicate );
			}
		}

		if ( ! empty( $data ) ) {
			$row->fill( $data )->save();

			// When is_active changes, sync the status of every log that
			// is linked to this redirect so the overview stays accurate.
			if ( isset( $data['is_active'] ) ) {
				LogRow::syncStatusForRedirect( $id, (bool) $data['is_active'] );
			}
		}

		return $this->respond( $this->shape( new RedirectRow( $id ) ) );
	}

	/**
	 * DELETE /redirects/{id}.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$row = new RedirectRow( $id );

		// Model::delete() returns null by design, so existence before the call is the signal.
		$deleted = $row->exists();

		if ( $deleted ) {
			$row->delete();
		}

		if ( ! $deleted ) {
			return $this->error( 'rest_delete_failed', __( 'Could not delete the redirect.', '404-to-301' ), 500 );
		}

		// Unlink any logs that were pointing at this redirect.
		LogRow::unlinkRedirect( $id );

		return $this->respond(
			[
				'id'      => $id,
				'deleted' => true,
			]
		);
	}

	/**
	 * DELETE /redirects (bulk).
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function bulkDelete( WP_REST_Request $request ): WP_REST_Response {
		$ids     = (array) $request->get_param( 'ids' );
		$deleted = 0;

		foreach ( $ids as $id ) {
			$intId = (int) $id;
			$row   = new RedirectRow( $intId );

			if ( $row->exists() ) {
				$row->delete();
				++$deleted;
				LogRow::unlinkRedirect( $intId );
			}
		}

		return $this->respond( [ 'deleted' => $deleted ] );
	}

	/**
	 * POST /redirects/bulk-update — apply a small set of column changes
	 * to every selected row in a single round-trip.
	 *
	 * Supported columns: `is_active`, `redirect_type`. The endpoint
	 * intentionally accepts only a curated subset of writable fields —
	 * bulk-editing `source` or `target_url` makes no sense (every row
	 * would end up identical), and a "set everything" API would be
	 * easy to misuse from the UI.
	 *
	 * Any column not in the payload is left untouched. Passing no
	 * mutating fields is a no-op that returns `{ updated: 0 }`.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response Body shape: `{ updated: int }`.
	 */
	public function bulkUpdate( WP_REST_Request $request ): WP_REST_Response {
		$ids  = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		$data = [];

		$isActive = $request->get_param( 'is_active' );
		if ( null !== $isActive ) {
			$data['is_active'] = (int) (bool) $isActive;
		}

		$redirectType = $request->get_param( 'redirect_type' );
		if ( null !== $redirectType ) {
			$data['redirect_type'] = (int) $redirectType;
		}

		if ( empty( $data ) || empty( $ids ) ) {
			return $this->respond( [ 'updated' => 0 ] );
		}

		$updated  = 0;
		$syncLogs = isset( $data['is_active'] );

		foreach ( $ids as $id ) {
			$row = 0 < $id ? new RedirectRow( $id ) : null;

			if ( $row && $row->exists() ) {
				$row->fill( $data )->save();
				++$updated;

				if ( $syncLogs ) {
					LogRow::syncStatusForRedirect( $id, (bool) $data['is_active'] );
				}
			}
		}

		return $this->respond( [ 'updated' => $updated ] );
	}

	/**
	 * Shape a redirect row for transport over REST.
	 *
	 * @since 4.0.0
	 *
	 * @param RedirectRow|null $row Row to shape.
	 *
	 * @return array
	 */
	/**
	 * Reject input that would store a rule the redirect engine can never run.
	 *
	 * Each of these used to be accepted with a 201 and then fail silently at request time: a
	 * malformed pattern is skipped by `preg_match`, a missing page resolves to no target, and a rule
	 * pointing at its own source loops until the browser gives up.
	 *
	 * @since 4.0.4
	 *
	 * @param  array      $data    Writable fields, merged with the stored row on update.
	 * @param  array|null $changed Field names this request sends; null on create, meaning "all of them".
	 * @return WP_Error|null       An error when the input can't be stored, null when it's fine.
	 */
	private function validate( array $data, ?array $changed = null ) {
		$source     = trim( (string) ( $data['source'] ?? '' ) );
		$matchType  = (string) ( $data['match_type'] ?? 'exact' );
		$targetType = (string) ( $data['target_type'] ?? 'link' );
		$targetUrl  = trim( (string) ( $data['target_url'] ?? '' ) );

		// On create every field is new; on update only what the request sends is judged.
		$touches = static function ( ...$fields ) use ( $changed ) {
			if ( null === $changed ) {
				return true;
			}

			return (bool) array_intersect( $fields, $changed );
		};

		if ( $touches( 'source' ) && '' === $source ) {
			return $this->invalid( 'rest_missing_source', __( 'Enter the source URL or pattern to redirect from.', '404-to-301' ), 'source' );
		}

		if (
			$touches( 'source', 'match_type' )
			&& 'regex' === $matchType
			&& ! aioseo404To301()->helpers->isValidRegex( $source )
		) {
			return $this->invalid(
				'rest_invalid_regex',
				__( 'That is not a valid regular expression. Check the pattern and its delimiters.', '404-to-301' ),
				'source'
			);
		}

		if (
			$touches( 'target_url', 'target_type' )
			&& 'link' === $targetType
			&& '' !== $targetUrl
			&& ! aioseo404To301()->helpers->isAllowedRedirectTarget( $targetUrl )
		) {
			return $this->invalid(
				'rest_invalid_target',
				__( 'The destination must be a relative path or an http(s) URL.', '404-to-301' ),
				'target_url'
			);
		}

		if ( $touches( 'target_page_id', 'target_type' ) && 'page' === $targetType ) {
			$pageId = (int) ( $data['target_page_id'] ?? 0 );

			if ( $pageId <= 0 || null === get_post( $pageId ) ) {
				return $this->invalid(
					'rest_invalid_target_page',
					__( 'Choose an existing page to redirect to.', '404-to-301' ),
					'target_page_id'
				);
			}
		}

		// Only meaningful for a literal source; a pattern doesn't have one path to compare.
		if (
			$touches( 'source', 'target_url', 'target_type', 'match_type' )
			&& 'exact' === $matchType
			&& 'link' === $targetType
			&& '' !== $targetUrl
		) {
			$helpers = aioseo404To301()->helpers;

			if ( $helpers->urlHashWithQuery( $source ) === $helpers->urlHashWithQuery( $targetUrl ) ) {
				return $this->invalid(
					'rest_redirect_loop',
					__( 'The destination is the same as the source, which would redirect in a loop.', '404-to-301' ),
					'target_url'
				);
			}
		}

		return null;
	}

	/**
	 * Build a 400 for a rejected field.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $code    Error code.
	 * @param  string $message Human-readable reason.
	 * @param  string $field   Field the React form should highlight.
	 * @return WP_Error
	 */
	private function invalid( string $code, string $message, string $field ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			[
				'status' => 400,
				'field'  => $field,
			]
		);
	}

	/**
	 * Build the 409 response returned when a create / update would
	 * collide with an existing row.
	 *
	 * Carries the conflicting row's id and source in the `data` bag so
	 * the React form can attach the message to the right field (and a
	 * future revision could add an "Edit existing" shortcut).
	 *
	 * @since 4.0.0
	 *
	 * @param RedirectRow $existing The row already using this source.
	 *
	 * @return WP_Error
	 */
	private function duplicateError( RedirectRow $existing ): WP_Error {
		return new WP_Error(
			'rest_duplicate_source',
			sprintf(
				/* translators: %s: source URL/path of the existing redirect. */
				__( 'A redirect for "%s" already exists. Edit the existing rule instead of creating a duplicate.', '404-to-301' ),
				$existing->source
			),
			[
				'status'      => 409,
				'field'       => 'source',
				'existing_id' => (int) $existing->id,
				'source'      => (string) $existing->source,
			]
		);
	}

	/**
	 * Shape a RedirectRow into the REST response payload.
	 *
	 * Casts each column to the type the React client expects and resolves
	 * the modifying user's display name so the UI can render it without a
	 * follow-up request.
	 *
	 * @since 4.0.0
	 *
	 * @param mixed $row Row instance from the data store. Anything other
	 *                   than a {@see RedirectRow} returns an empty array.
	 *
	 * @return array
	 */
	private function shape( $row ): array {
		if ( ! $row instanceof RedirectRow ) {
			return [];
		}

		$modifiedBy     = null === $row->modified_by ? null : (int) $row->modified_by;
		$modifiedByName = '';
		if ( $modifiedBy ) {
			// `display_name` is the canonical human label across the
			// admin UI. `get_userdata()` returns false on a stale id so
			// we degrade gracefully when the user has been deleted.
			$user           = get_userdata( $modifiedBy );
			$modifiedByName = $user ? (string) $user->display_name : '';
		}

		return [
			'id'               => (int) $row->id,
			'source'           => (string) $row->source,
			'match_type'       => (string) $row->match_type,
			'target_type'      => (string) $row->target_type,
			'target_url'       => (string) $row->target_url,
			'target_page_id'   => null === $row->target_page_id ? null : (int) $row->target_page_id,
			'redirect_type'    => (int) $row->redirect_type,
			'is_active'        => (bool) $row->is_active,
			'hits'             => (int) $row->hits,
			'last_hit_at'      => $row->last_hit_at,
			'notes'            => $row->notes,
			'query_handling'   => (string) $row->query_handling,
			'modified_by'      => $modifiedBy,
			'modified_by_name' => $modifiedByName,
			'created_at'       => $row->created_at,
			'updated_at'       => $row->updated_at,
			'has_linked_log'   => RedirectRow::hasLinkedLog( (int) $row->id ),
		];
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
			'page'          => [
				'type'    => 'integer',
				'default' => 1,
			],
			'per_page'      => [
				'type'    => 'integer',
				'default' => 20,
			],
			'orderby'       => [
				'type'    => 'string',
				'default' => 'id',
			],
			'order'         => [
				'type'    => 'string',
				'enum'    => [ 'ASC', 'DESC', 'asc', 'desc' ],
				'default' => 'DESC',
			],
			'search'        => [ 'type' => 'string' ],
			'match_type'    => [
				'type' => 'string',
				'enum' => [ 'exact', 'prefix', 'regex' ],
			],
			'target_type'   => [
				'type' => 'string',
				'enum' => [ 'link', 'page', 'none' ],
			],
			'redirect_type' => [ 'type' => 'integer' ],
			'is_active'     => [ 'type' => 'boolean' ],
		];
	}

	/**
	 * REST argument schema for create / update.
	 *
	 * `source` is required on create, optional on update.
	 *
	 * @since 4.0.0
	 *
	 * @param bool $isCreate Whether this is a create request.
	 *
	 * @return array
	 */
	private function writableArgs( bool $isCreate ): array {
		return [
			'source'         => [
				'type'     => 'string',
				'required' => $isCreate,
			],
			'match_type'     => [
				'type' => 'string',
				'enum' => [ 'exact', 'prefix', 'regex' ],
			],
			'target_type'    => [
				'type' => 'string',
				'enum' => [ 'link', 'page', 'none' ],
			],
			'target_url'     => [ 'type' => 'string' ],
			'target_page_id' => [ 'type' => 'integer' ],
			'redirect_type'  => [
				'type' => 'integer',
				// Sourced from the canonical catalogue in
				// `aioseo404To301()->helpers->redirectStatuses()`. Terminal codes (410/451)
				// are included — they don't redirect; the front controller
				// emits the status header and exits.
				'enum' => aioseo404To301()->helpers->redirectStatusCodes(),
			],
			'is_active'      => [ 'type' => 'boolean' ],
			'notes'          => [ 'type' => 'string' ],
			'query_handling' => [
				'type' => 'string',
				'enum' => [ 'ignore', 'preserve', 'require' ],
			],
		];
	}

	/**
	 * Pull the writable columns from a request body.
	 *
	 * @since 4.0.0
	 *
	 * @param WP_REST_Request $request   REST request.
	 * @param bool            $withDefaults Apply defaults for missing columns.
	 *
	 * @return array Column => value map (only set keys are included).
	 */
	private function collectWritable( WP_REST_Request $request, bool $withDefaults ): array {
		$keys = array_keys( $this->writableArgs( $withDefaults ) );
		$data = [];

		foreach ( $keys as $key ) {
			$value = $request->get_param( $key );

			if ( null === $value ) {
				continue;
			}

			switch ( $key ) {
				case 'source':
				case 'target_url':
				case 'notes':
					$data[ $key ] = sanitize_text_field( (string) $value );
					break;

				case 'match_type':
				case 'target_type':
					$data[ $key ] = (string) $value;
					break;

				case 'query_handling':
					$mode         = (string) $value;
					$data[ $key ] = in_array( $mode, [ 'ignore', 'preserve', 'require' ], true ) ? $mode : 'ignore';
					break;

				case 'target_page_id':
				case 'redirect_type':
					$data[ $key ] = (int) $value;
					break;

				case 'is_active':
					$data[ $key ] = (int) (bool) $value;
					break;
				default:
					// Unknown keys are dropped rather than trusted.
					break;

			}
		}

		// Sensible create-time defaults.
		if ( $withDefaults ) {
			$data['match_type']    = $data['match_type'] ?? 'exact';
			$data['target_type']   = $data['target_type'] ?? 'link';
			$data['redirect_type'] = $data['redirect_type'] ?? 301;
			$data['is_active']     = $data['is_active'] ?? 1;
		}

		return $data;
	}
}