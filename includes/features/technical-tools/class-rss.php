<?php
/**
 * RSS additions.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Technical_Tools;

use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Frontend\Replacer;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a line before and after every feed item, usually a link back to the
 * original post so copies made by feed scrapers credit the source.
 *
 * Besides the usual template variables it understands three that only make
 * sense in a feed, because they produce links: %postlink%, %bloglink% and
 * %authorlink%.
 */
class Rss {

	/**
	 * Variables that turn into links.
	 *
	 * @var string[]
	 */
	const LINK_VARIABLES = array( 'postlink', 'bloglink', 'authorlink' );

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_filter( 'the_content_feed', array( $this, 'filter' ) );
		add_filter( 'the_excerpt_rss', array( $this, 'filter' ) );

		// Show the link variables in the picker, but only on our settings screen.
		if ( is_admin() && self::is_settings_screen() ) {
			add_filter( 'tagwright_seo_variable_descriptions', array( $this, 'describe_variables' ) );
			add_filter( 'tagwright_seo_variable_labels', array( $this, 'label_variables' ) );
		}
	}

	/**
	 * Whether the current admin page is the Tagwright SEO settings page.
	 *
	 * @return bool
	 */
	private static function is_settings_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the page slug.
		return isset( $_GET['page'] ) && 'tagwright-seo-settings' === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Wraps the feed content.
	 *
	 * @param string $content Item content or excerpt.
	 * @return string
	 */
	public function filter( $content ) {
		if ( ! is_feed() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		$before = self::render( (string) Options::get( 'technical_tools_rss_before', '' ), $post );
		$after  = self::render( (string) Options::get( 'technical_tools_rss_after', '' ), $post );

		if ( '' !== $before ) {
			$content = '<p>' . $before . '</p>' . "\n" . $content;
		}

		if ( '' !== $after ) {
			$content .= "\n" . '<p>' . $after . '</p>';
		}

		return $content;
	}

	/**
	 * Fills in a template for one post. The result is safe HTML.
	 *
	 * @param string   $template Template.
	 * @param \WP_Post $post     Post.
	 * @return string
	 */
	public static function render( $template, $post ) {
		$template = trim( $template );
		if ( '' === $template ) {
			return '';
		}

		// Normal variables first, then escape, then add our links, so the
		// only markup in the result is markup we built ourselves.
		$text = esc_html( Replacer::replace( $template, Context::for_post( $post ) ) );

		$author_id = (int) $post->post_author;
		$links     = array(
			'%postlink%'   => self::link( (string) get_permalink( $post ), get_the_title( $post ) ),
			'%bloglink%'   => self::link( home_url( '/' ), get_bloginfo( 'name' ) ),
			'%authorlink%' => $author_id ? self::link( get_author_posts_url( $author_id ), (string) get_the_author_meta( 'display_name', $author_id ) ) : '',
		);

		return strtr( $text, $links );
	}

	/**
	 * A plain link.
	 *
	 * @param string $url  URL.
	 * @param string $text Link text, may contain entities from WordPress.
	 * @return string
	 */
	private static function link( $url, $text ) {
		$text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $text ) );
	}

	/**
	 * Adds the link variables to the picker.
	 *
	 * @param array $variables Variable descriptions.
	 * @return array
	 */
	public function describe_variables( $variables ) {
		$variables['postlink']   = __( 'Link to the post (RSS only)', 'tagwright-seo' );
		$variables['bloglink']   = __( 'Link to your site (RSS only)', 'tagwright-seo' );
		$variables['authorlink'] = __( 'Link to the author archive (RSS only)', 'tagwright-seo' );

		return $variables;
	}

	/**
	 * Chip labels for the link variables.
	 *
	 * @param array $labels Labels.
	 * @return array
	 */
	public function label_variables( $labels ) {
		$labels['postlink']   = __( 'Post link', 'tagwright-seo' );
		$labels['bloglink']   = __( 'Site link', 'tagwright-seo' );
		$labels['authorlink'] = __( 'Author link', 'tagwright-seo' );

		return $labels;
	}
}
