<?php
namespace AIOSEO\FourNotFour\Options;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Traits;

/**
 * Class that holds all internal options for 404 to 301.
 *
 * @since 1.0.0
 */
class InternalOptions {
	use Traits\Options;

	/**
	 * Holds a list of all the possible deprecated options.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $allDeprecatedOptions = [];

	/**
	 * All the default options.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $defaults = [
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		'internal' => [
			'firstActivated'     => [ 'type' => 'number', 'default' => 0 ],
			'lastActiveVersion'  => [ 'type' => 'string', 'default' => '0.0' ],
			'lastSchemaVersion'  => [ 'type' => 'string', 'default' => '0.0' ],
			'schedulerMode'      => [ 'type' => 'string', 'default' => '' ],
			// v3 -> v4 log migration state. `logsMigrated` gates the migration banner and the
			// React app's initial status request, so it stays cheap to read.
			'logsMigrated'       => [ 'type' => 'boolean', 'default' => false ],
			'migrationStarted'   => [ 'type' => 'boolean', 'default' => false ],
			'phase1Done'         => [ 'type' => 'boolean', 'default' => false ],
			'legacyTableDropped' => [ 'type' => 'boolean', 'default' => false ],
			// Set when a read against the legacy table fails. The migration stops rather than
			// treating an unreadable table as an empty one and dropping it.
			'migrationLastError' => [ 'type' => 'string', 'default' => '' ],
			// One-shot guard for the 4.0.3 addon adoption, plus the list of addon plugins it found
			// so the admin notice can offer to clean them up.
			'featuresMigrated'   => [ 'type' => 'boolean', 'default' => false ],
			'redundantAddons'    => [ 'type' => 'array', 'default' => [] ],
			// Bookkeeping written by the report and Telegram workers after a delivery attempt.
			'reportsLastSentAt'  => [ 'type' => 'string', 'default' => '' ],
			'reportsLastSentId'  => [ 'type' => 'number', 'default' => 0 ],
			'telegramLastSentAt' => [ 'type' => 'string', 'default' => '' ],
			'telegramLastError'  => [ 'type' => 'string', 'default' => '' ],
			// Set once the flat `404_to_301_settings` option has been folded into these two.
			'settingsMigrated'   => [ 'type' => 'boolean', 'default' => false ],
			// Same, for the v3 `i4t3_gnrl_options` blob on sites that skipped 4.0.x entirely.
			'v3SettingsMigrated' => [ 'type' => 'boolean', 'default' => false ],
			// Set once the retired opt-in feature flags have been reconciled with their own settings.
			'featureFlagsFolded' => [ 'type' => 'boolean', 'default' => false ]
		]
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	];

	/**
	 * Class constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $optionsName The options name.
	 */
	public function __construct( $optionsName = 'aioseo_404_to_301_options_internal' ) {
		$this->optionsName = is_network_admin() ? $optionsName . '_network' : $optionsName;

		$this->init();

		add_action( 'shutdown', [ $this, 'save' ] );
	}

	/**
	 * Initializes the options.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function init() {
		// Options from the DB.
		$dbOptions = $this->getDbOptions( $this->optionsName );

		// Refactor options.
		$this->defaultsMerged = array_replace_recursive( $this->defaults, $this->defaultsMerged );

		$options = array_replace_recursive(
			$this->defaultsMerged,
			$this->addValueToValuesArray( $this->defaultsMerged, $dbOptions )
		);

		aioseo404To301()->core->optionsCache->setOptions( $this->optionsName, apply_filters( 'aioseo_404_to_301_get_options_internal', $options ) );
	}

	/**
	 * Get all the deprecated options.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function getAllDeprecatedOptions() {
		return $this->allDeprecatedOptions;
	}

	/**
	 * Sanitizes, then saves the options to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param  array $options An array of options to sanitize, then save.
	 * @return void
	 */
	public function sanitizeAndSave( $options ) {
		if ( ! is_array( $options ) ) {
			return;
		}

		// Refactor options.
		$cachedOptions = aioseo404To301()->core->optionsCache->getOptions( $this->optionsName );
		$dbOptions     = array_replace_recursive(
			$cachedOptions,
			$this->addValueToValuesArray( $cachedOptions, $options, [], true )
		);

		aioseo404To301()->core->optionsCache->setOptions( $this->optionsName, $dbOptions );

		// Update values.
		$this->save( true );
	}
}