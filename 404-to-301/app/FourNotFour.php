<?php
namespace AIOSEO\FourNotFour {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * The main FourNotFour class.
	 *
	 * @since 4.0.3
	 */
	final class FourNotFour {
		/**
		 * Holds the instance of the plugin currently in use.
		 *
		 * @since 4.0.3
		 *
		 * @var FourNotFour
		 */
		private static $instance;

		/**
		 * Plugin version for enqueueing, etc.
		 * The value is retrieved from the AIOSEO_404_TO_301_VERSION constant.
		 *
		 * @since 4.0.3
		 *
		 * @var string
		 */
		public $version = '';

		/**
		 * Whether we're in a dev environment.
		 *
		 * @since 4.0.3
		 *
		 * @var bool
		 */
		public $isDev = false;

		/**
		 * Core class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Core\Core
		 */
		public $core;

		/**
		 * Database Schema class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Db\Schema
		 */
		public $dbSchema;

		/**
		 * InternalOptions class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Options\InternalOptions
		 */
		public $internalOptions;

		/**
		 * Pre updates class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Main\PreUpdates
		 */
		public $preUpdates;

		/**
		 * Helpers class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Utils\Helpers
		 */
		public $helpers;

		/**
		 * Options class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Options\Options
		 */
		public $options;

		/**
		 * Updates class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Main\Updates
		 */
		public $updates;

		/**
		 * Action scheduler class.
		 *
		 * @since 4.0.3
		 *
		 * @var Utils\ActionScheduler
		 */
		public $actionScheduler;

		/**
		 * Access class.
		 *
		 * @since 4.0.3
		 *
		 * @var Utils\Access
		 */
		public $access;

		/**
		 * Main class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Main\Main
		 */
		public $main;

		/**
		 * API class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Api\Api
		 */
		public $api;

		/**
		 * VueSettings class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Utils\VueSettings
		 */
		public $vueSettings;

		/**
		 * Admin class instance.
		 *
		 * @since 4.0.3
		 *
		 * @var Admin\Admin
		 */
		public $admin;

		/**
		 * The main FourNotFour instance.
		 *
		 * @since 4.0.3
		 *
		 * @return FourNotFour The 404 to 301 instance.
		 */
		public static function instance() {
			if ( null === self::$instance || ! self::$instance instanceof self ) {
				self::$instance = new self();

				self::$instance->init();
			}

			return self::$instance;
		}

		/**
		 * Initializes 404 to 301.
		 *
		 * @since 4.0.3
		 *
		 * @return void
		 */
		private function init() {
			$this->constants();
			$this->includes();
			$this->preLoad();
			$this->load();
		}

