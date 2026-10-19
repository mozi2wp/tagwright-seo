<?php
/**
 * Module registry.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps track of the features that can be switched on and off.
 *
 * A module that is switched off does not load any code at all, which is the
 * whole point: people only pay for what they use. Add-ons register their own
 * modules with the `tagwright_seo_modules` filter so they show up on the same
 * screen.
 */
class Modules {

	/**
	 * Cached module list.
	 *
	 * @var array|null
	 */
	private static $modules = null;

	/**
	 * Returns every registered module.
	 *
	 * @return array[] Keyed by module id.
	 */
	public static function all() {
		if ( null !== self::$modules ) {
			return self::$modules;
		}

		$modules = array(
			'social'           => array(
				'title'       => __( 'Social Cards', 'tagwright-seo' ),
				'description' => __( 'Open Graph and X (Twitter) Card tags so shared links look great on social networks.', 'tagwright-seo' ),
				'icon'        => 'share',
				'settings'    => 'social',
			),
			'schema'           => array(
				'title'       => __( 'Schema', 'tagwright-seo' ),
				'description' => __( 'Structured data (JSON-LD) that helps search engines understand your site and earn rich results.', 'tagwright-seo' ),
				'icon'        => 'code',
				'settings'    => 'schema',
			),
			'sitemaps'         => array(
				'title'       => __( 'XML Sitemaps', 'tagwright-seo' ),
				'description' => __( 'Fast sitemaps built straight from the Tagwright SEO index, with image entries.', 'tagwright-seo' ),
				'icon'        => 'sitemap',
				'settings'    => 'sitemaps',
			),
			'content_analysis' => array(
				'title'       => __( 'Content Analysis', 'tagwright-seo' ),
				'description' => __( 'Focus keyphrase, SEO and readability checks while you write. Runs only in the editor.', 'tagwright-seo' ),
				'icon'        => 'analysis',
				'settings'    => 'titles',
			),
			'llms_txt'         => array(
				'title'       => __( 'llms.txt', 'tagwright-seo' ),
				'description' => __( 'Publishes an llms.txt file that points AI assistants to your most useful content.', 'tagwright-seo' ),
				'icon'        => 'robot',
				'settings'    => 'technical',
			),
		);

		$modules = array_merge( $modules, Features\Registry::modules() );

		/**
		 * Filters the module list. Each module is an array with title,
		 * description, icon and settings (the settings tab id), plus an
		 * optional `addon` key holding the add-on name.
		 *
		 * @param array $modules Registered modules keyed by id.
		 */
		self::$modules = apply_filters( 'tagwright_seo_modules', $modules );

		return self::$modules;
	}

	/**
	 * Forgets the cached list so modules added later by add-ons show up.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$modules = null;
	}

	/**
	 * Whether a module is switched on.
	 *
	 * @param string $id Module id.
	 * @return bool
	 */
	public static function is_active( $id ) {
		$switches = Options::get( 'modules', array() );

		return ! empty( $switches[ $id ] );
	}

	/**
	 * Switches a module on or off.
	 *
	 * @param string $id     Module id.
	 * @param bool   $active New state.
	 * @return void
	 */
	public static function set_active( $id, $active ) {
		$switches        = Options::get( 'modules', array() );
		$switches[ $id ] = (bool) $active;

		Options::update( array( 'modules' => $switches ) );

		// Sitemaps and llms.txt add rewrite rules, so they need a flush.
		update_option( 'tagwright_seo_flush_rewrite', 1, false );

		/**
		 * Fires when a module is switched on or off.
		 *
		 * @param string $id     Module id.
		 * @param bool   $active New state.
		 */
		do_action( 'tagwright_seo_module_toggled', $id, (bool) $active );
	}
}
