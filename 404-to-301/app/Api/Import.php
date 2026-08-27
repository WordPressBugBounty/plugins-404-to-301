<?php
/**
 * Import REST endpoints.
 *
 * Five routes drive the UI's two-phase, chunked import flow:
 *
 *   GET    /imports/sources              — list available sources +
 *                                          their row counts.
 *
 *   POST   /imports/upload               — stash an uploaded CSV under
 *                                          a token so subsequent
 *                                          preview/run calls can find
 *                                          it without re-uploading.
 *
 *   POST   /imports/preview              — dry-run the source (or the
 *                                          CSV referenced by a token)
 *                                          and return counts + a
 *                                          handful of sample rows.
 *
 *   POST   /imports/run                  — process one offset/limit
 *                                          batch. Client loops until
 *                                          `processed < limit`.
 *
 *   POST   /imports/cleanup              — drop a CSV token's stashed
 *                                          tmp file (best-effort tidy
 *                                          on close / cancel).
 *
 * All routes live under the shared `/404-to-301/v1` namespace so the
 * existing `wpApiSettings` nonce works without any client-side plumbing.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use AIOSEO\FourNotFour\Main\Importer\Importer;
use AIOSEO\FourNotFour\Main\Importer\Sources\CsvSource;
use AIOSEO\FourNotFour\Main\Importer\Sources\Registry;
use AIOSEO\FourNotFour\Main\Importer\Sources\Source;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Import
 *
 * @since   4.0.3
 * @package AIOSEO\FourNotFour\Api
 */
class Import extends Endpoint {

	/**
	 * Transient key prefix for stashed CSV uploads.
	 *
	 * One transient per upload; the value is the absolute path of the
	 * tmp file. `cleanup()` deletes both the transient and the file.
	 *
	 * @since 4.0.3
	 */
	const CSV_TOKEN_PREFIX = 'd404_csv_';

	/**
	 * How long a stashed CSV stays on disk before it gets garbage
	 * collected.
	 *
	 * The preview→run flow usually finishes in a few minutes; an hour
	 * is comfortable head-room for slow networks or a user who walks
	 * away mid-import without explicitly cancelling.
	 *
	 * @since 4.0.3
	 */
	const CSV_TOKEN_TTL = HOUR_IN_SECONDS;

	/**
	 * Hard cap on rows per `run` batch — protects against a misbehaving
	 * client that asks for a million-row slice in one shot.
	 *
	 * @since 4.0.3
	 */
	const MAX_BATCH = 500;

	/**
	 * Singleton instance.
	 *
	 * @since 4.0.3
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the shared instance.
	 *
	 * @since 4.0.3
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the WordPress hooks owned by this class.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	/**
	 * Declare the REST routes.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/imports/sources',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'sources' ],
				'permission_callback' => [ $this, 'requireAccess' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports/upload',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'upload' ],
				'permission_callback' => [ $this, 'requireAccess' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports/preview',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'preview' ],
				'permission_callback' => [ $this, 'requireAccess' ],
				'args'                => [
					'source_id' => [
						'type'     => 'string',
						'required' => true,
					],
					'csv_token' => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports/run',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'run' ],
				'permission_callback' => [ $this, 'requireAccess' ],
				'args'                => [
					'source_id'       => [
						'type'     => 'string',
						'required' => true,
					],
					'csv_token'       => [
						'type'    => 'string',
						'default' => '',
					],
					'offset'          => [
						'type'    => 'integer',
						'default' => 0,
					],
					'limit'           => [
						'type'    => 'integer',
						'default' => 100,
					],
					'update_existing' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports/cleanup',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'cleanup' ],
				'permission_callback' => [ $this, 'requireAccess' ],
				'args'                => [
					'csv_token' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * GET /imports/sources — list of available sources for the picker.
	 *
	 * Always includes CSV; plugin-backed sources are only included when
	 * their underlying storage is actually present on the site.
	 *
	 * @since 4.0.3
	 *
	 * @return WP_REST_Response
	 */
	public function sources(): WP_REST_Response {
		$items = [];

		foreach ( Registry::available() as $source ) {
			// CSV count is meaningless before the upload exists, so we
			// report it as 0 and the UI suppresses the "found N rows"
			// hint for that source.
			$count = $source instanceof CsvSource ? 0 : $source->count();

			$items[] = [
				'id'    => $source->id(),
				'label' => $source->label(),
				'count' => (int) $count,
			];
		}

		return new WP_REST_Response( [ 'items' => $items ], 200 );
	}

