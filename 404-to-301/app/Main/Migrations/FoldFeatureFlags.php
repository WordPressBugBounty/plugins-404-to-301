<?php
namespace AIOSEO\FourNotFour\Main\Migrations;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciles the retired opt-in feature flags with the settings they used to gate.
 *
 * Logs Cleaner, Email Reports and Telegram Alerts each sat behind two switches: a feature flag and
 * their own setting. The flag is gone, so the setting is now the only gate — and a site that had the
 * feature switched off while its setting said "on" would suddenly start pruning logs or sending mail
 * on upgrade. That combination is reachable today by configuring a feature and then switching it off.
 *
 * Where the flag was off, the setting is forced off to match what the site was actually doing. Where
 * it was on, nothing is touched.
 *
 * The Redirects Importer and Logs Exporter flags need no reconciliation: they gated UI with no
 * behaviour of its own, and both are now always available.
 *
 * @since 4.0.3
 */
class FoldFeatureFlags implements Migration {
	/**
	 * The retired option group.
	 *
	 * @since 4.0.3
	 *
	 * @var string
	 */
	const FLAG_GROUP = 'features';

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	public function name() {
		return 'fold_feature_flags';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return string
	 */
	public function version() {
		return '4.0.3';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	public function up() {
		$flags = $this->storedFlags();

		// No flags on disk means a fresh install, or a site that has already been through this.
		if ( ! empty( $flags ) ) {
			$options = aioseo404To301()->options;
			$changes = [];

			if ( $this->wasOff( $flags, 'logsCleaner' ) && 'none' !== (string) $options->cleaner->method ) {
				$changes['cleaner'] = [ 'method' => 'none' ];
			}

			if ( $this->wasOff( $flags, 'emailReports' ) && $options->reports->enabled ) {
				$changes['reports'] = [ 'enabled' => false ];
			}

			if ( $this->wasOff( $flags, 'telegramAlerts' ) && $options->telegram->enabled ) {
				$changes['telegram'] = [ 'enabled' => false ];
			}

			if ( ! empty( $changes ) ) {
				$options->sanitizeAndSave( $changes );
			}

			$this->dropFlagGroup();
		}

		aioseo404To301()->internalOptions->sanitizeAndSave(
			[
				'internal' => [ 'featureFlagsFolded' => true ]
			]
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 4.0.3
	 *
	 * @return bool
	 */
	public function verify() {
		return (bool) aioseo404To301()->internalOptions->internal->featureFlagsFolded;
	}

	/**
	 * Remove the retired group from the stored option.
	 *
	 * Reconciliation is the only thing that reads it, so once that's done the group is dead weight in
	 * every upgraded site's options row — and leaving it there means a future group of the same name
	 * would inherit stale values.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	private function dropFlagGroup() {
		$stored = get_option( 'aioseo_404_to_301_options', '' );

		if ( is_string( $stored ) ) {
			$stored = json_decode( $stored, true );
		}

		if ( ! is_array( $stored ) || ! array_key_exists( self::FLAG_GROUP, $stored ) ) {
			return;
		}

		unset( $stored[ self::FLAG_GROUP ] );

		update_option( 'aioseo_404_to_301_options', wp_json_encode( $stored ) );

		// The options layer caches the row it read at boot, so it has to be told the group is gone.
		aioseo404To301()->core->optionsCache->resetDb();
	}

	/**
	 * Read the retired flags straight off the stored option.
	 *
	 * The group is gone from the defaults, so the options layer no longer surfaces it — but an
	 * upgrading site still has the values in its row, which is exactly what has to be reconciled.
	 *
	 * @since 4.0.3
	 *
	 * @return array Flag name => stored leaf, or empty when the group isn't present.
	 */
	private function storedFlags() {
		$stored = get_option( 'aioseo_404_to_301_options', '' );

		// The options row holds a JSON string, not a serialized array — reading it as an array is how
		// this reconciliation would quietly do nothing at all.
		if ( is_string( $stored ) ) {
			$stored = json_decode( $stored, true );
		}

		if ( ! is_array( $stored ) || empty( $stored[ self::FLAG_GROUP ] ) || ! is_array( $stored[ self::FLAG_GROUP ] ) ) {
			return [];
		}

		return $stored[ self::FLAG_GROUP ];
	}

	/**
	 * Whether a flag was explicitly switched off.
	 *
	 * A flag that isn't in the stored row was never touched, so it can't be the "configured, then
	 * switched off" case this reconciles — leave those settings alone.
	 *
	 * @since 4.0.3
	 *
	 * @param  array  $flags Stored flag group.
	 * @param  string $name  Flag name.
	 * @return bool          True when the flag is present and false.
	 */
	private function wasOff( array $flags, $name ) {
		if ( ! array_key_exists( $name, $flags ) ) {
			return false;
		}

		$leaf = $flags[ $name ];

		// The options layer stores each leaf as [ 'type' => ..., 'default' => ..., 'value' => ... ].
		$value = is_array( $leaf ) && array_key_exists( 'value', $leaf ) ? $leaf['value'] : $leaf;

		return ! (bool) $value;
	}
}