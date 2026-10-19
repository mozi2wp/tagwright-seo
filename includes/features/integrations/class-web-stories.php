<?php
/**
 * Web Stories integration.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Integrations;

use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Works with Google's Web Stories plugin.
 *
 * Single stories use the plugin's own template, which fires
 * `web_stories_story_head` instead of `wp_head`, so the Tagwright SEO block is
 * printed there. The plugin's own metadata is switched off through the
 * filters it provides for exactly this purpose, and only for the parts
 * Tagwright SEO actually prints (social tags need the Social module, schema
 * needs the Schema module).
 */
class Web_Stories {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_filter( 'web_stories_enable_metadata', '__return_false' );

		if ( Modules::is_active( 'schema' ) ) {
			add_filter( 'web_stories_enable_schemaorg_metadata', '__return_false' );
		}

		if ( Modules::is_active( 'social' ) ) {
			add_filter( 'web_stories_enable_open_graph_metadata', '__return_false' );
			add_filter( 'web_stories_enable_twitter_metadata', '__return_false' );
		}

		add_action( 'web_stories_story_head', array( $this, 'story_head' ), 1 );
	}

	/**
	 * Prints the Tagwright SEO block in a story's head.
	 *
	 * @return void
	 */
	public function story_head() {
		$head = Feature::head();
		if ( ! $head ) {
			return;
		}

		// The plugin hooks the core canonical here; we print our own.
		remove_action( 'web_stories_story_head', 'rel_canonical' );

		$head->output();
	}
}
