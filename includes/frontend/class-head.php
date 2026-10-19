<?php
/**
 * Prints the SEO tags in the document head.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Frontend;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the title, meta description, meta keywords, robots, canonical and
 * verification tags. Social and schema output hook into the same block via
 * the `tagwright_seo_head` action so everything appears together.
 */
class Head {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_filter( 'pre_get_document_title', array( $this, 'filter_title' ), 15 );
		add_filter( 'wp_title', array( $this, 'filter_title' ), 15 );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), 20 );

		// We print our own canonical, so stop WordPress from adding a second one.
		remove_action( 'wp_head', 'rel_canonical' );

		add_action( 'wp_head', array( $this, 'output' ), 1 );
		add_action( 'tagwright_seo_head', array( $this, 'print_description' ), 5 );
		add_action( 'tagwright_seo_head', array( $this, 'print_keywords' ), 6 );
		add_action( 'tagwright_seo_head', array( $this, 'print_canonical' ), 8 );
		add_action( 'tagwright_seo_head', array( $this, 'print_verification' ), 9 );
	}

	/**
	 * Replaces the document title.
	 *
	 * @param string $title Title WordPress would use.
	 * @return string
	 */
	public function filter_title( $title ) {
		$seo_title = Context::current()->get_title();

		return '' !== $seo_title ? esc_html( $seo_title ) : $title;
	}

	/**
	 * Merges our directives into the robots meta tag WordPress prints.
	 *
	 * @param array $robots Directives from WordPress and other plugins.
	 * @return array
	 */
	public function filter_robots( $robots ) {
		// When the site is set to discourage search engines WordPress already says noindex.
		if ( ! get_option( 'blog_public' ) ) {
			return $robots;
		}

		$ours = Context::current()->get_robots();

		if ( ! empty( $ours['noindex'] ) ) {
			unset( $robots['index'], $robots['max-image-preview'], $robots['max-snippet'], $robots['max-video-preview'] );
		}
		if ( ! empty( $ours['nofollow'] ) ) {
			unset( $robots['follow'] );
		}

		return array_merge( $robots, $ours );
	}

	/**
	 * Prints the Tagwright SEO block.
	 *
	 * @return void
	 */
	public function output() {
		echo "\n<!-- " . esc_html__( 'Search engine optimization by Tagwright SEO', 'tagwright-seo' ) . " -->\n";

		/**
		 * Prints tags inside the Tagwright SEO block in the head.
		 *
		 * Priorities used by the core: 5 description, 6 keywords, 8 canonical,
		 * 9 verification, 20 social, 30 schema.
		 *
		 * @param Context $context Current context.
		 */
		do_action( 'tagwright_seo_head', Context::current() );

		echo "<!-- /Tagwright SEO -->\n\n";
	}

	/**
	 * Meta description.
	 *
	 * @param Context $context Current context.
	 * @return void
	 */
	public function print_description( $context ) {
		$description = $context->get_description();

		if ( '' !== $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}
	}

	/**
	 * Meta keywords. Only printed when the legacy option is switched on.
	 *
	 * @param Context $context Current context.
	 * @return void
	 */
	public function print_keywords( $context ) {
		$keywords = $context->get_keywords();

		if ( '' !== $keywords ) {
			printf( '<meta name="keywords" content="%s" />' . "\n", esc_attr( $keywords ) );
		}
	}

	/**
	 * Canonical link.
	 *
	 * @param Context $context Current context.
	 * @return void
	 */
	public function print_canonical( $context ) {
		$canonical = $context->get_canonical();

		if ( '' !== $canonical ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
		}
	}

	/**
	 * Search engine verification codes. Only needed on the homepage.
	 *
	 * @param Context $context Current context.
	 * @return void
	 */
	public function print_verification( $context ) {
		if ( ! $context->is_front() ) {
			return;
		}

		$tags = array(
			'verify_google'    => 'google-site-verification',
			'verify_bing'      => 'msvalidate.01',
			'verify_yandex'    => 'yandex-verification',
			'verify_baidu'     => 'baidu-site-verification',
			'verify_pinterest' => 'p:domain_verify',
		);

		foreach ( $tags as $option => $name ) {
			$code = (string) Options::get( $option, '' );
			if ( '' !== $code ) {
				printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $name ), esc_attr( $code ) );
			}
		}
	}
}
