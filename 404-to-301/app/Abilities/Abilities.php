<?php
/**
 * Registers the plugin's abilities with the WordPress Abilities API.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Api\Endpoint;
use AIOSEO\FourNotFour\Main\Front\Actions\Redirect as RedirectAction;
use AIOSEO\FourNotFour\Models\Log as LogRow;
use AIOSEO\FourNotFour\Models\Redirect as RedirectRow;
use AIOSEO\FourNotFour\Utils\Permission;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Abilities
 *
 * Abilities are exposed at /wp-json/wp-abilities/v1/ on WordPress 6.9+, and become MCP tools when
 * an MCP adapter is active alongside the plugin. Nothing here depends on AIOSEO being installed:
 * the two core hooks are the only integration point.
 *
 * Every execute callback dispatches through the plugin's own REST routes rather than touching the
 * models. Those routes already own the validation an agent most needs enforced - a malformed regex,
 * a duplicate source, a rule that would redirect to itself - so re-implementing the writes here
 * would mean two paths that could disagree about what is a legal redirect.
 *
 * @since   4.0.4
 * @package AIOSEO\FourNotFour\Abilities
 */
class Abilities {

	/**
	 * Ability category slug => label.
	 *
	 * @since 4.0.4
	 *
	 * @var array<string, string>
	 */
	private const CATEGORIES = [
		'404-to-301-redirects' => '404 to 301 — Redirects',
		'404-to-301-logs'      => '404 to 301 — 404 Logs',
		'404-to-301-settings'  => '404 to 301 — Settings',
	];