	/**
	 * POST /imports/upload — stash an uploaded CSV under a token.
	 *
	 * The handler validates the upload, moves the tmp file into a
	 * private location under the WP uploads dir, and writes a
	 * transient mapping `csv_token → file path`. The token is returned
	 * to the client and used in subsequent /preview and /run calls.
	 *
	 * @since 4.0.3
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error(
				'rest_import_missing_file',
				__( 'No CSV file was uploaded.', '404-to-301' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error(
				'rest_import_upload_error',
				$this->uploadErrorMessage( (int) $file['error'] ),
				[ 'status' => 400 ]
			);
		}

		$name = (string) ( $file['name'] ?? '' );
		if ( '' !== $name && ! preg_match( '/\.(csv|txt)$/i', $name ) ) {
			return new WP_Error(
				'rest_import_bad_extension',
				__( 'Only .csv files are supported.', '404-to-301' ),
				[ 'status' => 400 ]
			);
		}

		$stash = $this->stashDirectory();
		if ( null === $stash ) {
			return new WP_Error(
				'rest_import_stash_failed',
				__( 'Could not create a temporary upload area.', '404-to-301' ),
				[ 'status' => 500 ]
			);
		}

		// Route wp_handle_upload() at our private stash directory and
		// rename to a token-style filename so two concurrent imports
		// can't collide. wp_handle_upload() also wraps the underlying
		// is_uploaded_file() / move_uploaded_file() pair in the
		// WordPress-sanctioned way, which is what Plugin Check expects
		// over direct move_uploaded_file() use.
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$destBasename = 'import-' . wp_generate_password( 8, false ) . '.csv';

		$stashFilter = static function ( $dirs ) use ( $stash ) {
			$dirs['path']    = untrailingslashit( $stash );
			$dirs['url']     = '';
			$dirs['subdir']  = '';
			$dirs['basedir'] = untrailingslashit( $stash );
			$dirs['baseurl'] = '';

			return $dirs;
		};
		add_filter( 'upload_dir', $stashFilter );

		$uploaded = wp_handle_upload(
			$file,
			[
				'test_form'                => false,
				'mimes'                    => [
					'csv' => 'text/csv',
					'txt' => 'text/plain',
				],
				'unique_filename_callback' => static function () use ( $destBasename ) {
					return $destBasename;
				},
			]
		);

		remove_filter( 'upload_dir', $stashFilter );

		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error(
				'rest_import_stash_failed',
				__( 'Could not stash the uploaded file.', '404-to-301' ),
				[ 'status' => 500 ]
			);
		}

		$dest = $uploaded['file'];

		$token = wp_generate_password( 24, false, false );
		set_transient( self::CSV_TOKEN_PREFIX . $token, $dest, self::CSV_TOKEN_TTL );

		return new WP_REST_Response(
			[
				'csv_token' => $token,
				'filename'  => $name,
				'count'     => ( new CsvSource() )->bind( $dest )->count(),
			],
			201
		);
	}

	/**
	 * POST /imports/preview — dry-run summary for the modal.
	 *
	 * @since 4.0.3
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		$source = $this->resolveSource( $request );
		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$summary = ( new Importer() )->preview( $source );

		return new WP_REST_Response( $summary, 200 );
	}

	/**
	 * POST /imports/run — process one offset/limit batch.
	 *
	 * @since 4.0.3
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function run( WP_REST_Request $request ) {
		$source = $this->resolveSource( $request );
		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$limit  = max( 1, min( self::MAX_BATCH, (int) $request->get_param( 'limit' ) ) );

		$summary = ( new Importer() )->runBatch(
			$source,
			$offset,
			$limit,
			(bool) $request->get_param( 'update_existing' )
		);

		// Tell the client where it is in the source — saves it from
		// having to track offset arithmetic locally and lets us hint
		// the next batch from a single place.
		$summary['next_offset'] = $offset + $summary['processed'];
		$summary['done']        = $summary['processed'] < $limit;

		return new WP_REST_Response( $summary, 200 );
	}

	/**
	 * POST /imports/cleanup — drop a CSV token + its tmp file.
	 *
	 * Idempotent: an unknown token is a no-op success, since the most
	 * common reason for missing it is "the client already cleaned up".
	 *
	 * @since 4.0.3
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function cleanup( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'csv_token' );

		if ( '' !== $token ) {
			$path = get_transient( self::CSV_TOKEN_PREFIX . $token );
			if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			delete_transient( self::CSV_TOKEN_PREFIX . $token );
		}

		return new WP_REST_Response( [ 'cleaned' => true ], 200 );
	}

	/**
	 * Resolve the requested source — either a registered plugin source
	 * or the CSV source bound to a stashed upload.
	 *
	 * @since 4.0.3
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return Source|WP_Error
	 */
	private function resolveSource( WP_REST_Request $request ) {
		$sourceId = (string) $request->get_param( 'source_id' );

		$source = Registry::get( $sourceId );
		if ( ! $source instanceof Source ) {
			return new WP_Error(
				'rest_import_unknown_source',
				__( 'Unknown import source.', '404-to-301' ),
				[ 'status' => 400 ]
			);
		}

		if ( $source instanceof CsvSource ) {
			$token = (string) $request->get_param( 'csv_token' );
			$path  = '' === $token ? '' : (string) get_transient( self::CSV_TOKEN_PREFIX . $token );

			if ( '' === $path || ! is_readable( $path ) ) {
				return new WP_Error(
					'rest_import_missing_csv',
					__( 'CSV upload not found. Re-upload the file.', '404-to-301' ),
					[ 'status' => 400 ]
				);
			}

			$source->bind( $path );
		} elseif ( ! $source->isAvailable() ) {
			// Plugin sources are listed off `is_available()`. Calling
			// the route with an unavailable source is a client bug —
			// surface the 400 so it gets caught in QA rather than
			// silently returning zero rows.
			return new WP_Error(
				'rest_import_source_unavailable',
				__( "This source isn't available on the site.", '404-to-301' ),
				[ 'status' => 400 ]
			);
		}

		return $source;
	}

