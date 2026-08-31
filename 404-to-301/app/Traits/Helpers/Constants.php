<?php
namespace AIOSEO\FourNotFour\Traits\Helpers;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contains constant specific helper methods.
 *
 * @since 1.0.0
 */
trait Constants {
	/**
	 * Returns the admin menu icon: the plugin logo's disc with its redirect arrow knocked out.
	 *
	 * ONE path, one `fill`, holes from `fill-rule="evenodd"` - not a mask, and not a stroke.
	 * `wp-admin/js/svg-painter.js` rewrites *every* `fill="..."` in a data-URI menu icon to the
	 * admin colour scheme's icon colour, so anything that encodes shape in a fill colour is
	 * destroyed on load: a mask whose black shapes become the same light grey as its white
	 * backdrop stops hiding anything, which silently drops the arrowhead while leaving a stroked
	 * ring intact. Winding order survives the repaint because it isn't a colour.
	 *
	 * The glyph is not the logo's arrow to scale, either. Rasterised at 20px the logo's
	 * proportions put the head at about four pixels butted against the ring's end, and the two
	 * merge into one unreadable blob. So the ring loses its shaft, the head hangs off the ring's
	 * end, and it is drawn large enough to hold its shape - and deliberately clear of the ring
	 * band, because two overlapping holes cancel back to solid under `evenodd`.
	 *
	 * @since   1.0.0
	 * @version 4.0.4 Redrawn from the logo: the disc with the arrow knocked out.
	 *
	 * @param  string $colorCode Disc colour.
	 * @return string            The icon as an SVG string.
	 */
	public function icon( $colorCode = '#A0A5AA' ) {
		return '<svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M10 0A10 10 0 0 1 10 20A10 10 0 0 1 10 0ZM9 4.7A6.1 6.1 0 1 0 15.1 10.8L11.7 10.8A2.7 2.7 0 1 1 9 8.1ZM9 3.6L16.2 6.4L9 9.2Z" fill="' . $colorCode . '"/></svg>'; // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	}
}