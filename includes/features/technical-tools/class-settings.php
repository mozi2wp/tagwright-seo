<?php
/**
 * Technical Tools settings.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Technical_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults, module card and settings tab for Technical Tools.
 *
 * The core Technical tab already covers attachment pages, ?replytocom, the
 * category base, robots.txt and llms.txt. This tab adds what sits around
 * them: head and feed cleanup, RSS footers, an HTML sitemap and .htaccess.
 */
final class Settings {

	/**
	 * Default values. Read on every request, so it stays a plain array.
	 *
	 * Only the removals that cannot break anything start switched on.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'technical_tools_remove_shortlink'     => false,
			'technical_tools_remove_rsd'           => false,
			'technical_tools_remove_wlw'           => true,
			'technical_tools_remove_generator'     => true,
			'technical_tools_remove_rest_link'     => false,
			'technical_tools_remove_oembed'        => false,
			'technical_tools_remove_emoji'         => false,
			'technical_tools_remove_comments_feed' => false,
			'technical_tools_remove_post_comments' => false,
			'technical_tools_remove_global_feeds'  => false,
			'technical_tools_remove_search_feeds'  => false,
			'technical_tools_remove_pingback'      => false,
			'technical_tools_search_redirect'      => false,
			'technical_tools_rss_before'           => '',
			'technical_tools_rss_after'            => '',
			'technical_tools_sitemap_post_types'   => array( 'page', 'post' ),
			'technical_tools_sitemap_taxonomies'   => array( 'category' ),
			'technical_tools_sitemap_order'        => 'title',
			'technical_tools_sitemap_dates'        => false,
			'technical_tools_sitemap_hierarchical' => true,
			'technical_tools_sitemap_exclude'      => '',
			'technical_tools_sitemap_limit'        => 500,
			'technical_tools_sitemap_page'         => '0',
		);
	}

	/**
	 * Card on the Modules screen.
	 *
	 * @return array
	 */
	public static function module(): array {
		return array(
			'title'       => __( 'Technical Tools', 'tagwright-seo' ),
			'description' => __( 'Crawl cleanup for head tags and feeds, RSS footers, an HTML sitemap block and a safe .htaccess editor.', 'tagwright-seo' ),
			'icon'        => 'tools',
			'settings'    => 'technical_tools',
		);
	}