	/**
	 * Resolve a private stash directory under uploads/.
	 *
	 * Created lazily on first upload, with a `.htaccess` deny rule so
	 * the stashed file isn't directly fetchable over the web — defence
	 * in depth, the file name is already unguessable.
	 *
	 * @since 4.0.3
	 *
	 * @return string|null Absolute path, or null when uploads are
	 *                     misconfigured (returns the WP error).
	 */
	private function stashDirectory(): ?string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . '404-to-301-imports';

		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}

			// `Deny from all` blocks Apache; the empty index.php
			// blocks dir listing on nginx/lighttpd; together they
			// cover the common shared-host setups.
			//
			// `WP_Filesystem` would be the canonical alternative but
			// bringing it up requires credentials prompting on FTP-mode
			// installs — we're inside an authenticated admin REST call,
			// the directory is under our own uploads path, and the
			// payload is two tiny string literals. Direct writes here
			// are deliberate.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Translate a PHP upload error code into an admin-facing message.
	 *
	 * @since 4.0.3
	 *
	 * @param int $code One of the `UPLOAD_ERR_*` constants.
	 *
	 * @return string
	 */
	private function uploadErrorMessage( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The file is larger than the server upload limit.', '404-to-301' );

			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Try again.', '404-to-301' );

			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was received.', '404-to-301' );

			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Server could not write the uploaded file to disk.', '404-to-301' );

			default:
				return __( 'Upload failed.', '404-to-301' );
		}
	}
}