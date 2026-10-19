<?php
/**
 * Works out what the current page is and what its SEO data should be.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Frontend;

use TagwrightSEO\Data\Objects;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A resolved page: its type, the WordPress object behind it, the stored SEO
 * row and helpers that turn settings and overrides into final values.
 *
 * Every front end component asks the context instead of calling the
 * conditional tags itself, so the logic lives in exactly one place.
 */
class Context {

	/**
	 * Context for the main query, built once per request.
	 *
	 * @var Context|null
	 */
	private static $current = null;

	/**
	 * Page type: singular, front_page, home, posts_page, term, author,
	 * post_type_archive, date, search, not_found or other.
	 *
	 * @var string
	 */
	public $type = 'other';

	/**
	 * The queried object, if any.
	 *
	 * @var \WP_Post|\WP_Term|\WP_User|\WP_Post_Type|null
	 */
	public $object = null;

	/**
	 * Stored SEO row for the object.
	 *
	 * @var array|null
	 */
	public $row = null;

	/**
	 * Current page number for paginated archives and posts.
	 *
	 * @var int
	 */
	public $paged = 1;

	/**
	 * Total pages, when known.
	 *
	 * @var int
	 */
	public $max_pages = 1;

	/**
	 * Memoized results.
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * Context for the current main query.
	 *
	 * @return Context
	 */
	public static function current() {
		if ( null === self::$current ) {
			self::$current = self::from_query();
		}

		return self::$current;
	}

	/**
	 * Forgets the cached context. Useful when a plugin changes the main query.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$current = null;
	}

	/**
	 * Builds a context from the global query.
	 *
	 * @return Context
	 */
	private static function from_query() {
		global $wp_query;

		$context            = new self();
		$context->paged     = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		$context->max_pages = $wp_query ? max( 1, (int) $wp_query->max_num_pages ) : 1;

		if ( is_404() ) {
			$context->type = 'not_found';
		} elseif ( is_search() ) {
			$context->type = 'search';
		} elseif ( is_front_page() && is_home() ) {
			$context->type = 'home';
		} elseif ( is_front_page() ) {
			$context->type = 'front_page';
			$context->set_object( 'post', get_queried_object() );
		} elseif ( is_home() ) {
			$context->type = 'posts_page';
			$context->set_object( 'post', get_queried_object() );
		} elseif ( is_singular() ) {
			$context->type = 'singular';
			$context->set_object( 'post', get_queried_object() );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$context->type = 'term';
			$context->set_object( 'term', get_queried_object() );
		} elseif ( is_author() ) {
			$context->type = 'author';
			$context->set_object( 'user', get_queried_object() );
		} elseif ( is_post_type_archive() ) {
			$context->type   = 'post_type_archive';
			$post_type       = get_query_var( 'post_type' );
			$post_type       = is_array( $post_type ) ? reset( $post_type ) : $post_type;
			$context->object = get_post_type_object( $post_type );
		} elseif ( is_date() ) {
			$context->type = 'date';
		}

		// Paginated single posts use <!--nextpage-->, so count those pages.
		if ( $context->object instanceof \WP_Post ) {
			$context->max_pages = max( 1, substr_count( $context->object->post_content, '<!--nextpage-->' ) + 1 );
		}

		/**
		 * Filters the resolved context, for example to support a custom page type.
		 *
		 * @param Context $context Resolved context.
		 */
		return apply_filters( 'tagwright_seo_context', $context );
	}

	/**
	 * Builds a context for a specific post, used by the REST API.
	 *
	 * @param \WP_Post $post Post object.
	 * @return Context
	 */
	public static function for_post( $post ) {
		$context = new self();

		$front_id = (int) get_option( 'page_on_front' );
		$blog_id  = (int) get_option( 'page_for_posts' );

		if ( 'page' === get_option( 'show_on_front' ) && $front_id === (int) $post->ID ) {
			$context->type = 'front_page';
		} elseif ( $blog_id === (int) $post->ID ) {
			$context->type = 'posts_page';
		} else {
			$context->type = 'singular';
		}

		$context->set_object( 'post', $post );

		return $context;
	}

	/**
	 * Builds a context for a term, used by the REST API.
	 *
	 * @param \WP_Term $term Term object.
	 * @return Context
	 */
	public static function for_term( $term ) {
		$context       = new self();
		$context->type = 'term';
		$context->set_object( 'term', $term );

		return $context;
	}

	/**
	 * Stores the object and loads its SEO row.
	 *
	 * @param string $type   Object type.
	 * @param mixed  $object WordPress object.
	 * @return void
	 */
	private function set_object( $type, $object ) {
		if ( ! $object ) {
			return;
		}

		$this->object = $object;

		switch ( $type ) {
			case 'post':
				$this->row = Objects::get( 'post', $object->ID );
				break;
			case 'term':
				$this->row = Objects::get( 'term', $object->term_id );
				break;
			case 'user':
				$this->row = Objects::get( 'user', $object->ID );
				break;
		}
	}

