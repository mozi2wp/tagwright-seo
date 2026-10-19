<?php
/**
 * Builds sitemap XML from the objects table.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Sitemaps;

use TagwrightSEO\Data\Objects;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the sitemap index and the individual sitemaps.
 *
 * Every list comes from a single indexed query on tagwright_objects. Permalinks
 * are stored there already, so we never have to load post objects or call
 * get_permalink() thousands of times.
 *
 * Each URL can carry lastmod, changefreq and priority. The values come from
 * the item itself when an editor set them, otherwise from the content type
 * defaults, the same way XML Sitemap Generator for Google works.
 */
class Builder {

	/**
	 * Change frequencies allowed by the sitemap protocol.
	 *
	 * @var string[]
	 */
	const FREQUENCIES = array( 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never' );

	/**
	 * Entries per sitemap page.
	 *
	 * @var int
	 */
	private $per_page;

	/**
	 * Highest and average comment counts per post type, for automatic priorities.
	 *
	 * @var array
	 */
	private $comment_stats = array();

	/**
	 * Set up.
	 */
	public function __construct() {
		/**
		 * Filters the number of entries per sitemap page.
		 *
		 * @param int $per_page Entries per page. Google allows up to 50,000.
		 */
		$this->per_page = (int) apply_filters( 'tagwright_seo_sitemap_entries', max( 10, min( 50000, (int) Options::get( 'sitemap_max_entries', 1000 ) ) ) );
	}

	/**
	 * Translated labels for the change frequencies, for select fields.
	 *
	 * @return array<string, string>
	 */
	public static function frequency_options() {
		return array(
			'always'  => __( 'Always', 'tagwright-seo' ),
			'hourly'  => __( 'Hourly', 'tagwright-seo' ),
			'daily'   => __( 'Daily', 'tagwright-seo' ),
			'weekly'  => __( 'Weekly', 'tagwright-seo' ),
			'monthly' => __( 'Monthly', 'tagwright-seo' ),
			'yearly'  => __( 'Yearly', 'tagwright-seo' ),
			'never'   => __( 'Never', 'tagwright-seo' ),
		);
	}

	/**
	 * Priority choices from 0.0 to 1.0.
	 *
	 * @return array<string, string>
	 */
	public static function priority_options() {
		$options = array();
		for ( $i = 10; $i >= 0; $i-- ) {
			$value             = number_format( $i / 10, 1, '.', '' );
			$options[ $value ] = $value;
		}

		return $options;
	}

	/**
	 * Sitemaps that are switched on, keyed by their URL name.
	 *
	 * @return array<string, array{kind: string, name: string}>
	 */
	public function providers() {
		$providers = array();

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( Options::get( 'pt_' . $post_type . '_sitemap' ) ) {
				$providers[ $post_type ] = array(
					'kind' => 'post',
					'name' => $post_type,
				);
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			if ( Options::get( 'tax_' . $taxonomy . '_sitemap' ) && ! isset( $providers[ $taxonomy ] ) ) {
				$providers[ $taxonomy ] = array(
					'kind' => 'term',
					'name' => $taxonomy,
				);
			}
		}

		if ( Options::get( 'sitemap_authors' ) && ! Options::get( 'author_archive_noindex' ) && ! isset( $providers['author'] ) ) {
			$providers['author'] = array(
				'kind' => 'user',
				'name' => 'author',
			);
		}

		if ( Options::get( 'sitemap_archives' ) && ! Options::get( 'date_archive_noindex' ) && ! isset( $providers['archives'] ) ) {
			$providers['archives'] = array(
				'kind' => 'archive',
				'name' => 'archives',
			);
		}

		if ( self::custom_urls() && ! isset( $providers['additional'] ) ) {
			$providers['additional'] = array(
				'kind' => 'additional',
				'name' => 'additional',
			);
		}

		/**
		 * Filters the sitemap providers. Add-ons that output their own
		 * sitemaps (news, video) register them here with kind `custom` and a
		 * `callback` that returns array( 'count' => int, 'urls' => callable ).
		 * The urls callable receives the page and page size and returns rows
		 * with loc and optionally lastmod, changefreq, priority and images.
		 * The callback may also return `namespaces` (prefix => URI) and a
		 * `lastmod` callable (page, page size), and rows may then carry
		 * `xml`: extra elements for the <url>, escaped by the add-on.
		 *
		 * @param array $providers Providers keyed by sitemap name.
		 */
		return apply_filters( 'tagwright_seo_sitemap_providers', $providers );
	}

	/**
	 * Sitemap index XML.
	 *
	 * @return string
	 */
	public function index() {
		$entries = array();

		foreach ( $this->providers() as $key => $provider ) {
			$total = $this->count( $provider );
			$pages = (int) ceil( $total / $this->per_page );

			for ( $page = 1; $page <= $pages; $page++ ) {
				$entries[] = array(
					'loc'     => Router::url( $key, $page ),
					'lastmod' => $this->page_lastmod( $provider, $page ),
				);
			}
		}

		$with_lastmod = (bool) Options::get( 'sitemap_lastmod' );

		$xml = $this->open( 'sitemapindex' );
		foreach ( $entries as $entry ) {
			$xml .= "\t<sitemap>\n";
			$xml .= "\t\t<loc>" . $this->escape_url( $entry['loc'] ) . "</loc>\n";
			if ( $with_lastmod && $entry['lastmod'] ) {
				$xml .= "\t\t<lastmod>" . $this->w3c_date( $entry['lastmod'] ) . "</lastmod>\n";
			}
			$xml .= "\t</sitemap>\n";
		}
		$xml .= "</sitemapindex>\n";

		return $xml . $this->footer_comment();
	}

	/**
	 * One sitemap page.
	 *
	 * @param string $key  Sitemap name.
	 * @param int    $page Page number.
	 * @return string|null Null if the sitemap or page does not exist.
	 */
	public function sitemap( $key, $page ) {
		$providers = $this->providers();
		if ( ! isset( $providers[ $key ] ) ) {
			return null;
		}

		$urls = $this->urls( $providers[ $key ], $page );
		if ( empty( $urls ) && $page > 1 ) {
			return null;
		}

		// Custom providers (video, news) can declare extra XML namespaces.
		$namespaces = array();
		if ( 'custom' === $providers[ $key ]['kind'] ) {
			$data       = call_user_func( $providers[ $key ]['callback'] );
			$namespaces = isset( $data['namespaces'] ) ? (array) $data['namespaces'] : array();
		}

		$with_images    = (bool) Options::get( 'sitemap_images' );
		$with_lastmod   = (bool) Options::get( 'sitemap_lastmod' );
		$with_frequency = (bool) Options::get( 'sitemap_changefreq' );
		$with_priority  = (bool) Options::get( 'sitemap_priority' );

		$xml = $this->open( 'urlset', $with_images, $namespaces );
		foreach ( $urls as $url ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . $this->escape_url( $url['loc'] ) . "</loc>\n";
			if ( $with_lastmod && ! empty( $url['lastmod'] ) ) {
				$xml .= "\t\t<lastmod>" . $this->w3c_date( $url['lastmod'] ) . "</lastmod>\n";
			}
			if ( $with_frequency && ! empty( $url['changefreq'] ) && in_array( $url['changefreq'], self::FREQUENCIES, true ) ) {
				$xml .= "\t\t<changefreq>" . $url['changefreq'] . "</changefreq>\n";
			}
			if ( $with_priority && isset( $url['priority'] ) && '' !== $url['priority'] && null !== $url['priority'] ) {
				$xml .= "\t\t<priority>" . self::format_priority( $url['priority'] ) . "</priority>\n";
			}
			if ( $with_images && ! empty( $url['images'] ) ) {
				foreach ( $url['images'] as $image ) {
					$xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . $this->escape_url( $image ) . "</image:loc>\n\t\t</image:image>\n";
				}
			}
			// Extra elements from a custom provider, already escaped by it.
			if ( $namespaces && ! empty( $url['xml'] ) ) {
				$xml .= $url['xml'];
			}
			$xml .= "\t</url>\n";
		}
		$xml .= "</urlset>\n";

		return $xml . $this->footer_comment();
	}

	/**
	 * Number of URLs a provider will list.
	 *
	 * @param array $provider Provider.
	 * @return int
	 */
	public function count( array $provider ) {
		global $wpdb;

		switch ( $provider['kind'] ) {
			case 'custom':
				$data = call_user_func( $provider['callback'] );
				return (int) ( $data['count'] ?? 0 );

			case 'additional':
				return count( self::custom_urls() );

			case 'archive':
				return count( $this->archive_months() );
		}

		list( $params, $excluded ) = $this->conditions( $provider );

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i o
				WHERE o.object_type = %s AND o.object_sub_type = %s AND o.is_public = 1 AND o.sitemap_exclude = 0
				AND ( %d = 0 OR EXISTS ( SELECT 1 FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = o.object_id AND tt.taxonomy = o.object_sub_type AND tt.count > 0 ) )
				AND COALESCE( o.robots_noindex, %d ) = 0
				AND ( o.canonical IS NULL OR o.canonical = '' OR o.canonical = o.permalink )
				AND o.object_id NOT IN (" . implode( ',', array_fill( 0, count( $excluded ), '%d' ) ) . ')',
				$params
			)
		);

		// The homepage is listed at the top of the page sitemap when it shows latest posts.
		if ( $this->adds_homepage( $provider ) ) {
			++$count;
		}

		return $count;
	}

