<?php
/**
 * Auto-redirect on slug change.
 *
 * When the "Monitor slug changes" setting is on, a published post whose
 * permalink changes (typically because its slug was edited) leaves its
 * old URL dangling — every existing link, bookmark and search-engine
 * result now points at a 404. This service watches post updates and
 * creates a 301 from the old URL to the new one so those links keep
 * working.
 *
 * Gated to public, published post types: drafts have no live URL to
 * preserve, and non-public types aren't reachable by visitors.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use WP_Post;
use AIOSEO\FourNotFour\Models\Redirect;

/**
 * Class SlugMonitor
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour
 */
class SlugMonitor {

	/**
	 * Marks the rows this class creates, so an admin's own redirects are never overwritten.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const NOTES = 'slug-monitor';

	/**
	 * Register hooks.
	 *
	 * `post_updated` fires after the row is written and hands us both the
	 * pre- and post-update objects, which is exactly what we need to
	 * compare the old and new permalinks. It fires for admin edits, REST
	 * (block editor) saves and WP-CLI alike, so the feature isn't tied to
	 * the classic edit screen.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'post_updated', [ $this, 'maybeCreateRedirect' ], 10, 3 );
	}

	/**
	 * Create a redirect from the old URL to the new one when a published
	 * post's permalink changes.
	 *
	 * @since   4.0.0
	 * @version 4.0.3 Params are no longer typed; a non-WP_Post is now skipped.
	 *
	 * @param int          $postId     Updated post id.
	 * @param WP_Post|null $postAfter  Post object after the update.
	 * @param WP_Post|null $postBefore Post object before the update.
	 *
	 * @return void
	 */
	public function maybeCreateRedirect( $postId, $postAfter = null, $postBefore = null ): void {
		unset( $postId );

		/*
		 * Core hands `get_post()`'s return value straight to this hook, so $postAfter is
		 * null whenever that lookup fails — and any plugin may fire `post_updated` itself
		 * with whatever it likes. Typed params turned that into a fatal TypeError blamed
		 * on us, reproducibly when trashing a post.
		 */
		if ( ! $postAfter instanceof WP_Post || ! $postBefore instanceof WP_Post ) {
			return;
		}

		// Feature gate.
		if ( ! aioseo404To301()->options->general->monitorPostSlug ) {
			return;
		}

		// Ignore autosaves and revisions — neither is a real slug change.
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $postAfter ) ) {
			return;
		}

		// Only posts that were live before and remain live after: a draft
		// has no public URL to preserve, and redirecting to an unpublished
		// target would send visitors to a 404 of a different kind.
		if ( 'publish' !== $postBefore->post_status || 'publish' !== $postAfter->post_status ) {
			return;
		}

		// Public, redirectable post types only.
		$type = get_post_type_object( $postAfter->post_type );
		if ( null === $type || empty( $type->public ) ) {
			return;
		}

		$oldUrl = get_permalink( $postBefore );
		$newUrl = get_permalink( $postAfter );

		// Nothing to do when the permalink is unchanged (eg. a plain
		// `?p=123` permalink structure, or an edit that didn't touch the
		// slug) or either lookup failed.
		if ( ! is_string( $oldUrl ) || ! is_string( $newUrl ) || '' === $oldUrl || $oldUrl === $newUrl ) {
			return;
		}

		/**
		 * Filter whether to auto-create a redirect for this slug change.
		 *
		 * Return false to skip a specific post — eg. to exclude a custom
		 * post type or a bulk slug-normalisation run.
		 *
		 * @since 4.0.0
		 *
		 * @param bool    $create      Whether to create the redirect.
		 * @param WP_Post $postAfter  Post after the update.
		 * @param WP_Post $postBefore Post before the update.
		 */
		if ( ! apply_filters( '404_to_301_monitor_slug_create', true, $postAfter, $postBefore ) ) {
			return;
		}

		$this->upsertRedirect( $oldUrl, $newUrl );
	}

	/**
	 * Create — or refresh — the redirect for an old → new URL pair.
	 *
	 * Stores the source as the old URL's path so it matches the request
	 * URI regardless of host. When a redirect already exists for the old
	 * URL (eg. the post was renamed twice) its target is updated rather
	 * than inserting a duplicate, which the unique `source_hash` index
	 * would reject anyway.
	 *
	 * @since 4.0.0
	 *
	 * @param string $oldUrl Previous permalink (full URL).
	 * @param string $newUrl New permalink (full URL).
	 *
	 * @return void
	 */
	private function upsertRedirect( string $oldUrl, string $newUrl ): void {
		$source = (string) wp_parse_url( $oldUrl, PHP_URL_PATH );
		if ( '' === $source ) {
			return;
		}

		$existing = Redirect::findExact( $source );

		if ( $existing && isset( $existing->id ) ) {
			// Don't clobber a redirect an admin built by hand - only refresh rows we auto-created.
			if ( self::NOTES !== (string) $existing->notes ) {
				return;
			}

			$redirect             = new Redirect( (int) $existing->id );
			$redirect->target_url = $newUrl;
			$redirect->save();

			return;
		}

		$redirect                = new Redirect();
		$redirect->source        = $source;
		$redirect->target_type   = 'link';
		$redirect->target_url    = $newUrl;
		$redirect->redirect_type = 301;
		$redirect->match_type    = 'exact';
		$redirect->is_active     = 1;
		$redirect->notes         = self::NOTES;
		$redirect->save();

		// Flatten any chain we'd otherwise create. If the post was renamed A -> B before and is now
		// B -> C, the A -> B row should be repointed to C so visitors (and search engines) follow a
		// single 301 instead of a hop chain. Only our own auto-created rows are touched.
		$this->repointChains( $oldUrl, $newUrl );
	}

	/**
	 * Repoint previously auto-created redirects that targeted the old URL
	 * so they point at the new one, collapsing 301 chains into single
	 * hops.
	 *
	 * @since 4.0.0
	 *
	 * @param string $oldUrl Previous permalink (now itself redirected).
	 * @param string $newUrl New permalink.
	 *
	 * @return void
	 */
	private function repointChains( string $oldUrl, string $newUrl ): void {
		global $wpdb;

		$table = $wpdb->prefix . '404_to_301_redirects';

		// Read-only lookup of our own auto-created rows that still target the now-redirected old URL.
		// There's no model "find by target" helper, hence the direct prepared SELECT; the writes below
		// go back through the model so its cache flush and audit event fire.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal, values are prepared.
				"SELECT id FROM {$table} WHERE target_url = %s AND notes = %s",
				$oldUrl,
				self::NOTES
			)
		);

		foreach ( (array) $ids as $id ) {
			$redirect             = new Redirect( (int) $id );
			$redirect->target_url = $newUrl;
			$redirect->save();
		}
	}
}