<?php
namespace AIOSEO\FourNotFour\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the registration, validation and authorization of API routes.
 *
 * @since 1.0.0
 */
class Api {
	/**
	 * The REST API namespace.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $namespace = 'aioseo404To301/v1';

	/**
	 * The routes we use in the rest API.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $routes = [
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		'GET'  => [
			'options' => [ 'callback' => [ 'VueSettings', 'getOptions' ], 'access' => 'any' ]
		],
		'POST' => [
			'objects'                 => [ 'callback' => [ 'PostsTerms', 'searchForObjects' ], 'access' => [ 'aioseo_404_to_301_page' ] ],
			'options'                 => [ 'callback' => [ 'VueSettings', 'saveChanges' ], 'access' => 'aioseo_404_to_301_page' ],
			'plugins/deactivate'      => [ 'callback' => [ 'Plugins', 'deactivatePlugins' ], 'access' => 'install_plugins' ],
			'plugins/install'         => [ 'callback' => [ 'Plugins', 'installPlugins' ], 'access' => 'install_plugins' ],
			'settings/toggle-radio'   => [ 'callback' => [ 'VueSettings', 'toggleRadio' ], 'access' => 'aioseo_404_to_301_page' ],
			'settings/items-per-page' => [ 'callback' => [ 'VueSettings', 'changeItemsPerPage' ], 'access' => 'aioseo_404_to_301_page' ]
		]
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	];

	/**
	 * Class contructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_filter( 'rest_allowed_cors_headers', [ $this, 'allowedHeaders' ] );
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
	}

	/**
	 * Registers the API routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerRoutes() {
		$class = new \ReflectionClass( get_called_class() );
		foreach ( $this->routes as $method => $data ) {
			foreach ( $data as $route => $options ) {
				register_rest_route(
					$this->namespace,
					$route,
					[
						'methods'             => $method,
						'permission_callback' => empty( $options['permissions'] ) ? [ $this, 'validRequest' ] : [ $this, $options['permissions'] ],
						'callback'            => $this->resolveCallback( $options['callback'], $class )
					]
				);
			}
		}
	}

	/**
	 * Resolve a route's configured callback into something register_rest_route() can call.
	 *
	 * An array callback names a class and a method, and may carry an explicit namespace in a third
	 * element. Without one, the registering class's namespace wins when the class exists there, and
	 * this file's namespace is the fallback.
	 *
	 * @since 4.0.4
	 *
	 * @param  mixed            $callback The configured callback.
	 * @param  \ReflectionClass $reflection The registering class, read for its namespace.
	 * @return array                      A callable for register_rest_route().
	 */
	private function resolveCallback( $callback, $reflection ) {
		if ( ! is_array( $callback ) ) {
			return [ $this, $callback ];
		}

		if ( ! empty( $callback[2] ) ) {
			$namespace = $callback[2];
		} elseif ( class_exists( $reflection->getNamespaceName() . '\\' . $callback[0] ) ) {
			$namespace = $reflection->getNamespaceName();
		} else {
			$namespace = __NAMESPACE__;
		}

		return [ $namespace . '\\' . $callback[0], $callback[1] ];
	}

	/**
	 * Sets headers that are allowed for our API routes.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $allowHeaders The allowed request headers.
	 * @return array               The allowed request headers.
	 */
	public function allowedHeaders( $allowHeaders ) {
		if ( ! array_search( 'X-WP-Nonce', $allowHeaders, true ) ) {
			$allowHeaders[] = 'X-WP-Nonce';
		}

		return $allowHeaders;
	}

	/**
	 * Determine if the user is logged in and has the proper permissions.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request $request The REST Request.
	 * @return bool                      Whether the user is allowed access to the route.
	 */
	public function validRequest( $request ) {
		return is_user_logged_in() && $this->validateAccess( $request );
	}

	/**
	 * Validates access for the routes.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request $request The REST Request.
	 * @return bool                      Whether the user is allowed access to the route.
	 */
	private function validateAccess( $request ) {
		$routeData = $this->getRouteData( $request );
		if ( empty( $routeData ) || empty( $routeData['access'] ) ) {
			return false;
		}

		// Admins users always have access.
		if ( aioseo404To301()->access->isAdmin() ) {
			return true;
		}

		switch ( $routeData['access'] ) {
			case 'any':
				// Users with any 404 to 301 permission can access the route.
				$user = wp_get_current_user();
				foreach ( $user->get_role_caps() as $capability => $enabled ) {
					if ( $enabled && preg_match( '/^aioseo_404_to_301_/', (string) $capability ) ) {
						return true;
					}
				}

				return false;
			default:
				// The user has access if he has any of the required capabilities.
				if ( ! is_array( $routeData['access'] ) ) {
					$routeData['access'] = [ $routeData['access'] ];
				}

				foreach ( $routeData['access'] as $access ) {
					if ( current_user_can( $access ) ) {
						return true;
					}
				}

				return false;
		}
	}

	/**
	 * Returns the data for the route that is being accessed.
	 *
	 * @since 1.0.0
	 *
	 * @param  \WP_REST_Request $request The REST Request.
	 * @return array                     The route data.
	 */
	private function getRouteData( $request ) {
		// NOTE: Since WordPress uses case-insensitive patterns to match routes,
		// we are forcing everything to lower case to ensure we have the proper route.
		// This prevents users with lower privileges from accessing routes they shouldn't.
		$route     = aioseo404To301()->helpers->toLowercase( $request->get_route() );
		$route     = untrailingslashit( str_replace( '/' . $this->namespace . '/', '', $route ) );
		$routeData = isset( $this->routes[ $request->get_method() ][ $route ] ) ? $this->routes[ $request->get_method() ][ $route ] : [];

		// No direct route name, let's try the regexes.
		if ( empty( $routeData ) ) {
			foreach ( $this->routes[ $request->get_method() ] as $routeRegex => $routeInfo ) {
				$routeRegex = str_replace( '@', '\@', $routeRegex );
				if ( preg_match( "@{$routeRegex}@", (string) $route ) ) {
					$routeData = $routeInfo;
					break;
				}
			}
		}

		return $routeData;
	}
}