	/**
	 * Register hooks.
	 *
	 * @since 4.0.4
	 */
	public function __construct() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'registerCategories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'registerAbilities' ] );
	}

	/**
	 * Register the plugin's ability categories.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	public function registerCategories(): void {
		// The constructor already bails on WordPress below 6.9. Repeated here because the method is
		// public, and because a guard in the calling scope is what Plugin Check's
		// `wp_function_not_compatible_with_requires_wp` sniff looks for.
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		foreach ( self::CATEGORIES as $slug => $label ) {
			wp_register_ability_category(
				$slug,
				[
					'label'       => $label,
					'description' => __( 'Redirect and 404 management abilities provided by 404 to 301.', '404-to-301' ),
				]
			);
		}
	}

	/**
	 * Register every ability.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	public function registerAbilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->registerRedirectAbilities();
		$this->registerLogAbilities();
		$this->registerSettingsAbilities();
	}

	/**
	 * Whether the caller may use the plugin's abilities.
	 *
	 * Deliberately the same check the admin pages and REST routes use, so granting an agent access
	 * is the same decision as granting a user access.
	 *
	 * @since 4.0.4
	 *
	 * @return bool
	 */
	public function hasAccess(): bool {
		return Permission::hasAccess();
	}

	/**
	 * Register the redirect abilities.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	private function registerRedirectAbilities(): void {
		wp_register_ability(
			'404-to-301/redirects-list',
			[
				'label'               => __( 'List Redirects', '404-to-301' ),
				'description'         => __( 'Returns a page of redirect rules with their source, destination, status code, match type, hit count and whether they are enabled. Filterable by search term, match type, status code and enabled state.', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'category'            => '404-to-301-redirects',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => array_merge(
						$this->pagingProperties( 'id' ),
						[
							'search'        => [
								'type'        => 'string',
								'description' => __( 'Match against the source and destination.', '404-to-301' ),
							],
							'match_type'    => [
								'type' => 'string',
								'enum' => [ 'exact', 'prefix', 'regex' ],
							],
							'redirect_type' => [
								'type'        => 'integer',
								'description' => __( 'HTTP status code the rule sends, e.g. 301.', '404-to-301' ),
							],
							'is_active'     => [ 'type' => 'boolean' ],
						]
					),
				],
				'output_schema'       => $this->collectionSchema( $this->redirectSchema() ),
				'execute_callback'    => [ $this, 'listRedirects' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->readonlyMeta(),
			]
		);

		wp_register_ability(
			'404-to-301/redirect-create',
			[
				'label'               => __( 'Create Redirect', '404-to-301' ),
				'description'         => __( 'Creates a redirect rule. The source is a path or pattern on this site; the destination is either a URL or the ID of an existing page. Rejects a source that duplicates an existing rule, an invalid regular expression, and a destination equal to its own source.', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'category'            => '404-to-301-redirects',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => $this->writableRedirectProperties(),
					'required'   => [ 'source' ],
				],
				'output_schema'       => $this->redirectSchema(),
				'execute_callback'    => [ $this, 'createRedirect' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->writeMeta(),
			]
		);

		wp_register_ability(
			'404-to-301/redirect-update',
			[
				'label'               => __( 'Update Redirect', '404-to-301' ),
				'description'         => __( 'Updates an existing redirect rule. Only the fields passed in are changed; everything else is left as it is. Use this to repoint, re-code or disable a rule.', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'category'            => '404-to-301-redirects',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => array_merge(
						[
							'redirectId' => [
								'type'        => 'integer',
								'description' => __( 'The redirect ID.', '404-to-301' ),
							],
						],
						$this->writableRedirectProperties()
					),
					'required'   => [ 'redirectId' ],
				],
				'output_schema'       => $this->redirectSchema(),
				'execute_callback'    => [ $this, 'updateRedirect' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->writeMeta(),
			]
		);

		wp_register_ability(
			'404-to-301/redirect-delete',
			[
				'label'               => __( 'Delete Redirect', '404-to-301' ),
				'description'         => __( 'Permanently deletes a redirect rule and unlinks it from any 404 log entry it was resolving. Disabling a rule is usually the safer option — see the update ability.', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'category'            => '404-to-301-redirects',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'redirectId' => [
							'type'        => 'integer',
							'description' => __( 'The redirect ID.', '404-to-301' ),
						],
					],
					'required'   => [ 'redirectId' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'      => [ 'type' => 'integer' ],
						'deleted' => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ $this, 'deleteRedirect' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->writeMeta(),
			]
		);

		wp_register_ability(
			'404-to-301/redirect-test',
			[
				'label'               => __( 'Test a URL Against the Redirects', '404-to-301' ),
				'description'         => __( 'Resolves a path the way the plugin would for a real visitor and reports what happens: which rule matches (if any), the destination it sends them to, the status code, and whether the site-wide 404 fallback would take over instead. Changes nothing.', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'category'            => '404-to-301-redirects',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url' => [
							'type'        => 'string',
							'description' => __( 'Path or URL to resolve, e.g. /old-post/.', '404-to-301' ),
						],
					],
					'required'   => [ 'url' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'url'         => [ 'type' => 'string' ],
						'outcome'     => [
							'type'        => 'string',
							'enum'        => [ 'rule', 'fallback', 'none' ],
							'description' => __( 'Which mechanism handles the request: a redirect rule, the site-wide 404 fallback, or nothing.', '404-to-301' ),
						],
						'destination' => [
							'type'        => [ 'string', 'null' ],
							'description' => __( 'Where the visitor lands; null when nothing redirects them.', '404-to-301' ),
						],
						'status_code' => [ 'type' => [ 'integer', 'null' ] ],
						'redirect'    => array_merge(
							$this->redirectSchema(),
							[ 'type' => [ 'object', 'null' ] ]
						),
					],
				],
				'execute_callback'    => [ $this, 'testRedirect' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->readonlyMeta(),
			]
		);
	}

	/**
	 * Register the 404 log abilities.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	private function registerLogAbilities(): void {
		wp_register_ability(
			'404-to-301/logs-list',
			[
				'label'               => __( 'List 404 Logs', '404-to-301' ),
				'description'         => __( 'Returns a page of logged 404 requests with the requested path, referrer, hit count and whether the entry is still open. Sort by hits to find the URLs worth fixing first.', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'category'            => '404-to-301-logs',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => array_merge(
						$this->pagingProperties( 'updated_at' ),
						[
							'search' => [
								'type'        => 'string',
								'description' => __( 'Match against the requested path and the referrer.', '404-to-301' ),
							],
							'status' => [
								'type'        => 'integer',
								'enum'        => [ LogRow::STATUS_OPEN, LogRow::STATUS_IGNORED, LogRow::STATUS_FIXED ],
								'description' => __( '0 open, 1 ignored, 2 fixed.', '404-to-301' ),
							],
						]
					),
				],
				'output_schema'       => $this->collectionSchema( $this->logSchema() ),
				'execute_callback'    => [ $this, 'listLogs' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->readonlyMeta(),
			]
		);

		wp_register_ability(
			'404-to-301/logs-summary',
			[
				'label'               => __( 'Summarise 404 Logs', '404-to-301' ),
				'description'         => __( 'Returns how many logged 404s are open, ignored, fixed and resolved by a custom redirect. A cheap health check to run before listing anything.', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'category'            => '404-to-301-logs',
				'input_schema'        => $this->noInputSchema(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'total'   => [ 'type' => 'integer' ],
						'open'    => [ 'type' => 'integer' ],
						'ignored' => [ 'type' => 'integer' ],
						'fixed'   => [ 'type' => 'integer' ],
						'custom'  => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'summariseLogs' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->readonlyMeta(),
			]
		);

		wp_register_ability(
			'404-to-301/log-update',
			[
				'label'               => __( 'Update a 404 Log Entry', '404-to-301' ),
				'description'         => __( 'Marks a logged 404 as open, ignored or fixed. Ignoring an entry keeps it out of the open count and the email reports without deleting its history.', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'category'            => '404-to-301-logs',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'logId'  => [
							'type'        => 'integer',
							'description' => __( 'The log entry ID.', '404-to-301' ),
						],
						'status' => [
							'type'        => 'integer',
							'enum'        => [ LogRow::STATUS_OPEN, LogRow::STATUS_IGNORED, LogRow::STATUS_FIXED ],
							'description' => __( '0 open, 1 ignored, 2 fixed.', '404-to-301' ),
						],
					],
					'required'   => [ 'logId', 'status' ],
				],
				'output_schema'       => $this->logSchema(),
				'execute_callback'    => [ $this, 'updateLog' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->writeMeta(),
			]
		);

		wp_register_ability(
			'404-to-301/log-fix',
			[
				'label'               => __( 'Fix a Logged 404', '404-to-301' ),
				'description'         => __( 'Creates a redirect for a logged 404 and links the two, which marks the log entry fixed. The source is taken from the log entry, so only the destination has to be supplied.', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'category'            => '404-to-301-logs',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'logId'          => [
							'type'        => 'integer',
							'description' => __( 'The log entry ID to resolve.', '404-to-301' ),
						],
						'target_url'     => [
							'type'        => 'string',
							'description' => __( 'Destination path or URL.', '404-to-301' ),
						],
						'target_page_id' => [
							'type'        => 'integer',
							'description' => __( 'ID of an existing page to send visitors to, instead of a URL.', '404-to-301' ),
						],
						'redirect_type'  => [
							'type'        => 'integer',
							'description' => __( 'HTTP status code to send. Defaults to 301.', '404-to-301' ),
						],
					],
					'required'   => [ 'logId' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'log'      => $this->logSchema(),
						'redirect' => $this->redirectSchema(),
					],
				],
				'execute_callback'    => [ $this, 'fixLog' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->writeMeta(),
			]
		);
	}

	/**
	 * Register the settings abilities.
	 *
	 * @since 4.0.4
	 *
	 * @return void
	 */
	private function registerSettingsAbilities(): void {
		wp_register_ability(
			'404-to-301/settings-get',
			[
				'label'               => __( 'Get 404 to 301 Settings', '404-to-301' ),
				'description'         => __( 'Returns every plugin setting as a flat map: the site-wide 404 fallback, 404 logging, email notifications and reports, and log trimming.', '404-to-301' ),
				'category'            => '404-to-301-settings',
				'input_schema'        => $this->noInputSchema(),
				'output_schema'       => [ 'type' => 'object' ],
				'execute_callback'    => [ $this, 'getSettings' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->readonlyMeta(),
			]
		);

		wp_register_ability(
			'404-to-301/settings-update',
			[
				'label'               => __( 'Update 404 to 301 Settings', '404-to-301' ),
				'description'         => __( 'Writes plugin settings from a flat map of the same keys the get ability returns. Only the keys passed in are changed. Unknown keys are ignored, and the Telegram credentials cannot be written here.', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
				'category'            => '404-to-301-settings',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'settings' => [
							'type'        => 'object',
							'description' => __( 'Flat key => value map of the settings to change.', '404-to-301' ),
						],
					],
					'required'   => [ 'settings' ],
				],
				'output_schema'       => [ 'type' => 'object' ],
				'execute_callback'    => [ $this, 'updateSettings' ],
				'permission_callback' => [ $this, 'hasAccess' ],
				'meta'                => $this->writeMeta(),
			]
		);
	}

	/**
	 * List redirects.
	 *
	 * @since 4.0.4
	 *
	 * @param  array          $input Ability input.
	 * @return array|WP_Error        Items plus the unpaged total.
	 */
	public function listRedirects( $input = [] ) {
		return $this->collection(
			$this->dispatch( 'GET', '/redirects', $this->pick( $input, [ 'page', 'per_page', 'orderby', 'order', 'search', 'match_type', 'redirect_type', 'is_active' ] ) )
		);
	}

	/**
	 * Create a redirect.
	 *
	 * @since 4.0.4
	 *
	 * @param  array          $input Ability input.
	 * @return array|WP_Error        The created redirect.
	 */
	public function createRedirect( $input = [] ) {
		return $this->data( $this->dispatch( 'POST', '/redirects', $this->pick( $input, $this->writableRedirectKeys() ) ) );
	}

	/**
	 * Update a redirect.
	 *
	 * @since 4.0.4
	 *
	 * @param  array          $input Ability input.
	 * @return array|WP_Error        The updated redirect.
	 */
	public function updateRedirect( $input = [] ) {
		$id = (int) ( $input['redirectId'] ?? 0 );

		return $this->data(
			$this->dispatch( 'PATCH', '/redirects/' . $id, $this->pick( $input, $this->writableRedirectKeys() ) )
		);
	}

	/**
	 * Delete a redirect.
	 *
	 * @since 4.0.4
	 *
	 * @param  array          $input Ability input.
	 * @return array|WP_Error        The deleted ID.
	 */
	public function deleteRedirect( $input = [] ) {
		return $this->data( $this->dispatch( 'DELETE', '/redirects/' . (int) ( $input['redirectId'] ?? 0 ) ) );
	}

	/**
	 * Resolve a URL against the redirect rules without touching anything.
	 *
	 * @since 4.0.4
	 *
	 * @param  array $input Ability input.
	 * @return array        What would happen to a visitor requesting this URL.
	 */
	public function testRedirect( $input = [] ) {
		$url   = (string) ( $input['url'] ?? '' );
		$match = RedirectRow::findMatch( $url );

		if ( $match && $match->exists() ) {
			$destination = $match->resolveTarget();

			return [
				'url'         => $url,
				'outcome'     => 'rule',
				'destination' => '' !== $destination ? $destination : null,
				'status_code' => (int) $match->redirect_type,
				'redirect'    => $this->data( $this->dispatch( 'GET', '/redirects/' . (int) $match->id ) ),
			];
		}

		$fallbackEnabled = (bool) aioseo404To301()->options->redirects->enabled;
		$fallback        = $fallbackEnabled ? ( new RedirectAction() )->resolveGlobalTarget() : '';

		if ( '' === $fallback ) {
			return [
				'url'         => $url,
				'outcome'     => 'none',
				'destination' => null,
				'status_code' => null,
				'redirect'    => null,
			];
		}

		return [
			'url'         => $url,
			'outcome'     => 'fallback',
			'destination' => $fallback,
			'status_code' => (int) aioseo404To301()->options->redirects->statusCode,
			'redirect'    => null,
		];
	}

	/**
	 * List 404 logs.
	 *
	 * @since 4.0.4
	 *
	 * @param  array          $input Ability input.
	 * @return array|WP_Error        Items plus the unpaged total.
	 */
	public function listLogs( $input = [] ) {
		return $this->collection(
			$this->dispatch( 'GET', '/logs', $this->pick( $input, [ 'page', 'per_page', 'orderby', 'order', 'search', 'status' ] ) )
		);
	}

	/**
	 * Summarise the 404 logs.
	 *
	 * @since 4.0.4
	 *
	 * @return array|WP_Error Counts per status.
	 */
	public function summariseLogs() {
		return $this->data( $this->dispatch( 'GET', '/logs/summary' ) );
	}

	/**
	 * Set the status of a log entry.
	 *
	 * @since 4.0.4
	 *
	 * @param  array          $input Ability input.
	 * @return array|WP_Error        The updated log entry.
	 */
	public function updateLog( $input = [] ) {
		return $this->data(
			$this->dispatch(
				'PATCH',
				'/logs/' . (int) ( $input['logId'] ?? 0 ),
				$this->pick( $input, [ 'status' ] )
			)
		);
	}

	/**
	 * Create a redirect for a logged 404 and link the two.
	 *
	 * @since 4.0.4
	 *
	 * @param  array          $input Ability input.
	 * @return array|WP_Error        The updated log entry and the redirect that now resolves it.
	 */
	public function fixLog( $input = [] ) {
		$logId = (int) ( $input['logId'] ?? 0 );
		$log   = new LogRow( $logId );

		if ( ! $log->exists() ) {
			return new WP_Error( 'rest_not_found', __( 'Log not found.', '404-to-301' ) );
		}

		$payload = $this->pick( $input, [ 'target_url', 'target_page_id', 'redirect_type' ] );

		// The log stores the requested path, and that path is the whole point of the rule - an
		// agent-supplied source would silently create a redirect that never fires for this 404.
		$payload['source'] = (string) $log->url;

		if ( ! empty( $payload['target_page_id'] ) ) {
			$payload['target_type'] = 'page';
		}

		$redirect = $this->data( $this->dispatch( 'POST', '/redirects', $payload ) );
		if ( is_wp_error( $redirect ) ) {
			return $redirect;
		}

		$linked = $this->data(
			$this->dispatch( 'PATCH', '/logs/' . $logId, [ 'redirect_id' => (int) ( $redirect['id'] ?? 0 ) ] )
		);
		if ( is_wp_error( $linked ) ) {
			return $linked;
		}

		return [
			'log'      => $linked,
			'redirect' => $redirect,
		];
	}

	/**
	 * Read the plugin settings.
	 *
	 * @since 4.0.4
	 *
	 * @return array|WP_Error Flat settings map.
	 */
	public function getSettings() {
		$response = $this->data( $this->dispatch( 'GET', '/settings' ) );

		return is_wp_error( $response ) ? $response : (array) ( $response['settings'] ?? [] );
	}

	/**
	 * Write the plugin settings.
	 *
	 * @since 4.0.4
	 *
	 * @param  array          $input Ability input.
	 * @return array|WP_Error        The settings as stored after the write.
	 */
	public function updateSettings( $input = [] ) {
		$response = $this->data(
			$this->dispatch( 'POST', '/settings', [ 'settings' => (array) ( $input['settings'] ?? [] ) ] )
		);

		return is_wp_error( $response ) ? $response : (array) ( $response['settings'] ?? [] );
	}

	/**
	 * Run a request against the plugin's own REST routes.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $method HTTP method.
	 * @param  string $route  Route below the plugin namespace, with a leading slash.
	 * @param  array  $params Request params.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . Endpoint::NAMESPACE . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Unwrap a dispatched response into its data, or the error it carries.
	 *
	 * @since 4.0.4
	 *
	 * @param  WP_REST_Response $response Dispatched response.
	 * @return mixed|WP_Error
	 */
	private function data( WP_REST_Response $response ) {
		return $response->is_error() ? $response->as_error() : $response->get_data();
	}

	/**
	 * Unwrap a dispatched collection, pairing the items with the unpaged total.
	 *
	 * The total lives in the `X-WP-Total` header rather than the body, and an agent deciding whether
	 * to page again needs it.
	 *
	 * @since 4.0.4
	 *
	 * @param  WP_REST_Response $response Dispatched response.
	 * @return array|WP_Error
	 */
	private function collection( WP_REST_Response $response ) {
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$headers = $response->get_headers();

		return [
			'items' => (array) $response->get_data(),
			'total' => isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : 0,
		];
	}

	/**
	 * Keep only the given keys from an ability's input.
	 *
	 * @since 4.0.4
	 *
	 * @param  mixed    $input Ability input.
	 * @param  string[] $keys  Keys to forward.
	 * @return array           The subset actually present.
	 */
	private function pick( $input, array $keys ): array {
		$picked = [];

		foreach ( $keys as $key ) {
			if ( is_array( $input ) && array_key_exists( $key, $input ) ) {
				$picked[ $key ] = $input[ $key ];
			}
		}

		return $picked;
	}

	/**
	 * Writable redirect columns, as ability input properties.
	 *
	 * @since 4.0.4
	 *
	 * @return array
	 */
	private function writableRedirectProperties(): array {
		return [
			'source'         => [
				'type'        => 'string',
				'description' => __( 'Path or pattern to redirect from, e.g. /old-post/.', '404-to-301' ),
			],
			'match_type'     => [
				'type'        => 'string',
				'enum'        => [ 'exact', 'prefix', 'regex' ],
				'description' => __( 'How the source is matched. Defaults to exact.', '404-to-301' ),
			],
			'target_type'    => [
				'type'        => 'string',
				'enum'        => [ 'link', 'page', 'none' ],
				'description' => __( 'Whether the destination is a URL, an existing page, or nothing.', '404-to-301' ),
			],
			'target_url'     => [
				'type'        => 'string',
				'description' => __( 'Destination path or URL, used when target_type is link.', '404-to-301' ),
			],
			'target_page_id' => [
				'type'        => 'integer',
				'description' => __( 'Destination page ID, used when target_type is page.', '404-to-301' ),
			],
			'redirect_type'  => [
				'type'        => 'integer',
				'description' => __( 'HTTP status code to send. Defaults to 301.', '404-to-301' ),
			],
			'is_active'      => [
				'type'        => 'boolean',
				'description' => __( 'Whether the rule runs. Defaults to true.', '404-to-301' ),
			],
			'query_handling' => [
				'type'        => 'string',
				'enum'        => [ 'ignore', 'preserve', 'require' ],
				'description' => __( 'What to do with the request query string. Defaults to ignore.', '404-to-301' ),
			],
			'notes'          => [ 'type' => 'string' ],
		];
	}

	/**
	 * Writable redirect column names.
	 *
	 * @since 4.0.4
	 *
	 * @return string[]
	 */
	private function writableRedirectKeys(): array {
		return array_keys( $this->writableRedirectProperties() );
	}

	/**
	 * Input schema for an ability that takes no arguments.
	 *
	 * Deliberately declares no `properties`: an empty PHP array encodes as JSON `[]` rather than
	 * `{}`, which a strict validator reads as a malformed schema and then treats as no schema at
	 * all - and the Abilities API rejects any input, including the empty `[]` that MCP clients and
	 * WP-CLI send, once it believes a schema is missing.
	 *
	 * @since 4.0.4
	 *
	 * @return array
	 */
	private function noInputSchema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'default'              => [],
		];
	}

	/**
	 * Paging and ordering input properties shared by the list abilities.
	 *
	 * @since 4.0.4
	 *
	 * @param  string $defaultOrderby Column the endpoint sorts by when none is given.
	 * @return array
	 */
	private function pagingProperties( string $defaultOrderby ): array {
		return [
			'page'     => [
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			],
			'per_page' => [
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			],
			'orderby'  => [
				'type'    => 'string',
				'default' => $defaultOrderby,
			],
			'order'    => [
				'type'    => 'string',
				'enum'    => [ 'ASC', 'DESC' ],
				'default' => 'DESC',
			],
		];
	}

	/**
	 * Output schema for a paged list.
	 *
	 * @since 4.0.4
	 *
	 * @param  array $itemSchema Schema of one item.
	 * @return array
	 */
	private function collectionSchema( array $itemSchema ): array {
		return [
			'type'       => 'object',
			'properties' => [
				'items' => [
					'type'  => 'array',
					'items' => $itemSchema,
				],
				'total' => [
					'type'        => 'integer',
					'description' => __( 'Total rows matching the filters, across all pages.', '404-to-301' ),
				],
			],
		];
	}

	/**
	 * Output schema for one redirect.
	 *
	 * @since 4.0.4
	 *
	 * @return array
	 */
	private function redirectSchema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'             => [ 'type' => 'integer' ],
				'source'         => [ 'type' => 'string' ],
				'match_type'     => [ 'type' => 'string' ],
				'target_type'    => [ 'type' => 'string' ],
				'target_url'     => [ 'type' => 'string' ],
				'target_page_id' => [ 'type' => [ 'integer', 'null' ] ],
				'redirect_type'  => [ 'type' => 'integer' ],
				'is_active'      => [ 'type' => 'boolean' ],
				'hits'           => [ 'type' => 'integer' ],
				'last_hit_at'    => [ 'type' => [ 'string', 'null' ] ],
				'query_handling' => [ 'type' => 'string' ],
				'notes'          => [ 'type' => [ 'string', 'null' ] ],
				'created_at'     => [ 'type' => [ 'string', 'null' ] ],
				'updated_at'     => [ 'type' => [ 'string', 'null' ] ],
			],
		];
	}

	/**
	 * Output schema for one 404 log entry.
	 *
	 * @since 4.0.4
	 *
	 * @return array
	 */
	private function logSchema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'           => [ 'type' => 'integer' ],
				'url'          => [ 'type' => 'string' ],
				'ref'          => [ 'type' => 'string' ],
				'ua'           => [ 'type' => 'string' ],
				'method'       => [ 'type' => 'string' ],
				'hits'         => [ 'type' => 'integer' ],
				'status'       => [ 'type' => 'integer' ],
				'status_label' => [ 'type' => 'string' ],
				'redirect_id'  => [ 'type' => [ 'integer', 'null' ] ],
				'created_at'   => [ 'type' => [ 'string', 'null' ] ],
				'updated_at'   => [ 'type' => [ 'string', 'null' ] ],
			],
		];
	}

	/**
	 * Meta for an ability that only reads.
	 *
	 * @since 4.0.4
	 *
	 * @return array
	 */
	private function readonlyMeta(): array {
		return [
			'annotations'  => [ 'readonly' => true ],
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
		];
	}

	/**
	 * Meta for an ability that writes.
	 *
	 * @since 4.0.4
	 *
	 * @return array
	 */
	private function writeMeta(): array {
		return [
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
		];
	}
}