<?php
/**
 * Download endpoint.
 *
 * Wires an `admin-post.php` handler that streams the CSV. We use
 * admin-post rather than a REST route because the WP REST stack
 * insists on JSON-encoding the response body, and forcing it to emit
 * raw CSV requires either an `rest_pre_serve_request` short-circuit
 * or a custom output buffer dance — admin-post is the boring, well-trodden
 * path for "download a file from a logged-in admin click".
 *
 * @package AIOSEO\FourNotFour\Exporter
 */

namespace AIOSEO\FourNotFour\Main\Exporter;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use AIOSEO\FourNotFour\Utils\Permission;

/**
 * Class Download
 *
 * @since 4.0.3
 */
final class Download {

	/**
	 * `admin-post.php` action name, and the nonce action paired with it.
	 *
	 * Unchanged from the standalone Logs Exporter addon so a bookmarked
	 * or in-flight download URL keeps resolving after the upgrade.
	 *
	 * @since 4.0.3
	 */
	const ACTION = 'd404_logs_export';

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
		add_action( 'admin_post_' . self::ACTION, [ $this, 'download' ] );
	}

	/**
	 * Stream the CSV download.
	 *
	 * Validates the nonce + capability, emits the file headers, hands
	 * off to {@see Exporter::stream()} for the row loop, and exits
	 * before WordPress can write anything else to the response body.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function download(): void {
		// Capability check first — a 403 for an unauthenticated request
		// is cheaper than parsing the nonce and gives the right error
		// surface to admin-post's default handlers.
		if ( ! current_user_can( $this->requiredCap() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to export logs.', '404-to-301' ),
				esc_html__( 'Forbidden', '404-to-301' ),
				[ 'response' => 403 ]
			);
		}

		// Nonce rides in on the localised `d404.exportNonce` value; both
		// the GET and POST forms include it as `_wpnonce`.
		check_admin_referer( self::ACTION );

		$filters = $this->collectFilters();

		// Suggest a date-stamped filename so repeated exports don't
		// clobber each other in the user's downloads folder.
		$filename = sprintf( '404-to-301-logs-%s.csv', gmdate( 'Y-m-d-His' ) );

		// Discard any buffered output (eg. UTF-8 BOMs from translation
		// files or stray whitespace from a misbehaving plugin) before
		// we start writing the CSV.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Keep streaming even if the user closes the tab, so the partial
		// CSV they already received isn't truncated when the script
		// would otherwise be killed.
		ignore_user_abort( true );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		( new Exporter() )->stream( $filters );

		exit;
	}

	/**
	 * Resolve the capability needed to trigger an export.
	 *
	 * Defers to the parent plugin's `Permission::getCap()` when
	 * present so the export honours any custom capability filter the
	 * site sets there. Falls back to `manage_options` so the handler
	 * still gates correctly if the parent isn't loaded.
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	private function requiredCap(): string {
		if ( class_exists( Permission::class ) ) {
			return Permission::getCap();
		}

		return 'manage_options';
	}

	/**
	 * Pull the optional filter values off the request.
	 *
	 * Each value goes through a tight type-cast / sanitiser before it
	 * reaches the exporter — the model layer will re-validate, but
	 * normalising at the boundary keeps the query args predictable.
	 *
	 * @since 4.0.3
	 *
	 * @return array
	 */
	private function collectFilters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, HM.Security.NonceVerification.Recommended -- Verified in download().
		$status = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['status'] ) ) : '';

		$filters = [
			'search'    => isset( $_REQUEST['search'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['search'] ) ) : '',
			'orderby'   => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['orderby'] ) ) : 'updated_at',
			'order'     => isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_REQUEST['order'] ) ) ) : 'DESC',
			'date_from' => isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['date_from'] ) ) : '',
			'date_to'   => isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['date_to'] ) ) : '',
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, HM.Security.NonceVerification.Recommended

		if ( '' !== $status && is_numeric( $status ) ) {
			$filters['status'] = (int) $status;
		}

		if ( ! in_array( $filters['order'], [ 'ASC', 'DESC' ], true ) ) {
			$filters['order'] = 'DESC';
		}

		return $filters;
	}
}