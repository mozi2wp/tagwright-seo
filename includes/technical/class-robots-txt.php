<?php
/**
 * robots.txt editor.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Technical;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the virtual robots.txt WordPress serves with the custom content
 * saved in the settings.
 *
 * This only works when there is no physical robots.txt file in the site
 * root, because the web server serves a real file before WordPress loads.
 */
class Robots_Txt {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		if ( Options::get( 'robots_txt_enabled' ) ) {
			add_filter( 'robots_txt', array( $this, 'filter' ), 10, 2 );
		}
	}

	/**
	 * Swaps in the saved content.
	 *
	 * @param string $output Default robots.txt.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function filter( $output, $public ) {
		// A site that hides from search engines should keep doing so.
		if ( ! $public ) {
			return $output;
		}

		$custom = trim( (string) Options::get( 'robots_txt_content', '' ) );

		return '' !== $custom ? $custom . "\n" : $output;
	}

	/**
	 * Whether a physical robots.txt file would override ours.
	 *
	 * @return bool
	 */
	public static function has_physical_file() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return file_exists( trailingslashit( get_home_path() ) . 'robots.txt' );
	}

	/**
	 * The robots.txt WordPress would serve without us, used as a starting point.
	 *
	 * @return string
	 */
	public static function default_content() {
		$site_url = wp_parse_url( site_url() );
		$path     = ! empty( $site_url['path'] ) ? $site_url['path'] : '';

		$content  = "User-agent: *\n";
		$content .= "Disallow: {$path}/wp-admin/\n";
		$content .= "Allow: {$path}/wp-admin/admin-ajax.php\n";

		return $content;
	}
}

