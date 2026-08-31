<?php
namespace AIOSEO\FourNotFour\Admin\Notices;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\FourNotFour\Utils\Plugin;
/**
 * Review plugin notice.
 *
 * @since 1.2.0
 */
class Review {
	/**
	 * Class constructor.
	 *
	 * The notice hook is registered here rather than by a caller. This class was written to be
	 * driven by an `Admin\Notifications` renderer, as it still is in the other plugins - this one
	 * has no notifications centre, so nothing ever called `maybeShowNotice()` and the notice never
	 * appeared. Self-registering matches how {@see RedundantAddons} does it.
	 *
	 * @since   1.2.0
	 * @version 4.0.4 Registers its own `admin_notices` hook.
	 */
	public function __construct() {
		add_action( 'wp_ajax_404-to-301-dismiss-review-plugin-cta', [ $this, 'dismissNotice' ] );
		add_action( 'admin_notices', [ $this, 'maybeShowNotice' ] );
	}

	/**
	 * Go through all the checks to see if we should show the notice.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function maybeShowNotice() {
		// Don't show to users that cannot interact with the plugin.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		if ( Plugin::isPluginScreen() ) {
			return;
		}

		$dismissed = get_user_meta( get_current_user_id(), '_aioseo_404_to_301_plugin_review_dismissed', true );
		if ( '3' === $dismissed || '4' === $dismissed ) {
			return;
		}

		if ( ! empty( $dismissed ) && $dismissed > time() ) {
			return;
		}

		// Show once plugin has been active for 2 weeks.
		if ( ! aioseo404To301()->internalOptions->internal->firstActivated ) {
			aioseo404To301()->internalOptions->internal->firstActivated = time();
		}

		$activated = aioseo404To301()->internalOptions->internal->firstActivated( time() );
		if ( $activated > strtotime( '-2 weeks' ) ) {
			return;
		}

		$this->showNotice();

		// Print the script to the footer.
		add_action( 'admin_footer', [ $this, 'printScript' ] );
	}

	/**
	 * Actually show the review plugin 2.0.
	 *
	 * NOTE: the class names use the `d404-` prefix the rest of the plugin's CSS uses, not the
	 * `404-to-301-` one this file shipped with. A CSS identifier cannot start with a digit, so
	 * `document.querySelector('.404-to-301-...')` and `Element.matches()` both throw a SyntaxError -
	 * which killed every one of the notice's own dismiss handlers.
	 *
	 * The notice is also single-step here: only `.step-3` exists. The step-switching branches that
	 * came with it referenced two more elements and two more classes that this markup never had.
	 *
	 * @since   1.2.0
	 * @version 4.0.4 Class names re-prefixed so they are valid CSS identifiers.
	 * @version 4.0.4 Dropped the step-switching branches; this notice has one step.
	 *
	 * @return void
	 */
	public function showNotice() {
		$string1 = sprintf(
			// Translators: 1 - The plugin name ("404 to 301").
			__( 'Hey, we noticed you have been using %1$s for some time - that’s awesome! Could you please do us a BIG favor and give it a 5-star rating on WordPress to help us spread the word and boost our motivation?', '404-to-301' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
			'<strong>' . esc_html( AIOSEO_404_TO_301_PLUGIN_NAME ) . '</strong>'
		);

		// Translators: 1 - The plugin name ("404 to 301").
		$string9  = __( 'Ok, you deserve it', '404-to-301' );
		$string10 = __( 'Nope, maybe later', '404-to-301' );
		$string11 = __( 'I already did', '404-to-301' );

		?>
		<div class="notice notice-info d404-review-plugin-cta is-dismissible">
			<div class="step-3">
				<p><?php echo wp_kses_post( $string1 ); ?></p>
				<p>
					<?php // phpcs:ignore Generic.Files.LineLength.MaxExceeded ?>
					<a href="https://aioseo.com/404-to-301-rating" class="d404-dismiss-review-notice" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $string9 ); ?>
					</a>&nbsp;&bull;&nbsp;
					<a href="#" class="d404-dismiss-review-notice-delay" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $string10 ); ?>
					</a>&nbsp;&bull;&nbsp;
					<a href="#" class="d404-dismiss-review-notice" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $string11 ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Print the script for dismissing the notice.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function printScript() {
		// Create a nonce.
		$nonce = wp_create_nonce( '404-to-301-dismiss-review' );
		?>
		<style>
			@keyframes dismissBtnVisible {
				from { opacity: 0.99; }
				to { opacity: 1; }
			}
			.d404-review-plugin-cta button.notice-dismiss {
				animation-duration: 0.001s;
				animation-name: dismissBtnVisible;
			}
		</style>
		<script>
			window.addEventListener('load', function () {
				var aioseoFourNotFourSetupButton,
					dismissBtn,
					interval

				aioseoFourNotFourSetupButton = function (dismissBtn) {
					var notice = document.querySelector('.notice.d404-review-plugin-cta'),
						delay  = false,
						relay  = true

					// Add an event listener to the dismiss button.
					dismissBtn.addEventListener('click', function (event) {
						var httpRequest = new XMLHttpRequest(),
							postData    = ''

						// Build the data to send in our request.
						postData += '&delay=' + delay
						postData += '&relay=' + relay
						postData += '&action=404-to-301-dismiss-review-plugin-cta'
						postData += '&nonce=<?php echo esc_html( $nonce ); ?>'

						httpRequest.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>')
						httpRequest.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded')
						httpRequest.send(postData)
					})

					notice.addEventListener('click', function (event) {
						if (event.target.matches('.d404-dismiss-review-notice-delay')) {
							event.preventDefault()
							delay = true
							relay = false
							dismissBtn.click()
						}
						if (event.target.matches('.d404-dismiss-review-notice')) {
							if ('#' === event.target.getAttribute('href')) {
								event.preventDefault()
							}
							relay = false
							dismissBtn.click()
						}
					})
				}

				dismissBtn = document.querySelector('.d404-review-plugin-cta .notice-dismiss')
				if (!dismissBtn) {
					document.addEventListener('animationstart', function (event) {
						if (event.animationName == 'dismissBtnVisible') {
							dismissBtn = document.querySelector('.d404-review-plugin-cta .notice-dismiss')
							if (dismissBtn) {
								aioseoFourNotFourSetupButton(dismissBtn)
							}
						}
					}, false)

				} else {
					aioseoFourNotFourSetupButton(dismissBtn)
				}
			});
		</script>
		<?php
	}

	/**
	 * Dismiss the review plugin CTA.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function dismissNotice() {
		// Early exit if we're not on a 404-to-301-dismiss-review-plugin-cta action.
		if ( ! isset( $_POST['action'] ) || '404-to-301-dismiss-review-plugin-cta' !== $_POST['action'] ) {
			return;
		}

		check_ajax_referer( '404-to-301-dismiss-review', 'nonce' );
		$delay = isset( $_POST['delay'] ) ? 'true' === sanitize_text_field( wp_unslash( $_POST['delay'] ) ) : false;
		$relay = isset( $_POST['relay'] ) ? 'true' === sanitize_text_field( wp_unslash( $_POST['relay'] ) ) : false;

		if ( ! $delay ) {
			update_user_meta( get_current_user_id(), '_aioseo_404_to_301_plugin_review_dismissed', $relay ? '4' : '3' );

			wp_send_json_success();

			return;
		}

		update_user_meta( get_current_user_id(), '_aioseo_404_to_301_plugin_review_dismissed', strtotime( '+1 week' ) );

		wp_send_json_success();
	}
}