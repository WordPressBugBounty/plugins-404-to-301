<?php
namespace AIOSEO\FourNotFour\Options;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Traits;

/**
 * Handles the main options.
 *
 * @since 4.0.3
 */
class Options {
	use Traits\Options;

	/**
	 * All the default options.
	 *
	 * NOTE: defaults are static. Anything depending on site state - the fallback redirect target
	 * (`home_url()`, resolved by {@see \AIOSEO\FourNotFour\Main\Front\Actions\Redirect::resolveGlobalTarget()})
	 * and the report recipients (`admin_email`) - resolves at the point of use instead, so a site that
	 * later changes its URL or admin address isn't stuck with a stale value baked into the options.
	 *
	 * @since 4.0.3
	 *
	 * @var array
	 */
	protected $defaults = [
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		'general'       => [
			// off | light | strict. `light` only stops WordPress guessing the closest post;
			// `strict` bypasses redirect_canonical() entirely.
			'disableGuessing' => [ 'type' => 'string', 'default' => 'light' ],
			'excludePaths'    => [ 'type' => 'array', 'default' => [] ],
			'monitorPostSlug' => [ 'type' => 'boolean', 'default' => false ],
			'maskIp'          => [ 'type' => 'boolean', 'default' => false ],
			'trackAdmin404'   => [ 'type' => 'boolean', 'default' => false ]
		],
		'redirects'     => [
			'enabled'    => [ 'type' => 'boolean', 'default' => true ],
			// NOTE: named statusCode, not `type`. The options trait stores each leaf as
			// [ 'type' => ..., 'default' => ..., 'value' => ... ], so a leaf literally called `type`
			// is indistinguishable from that metadata and the whole group gets dropped on save.
			// `default` and `value` are reserved for the same reason.
			'statusCode' => [ 'type' => 'string', 'default' => '301' ],
			'target'     => [ 'type' => 'string', 'default' => 'link' ],
			// Empty means "fall back to home_url()" - see the note on $defaults.
			'link'       => [ 'type' => 'string', 'default' => '' ],
			'pageId'     => [ 'type' => 'number', 'default' => 0 ]
		],
		'logs'          => [
			'enabled'        => [ 'type' => 'boolean', 'default' => true ],
			'skipBots'       => [ 'type' => 'boolean', 'default' => true ],
			'skipDuplicates' => [ 'type' => 'boolean', 'default' => false ]
		],
		'notifications' => [
			'email' => [
				'enabled'    => [ 'type' => 'boolean', 'default' => false ],
				// Empty means "send to nobody" - clearing the field is how you silence 404 notifications
				// without flipping the toggle. Email Reports is the one that falls back to admin_email.
				'recipients' => [ 'type' => 'array', 'default' => [] ],
				'threshold'  => [ 'type' => 'number', 'default' => 1 ]
			]
		],
		'cleaner'       => [
			// none | age | count | periodic.
			'method'           => [ 'type' => 'string', 'default' => 'none' ],
			'ageDays'          => [ 'type' => 'number', 'default' => 30 ],
			'countThreshold'   => [ 'type' => 'number', 'default' => 10000 ],
			// all | percent | count.
			'countStrategy'    => [ 'type' => 'string', 'default' => 'percent' ],
			'trimPercent'      => [ 'type' => 'number', 'default' => 25 ],
			'trimCount'        => [ 'type' => 'number', 'default' => 1000 ],
			// hourly | twicedaily | daily | weekly.
			'periodicSchedule' => [ 'type' => 'string', 'default' => 'daily' ],
			'keepRedirects'    => [ 'type' => 'boolean', 'default' => true ]
		],
		'reports'       => [
			'enabled'    => [ 'type' => 'boolean', 'default' => false ],
			// daily | weekly | monthly.
			'frequency'  => [ 'type' => 'string', 'default' => 'weekly' ],
			'recipients' => [ 'type' => 'array', 'default' => [] ],
			'attachCsv'  => [ 'type' => 'boolean', 'default' => true ]
		],
		/*
		 * Telegram Alerts is deprecated. Retained so sites with a live connection keep working; it is
		 * hidden everywhere else and cannot be switched back on once disabled.
		 */
		'telegram'      => [
			'enabled'    => [ 'type' => 'boolean', 'default' => false ],
			'botToken'   => [ 'type' => 'string', 'default' => '' ],
			'chatId'     => [ 'type' => 'string', 'default' => '' ],
			'on404'      => [ 'type' => 'boolean', 'default' => true ],
			'onRedirect' => [ 'type' => 'boolean', 'default' => false ],
			'threshold'  => [ 'type' => 'number', 'default' => 1 ]
		]
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	];

	/**
	 * Class constructor.
	 *
	 * @since 4.0.3
	 *
	 * @param string $optionsName The options name.
	 */
	public function __construct( $optionsName = 'aioseo_404_to_301_options' ) {
		$this->optionsName = $optionsName;

		$this->init();

		add_action( 'shutdown', [ $this, 'save' ] );
	}

	/**
	 * Initializes the options.
	 *
	 * @since 4.0.3
	 *
	 * @return void
	 */
	protected function init() {
		$options = $this->getFourNotFourDbOptions();

		aioseo404To301()->core->optionsCache->setOptions( $this->optionsName, apply_filters( 'aioseo_404_to_301_get_options', $options ) );
	}

	/**
	 * Returns the options from the DB, merged over the defaults.
	 *
	 * @since 4.0.3
	 *
	 * @return array An array of options.
	 */
	public function getFourNotFourDbOptions() {
		$dbOptions = $this->getDbOptions( $this->optionsName );

		$this->defaultsMerged = array_replace_recursive( $this->defaults, $this->defaultsMerged );

		return array_replace_recursive(
			$this->defaultsMerged,
			$this->addValueToValuesArray( $this->defaultsMerged, $dbOptions )
		);
	}

	/**
	 * Sanitizes, then saves the options to the database.
	 *
	 * @since 4.0.3
	 *
	 * @param  array $newOptions An array of options to sanitize, then save.
	 * @return void
	 */
	public function sanitizeAndSave( $newOptions ) {
		$this->init();

		if ( ! is_array( $newOptions ) ) {
			return;
		}

		// The helper is used instead of array_replace_recursive so a populated array can be replaced
		// with an empty one when a setting is cleared out.
		$cachedOptions = aioseo404To301()->core->optionsCache->getOptions( $this->optionsName );
		$dbOptions     = aioseo404To301()->helpers->arrayReplaceRecursive(
			$cachedOptions,
			$this->addValueToValuesArray( $cachedOptions, $newOptions, [], true )
		);

		// Intersect too, to drop individual keys that were unset - arrayReplaceRecursive leaves keys
		// absent from the replacement array untouched in the target.
		$dbOptions = aioseo404To301()->helpers->arrayIntersectRecursive(
			$dbOptions,
			$this->addValueToValuesArray( $cachedOptions, $newOptions, [], true ),
			'value'
		);

		aioseo404To301()->core->optionsCache->setOptions( $this->optionsName, $dbOptions );

		$this->save( true );
	}
}