	/**
	 * Final document title.
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->memo(
			'title',
			function () {
				$template = $this->row_value( 'title' );
				if ( '' === $template ) {
					$template = $this->setting_for( 'title' );
				}

				$title = Replacer::replace( $template, $this );

				// Never leave the page without a title.
				if ( '' === $title && $this->object instanceof \WP_Post ) {
					$title = Replacer::replace( '%title% %sep% %sitename%', $this );
				}

				/**
				 * Filters the final SEO title.
				 *
				 * @param string  $title   Title.
				 * @param Context $context Current context.
				 */
				return apply_filters( 'tagwright_seo_title', $title, $this );
			}
		);
	}

	/**
	 * Final meta description.
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->memo(
			'description',
			function () {
				$template = $this->row_value( 'description' );
				if ( '' === $template ) {
					$template = $this->setting_for( 'description' );
				}

				$description = Replacer::replace( $template, $this );

				/**
				 * Filters the final meta description.
				 *
				 * @param string  $description Description.
				 * @param Context $context     Current context.
				 */
				return apply_filters( 'tagwright_seo_description', $description, $this );
			}
		);
	}

	/**
	 * Final meta keywords. Empty unless the legacy option is switched on.
	 *
	 * @return string
	 */
	public function get_keywords() {
		return $this->memo(
			'keywords',
			function () {
				if ( ! Options::get( 'meta_keywords' ) ) {
					return '';
				}

				$template = $this->row_value( 'meta_keywords' );
				if ( '' === $template ) {
					$template = $this->setting_for( 'keywords' );
				}

				$keywords = Replacer::replace( $template, $this );

				// Tidy the list: trim each keyword, drop blanks and duplicates.
				$keywords = array_unique( array_filter( array_map( 'trim', explode( ',', $keywords ) ) ) );

				/**
				 * Filters the final meta keywords.
				 *
				 * @param string  $keywords Comma separated keywords.
				 * @param Context $context  Current context.
				 */
				return apply_filters( 'tagwright_seo_keywords', implode( ', ', $keywords ), $this );
			}
		);
	}

	/**
	 * Robots directives as used by the wp_robots filter.
	 *
	 * @return array<string, bool|string>
	 */
	public function get_robots() {
		return $this->memo(
			'robots',
			function () {
				$robots = array();

				if ( $this->is_noindex() ) {
					$robots['noindex'] = true;
				} else {
					$robots['index']             = true;
					$robots['max-snippet']       = '-1';
					$robots['max-image-preview'] = 'large';
					$robots['max-video-preview'] = '-1';
				}

				if ( $this->row ) {
					if ( 1 === $this->row['robots_nofollow'] ) {
						$robots['nofollow'] = true;
					} else {
						$robots['follow'] = true;
					}

					foreach ( array( 'noarchive', 'nosnippet', 'noimageindex' ) as $directive ) {
						if ( 1 === $this->row[ 'robots_' . $directive ] ) {
							$robots[ $directive ] = true;
						}
					}

					if ( ! isset( $robots['noindex'] ) ) {
						if ( null !== $this->row['max_snippet'] ) {
							$robots['max-snippet'] = (string) $this->row['max_snippet'];
						}
						if ( null !== $this->row['max_image_preview'] && '' !== $this->row['max_image_preview'] ) {
							$robots['max-image-preview'] = $this->row['max_image_preview'];
						}
						if ( null !== $this->row['max_video_preview'] ) {
							$robots['max-video-preview'] = (string) $this->row['max_video_preview'];
						}
					}
				} else {
					$robots['follow'] = true;
				}

				// nosnippet makes the snippet length meaningless.
				if ( isset( $robots['nosnippet'] ) ) {
					unset( $robots['max-snippet'] );
				}

				/**
				 * Filters the robots directives Tagwright SEO outputs.
				 *
				 * @param array   $robots  Directives.
				 * @param Context $context Current context.
				 */
				return apply_filters( 'tagwright_seo_robots', $robots, $this );
			}
		);
	}

	/**
	 * Whether the page should be kept out of search results.
	 *
	 * @return bool
	 */
	public function is_noindex() {
		return $this->memo(
			'noindex',
			function () {
				if ( $this->row && null !== $this->row['robots_noindex'] ) {
					return 1 === $this->row['robots_noindex'];
				}

				switch ( $this->type ) {
					case 'search':
					case 'not_found':
						return true;

					case 'singular':
					case 'front_page':
					case 'posts_page':
						$noindex = $this->object ? (bool) Options::get( 'pt_' . $this->object->post_type . '_noindex' ) : false;
						break;

					case 'term':
						$noindex = (bool) Options::get( 'tax_' . $this->object->taxonomy . '_noindex' );
						if ( ! $noindex && Options::get( 'noindex_empty_terms' ) && 0 === (int) $this->object->count ) {
							$noindex = true;
						}
						break;

					case 'author':
						$noindex = (bool) Options::get( 'author_archive_noindex' );
						break;

					case 'post_type_archive':
						$noindex = $this->object ? (bool) Options::get( 'pta_' . $this->object->name . '_noindex' ) : false;
						break;

					case 'date':
						$noindex = (bool) Options::get( 'date_archive_noindex' );
						break;

					default:
						$noindex = false;
				}

				if ( ! $noindex && $this->paged > 1 && Options::get( 'noindex_paginated' ) ) {
					$noindex = true;
				}

				return $noindex;
			}
		);
	}

	/**
	 * Canonical URL, or an empty string when the page should not have one.
	 *
	 * @return string
	 */
	public function get_canonical() {
		return $this->memo(
			'canonical',
			function () {
				$custom = $this->row_value( 'canonical' );
				if ( '' !== $custom ) {
					return apply_filters( 'tagwright_seo_canonical', $custom, $this );
				}

				// A canonical on a noindexed page sends mixed signals, so skip it.
				if ( $this->is_noindex() ) {
					return apply_filters( 'tagwright_seo_canonical', '', $this );
				}

				$url = '';
				switch ( $this->type ) {
					case 'singular':
					case 'front_page':
					case 'posts_page':
						if ( 'posts_page' === $this->type ) {
							$url = $this->paginate( get_permalink( $this->object ) );
						} elseif ( 'front_page' === $this->type ) {
							$url = home_url( '/' );
						} else {
							$url = (string) wp_get_canonical_url( $this->object );
						}
						break;

					case 'home':
						$url = $this->paginate( home_url( '/' ) );
						break;

					case 'term':
						$link = get_term_link( $this->object );
						$url  = is_wp_error( $link ) ? '' : $this->paginate( $link );
						break;

					case 'author':
						$url = $this->paginate( get_author_posts_url( $this->object->ID ) );
						break;

					case 'post_type_archive':
						$url = $this->object ? $this->paginate( (string) get_post_type_archive_link( $this->object->name ) ) : '';
						break;

					case 'date':
						if ( is_day() ) {
							$url = get_day_link( get_query_var( 'year' ), get_query_var( 'monthnum' ), get_query_var( 'day' ) );
						} elseif ( is_month() ) {
							$url = get_month_link( get_query_var( 'year' ), get_query_var( 'monthnum' ) );
						} else {
							$url = get_year_link( get_query_var( 'year' ) );
						}
						$url = $this->paginate( $url );
						break;
				}

				/**
				 * Filters the canonical URL.
				 *
				 * @param string  $url     Canonical URL.
				 * @param Context $context Current context.
				 */
				return apply_filters( 'tagwright_seo_canonical', $url, $this );
			}
		);
	}

	/**
	 * The template from the settings for a field on this kind of page.
	 *
	 * @param string $field title, description or keywords.
	 * @return string
	 */
	public function setting_for( $field ) {
		switch ( $this->type ) {
			case 'home':
				return (string) Options::get( 'home_' . $field, '' );

			case 'front_page':
				// A static front page falls back to the homepage settings.
				return (string) Options::get( 'home_' . $field, '' );

			case 'singular':
			case 'posts_page':
				return (string) Options::get( 'pt_' . $this->object->post_type . '_' . $field, '' );

			case 'term':
				return (string) Options::get( 'tax_' . $this->object->taxonomy . '_' . $field, '' );

			case 'author':
				return (string) Options::get( 'author_archive_' . $field, '' );

			case 'post_type_archive':
				return $this->object ? (string) Options::get( 'pta_' . $this->object->name . '_' . $field, '' ) : '';

			case 'date':
				return (string) Options::get( 'date_archive_' . $field, '' );

			case 'search':
				return 'title' === $field ? (string) Options::get( 'search_title', '' ) : '';

			case 'not_found':
				return 'title' === $field ? (string) Options::get( 'not_found_title', '' ) : '';
		}

		return '';
	}

	/**
	 * Reads a string column from the stored row.
	 *
	 * @param string $column Column name.
	 * @return string
	 */
	public function row_value( $column ) {
		return isset( $this->row[ $column ] ) ? trim( (string) $this->row[ $column ] ) : '';
	}

	/**
	 * Whether this is the site's front page, static or not.
	 *
	 * @return bool
	 */
	public function is_front() {
		return in_array( $this->type, array( 'home', 'front_page' ), true );
	}

	/**
	 * Adds the page number to an archive URL.
	 *
	 * @param string $url Base URL.
	 * @return string
	 */
	public function paginate( $url ) {
		global $wp_rewrite;

		if ( $this->paged < 2 || ! $url ) {
			return $url;
		}

		if ( $wp_rewrite->using_permalinks() ) {
			return user_trailingslashit( trailingslashit( $url ) . $wp_rewrite->pagination_base . '/' . $this->paged, 'paged' );
		}

		return add_query_arg( 'paged', $this->paged, $url );
	}

	/**
	 * Small memoization helper.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $callback Producer.
	 * @return mixed
	 */
	private function memo( $key, $callback ) {
		if ( ! array_key_exists( $key, $this->memo ) ) {
			$this->memo[ $key ] = $callback();
		}

		return $this->memo[ $key ];
	}
}
