<?php
/**
 * Head and feed cleanup.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Technical_Tools;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the tags, headers and feeds WordPress adds by default that do
 * nothing for search engines.
 *
 * The callbacks being removed are attached in wp-includes/default-filters.php,
 * which runs before any plugin loads, so removing them while this class is
 * constructed (on plugins_loaded) is early enough. Each removal is its own
 * setting so a site can keep what it actually uses.
 */
class Crawl_Cleanup {

	/**
	 * Registers the removals that are switched on.
	 */
	public function __construct() {
		$heads = array( 'wp_head', 'web_stories_story_head' );

		if ( self::on( 'remove_shortlink' ) ) {
			foreach ( $heads as $head ) {
				remove_action( $head, 'wp_shortlink_wp_head', 10 );
			}
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		if ( self::on( 'remove_rsd' ) ) {
			foreach ( $heads as $head ) {
				remove_action( $head, 'rsd_link' );
			}
		}

		if ( self::on( 'remove_wlw' ) ) {
			// Core stopped printing this in 6.3, but older sites and the Web Stories head still have it.
			foreach ( $heads as $head ) {
				remove_action( $head, 'wlwmanifest_link' );
			}
		}

		if ( self::on( 'remove_generator' ) ) {
			foreach ( $heads as $head ) {
				remove_action( $head, 'wp_generator' );
			}
			// Feeds print their own generator tag through this filter.
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( self::on( 'remove_rest_link' ) ) {
			foreach ( $heads as $head ) {
				remove_action( $head, 'rest_output_link_wp_head', 10 );
			}
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}

		if ( self::on( 'remove_oembed' ) ) {
			foreach ( $heads as $head ) {
				remove_action( $head, 'wp_oembed_add_discovery_links' );
			}
		}

		if ( self::on( 'remove_emoji' ) ) {
			$this->remove_emoji();
		}

		if ( self::on( 'remove_pingback' ) ) {
			add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
		}

		if ( self::on( 'remove_comments_feed' ) ) {
			add_filter( 'feed_links_show_comments_feed', '__return_false' );
		}

		if ( self::on( 'remove_post_comments' ) ) {
			add_filter( 'feed_links_extra_show_post_comments_feed', '__return_false' );
		}

		if ( self::on( 'remove_search_feeds' ) ) {
			add_filter( 'feed_links_extra_show_search_feed', '__return_false' );
		}

		if ( self::on( 'remove_global_feeds' ) ) {
			foreach ( $heads as $head ) {
				remove_action( $head, 'feed_links', 2 );
			}
			add_filter( 'feed_links_show_posts_feed', '__return_false' );
		}

		if ( self::on( 'remove_comments_feed' ) || self::on( 'remove_post_comments' ) || self::on( 'remove_search_feeds' ) || self::on( 'remove_global_feeds' ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_removed_feeds' ), 1 );
		}

		if ( self::on( 'search_redirect' ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_search' ), 2 );
		}
	}

	/**
	 * Reads one of our toggles.
	 *
	 * @param string $key Key without the prefix.
	 * @return bool
	 */
	private static function on( $key ) {
		return (bool) Options::get( 'technical_tools_' . $key );
	}

	/**
	 * Unhooks every piece of the emoji support script.
	 *
	 * @return void
	 */
	private function remove_emoji() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'embed_head', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		// Also drops the s.w.org dns-prefetch hint, which only exists for emoji.
		add_filter( 'emoji_svg_url', '__return_false' );
		add_filter( 'tiny_mce_plugins', array( $this, 'remove_tinymce_emoji' ) );
	}

	/**
	 * Removes the emoji plugin from the classic editor.
	 *
	 * @param string[] $plugins TinyMCE plugins.
	 * @return string[]
	 */
	public function remove_tinymce_emoji( $plugins ) {
		return is_array( $plugins ) ? array_values( array_diff( $plugins, array( 'wpemoji' ) ) ) : $plugins;
	}

	/**
	 * Drops the X-Pingback header.
	 *
	 * @param array $headers Response headers.
	 * @return array
	 */
	public function remove_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );

		return $headers;
	}

	/**
	 * Sends requests for removed feeds to the page they came from, so links
	 * that already exist keep working and stop being crawled as feeds.
	 *
	 * @return void
	 */
	public function redirect_removed_feeds() {
		if ( ! is_feed() ) {
			return;
		}

		$target = '';

		if ( is_search() ) {
			if ( self::on( 'remove_search_feeds' ) ) {
				$target = get_search_link();
			}
		} elseif ( is_comment_feed() ) {
			if ( is_singular() && self::on( 'remove_post_comments' ) ) {
				$target = (string) get_permalink( get_queried_object_id() );
			} elseif ( ! is_singular() && self::on( 'remove_comments_feed' ) ) {
				$target = home_url( '/' );
			}
		} elseif ( self::on( 'remove_global_feeds' ) && ! is_archive() ) {
			// Only the main feed: category, tag and author feeds are separate URLs people subscribe to.
			$target = home_url( '/' );
		}

		if ( '' !== $target ) {
			wp_safe_redirect( $target, 301, 'Tagwright SEO' );
			exit;
		}
	}

	/**
	 * Redirects /?s=term to /search/term/.
	 *
	 * Only plain searches are moved; anything with extra parameters (a
	 * filter, a post type, a page number) is left alone so search forms
	 * that depend on them keep working.
	 *
	 * @return void
	 */
	public function redirect_search() {
		global $wp_rewrite;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, used to build a redirect.
		if ( ! is_search() || is_admin() || is_feed() || ! isset( $_GET['s'] ) || ! $wp_rewrite || ! $wp_rewrite->using_permalinks() ) {
			return;
		}

		$extra = array_diff( array_keys( $_GET ), array( 's' ) );
		// phpcs:enable

		if ( $extra ) {
			return;
		}

		$query = get_search_query( false );
		if ( '' === trim( $query ) ) {
			return;
		}

		wp_safe_redirect( get_search_link( $query ), 301, 'Tagwright SEO' );
		exit;
	}
}