	/**
	 * URLs for one page of a provider.
	 *
	 * @param array $provider Provider.
	 * @param int   $page     Page number.
	 * @return array[] List of array( loc, lastmod, changefreq, priority, images ).
	 */
	public function urls( array $provider, $page ) {
		global $wpdb;

		$offset = ( $page - 1 ) * $this->per_page;

		switch ( $provider['kind'] ) {
			case 'custom':
				$data = call_user_func( $provider['callback'] );
				return isset( $data['urls'] ) ? (array) call_user_func( $data['urls'], $page, $this->per_page ) : array();

			case 'additional':
				return array_slice( $this->additional_urls(), $offset, $this->per_page );

			case 'archive':
				return array_slice( $this->archive_urls(), $offset, $this->per_page );
		}

		$limit    = $this->per_page;
		$urls     = array();
		$homepage = $this->adds_homepage( $provider );

		if ( $homepage ) {
			if ( 1 === $page ) {
				$urls[] = array(
					'loc'        => home_url( '/' ),
					'lastmod'    => get_lastpostmodified( 'gmt' ),
					'changefreq' => (string) Options::get( 'sitemap_home_freq' ),
					'priority'   => (string) Options::get( 'sitemap_home_priority' ),
					'images'     => array(),
				);
				--$limit;
			} else {
				--$offset;
			}
		}

		list( $params, $excluded ) = $this->conditions( $provider );

		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT o.object_id, o.permalink, o.modified_at, o.images, o.sitemap_priority, o.sitemap_changefreq, o.comment_count
				FROM %i o
				WHERE o.object_type = %s AND o.object_sub_type = %s AND o.is_public = 1 AND o.sitemap_exclude = 0
				AND ( %d = 0 OR EXISTS ( SELECT 1 FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = o.object_id AND tt.taxonomy = o.object_sub_type AND tt.count > 0 ) )
				AND COALESCE( o.robots_noindex, %d ) = 0
				AND ( o.canonical IS NULL OR o.canonical = '' OR o.canonical = o.permalink )
				AND o.object_id NOT IN (" . implode( ',', array_fill( 0, count( $excluded ), '%d' ) ) . ')' . '
				ORDER BY o.id ASC LIMIT %d OFFSET %d',
				$params
			),
			ARRAY_A
		);

