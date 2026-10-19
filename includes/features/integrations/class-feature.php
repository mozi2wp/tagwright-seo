<?php
/**
 * Integrations feature.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Integrations;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Loads each integration only when its plugin is active and its switch is
 * on, so a site without any of them pays for one hook and a few
 * defined() checks.
 */
class Feature {

	/**
	 * Waits for the theme, because Divi and Bricks are themes and load
	 * after plugins. Every hook the integrations need fires later than this.
	 */
	public function __construct() {
		add_action( 'after_setup_theme', array( $this, 'load' ), 20 );
	}

	/**
	 * Starts the integrations that apply.
	 *
	 * @return void
	 */
	public function load() {
		if ( is_admin() ) {
			if ( Options::get( 'integrations_builders' ) && Detect::builders() ) {
				new Page_Builders();
			}
			return;
		}

		if ( Options::get( 'integrations_amp' ) && Detect::amp() ) {
			new Amp();
		}

		if ( Options::get( 'integrations_web_stories' ) && Detect::web_stories() ) {
			new Web_Stories();
		}

		if ( Options::get( 'integrations_bbpress' ) && Detect::bbpress() ) {
			new Bbpress();
		}

		if ( Options::get( 'integrations_buddypress' ) && Detect::buddypress() ) {
			new Buddypress();
		}
	}

	/**
	 * The Tagwright SEO head printer, or null outside the front end.
	 *
	 * @return \TagwrightSEO\Frontend\Head|null
	 */
	public static function head() {
		$head = \TagwrightSEO\Plugin::instance()->get( 'head' );

		return $head instanceof \TagwrightSEO\Frontend\Head ? $head : null;
	}

	/**
	 * Turns a robots array into noindex, follow.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public static function noindex( array $robots ) {
		unset( $robots['index'], $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );

		$robots['noindex'] = true;
		if ( empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}

		return $robots;
	}
}
