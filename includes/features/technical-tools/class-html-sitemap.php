<?php
/**
 * HTML sitemap.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Technical_Tools;

use TagwrightSEO\Data\Objects;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A list of links to every indexable page, for visitors.
 *
 * Built from the Tagwright SEO objects table with one query per section and the
 * same rules as the XML sitemap (public, not noindexed, not excluded, no
 * canonical pointing elsewhere). The finished HTML is cached in a single
 * transient and thrown away whenever an object row changes.
 */
class Html_Sitemap {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'tagwright_html_sitemap';

	/**
	 * Block name.
	 */
	const BLOCK = 'tagwright-seo/html-sitemap';

	/**
	 * Transient with the rendered variants.
	 */
	const CACHE_KEY = 'tagwright_seo_technical_tools_html_sitemap';

	/**
	 * Whether the cache was already cleared during this request.
	 *
	 * @var bool
	 */
	private static $flushed = false;

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );

		add_action( 'tagwright_seo_object_saved', array( __CLASS__, 'flush_cache' ) );
		add_action( 'tagwright_seo_object_deleted', array( __CLASS__, 'flush_cache' ) );
		add_action( 'tagwright_seo_settings_updated', array( __CLASS__, 'flush_cache' ) );

		if ( (int) Options::get( 'technical_tools_sitemap_page', 0 ) > 0 ) {
			add_filter( 'the_content', array( $this, 'append_to_page' ), 20 );
		}
	}

	/**
	 * Registers the dynamic block. The editor script only loads in the editor.
	 *
	 * @return void
	 */
	public function register_block() {
		wp_register_script(
			'tagwright-seo-technical-tools-block',
			TAGWRIGHT_SEO_URL . 'assets/features/technical-tools/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			TAGWRIGHT_SEO_VERSION,
			true
		);
		wp_set_script_translations( 'tagwright-seo-technical-tools-block', 'tagwright-seo' );

		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'HTML Sitemap', 'tagwright-seo' ),
				'description'     => __( 'A list of links to your pages, posts and categories.', 'tagwright-seo' ),
				'category'        => 'widgets',
				'icon'            => 'networking',
				'editor_script'   => 'tagwright-seo-technical-tools-block',
				'render_callback' => array( $this, 'render_block' ),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
				'attributes'      => array(
					'className' => array( 'type' => 'string' ),
					'align'     => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Block output.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$html = self::render();
		if ( '' === $html ) {
			return '';
		}

		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'tagwright-html-sitemap' ) ) : 'class="tagwright-html-sitemap"';

		return '<div ' . $wrapper . '>' . $html . '</div>';
	}

	/**
	 * Shortcode output.
	 *
	 * @return string
	 */
	public function shortcode() {
		$html = self::render();

		return '' !== $html ? '<div class="tagwright-html-sitemap">' . $html . '</div>' : '';
	}

	/**
	 * Adds the sitemap to the chosen page.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_to_page( $content ) {
		$page_id = (int) Options::get( 'technical_tools_sitemap_page', 0 );

		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() || get_the_ID() !== $page_id ) {
			return $content;
		}

		// Do not print it twice when the page already has the block or shortcode.
		if ( has_shortcode( $content, self::SHORTCODE ) || false !== strpos( $content, 'tagwright-html-sitemap' ) ) {
			return $content;
		}

		return $content . $this->shortcode();
	}

	/**
	 * The sitemap HTML for the saved settings, from cache when possible.
	 *
	 * @return string
	 */
	public static function render() {
		$args = self::args();
		$hash = md5( (string) wp_json_encode( $args ) );

		$cache = get_transient( self::CACHE_KEY );
		$cache = is_array( $cache ) ? $cache : array();

		if ( isset( $cache[ $hash ] ) ) {
			return (string) $cache[ $hash ];
		}

		$html = self::build( $args );

		// Keep a couple of variants (for example right after a settings change), never a growing pile.
		$cache          = array_slice( $cache, -2, 2, true );
		$cache[ $hash ] = $html;
		set_transient( self::CACHE_KEY, $cache, DAY_IN_SECONDS );

		return $html;
	}

	/**
	 * Settings that shape the output.
	 *
	 * @return array
	 */
	private static function args() {
		return array(
			'post_types'   => array_values( (array) Options::get( 'technical_tools_sitemap_post_types', array() ) ),
			'taxonomies'   => array_values( (array) Options::get( 'technical_tools_sitemap_taxonomies', array() ) ),
			'order'        => 'date' === Options::get( 'technical_tools_sitemap_order' ) ? 'date' : 'title',
			'dates'        => (bool) Options::get( 'technical_tools_sitemap_dates' ),
			'hierarchical' => (bool) Options::get( 'technical_tools_sitemap_hierarchical' ),
			'exclude'      => self::id_list( (string) Options::get( 'technical_tools_sitemap_exclude', '' ) ),
			'limit'        => max( 10, min( 5000, (int) Options::get( 'technical_tools_sitemap_limit', 500 ) ) ),
			'locale'       => get_locale(),
		);
	}

	/**
	 * Builds the HTML.
	 *
	 * @param array $args Arguments from args().
	 * @return string
	 */
	public static function build( array $args ) {
		$sections = array();

		foreach ( $args['post_types'] as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object || ! is_post_type_viewable( $object ) ) {
				continue;
			}

			$items = self::post_items( $post_type, $args );
			if ( $items ) {
				$tree       = $args['hierarchical'] && $object->hierarchical ? self::tree( $items ) : $items;
				$sections[] = self::section( $object->labels->name, $tree, $args['dates'] && ! $object->hierarchical, 'post-type-' . $post_type );
			}
		}

		foreach ( $args['taxonomies'] as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( ! $object || ! is_taxonomy_viewable( $object ) ) {
				continue;
			}

			$items = self::term_items( $taxonomy, $args );
			if ( $items ) {
				$tree       = $args['hierarchical'] && $object->hierarchical ? self::tree( $items ) : $items;
				$sections[] = self::section( $object->labels->name, $tree, false, 'taxonomy-' . $taxonomy );
			}
		}

		return implode( "\n", $sections );
	}

	/**
	 * Indexable posts of one type.
	 *
	 * @param string $post_type Post type.
	 * @param array  $args      Arguments.
	 * @return array[] Items with id, parent, title, url and date.
	 */
	private static function post_items( $post_type, array $args ) {
		global $wpdb;

		$table   = Objects::table();
		$noindex = Options::get( 'pt_' . $post_type . '_noindex' ) ? 'o.robots_noindex = 0' : '( o.robots_noindex IS NULL OR o.robots_noindex = 0 )';
		$exclude = array_unique( array_merge( $args['exclude'], self::id_list( (string) Options::get( 'sitemap_exclude_ids', '' ) ) ) );
		$params  = array( $post_type );
		$where   = '';

		if ( $exclude ) {
			$where  = ' AND o.object_id NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
			$params = array_merge( $params, $exclude );
		}

		$order    = 'date' === $args['order'] ? 'p.post_date DESC' : 'p.menu_order ASC, p.post_title ASC';
		$params[] = (int) $args['limit'];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names and the noindex clause come from code; every value is a placeholder.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cached by render().
			$wpdb->prepare(
				"SELECT o.object_id, o.permalink, p.post_title, p.post_parent, p.post_date
				FROM {$table} o
				INNER JOIN {$wpdb->posts} p ON p.ID = o.object_id
				WHERE o.object_type = 'post' AND o.object_sub_type = %s AND o.is_public = 1 AND o.sitemap_exclude = 0
				AND {$noindex}
				AND ( o.canonical IS NULL OR o.canonical = '' OR o.canonical = o.permalink ){$where}
				ORDER BY {$order}
				LIMIT %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['permalink'] ) ) {
				continue;
			}

			$items[] = array(
				'id'     => (int) $row['object_id'],
				'parent' => (int) $row['post_parent'],
				'title'  => '' !== trim( (string) $row['post_title'] ) ? $row['post_title'] : $row['permalink'],
				'url'    => $row['permalink'],
				'date'   => $row['post_date'],
			);
		}

		return $items;
	}

	/**
	 * Indexable terms of one taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param array  $args     Arguments.
	 * @return array[] Items with id, parent, title and url.
	 */
	private static function term_items( $taxonomy, array $args ) {
		global $wpdb;

		$table   = Objects::table();
		$noindex = Options::get( 'tax_' . $taxonomy . '_noindex' ) ? 'o.robots_noindex = 0' : '( o.robots_noindex IS NULL OR o.robots_noindex = 0 )';
		$empty   = Options::get( 'noindex_empty_terms' ) ? ' AND tt.count > 0' : '';
		$params  = array( $taxonomy );
		$where   = '';

		if ( $args['exclude'] ) {
			$where  = ' AND o.object_id NOT IN (' . implode( ',', array_fill( 0, count( $args['exclude'] ), '%d' ) ) . ')';
			$params = array_merge( $params, $args['exclude'] );
		}

		$order    = 'date' === $args['order'] ? 'tt.count DESC, t.name ASC' : 't.name ASC';
		$params[] = (int) $args['limit'];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names and fixed clauses come from code; every value is a placeholder.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cached by render().
			$wpdb->prepare(
				"SELECT o.object_id, o.permalink, t.name, tt.parent
				FROM {$table} o
				INNER JOIN {$wpdb->terms} t ON t.term_id = o.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = o.object_id AND tt.taxonomy = o.object_sub_type
				WHERE o.object_type = 'term' AND o.object_sub_type = %s AND o.is_public = 1 AND o.sitemap_exclude = 0
				AND {$noindex}{$empty}
				AND ( o.canonical IS NULL OR o.canonical = '' OR o.canonical = o.permalink ){$where}
				ORDER BY {$order}
				LIMIT %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable

		$items = array();
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['permalink'] ) ) {
				continue;
			}

			$items[] = array(
				'id'     => (int) $row['object_id'],
				'parent' => (int) $row['parent'],
				'title'  => $row['name'],
				'url'    => $row['permalink'],
				'date'   => '',
			);
		}

		return $items;
	}

	/**
	 * Nests items under their parents. Items whose parent is not listed
	 * (excluded, noindexed or past the limit) move up to the top level.
	 *
	 * @param array[] $items Flat items in display order.
	 * @return array[] Top level items, each with `children`.
	 */
	public static function tree( array $items ) {
		$by_id = array();
		foreach ( $items as $item ) {
			$item['children']     = array();
			$by_id[ $item['id'] ] = $item;
		}

		$roots = array();
		foreach ( $items as $item ) {
			$parent = $item['parent'];
			if ( $parent && isset( $by_id[ $parent ] ) && $parent !== $item['id'] ) {
				$by_id[ $parent ]['children'][] = $item['id'];
			} else {
				$roots[] = $item['id'];
			}
		}

		$build = static function ( $id, $depth ) use ( &$build, &$by_id ) {
			$node     = $by_id[ $id ];
			$children = array();

			// Guards against parent loops in broken data.
			if ( $depth < 20 ) {
				foreach ( $node['children'] as $child_id ) {
					$children[] = $build( $child_id, $depth + 1 );
				}
			}

			$node['children'] = $children;

			return $node;
		};

		$tree = array();
		foreach ( $roots as $id ) {
			$tree[] = $build( $id, 0 );
		}

		return $tree;
	}

	/**
	 * One heading and its list.
	 *
	 * @param string  $title      Section title.
	 * @param array[] $items      Items, optionally nested.
	 * @param bool    $show_dates Whether to print dates.
	 * @param string  $modifier   Class modifier.
	 * @return string
	 */
	private static function section( $title, array $items, $show_dates, $modifier ) {
		return sprintf(
			'<section class="tagwright-html-sitemap__section tagwright-html-sitemap__section--%1$s"><h2>%2$s</h2>%3$s</section>',
			esc_attr( sanitize_html_class( $modifier ) ),
			esc_html( $title ),
			self::list_html( $items, $show_dates )
		);
	}

	/**
	 * A nested list.
	 *
	 * @param array[] $items      Items.
	 * @param bool    $show_dates Whether to print dates.
	 * @return string
	 */
	private static function list_html( array $items, $show_dates ) {
		$html = '<ul>';

		foreach ( $items as $item ) {
			$html .= '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( wp_strip_all_tags( html_entity_decode( (string) $item['title'], ENT_QUOTES, 'UTF-8' ) ) ) . '</a>';

			if ( $show_dates && ! empty( $item['date'] ) ) {
				$html .= ' <time datetime="' . esc_attr( mysql2date( 'c', $item['date'], false ) ) . '">' . esc_html( mysql2date( get_option( 'date_format' ), $item['date'] ) ) . '</time>';
			}

			if ( ! empty( $item['children'] ) ) {
				$html .= self::list_html( $item['children'], $show_dates );
			}

			$html .= '</li>';
		}

		return $html . '</ul>';
	}

	/**
	 * Throws away the cached HTML. Many objects can be saved in one
	 * request (an import, a full index), so this runs once per request.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		if ( self::$flushed ) {
			return;
		}

		self::$flushed = true;
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Turns "12, 34,x" into array( 12, 34 ).
	 *
	 * @param string $value Raw list.
	 * @return int[]
	 */
	public static function id_list( $value ) {
		return array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) ) );
	}

	/**
	 * Sanitizer for the exclude field.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_ids( $value ) {
		return implode( ', ', self::id_list( is_scalar( $value ) ? (string) $value : '' ) );
	}

	/**
	 * Pages for the "Add to page" dropdown. Capped so huge sites stay fast;
	 * the saved page is always included.
	 *
	 * @return array<string, string>
	 */
	public static function page_options() {
		$options = array( '0' => __( '— None —', 'tagwright-seo' ) );

		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'numberposts'            => 300,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$saved = (int) Options::get( 'technical_tools_sitemap_page', 0 );
		if ( $saved && ! in_array( $saved, wp_list_pluck( $pages, 'ID' ), true ) ) {
			$page = get_post( $saved );
			if ( $page ) {
				$pages[] = $page;
			}
		}

		foreach ( $pages as $page ) {
			$title                         = get_the_title( $page );
			$options[ (string) $page->ID ] = '' !== $title ? $title : '#' . $page->ID;
		}

		return $options;
	}
}
