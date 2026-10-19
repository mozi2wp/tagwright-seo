<?php
/**
 * Plugin and theme detection.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Cheap checks for the plugins and themes we integrate with: constants and
 * class names they define when loaded, never database lookups.
 *
 * Divi and Bricks are themes, which load after plugins, so anything that
 * asks about builders must run on after_setup_theme or later.
 */
class Detect {

	/**
	 * Official AMP plugin.
	 *
	 * @return bool
	 */
	public static function amp() {
		return defined( 'AMP__VERSION' ) || function_exists( 'amp_is_request' );
	}

	/**
	 * Web Stories plugin.
	 *
	 * @return bool
	 */
	public static function web_stories() {
		return defined( 'WEBSTORIES_VERSION' );
	}

	/**
	 * bbPress.
	 *
	 * @return bool
	 */
	public static function bbpress() {
		return class_exists( 'bbPress' );
	}

	/**
	 * BuddyPress.
	 *
	 * @return bool
	 */
	public static function buddypress() {
		return class_exists( 'BuddyPress' );
	}

	/**
	 * Active page builders.
	 *
	 * @return array<string, string> Builder id => name.
	 */
	public static function builders() {
		$template = function_exists( 'get_template' ) ? strtolower( (string) get_template() ) : '';
		$found    = array();

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$found['elementor'] = 'Elementor';
		}

		if ( defined( 'FL_BUILDER_VERSION' ) || class_exists( 'FLBuilderLoader' ) ) {
			$found['beaver'] = 'Beaver Builder';
		}

		if ( defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_BUILDER_PLUGIN_VERSION' ) || 'divi' === $template || 'extra' === $template ) {
			$found['divi'] = 'Divi';
		}

		if ( defined( 'BRICKS_VERSION' ) || 'bricks' === $template ) {
			$found['bricks'] = 'Bricks';
		}

		if ( defined( 'CT_VERSION' ) ) {
			$found['oxygen'] = 'Oxygen';
		}

		return $found;
	}

	/**
	 * Everything at once, for the settings tab.
	 *
	 * @return array<string, bool>
	 */
	public static function all() {
		return array(
			'amp'         => self::amp(),
			'web_stories' => self::web_stories(),
			'bbpress'     => self::bbpress(),
			'buddypress'  => self::buddypress(),
			'builders'    => (bool) self::builders(),
		);
	}
}
