<?php
/**
 * Capability helpers for the plugin.
 *
 * Centralises the "who can manage the plugin?" decision so the answer
 * lives in one place. Every admin menu, REST endpoint and CLI command
 * defers to {@see Permission::hasAccess()} instead of calling
 * `current_user_can()` directly.
 *
 * @package AIOSEO\FourNotFour
 */

namespace AIOSEO\FourNotFour\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Permission
 *
 * @since   4.0.0
 * @package AIOSEO\FourNotFour\Utils
 */
class Permission {

	/**
	 * Default capability required to manage the plugin.
	 *
	 * `manage_options` is the WordPress capability granted to
	 * administrators only.
	 *
	 * @since 4.0.0
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Get the capability required to manage the plugin.
	 *
	 * Filterable so site owners can grant access to other roles (for
	 * example, an editor on a multi-author site).
	 *
	 * @since 4.0.0
	 *
	 * @return string
	 */
	public static function getCap(): string {
		/**
		 * Filter the capability required to manage the plugin.
		 *
		 * @since 4.0.0
		 *
		 * @param string $cap Default capability ('manage_options').
		 */
		$cap = apply_filters( '404_to_301_capability', self::CAPABILITY );

		return (string) $cap;
	}

	/**
	 * Determine whether the current user can manage the plugin.
	 *
	 * Filterable so the access check can be replaced entirely (for
	 * example, to add an IP allow list or a feature flag).
	 *
	 * @since 4.0.0
	 *
	 * @return bool True when the current user passes the access check.
	 */
	public static function hasAccess(): bool {
		/**
		 * Filter the plugin access check.
		 *
		 * @since 4.0.0
		 *
		 * @param bool $hasAccess Result of the default capability check.
		 */
		$hasAccess = apply_filters(
			'404_to_301_has_access',
			current_user_can( self::getCap() )
		);

		return (bool) $hasAccess;
	}
}