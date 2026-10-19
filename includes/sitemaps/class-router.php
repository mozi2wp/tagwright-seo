<?php
/**
 * Sitemap URLs and responses.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Sitemaps;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the sitemap URLs and serves them before WordPress runs its main
 * query, so a sitemap request costs a couple of indexed lookups and nothing
 * else.
 */
class Router {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		// Our sitemaps replace the ones built into WordPress.
		add_filter( 'wp_sitemaps_enabled', '__return_false' );

		add_action( 'init', array( $this, 'add_rewrite_rules' ), 5 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 1 );
		add_filter( 'robots_txt', array( $this, 'add_to_robots_txt' ), 20, 2 );
		add_filter( 'redirect_canonical', array( $this, 'stop_trailing_slash' ), 10, 2 );
		add_action( 'tagwright_seo_settings_updated', array( $this, 'maybe_flush' ) );
	}

	/**
	 * The index file name without extension, as chosen in the settings.
	 *
	 * @return string
	 */
	public static function index_name() {
		$name = self::sanitize_name( (string) Options::get( 'sitemap_index_name', 'sitemap_index' ) );

		return '' !== $name ? $name : 'sitemap_index';
	}

	/**
	 * Cleans a file name: lowercase letters, numbers, dashes and underscores,
	 * without the .xml extension.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function sanitize_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		$name = preg_replace( '/\.xml(\.gz)?$/', '', $name );
		$name = preg_replace( '/[^a-z0-9_-]/', '', $name );

		// Names that look like a section sitemap would clash with those URLs.
		if ( preg_match( '/-sitemap[0-9]*$/', $name ) ) {
			$name = '';
		}

		return $name;
	}

	/**
	 * Rewrite rules depend on the index name, so flush them when it changes.
	 *
	 * @param array $values Changed settings.
	 * @return void
	 */
	public function maybe_flush( $values ) {
		if ( array_key_exists( 'sitemap_index_name', $values ) ) {
			update_option( 'tagwright_seo_flush_rewrite', 1, false );
		}
	}

	/**
	 * URL of the sitemap index.
	 *
	 * @return string
	 */
	public static function index_url() {
		// Plain permalinks have no rewrite rules, but the public query var still works.
		if ( ! self::pretty() ) {
			return add_query_arg( 'tagwright_sitemap', 'index', home_url( '/' ) );
		}

		return home_url( '/' . self::index_name() . '.xml' );
	}

	/**
	 * URL of one section sitemap, e.g. post-sitemap2.xml.
	 *
	 * @param string $key  Sitemap key (provider name).
	 * @param int    $page Page number, 1 for the first.
	 * @return string
	 */
	public static function url( $key, $page = 1 ) {
		$page = max( 1, (int) $page );

		if ( ! self::pretty() ) {
			$args = array( 'tagwright_sitemap' => $key );
			if ( $page > 1 ) {
				$args['tagwright_sitemap_page'] = $page;
			}
			return add_query_arg( $args, home_url( '/' ) );
		}

		return home_url( '/' . $key . '-sitemap' . ( $page > 1 ? $page : '' ) . '.xml' );
	}

	/**
	 * URL of the XSL stylesheet.
	 *
	 * @return string
	 */
	public static function xsl_url() {
		if ( ! self::pretty() ) {
			return add_query_arg( 'tagwright_sitemap', 'xsl', home_url( '/' ) );
		}

		return home_url( '/tagwright-seo-sitemap.xsl' );
	}

	/**
	 * Whether the site uses pretty permalinks, so our rewrite rules apply.
	 *
	 * @return bool
	 */
	private static function pretty() {
		global $wp_rewrite;

		return $wp_rewrite && $wp_rewrite->using_permalinks();
	}

	/**
	 * Adds the rewrite rules.
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		$name = preg_quote( self::index_name(), '/' );

		add_rewrite_rule( '^' . $name . '\.xml(\.gz)?$', 'index.php?tagwright_sitemap=index&tagwright_sitemap_gz=$matches[1]', 'top' );
		add_rewrite_rule( '^([a-z0-9_-]+)-sitemap([0-9]+)?\.xml(\.gz)?$', 'index.php?tagwright_sitemap=$matches[1]&tagwright_sitemap_page=$matches[2]&tagwright_sitemap_gz=$matches[3]', 'top' );
		add_rewrite_rule( '^tagwright-seo-sitemap\.xsl$', 'index.php?tagwright_sitemap=xsl', 'top' );

		// Send people and bots that guess the usual names to the right place.
		foreach ( array( 'sitemap', 'sitemap_index', 'wp-sitemap' ) as $guess ) {
			if ( self::index_name() !== $guess ) {
				add_rewrite_rule( '^' . preg_quote( $guess, '/' ) . '\.xml$', 'index.php?tagwright_sitemap=redirect', 'top' );
			}
		}
	}

	/**
	 * Registers our query vars.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'tagwright_sitemap';
		$vars[] = 'tagwright_sitemap_page';
		$vars[] = 'tagwright_sitemap_gz';

		return $vars;
	}

	/**
	 * Serves the sitemap when one was requested.
	 *
	 * @param \WP $wp Current WordPress environment.
	 * @return void
	 */
	public function maybe_serve( $wp ) {
		if ( empty( $wp->query_vars['tagwright_sitemap'] ) ) {
			return;
		}

		$name = sanitize_key( $wp->query_vars['tagwright_sitemap'] );
		$page = isset( $wp->query_vars['tagwright_sitemap_page'] ) ? max( 1, absint( $wp->query_vars['tagwright_sitemap_page'] ) ) : 1;

		if ( 'redirect' === $name ) {
			wp_safe_redirect( self::index_url(), 301, 'Tagwright SEO' );
			exit;
		}

		if ( 'xsl' === $name ) {
			$this->send_headers( 'text/xsl' );
			echo Stylesheet::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static XSL, escaped while built.
			exit;
		}

		$builder = new Builder();
		$xml     = 'index' === $name ? $builder->index() : $builder->sitemap( $name, $page );

		if ( null === $xml ) {
			// Unknown sitemap or a page past the end: let WordPress show its 404.
			$wp->query_vars['error'] = '404';
			unset( $wp->query_vars['tagwright_sitemap'], $wp->query_vars['tagwright_sitemap_page'] );
			return;
		}

		// Compressed copies are served at the same URL with .gz on the end.
		if ( ! empty( $wp->query_vars['tagwright_sitemap_gz'] ) ) {
			if ( ! Options::get( 'sitemap_gzip' ) || ! function_exists( 'gzencode' ) ) {
				$wp->query_vars['error'] = '404';
				unset( $wp->query_vars['tagwright_sitemap'], $wp->query_vars['tagwright_sitemap_page'], $wp->query_vars['tagwright_sitemap_gz'] );
				return;
			}

			$this->send_headers( 'application/x-gzip' );
			echo gzencode( $xml, 9 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary gzip of escaped XML.
			exit;
		}

		$this->send_headers( 'application/xml' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value is escaped by the builder.
		exit;
	}

	/**
	 * Sends response headers.
	 *
	 * @param string $content_type MIME type.
	 * @return void
	 */
	private function send_headers( $content_type ) {
		if ( headers_sent() ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
		// Sitemaps are for bots to read, not to rank.
		header( 'X-Robots-Tag: noindex, follow', true );
		header( 'Cache-Control: max-age=' . HOUR_IN_SECONDS . ', public' );
	}

	/**
	 * Adds the sitemap line to the virtual robots.txt.
	 *
	 * @param string $output Robots.txt content.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function add_to_robots_txt( $output, $public ) {
		if ( ! $public || ! Options::get( 'sitemap_robots_txt' ) ) {
			return $output;
		}

		if ( false !== stripos( $output, self::index_url() ) ) {
			return $output;
		}

		return rtrim( $output ) . "\n\nSitemap: " . esc_url_raw( self::index_url() ) . "\n";
	}

	/**
	 * Stops WordPress adding a trailing slash to .xml and .xsl URLs.
	 *
	 * @param string $redirect_url  URL WordPress wants to redirect to.
	 * @param string $requested_url Requested URL.
	 * @return string|false
	 */
	public function stop_trailing_slash( $redirect_url, $requested_url ) {
		if ( preg_match( '#\.(xml|xsl|gz)$#i', (string) wp_parse_url( $requested_url, PHP_URL_PATH ) ) ) {
			return false;
		}

		return $redirect_url;
	}
}
