<?php
/**
 * llms.txt generator.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Technical;

use TagwrightSEO\Data\Objects;
use TagwrightSEO\Data\Stats;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Serves /llms.txt, a Markdown file that gives AI assistants a short map of
 * the site and its most useful pages. See https://llmstxt.org.
 */
class Llms_Txt {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rule' ), 5 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'stop_trailing_slash' ), 10, 2 );
	}

	/**
	 * Adds the rewrite rule.
	 *
	 * @return void
	 */
	public function add_rewrite_rule() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?tagwright_llms_txt=1', 'top' );
	}

	/**
	 * Registers the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'tagwright_llms_txt';

		return $vars;
	}

	/**
	 * Serves the file.
	 *
	 * @param \WP $wp WordPress environment.
	 * @return void
	 */
	public function maybe_serve( $wp ) {
		if ( empty( $wp->query_vars['tagwright_llms_txt'] ) ) {
			return;
		}

		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex', true );
		}

		echo self::generate(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text, cleaned while built.
		exit;
	}

	/**
	 * Builds the Markdown content.
	 *
	 * @return string
	 */
	public static function generate() {
		global $wpdb;

		$lines   = array();
		$lines[] = '# ' . self::clean( get_bloginfo( 'name' ) );
		$lines[] = '';

		$tagline = self::clean( get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = '> ' . $tagline;
			$lines[] = '';
		}

		$intro = trim( (string) Options::get( 'llms_intro', '' ) );
		if ( '' !== $intro ) {
			$lines[] = self::clean( $intro, true );
			$lines[] = '';
		}

		$limit     = max( 1, min( 500, (int) Options::get( 'llms_limit', 50 ) ) );
		$table     = Objects::table();
		$noindexed = Stats::noindexed_post_types();

		foreach ( (array) Options::get( 'llms_post_types', array() ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object || ! $object->public ) {
				continue;
			}

			// Rows left on the default robots setting follow the post type, so
			// the post type's own noindex setting is the fallback value here.
			$type_noindex = in_array( $post_type, $noindexed, true ) ? 1 : 0;

			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT object_id, permalink, description FROM %i WHERE object_type = 'post' AND object_sub_type = %s AND is_public = 1 AND COALESCE( robots_noindex, %d ) = 0 ORDER BY modified_at DESC LIMIT %d",
					$table,
					$post_type,
					$type_noindex,
					$limit
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				continue;
			}

			$lines[] = '## ' . self::clean( $object->labels->name );
			$lines[] = '';

			// One query for all posts instead of one per get_the_title() call.
			_prime_post_caches( array_map( 'intval', wp_list_pluck( $rows, 'object_id' ) ), false, false );

			foreach ( $rows as $row ) {
				$title       = self::clean( get_the_title( (int) $row['object_id'] ) );
				$description = self::clean( (string) $row['description'] );
				$line        = '- [' . ( '' !== $title ? $title : $row['permalink'] ) . '](' . esc_url_raw( $row['permalink'] ) . ')';

				// Skip descriptions that still contain template variables.
				if ( '' !== $description && false === strpos( $description, '%' ) ) {
					$line .= ': ' . $description;
				}

				$lines[] = $line;
			}

			$lines[] = '';
		}

		/**
		 * Filters the llms.txt content.
		 *
		 * @param string $content Markdown content.
		 */
		return apply_filters( 'tagwright_seo_llms_txt', implode( "\n", $lines ) );
	}

	/**
	 * Strips tags and line breaks so text is safe inside Markdown.
	 *
	 * @param string $text            Text.
	 * @param bool   $keep_paragraphs Whether to keep line breaks.
	 * @return string
	 */
	private static function clean( $text, $keep_paragraphs = false ) {
		$text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ), ! $keep_paragraphs );

		return trim( str_replace( array( '[', ']' ), array( '(', ')' ), $text ) );
	}

	/**
	 * Stops WordPress adding a trailing slash to /llms.txt.
	 *
	 * @param string $redirect_url  URL WordPress wants to redirect to.
	 * @param string $requested_url Requested URL.
	 * @return string|false
	 */
	public function stop_trailing_slash( $redirect_url, $requested_url ) {
		if ( '/llms.txt' === substr( (string) wp_parse_url( $requested_url, PHP_URL_PATH ), -9 ) ) {
			return false;
		}

		return $redirect_url;
	}
}
