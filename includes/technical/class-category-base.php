<?php
/**
 * Removes the /category/ base from category URLs.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Technical;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Turns example.com/category/news/ into example.com/news/.
 *
 * WordPress needs a rule per category for this to work, so the rules are
 * rebuilt whenever categories change. Old URLs keep working through a 301.
 */
class Category_Base {

	/**
	 * Registers hooks when the option is on, plus the flush trigger.
	 */
	public function __construct() {
		add_action( 'tagwright_seo_settings_updated', array( $this, 'maybe_flush' ) );

		if ( ! Options::get( 'strip_category_base' ) ) {
			return;
		}

		add_filter( 'category_link', array( $this, 'filter_link' ), 10, 2 );
		add_filter( 'category_rewrite_rules', array( $this, 'rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'request', array( $this, 'redirect_old_base' ) );

		foreach ( array( 'created_category', 'edited_category', 'delete_category' ) as $hook ) {
			add_action( $hook, array( $this, 'schedule_flush' ) );
		}
	}

	/**
	 * Removes the base from generated category links.
	 *
	 * @param string $link    Category link.
	 * @param int    $term_id Term id.
	 * @return string
	 */
	public function filter_link( $link, $term_id ) {
		$base = $this->base();

		return preg_replace( '#/' . preg_quote( $base, '#' ) . '/#', '/', $link, 1 );
	}

	/**
	 * Replaces the category rules with one rule per category.
	 *
	 * @param array $rules Rules WordPress generated.
	 * @return array
	 */
	public function rewrite_rules( $rules ) {
		global $wp_rewrite;

		$rules      = array();
		$prefix     = $this->front();
		$categories = get_categories( array( 'hide_empty' => false ) );

		foreach ( $categories as $category ) {
			$slug = $category->slug;
			if ( $category->parent && $category->parent !== $category->term_id ) {
				$slug = get_category_parents( $category->parent, false, '/', true ) . $slug;
			}

			$slug = trim( $slug, '/' );

			// Non-latin slugs are stored as lowercase %xx, but browsers send uppercase
			// or raw UTF-8. WordPress also tries the decoded request, so match that too.
			$decoded = urldecode( $slug );
			if ( $decoded !== $slug ) {
				$slug .= '|' . preg_quote( $decoded, '#' );
			}

			$rules[ $prefix . '(' . $slug . ')/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$' ] = 'index.php?category_name=$matches[1]&feed=$matches[2]';
			$rules[ $prefix . '(' . $slug . ')/' . $wp_rewrite->pagination_base . '/?([0-9]{1,})/?$' ] = 'index.php?category_name=$matches[1]&paged=$matches[2]';
			$rules[ $prefix . '(' . $slug . ')/?$' ] = 'index.php?category_name=$matches[1]';
		}

		// Catch the old /category/slug/ URLs and redirect them.
		$old_base = trim( str_replace( '%category%', '(.+)', $wp_rewrite->get_category_permastruct() ), '/' );
		$rules[ $old_base . '$' ] = 'index.php?tagwright_category_redirect=$matches[1]';

		return $rules;
	}

	/**
	 * Registers the redirect query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'tagwright_category_redirect';

		return $vars;
	}

	/**
	 * Redirects /category/slug/ to /slug/.
	 *
	 * @param array $query_vars Request query vars.
	 * @return array
	 */
	public function redirect_old_base( $query_vars ) {
		if ( isset( $query_vars['tagwright_category_redirect'] ) ) {
			$slug = user_trailingslashit( $query_vars['tagwright_category_redirect'], 'category' );
			wp_safe_redirect( home_url( '/' . $this->front() . ltrim( $slug, '/' ) ), 301, 'Tagwright SEO' );
			exit;
		}

		return $query_vars;
	}

	/**
	 * Asks for a rewrite flush on the next request.
	 *
	 * @return void
	 */
	public function schedule_flush() {
		update_option( 'tagwright_seo_flush_rewrite', 1, false );
	}

	/**
	 * Flushes when the option itself is changed.
	 *
	 * @param array $values Changed settings.
	 * @return void
	 */
	public function maybe_flush( $values ) {
		if ( array_key_exists( 'strip_category_base', $values ) ) {
			$this->schedule_flush();

			// Stored category links change too, so refresh them in the background.
			\TagwrightSEO\Data\Indexer::queue_full_index();
		}
	}

	/**
	 * Permalink front the category links keep, e.g. "blog/" for /blog/%postname%/.
	 *
	 * Mirrors core: category URLs use the front only with the default base
	 * (or with /index.php/ permalinks), see create_initial_taxonomies().
	 *
	 * @return string
	 */
	private function front() {
		global $wp_rewrite;

		if ( get_option( 'category_base' ) && ! $wp_rewrite->using_index_permalinks() ) {
			return '';
		}

		return ltrim( (string) $wp_rewrite->front, '/' );
	}

	/**
	 * Current category base, "category" unless changed in Settings > Permalinks.
	 *
	 * @return string
	 */
	private function base() {
		$base = trim( (string) get_option( 'category_base' ), '/' );

		return '' !== $base ? $base : 'category';
	}
}
