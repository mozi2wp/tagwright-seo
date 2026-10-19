<?php
/**
 * AMP integration.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Integrations;

use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Works with the official AMP plugin.
 *
 * Standard and transitional modes render through the theme, so wp_head()
 * already prints the Tagwright SEO block exactly once; the only job there is to
 * stop the AMP plugin adding its own schema next to ours. Reader mode uses
 * the plugin's own templates, which never call wp_head(), so the block is
 * printed on amp_post_template_head instead and the plugin's canonical is
 * removed to avoid a second one.
 */
class Amp {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		if ( Modules::is_active( 'schema' ) ) {
			// Read by amp_print_schemaorg_metadata() and the reader template; empty means "print nothing".
			add_filter( 'amp_schemaorg_metadata', '__return_empty_array', 99 );
		}

		add_action( 'amp_post_template_head', array( $this, 'reader_head' ), 1 );
	}

	/**
	 * Prints the Tagwright SEO block in reader templates.
	 *
	 * @return void
	 */
	public function reader_head() {
		$head = Feature::head();
		if ( ! $head ) {
			return;
		}

		// Our canonical points at the regular post, as AMP requires, so theirs would be a duplicate.
		remove_action( 'amp_post_template_head', 'amp_post_template_add_canonical' );

		// Reader templates do not print the robots meta tag on every AMP version.
		if ( function_exists( 'wp_robots' ) && ! has_action( 'amp_post_template_head', 'wp_robots' ) ) {
			wp_robots();
		}

		$head->output();
	}
}
