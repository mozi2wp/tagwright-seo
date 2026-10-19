<?php
/**
 * Add-on registry.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Lets add-ons announce themselves and checks that they work with the
 * installed version of the core.
 *
 * An add-on boots like this:
 *
 *     add_action( 'tagwright_seo_loaded', function () {
 *         if ( TagwrightSEO\Addons::register( array(
 *             'id'       => 'pro',
 *             'name'     => 'Tagwright SEO Pro',
 *             'version'  => '1.0.0',
 *             'requires' => '1.2.0',
 *         ) ) ) {
 *             // Safe to load the add-on.
 *         }
 *     } );
 *
 * Add-ons should also declare `Requires Plugins: tagwright-seo` in their
 * plugin header so WordPress enforces the dependency. Tagwright SEO Pro registers
 * itself the same way, and its modules use the ids in the catalogue below.
 */
class Addons {

	/**
	 * Registered add-ons.
	 *
	 * @var array[]
	 */
	private static $registered = array();

	/**
	 * Registers an add-on.
	 *
	 * @param array $addon id, name, version and requires (minimum core version).
	 * @return bool False when the core is too old for the add-on.
	 */
	public static function register( array $addon ) {
		$addon = wp_parse_args(
			$addon,
			array(
				'id'       => '',
				'name'     => '',
				'version'  => '',
				'requires' => '0',
			)
		);

		if ( '' === $addon['id'] ) {
			return false;
		}

		$addon['compatible'] = version_compare( TAGWRIGHT_SEO_VERSION, $addon['requires'], '>=' );

		self::$registered[ $addon['id'] ] = $addon;

		if ( ! $addon['compatible'] ) {
			add_action(
				'admin_notices',
				static function () use ( $addon ) {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: 1: add-on name, 2: required core version. */
								__( '%1$s needs Tagwright SEO %2$s or newer. Please update Tagwright SEO.', 'tagwright-seo' ),
								$addon['name'],
								$addon['requires']
							)
						)
					);
				}
			);
		}

		return $addon['compatible'];
	}

	/**
	 * Whether an add-on is active and compatible.
	 *
	 * @param string $id Add-on id.
	 * @return bool
	 */
	public static function is_active( $id ) {
		return ! empty( self::$registered[ $id ]['compatible'] );
	}

	/**
	 * Registered add-ons.
	 *
	 * @return array[]
	 */
	public static function registered() {
		return self::$registered;
	}

	/**
	 * The Tagwright SEO Pro modules, shown on the Add-ons and Pro screens.
	 *
	 * The free features ship inside the plugin (see Features\Registry), so
	 * this list only holds what Tagwright SEO Pro adds. `plan` is the lowest plan
	 * that includes the module: `pro` means every paid plan.
	 *
	 * @return array[] Id => array( name, description, icon, plan ).
	 */
	public static function catalogue() {
		$catalogue = array(
			'analysis'       => array(
				'name'        => __( 'Advanced Analysis', 'tagwright-seo' ),
				'description' => __( 'Several keyphrases per post, synonyms, word forms, inclusive language checks and cornerstone content.', 'tagwright-seo' ),
				'icon'        => 'keyword',
			),
			'links'          => array(
				'name'        => __( 'Link Manager', 'tagwright-seo' ),
				'description' => __( 'Internal link counts, orphaned content, link suggestions while you write and automatic linking.', 'tagwright-seo' ),
				'icon'        => 'link',
			),
			'schema_pro'     => array(
				'name'        => __( 'Schema Pro', 'tagwright-seo' ),
				'description' => __( 'Product, Recipe, Event, Job, Course and more schema types, a visual builder, reusable templates and import from any URL.', 'tagwright-seo' ),
				'icon'        => 'code',
			),
			'social_plus'    => array(
				'name'        => __( 'Social Plus', 'tagwright-seo' ),
				'description' => __( 'Branded share images, Slack and Discord previews, Pinterest controls and a share preview checker.', 'tagwright-seo' ),
				'icon'        => 'share',
			),
			'search_console' => array(
				'name'        => __( 'Search Console & Analytics', 'tagwright-seo' ),
				'description' => __( 'Clicks, keywords and positions from Google, what to improve next, keyword tracking, index status, sitemaps, Google Analytics and email reports.', 'tagwright-seo' ),
				'icon'        => 'trend',
			),
			'audit'          => array(
				'name'        => __( 'Site Audit', 'tagwright-seo' ),
				'description' => __( 'Scans your content and live pages for SEO problems, broken links and orphaned pages, with a site score and scheduled reports by email.', 'tagwright-seo' ),
				'icon'        => 'shield',
			),
			'ai'             => array(
				'name'        => __( 'AI Assistant', 'tagwright-seo' ),
				'description' => __( 'Titles, descriptions, keyphrases, alt text and FAQs with Claude, ChatGPT or Gemini, Smart fill for any post\'s fields, and bulk filling, with your own API key.', 'tagwright-seo' ),
				'icon'        => 'sparkles',
			),
			'woocommerce'    => array(
				'name'        => __( 'WooCommerce SEO', 'tagwright-seo' ),
				'description' => __( 'Product schema with offers, shipping and returns, GTIN, MPN and brand fields, shop and category SEO, and a Google Merchant Center feed.', 'tagwright-seo' ),
				'icon'        => 'cart',
			),
			'local'          => array(
				'name'        => __( 'Local SEO', 'tagwright-seo' ),
				'description' => __( 'One business or many locations with opening hours, LocalBusiness schema, map and store locator blocks, and a KML file.', 'tagwright-seo' ),
				'icon'        => 'pin',
			),
			'video'          => array(
				'name'        => __( 'Video SEO', 'tagwright-seo' ),
				'description' => __( 'VideoObject schema with key moments, a video sitemap and light YouTube and Vimeo embeds for the videos in your posts.', 'tagwright-seo' ),
				'icon'        => 'video',
			),
			'news'           => array(
				'name'        => __( 'News SEO', 'tagwright-seo' ),
				'description' => __( 'A Google News sitemap, NewsArticle schema with datelines and corrections, and newsroom policy pages in the publisher schema.', 'tagwright-seo' ),
				'icon'        => 'news',
			),
			'podcast'        => array(
				'name'        => __( 'Podcast SEO', 'tagwright-seo' ),
				'description' => __( 'A podcast feed ready for Apple Podcasts and Spotify, episode schema, chapters, transcripts and an episode player.', 'tagwright-seo' ),
				'icon'        => 'mic',
			),
			'agency'         => array(
				'name'        => __( 'Agency Tools', 'tagwright-seo' ),
				'description' => __( 'White label, client access by role, branded client reports, settings presets for new sites and multisite networks, and an activity log.', 'tagwright-seo' ),
				'icon'        => 'user',
				'plan'        => 'agency',
			),
		);

		foreach ( $catalogue as $id => $module ) {
			$catalogue[ $id ] += array( 'plan' => 'pro' );
		}

		/**
		 * Filters the Pro module catalogue.
		 *
		 * @param array $catalogue Id => array( name, description, icon, plan ).
		 */
		return apply_filters( 'tagwright_seo_addon_catalogue', $catalogue );
	}

	/**
	 * The paid plans, for the comparison on the Pro screen.
	 *
	 * Prices are kept off this screen on purpose: they live on the website,
	 * so changing them never needs a plugin release.
	 *
	 * @return array[] Plan id => array( name, sites, description ).
	 */
	public static function plans() {
		return array(
			'free'     => array(
				'name'        => __( 'Free', 'tagwright-seo' ),
				'sites'       => __( 'Unlimited sites', 'tagwright-seo' ),
				'description' => __( 'Everything in this plugin, forever free.', 'tagwright-seo' ),
			),
			'pro'      => array(
				'name'        => __( 'Pro', 'tagwright-seo' ),
				'sites'       => __( '1 site', 'tagwright-seo' ),
				'description' => __( 'For your own site or blog.', 'tagwright-seo' ),
			),
			'business' => array(
				'name'        => __( 'Business', 'tagwright-seo' ),
				'sites'       => __( '10 sites', 'tagwright-seo' ),
				'description' => __( 'For businesses and freelancers.', 'tagwright-seo' ),
			),
			'agency'   => array(
				'name'        => __( 'Agency', 'tagwright-seo' ),
				'sites'       => __( '100 sites', 'tagwright-seo' ),
				'description' => __( 'For agencies managing client sites.', 'tagwright-seo' ),
			),
		);
	}
}