	/**
	 * Settings tab.
	 *
	 * @return array
	 */
	public static function tab(): array {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( 'attachment' !== $post_type->name ) {
				$post_types[ $post_type->name ] = $post_type->labels->name;
			}
		}

		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( 'post_format' !== $taxonomy->name ) {
				$taxonomies[ $taxonomy->name ] = $taxonomy->labels->name;
			}
		}

		$rss_variables = '<code>%postlink%</code> <code>%bloglink%</code> <code>%authorlink%</code> <code>%title%</code> <code>%sitename%</code>';

		return array(
			'title'       => __( 'Technical Tools', 'tagwright-seo' ),
			'description' => __( 'Remove clutter WordPress adds to every page, extend your feeds and publish an HTML sitemap.', 'tagwright-seo' ),
			'icon'        => 'tools',
			'group'       => 'advanced',
			'module'      => 'technical_tools',
			'sections'    => array(
				array(
					'title'       => __( 'Head cleanup', 'tagwright-seo' ),
					'description' => __( 'Tags WordPress prints in every page that search engines do not need.', 'tagwright-seo' ),
					'icon'        => 'code',
					'accordion'   => true,
					'open'        => true,
					'fields'      => array(
						self::toggle( 'remove_shortlink', __( 'Shortlink', 'tagwright-seo' ), __( 'The ?p=123 link tag and HTTP header.', 'tagwright-seo' ) ),
						self::toggle( 'remove_rsd', __( 'RSD link', 'tagwright-seo' ), __( 'Really Simple Discovery, only used by old remote publishing apps over XML-RPC.', 'tagwright-seo' ) ),
						self::toggle( 'remove_wlw', __( 'Windows Live Writer', 'tagwright-seo' ), __( 'Manifest link for an app that was discontinued in 2017.', 'tagwright-seo' ) ),
						self::toggle( 'remove_generator', __( 'Generator tag', 'tagwright-seo' ), __( 'Hides the WordPress version from pages and feeds.', 'tagwright-seo' ) ),
						self::toggle( 'remove_rest_link', __( 'REST API link', 'tagwright-seo' ), __( 'The api.w.org link tag and Link header. The REST API itself keeps working.', 'tagwright-seo' ) ),
						self::toggle( 'remove_oembed', __( 'oEmbed discovery', 'tagwright-seo' ), __( 'Links that let other sites embed your posts as cards. Embedding still works where it is set up directly.', 'tagwright-seo' ) ),
						self::toggle( 'remove_emoji', __( 'Emoji scripts', 'tagwright-seo' ), __( 'The emoji detection script and styles. Modern browsers draw emoji on their own.', 'tagwright-seo' ) ),
						self::toggle( 'remove_pingback', __( 'Pingback header', 'tagwright-seo' ), __( 'Removes the X-Pingback HTTP header.', 'tagwright-seo' ) ),
					),
				),
				array(
					'title'       => __( 'Feeds', 'tagwright-seo' ),
					'description' => __( 'Fewer feed URLs means less crawling of thin duplicate pages. Removed feeds redirect to the page they belong to.', 'tagwright-seo' ),
					'icon'        => 'news',
					'accordion'   => true,
					'fields'      => array(
						self::toggle( 'remove_comments_feed', __( 'Site comments feed', 'tagwright-seo' ), __( 'The feed of the latest comments across the site.', 'tagwright-seo' ) ),
						self::toggle( 'remove_post_comments', __( 'Post comment feeds', 'tagwright-seo' ), __( 'A separate comments feed for every single post.', 'tagwright-seo' ) ),
						self::toggle( 'remove_search_feeds', __( 'Search result feeds', 'tagwright-seo' ), __( 'Feeds of search results, which can be abused to create endless URLs.', 'tagwright-seo' ) ),
						array(
							'id'           => 'technical_tools_remove_global_feeds',
							'type'         => 'toggle',
							'label'        => __( 'Main site feed', 'tagwright-seo' ),
							'description'  => __( 'Careful: feed readers, email newsletters, podcast apps and Google News may rely on your main RSS feed. Only switch this off if you are sure nothing uses it.', 'tagwright-seo' ),
							'toggle_label' => __( 'Remove and redirect the main feed', 'tagwright-seo' ),
							'badge'        => __( 'Use with care', 'tagwright-seo' ),
							'badge_type'   => 'bad',
						),
					),
				),
				array(
					'title'  => __( 'Search URLs', 'tagwright-seo' ),
					'icon'   => 'search',
					'fields' => array(
						array(
							'id'           => 'technical_tools_search_redirect',
							'type'         => 'toggle',
							'label'        => __( 'Pretty search URLs', 'tagwright-seo' ),
							'description'  => __( 'Redirects /?s=shoes to /search/shoes/. Needs pretty permalinks.', 'tagwright-seo' ),
							'toggle_label' => __( 'Redirect ?s= searches', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'       => __( 'RSS additions', 'tagwright-seo' ),
					'description' => sprintf(
						/* translators: %s: list of variables. */
						__( 'Text added to every item in your feeds. Scrapers that copy your feed keep the link back to you. Variables: %s', 'tagwright-seo' ),
						$rss_variables
					),
					'icon'        => 'link',
					'fields'      => array(
						array(
							'id'          => 'technical_tools_rss_before',
							'type'        => 'template_textarea',
							'label'       => __( 'Before each item', 'tagwright-seo' ),
							'placeholder' => '',
						),
						array(
							'id'          => 'technical_tools_rss_after',
							'type'        => 'template_textarea',
							'label'       => __( 'After each item', 'tagwright-seo' ),
							'placeholder' => 'The post %postlink% appeared first on %bloglink%.',
						),
					),
				),
				array(
					'title'       => __( 'HTML sitemap', 'tagwright-seo' ),
					'description' => __( 'A page of links for people. Add the "HTML Sitemap" block or the [tagwright_html_sitemap] shortcode to any page, or pick a page below.', 'tagwright-seo' ),
					'icon'        => 'sitemap',
					'fields'      => array(
						array(
							'id'      => 'technical_tools_sitemap_post_types',
							'type'    => 'checkboxes',
							'label'   => __( 'Content types', 'tagwright-seo' ),
							'options' => $post_types,
						),
						array(
							'id'      => 'technical_tools_sitemap_taxonomies',
							'type'    => 'checkboxes',
							'label'   => __( 'Taxonomies', 'tagwright-seo' ),
							'options' => $taxonomies,
						),
						array(
							'id'      => 'technical_tools_sitemap_order',
							'type'    => 'segmented',
							'label'   => __( 'Sort by', 'tagwright-seo' ),
							'options' => array(
								'title' => __( 'Title', 'tagwright-seo' ),
								'date'  => __( 'Newest first', 'tagwright-seo' ),
							),
						),
						array(
							'id'           => 'technical_tools_sitemap_hierarchical',
							'type'         => 'toggle',
							'label'        => __( 'Hierarchy', 'tagwright-seo' ),
							'toggle_label' => __( 'Nest child pages and categories under their parents', 'tagwright-seo' ),
						),
						array(
							'id'           => 'technical_tools_sitemap_dates',
							'type'         => 'toggle',
							'label'        => __( 'Dates', 'tagwright-seo' ),
							'toggle_label' => __( 'Show the publish date next to posts', 'tagwright-seo' ),
						),
						array(
							'id'          => 'technical_tools_sitemap_exclude',
							'type'        => 'text',
							'label'       => __( 'Exclude', 'tagwright-seo' ),
							'description' => __( 'Post, page or term ids, comma separated. Noindexed items are always left out.', 'tagwright-seo' ),
							'placeholder' => '12, 34',
							'sanitize'    => array( Html_Sitemap::class, 'sanitize_ids' ),
						),
						array(
							'id'          => 'technical_tools_sitemap_limit',
							'type'        => 'number',
							'label'       => __( 'Items per section', 'tagwright-seo' ),
							'min'         => 10,
							'max'         => 5000,
							'description' => __( 'Keeps very large sites readable and fast.', 'tagwright-seo' ),
						),
						array(
							'id'          => 'technical_tools_sitemap_page',
							'type'        => 'select',
							'label'       => __( 'Add to page', 'tagwright-seo' ),
							'description' => __( 'Appends the sitemap to the end of this page automatically.', 'tagwright-seo' ),
							'options'     => Html_Sitemap::page_options(),
						),
					),
				),
				array(
					'title'       => __( '.htaccess', 'tagwright-seo' ),
					'description' => __( 'Edit the server configuration file in your site root. A mistake here can take the whole site down, so a backup is kept of every save.', 'tagwright-seo' ),
					'icon'        => 'file',
					'accordion'   => true,
					'fields'      => array(
						array(
							'id'     => 'technical_tools_htaccess_box',
							'type'   => 'html',
							'wide'   => true,
							'render' => array( Htaccess::class, 'render' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Shorthand for the many cleanup toggles.
	 *
	 * @param string $key         Key without the prefix.
	 * @param string $label       Label.
	 * @param string $description Help text.
	 * @return array
	 */
	private static function toggle( $key, $label, $description ) {
		return array(
			'id'           => 'technical_tools_' . $key,
			'type'         => 'toggle',
			'label'        => $label,
			'description'  => $description,
			'toggle_label' => __( 'Remove', 'tagwright-seo' ),
		);
	}
}