		/**
		 * Sets up the plugin constants.
		 * All the path/URL related constants are defined in the main plugin file.
		 *
		 * @since 4.0.3
		 *
		 * @return void
		 */
		private function constants() {
			$defaultHeaders = [
				'name'    => '404 to 301',
				'version' => 'Version'
			];

			$pluginData = get_file_data( AIOSEO_404_TO_301_FILE, $defaultHeaders );

			$constants = [
				'AIOSEO_404_TO_301_PLUGIN_BASENAME'  => plugin_basename( AIOSEO_404_TO_301_FILE ),
				'AIOSEO_404_TO_301_PLUGIN_NAME'      => '404 to 301',
				'AIOSEO_404_TO_301_PLUGIN_URL'       => plugin_dir_url( AIOSEO_404_TO_301_FILE ),
				'AIOSEO_404_TO_301_VERSION'          => $pluginData['version'],
				'AIOSEO_404_TO_301_MARKETING_URL'    => 'https://aioseo.com/',
				'AIOSEO_404_TO_301_MARKETING_DOMAIN' => 'aioseo.com'
			];

			foreach ( $constants as $constant => $value ) {
				if ( ! defined( $constant ) ) {
					define( $constant, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound
				}
			}

			$this->version = AIOSEO_404_TO_301_VERSION;
		}

		/**
		 * Loads the required dependencies.
		 *
		 * @since 4.0.3
		 *
		 * @return void
		 */
		private function includes() {
			$dependencies = [
				'/vendor/autoload.php' => true,
				// Required below rather than here - see the note under the loop.
				'/vendor/woocommerce/action-scheduler/action-scheduler.php' => false
			];

			foreach ( $dependencies as $path => $shouldRequire ) {
				if ( ! file_exists( AIOSEO_404_TO_301_DIR . $path ) ) {
					// Something is not right.
					status_header( 500 );
					wp_die( esc_html__( 'Plugin is missing required dependencies. Please contact support for more information.', '404-to-301' ) );
				}

				if ( $shouldRequire ) {
					require_once AIOSEO_404_TO_301_DIR . $path;
				}
			}

			// Only now that the autoloader has run, because isCronOnly() lives in a class it provides.
			// Skipping the require keeps Action Scheduler out of the process entirely: it never
			// registers its hooks, builds a store or creates its tables.
			if ( ! Utils\ActionScheduler::isCronOnly() ) {
				require_once AIOSEO_404_TO_301_DIR . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
			}

			$this->loadVersion();
		}

		/**
		 * Loads the version of the plugin we are currently using.
		 *
		 * @since 4.0.3
		 *
		 * @return void
		 */
		private function loadVersion() {
			if (
				! class_exists( '\Dotenv\Dotenv' ) ||
				! file_exists( AIOSEO_404_TO_301_DIR . '/build/.env' )
			) {
				return;
			}

			$dotenv = \Dotenv\Dotenv::createUnsafeImmutable( AIOSEO_404_TO_301_DIR, '/build/.env' );
			$dotenv->load();

			$devPort = strtolower( getenv( 'VITE_AIOSEO_404_TO_301_DEV_PORT' ) );
			if ( ! empty( $devPort ) ) {
				$this->isDev = true;

				// Fix SSL certificate invalid in our local environments.
				add_filter( 'https_ssl_verify', '__return_false' );
			}
		}

		/**
		 * Runs before we load the plugin.
		 *
		 * @since 4.0.3
		 *
		 * @return void
		 */
		private function preLoad() {
			$this->core            = new Core\Core();
			$this->helpers         = new Utils\Helpers();
			$this->dbSchema        = new Db\Schema();
			$this->internalOptions = new Options\InternalOptions();
			$this->preUpdates      = new Main\PreUpdates();
		}

		/**
		 * Loads our classes.
		 *
		 * @since 4.0.3
		 *
		 * @return void
		 */
		public function load() {
			$this->options         = new Options\Options();
			$this->updates         = new Main\Updates();
			$this->actionScheduler = new Utils\ActionScheduler();
			$this->access          = new Utils\Access();
			$this->main            = new Main\Main();
			$this->api             = new Api\Api();

			$this->endpoints();
			$this->admin = new Admin\Admin();

			// Static registrar, and only meaningful under WP-CLI.
			if ( defined( 'WP_CLI' ) && \WP_CLI ) {
				Cli\Cli::register();
			}

			add_action( 'init', [ $this, 'loadInit' ], 999 );
		}

		/**
		 * Registers our REST endpoints.
		 *
		 * Each endpoint hooks its own `rest_api_init` from its constructor, so instantiating is all
		 * that's needed. They hold no per-request state, so a fresh instance per boot is fine.
		 *
		 * @since 4.0.3
		 *
		 * @return void
		 */
		private function endpoints() {
			$endpoints = [ 'Settings', 'Logs', 'Redirects', 'Import', 'Migration' ];

			foreach ( $endpoints as $endpoint ) {
				$class = __NAMESPACE__ . '\\Api\\' . $endpoint;

				if ( class_exists( $class ) ) {
					new $class();
				}
			}
		}

		/**
		 * Things that need to load after init.
		 *
		 * @since 4.0.3
		 *
		 * @return void
		 */
		public function loadInit() {
			$this->vueSettings = new Utils\VueSettings( '_aioseo_404_to_301_settings' );
		}
	}
}

namespace {
	// Exit if accessed directly.
	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * The function which returns the one 404 to 301 instance.
	 *
	 * @since 4.0.3
	 *
	 * @return AIOSEO\FourNotFour\FourNotFour The instance.
	 */
	function aioseo404To301() {
		return AIOSEO\FourNotFour\FourNotFour::instance();
	}
}