		$defaults = $this->type_defaults( $provider );
		$home     = trailingslashit( home_url( '/' ) );

		foreach ( (array) $rows as $row ) {
			if ( empty( $row['permalink'] ) ) {
				continue;
			}

			$images = $row['images'] ? json_decode( $row['images'], true ) : array();

			// A static front page gets the homepage values unless it has its own.
			$is_home   = trailingslashit( $row['permalink'] ) === $home;
			$frequency = $is_home ? (string) Options::get( 'sitemap_home_freq' ) : $defaults['changefreq'];
			$priority  = $is_home ? (string) Options::get( 'sitemap_home_priority' ) : $this->priority_for( $provider, $row, $defaults['priority'] );

			$urls[] = array(
				'loc'        => $row['permalink'],
				'lastmod'    => $row['modified_at'],
				'changefreq' => ! empty( $row['sitemap_changefreq'] ) ? $row['sitemap_changefreq'] : $frequency,
				'priority'   => ( null !== $row['sitemap_priority'] && '' !== $row['sitemap_priority'] ) ? $row['sitemap_priority'] : $priority,
				'images'     => is_array( $images ) ? $images : array(),
			);
		}

		/**
		 * Filters the URLs on one sitemap page.
		 *
		 * @param array[] $urls     List of array( loc, lastmod, changefreq, priority, images ).
		 * @param array   $provider Provider.
		 * @param int     $page     Page number.
		 */
		return apply_filters( 'tagwright_seo_sitemap_urls', $urls, $provider, $page );
	}

	/**
	 * Default change frequency and priority for a provider.
	 *
	 * @param array $provider Provider.
	 * @return array{changefreq: string, priority: string}
	 */
	private function type_defaults( array $provider ) {
		switch ( $provider['kind'] ) {
			case 'post':
				$prefix = 'pt_' . $provider['name'] . '_';
				break;
			case 'term':
				$prefix = 'tax_' . $provider['name'] . '_';
				break;
			default:
				return array(
					'changefreq' => (string) Options::get( 'sitemap_author_freq' ),
					'priority'   => (string) Options::get( 'sitemap_author_priority' ),
				);
		}

		return array(
			'changefreq' => (string) Options::get( $prefix . 'sitemap_freq' ),
			'priority'   => (string) Options::get( $prefix . 'sitemap_priority' ),
		);
	}

	/**
	 * Works out a post's priority when automatic priorities are on.
	 *
	 * "comments" scales every post against the most commented one, so the
	 * busiest post gets 1.0. "comments_avg" scales the type default up or down
	 * depending on how a post compares with the average.
	 *
	 * @param array  $provider Provider.
	 * @param array  $row      Row with comment_count.
	 * @param string $fallback Type default.
	 * @return string
	 */
	private function priority_for( array $provider, array $row, $fallback ) {
		global $wpdb;

		$mode = (string) Options::get( 'sitemap_priority_mode', 'manual' );
		if ( 'post' !== $provider['kind'] || 'manual' === $mode ) {
			return $fallback;
		}

		$type = $provider['name'];
		if ( ! isset( $this->comment_stats[ $type ] ) ) {
			$table                        = Objects::table();
			$this->comment_stats[ $type ] = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT MAX(comment_count) AS max_count, AVG(comment_count) AS avg_count FROM %i WHERE object_type = 'post' AND object_sub_type = %s AND is_public = 1", $table, $type ),
				ARRAY_A
			);
		}

		$stats = $this->comment_stats[ $type ];
		$count = (int) $row['comment_count'];
		$max   = (float) ( $stats['max_count'] ?? 0 );
		$avg   = (float) ( $stats['avg_count'] ?? 0 );

		if ( $max <= 0 ) {
			return $fallback;
		}

		if ( 'comments' === $mode ) {
			$priority = 0.1 + 0.9 * ( $count / $max );
		} else {
			$priority = (float) $fallback * ( ( $count + 1 ) / ( $avg + 1 ) );
		}

		return self::format_priority( max( 0.1, min( 1.0, $priority ) ) );
	}

	/**
	 * Query parameters for a provider, in the order the provider queries use them.
	 *
	 * The SQL itself is written out in full in count(), urls() and
	 * page_lastmod() so every value goes through $wpdb->prepare(). Optional
	 * rules are switched on and off with a flag instead of changing the SQL:
	 *
	 * - Empty terms are only skipped when the flag is 1.
	 * - Items left on the default robots setting fall back to the type default.
	 * - The excluded id list is never empty; id 0 stands in when nothing is
	 *   excluded, since no object has that id.
	 *
	 * @param array $provider Provider.
	 * @return array{0: array, 1: int[]} Parameters (table first, excluded ids last) and the excluded ids.
	 */
	private function conditions( array $provider ) {
		$skip_empty_terms = 0;

		switch ( $provider['kind'] ) {
			case 'post':
				$default_noindex = (bool) Options::get( 'pt_' . $provider['name'] . '_noindex' );
				break;
			case 'term':
				$default_noindex  = (bool) Options::get( 'tax_' . $provider['name'] . '_noindex' );
				$skip_empty_terms = Options::get( 'noindex_empty_terms' ) ? 1 : 0;
				break;
			default:
				$default_noindex = (bool) Options::get( 'author_archive_noindex' );
		}

		$excluded = 'post' === $provider['kind'] ? $this->excluded_ids() : array();
		if ( empty( $excluded ) ) {
			$excluded = array( 0 );
		}

		$params = array_merge(
			array(
				Objects::table(),
				$provider['kind'],
				'user' === $provider['kind'] ? 'author' : $provider['name'],
				$skip_empty_terms,
				$default_noindex ? 1 : 0,
			),
			$excluded
		);

		return array( $params, $excluded );
	}

	/**
	 * Last modified date for a sitemap page in the index.
	 *
	 * @param array $provider Provider.
	 * @param int   $page     Page number.
	 * @return string|null
	 */
	private function page_lastmod( array $provider, $page ) {
		global $wpdb;

		if ( in_array( $provider['kind'], array( 'archive', 'additional' ), true ) ) {
			$dates = array_filter( wp_list_pluck( array_slice( $this->urls( $provider, $page ), 0 ), 'lastmod' ) );
			return $dates ? max( $dates ) : null;
		}

		if ( 'custom' === $provider['kind'] ) {
			$data = call_user_func( $provider['callback'] );
			return isset( $data['lastmod'] ) && is_callable( $data['lastmod'] ) ? call_user_func( $data['lastmod'], $page, $this->per_page ) : null;
		}

		list( $params, $excluded ) = $this->conditions( $provider );

		$params[] = $this->per_page;
		$params[] = ( $page - 1 ) * $this->per_page;

		return $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(modified_at) FROM (
					SELECT o.modified_at FROM %i o
					WHERE o.object_type = %s AND o.object_sub_type = %s AND o.is_public = 1 AND o.sitemap_exclude = 0
					AND ( %d = 0 OR EXISTS ( SELECT 1 FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = o.object_id AND tt.taxonomy = o.object_sub_type AND tt.count > 0 ) )
					AND COALESCE( o.robots_noindex, %d ) = 0
					AND ( o.canonical IS NULL OR o.canonical = '' OR o.canonical = o.permalink )
					AND o.object_id NOT IN (" . implode( ',', array_fill( 0, count( $excluded ), '%d' ) ) . ')' . '
					ORDER BY o.id ASC LIMIT %d OFFSET %d
				) AS chunk',
				$params
			)
		);
	}

	/**
	 * Months that have published posts, newest first.
	 *
	 * @return array[] Rows with year, month and lastmod.
	 */
	private function archive_months() {
		global $wpdb;

		static $months = null;

		if ( null === $months ) {
			$months = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT YEAR(post_date) AS year, MONTH(post_date) AS month, MAX(post_modified_gmt) AS lastmod
				FROM {$wpdb->posts}
				WHERE post_type = 'post' AND post_status = 'publish'
				GROUP BY YEAR(post_date), MONTH(post_date)
				ORDER BY year DESC, month DESC",
				ARRAY_A
			);
		}

		return $months;
	}

	/**
	 * Monthly date archive URLs.
	 *
	 * @return array[]
	 */
	private function archive_urls() {
		$current = wp_date( 'Y-n' );
		$urls    = array();

		foreach ( $this->archive_months() as $month ) {
			$is_current = ( (int) $month['year'] . '-' . (int) $month['month'] ) === $current;

			$urls[] = array(
				'loc'        => get_month_link( (int) $month['year'], (int) $month['month'] ),
				'lastmod'    => $month['lastmod'],
				'changefreq' => (string) Options::get( $is_current ? 'sitemap_archive_freq' : 'sitemap_archive_old_freq' ),
				'priority'   => (string) Options::get( 'sitemap_archive_priority' ),
				'images'     => array(),
			);
		}

		return $urls;
	}

	/**
	 * Additional pages entered in the settings, in sitemap row form.
	 *
	 * @return array[]
	 */
	private function additional_urls() {
		$urls = array();

		foreach ( self::custom_urls() as $item ) {
			$lastmod = '';
			if ( ! empty( $item['lastmod'] ) ) {
				// Stored as a local Y-m-d date; the sitemap wants GMT.
				$lastmod = get_gmt_from_date( $item['lastmod'] . ' 00:00:00' );
			}

			$urls[] = array(
				'loc'        => $item['url'],
				'lastmod'    => $lastmod,
				'changefreq' => $item['changefreq'] ?? '',
				'priority'   => $item['priority'] ?? '',
				'images'     => array(),
			);
		}

		return $urls;
	}

	/**
	 * Valid additional URLs from the settings.
	 *
	 * @return array[]
	 */
	public static function custom_urls() {
		$items = Options::get( 'sitemap_custom_urls', array() );

		return array_values(
			array_filter(
				is_array( $items ) ? $items : array(),
				static fn( $item ) => is_array( $item ) && ! empty( $item['url'] )
			)
		);
	}

	/**
	 * Whether this provider lists the homepage at the top.
	 *
	 * @param array $provider Provider.
	 * @return bool
	 */
	private function adds_homepage( array $provider ) {
		return 'post' === $provider['kind'] && 'page' === $provider['name'] && 'page' !== get_option( 'show_on_front' );
	}

	/**
	 * Post ids excluded in the settings.
	 *
	 * @return int[]
	 */
	private function excluded_ids() {
		return array_values( array_filter( array_map( 'absint', explode( ',', (string) Options::get( 'sitemap_exclude_ids', '' ) ) ) ) );
	}

	/**
	 * Formats a priority as 0.0 to 1.0 with one decimal.
	 *
	 * @param float|string $priority Priority.
	 * @return string
	 */
	public static function format_priority( $priority ) {
		return number_format( max( 0, min( 1, (float) $priority ) ), 1, '.', '' );
	}

	/**
	 * XML prolog and opening tag.
	 *
	 * @param string $root        Root element.
	 * @param bool   $with_images Whether to declare the image namespace.
	 * @return string
	 */
	private function open( $root, $with_images = false, array $namespaces = array() ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<?xml-stylesheet type="text/xsl" href="' . $this->escape_url( Router::xsl_url() ) . '"?>' . "\n";
		$xml .= '<' . $root . ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		if ( $with_images ) {
			$xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
		}
		foreach ( $namespaces as $prefix => $uri ) {
			if ( preg_match( '/^[a-z]+$/', (string) $prefix ) && ( 'image' !== $prefix || ! $with_images ) ) {
				$xml .= ' xmlns:' . $prefix . '="' . esc_attr( $uri ) . '"';
			}
		}
		$xml .= ">\n";

		return $xml;
	}

	/**
	 * Small comment with generation info, handy when debugging caches.
	 *
	 * @return string
	 */
	private function footer_comment() {
		return '<!-- ' . sprintf( 'Tagwright SEO sitemap generated in %s seconds', number_format( timer_stop(), 3 ) ) . " -->\n";
	}

	/**
	 * Escapes a URL for XML.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function escape_url( $url ) {
		return htmlspecialchars( esc_url_raw( $url ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Formats a GMT MySQL date as W3C datetime.
	 *
	 * @param string $date MySQL date in GMT.
	 * @return string
	 */
	private function w3c_date( $date ) {
		return esc_html( mysql2date( DATE_W3C, get_date_from_gmt( $date ), false ) );
	}
}
