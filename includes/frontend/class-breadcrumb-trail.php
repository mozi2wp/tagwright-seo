<?php
/**
 * Breadcrumb trail builder.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of crumbs leading to the current page.
 *
 * The core uses this for BreadcrumbList schema. The Content Blocks add-on
 * (or a theme) can call tagwright_seo_get_breadcrumbs() to display it, so the
 * schema and the visible trail always agree.
 */
class Breadcrumb_Trail {

	/**
	 * Trail for the current request.
	 *
	 * @return array[] List of array( 'text' => string, 'url' => string ).
	 */
	public static function for_current_page() {
		return self::build( Context::current() );
	}

	/**
	 * Builds a trail for a context.
	 *
	 * @param Context $context Page context.
	 * @return array[]
	 */
	public static function build( Context $context ) {
		$crumbs = array(
			array(
				'text' => __( 'Home', 'tagwright-seo' ),
				'url'  => home_url( '/' ),
			),
		);

		switch ( $context->type ) {
			case 'singular':
			case 'posts_page':
				$crumbs = array_merge( $crumbs, self::post_crumbs( $context->object ) );
				break;

			case 'term':
				$crumbs = array_merge( $crumbs, self::term_crumbs( $context->object ) );
				break;

			case 'author':
				$crumbs[] = array(
					'text' => $context->object->display_name,
					'url'  => get_author_posts_url( $context->object->ID ),
				);
				break;

			case 'post_type_archive':
				if ( $context->object ) {
					$crumbs[] = array(
						'text' => $context->object->labels->name,
						'url'  => (string) get_post_type_archive_link( $context->object->name ),
					);
				}
				break;

			case 'date':
				$year = (int) get_query_var( 'year' );
				if ( $year ) {
					$crumbs[] = array(
						'text' => (string) $year,
						'url'  => get_year_link( $year ),
					);
				}
				$month = (int) get_query_var( 'monthnum' );
				if ( $month ) {
					$crumbs[] = array(
						'text' => $GLOBALS['wp_locale']->get_month( $month ),
						'url'  => get_month_link( $year, $month ),
					);
				}
				$day = (int) get_query_var( 'day' );
				if ( $day ) {
					$crumbs[] = array(
						'text' => (string) $day,
						'url'  => get_day_link( $year, $month, $day ),
					);
				}
				break;

			case 'search':
				$crumbs[] = array(
					/* translators: %s: search phrase. */
					'text' => sprintf( __( 'Search results for "%s"', 'tagwright-seo' ), get_search_query( false ) ),
					'url'  => get_search_link(),
				);
				break;

			case 'not_found':
				$crumbs[] = array(
					'text' => __( 'Page not found', 'tagwright-seo' ),
					'url'  => '',
				);
				break;
		}

		/**
		 * Filters the breadcrumb trail.
		 *
		 * @param array[] $crumbs  List of array( 'text', 'url' ).
		 * @param Context $context Page context.
		 */
		return apply_filters( 'tagwright_seo_breadcrumbs', $crumbs, $context );
	}

	/**
	 * Crumbs for a post: archive or category path, ancestors, then the post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array[]
	 */
	private static function post_crumbs( $post ) {
		$crumbs = array();

		if ( 'post' === $post->post_type ) {
			$blog_page = (int) get_option( 'page_for_posts' );
			if ( $blog_page && 'page' === get_option( 'show_on_front' ) && $blog_page !== $post->ID ) {
				$crumbs[] = array(
					'text' => get_the_title( $blog_page ),
					'url'  => get_permalink( $blog_page ),
				);
			}

			$categories = get_the_category( $post->ID );
			if ( $categories ) {
				$crumbs = array_merge( $crumbs, self::term_crumbs( $categories[0] ) );
			}
		} elseif ( 'page' !== $post->post_type ) {
			$type = get_post_type_object( $post->post_type );
			if ( $type && $type->has_archive ) {
				$crumbs[] = array(
					'text' => $type->labels->name,
					'url'  => (string) get_post_type_archive_link( $post->post_type ),
				);
			}
		}

		foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
			$crumbs[] = array(
				'text' => get_the_title( $ancestor_id ),
				'url'  => get_permalink( $ancestor_id ),
			);
		}

		$crumbs[] = array(
			'text' => get_the_title( $post ),
			'url'  => get_permalink( $post ),
		);

		return $crumbs;
	}

	/**
	 * Crumbs for a term and its parents.
	 *
	 * @param \WP_Term $term Term.
	 * @return array[]
	 */
	private static function term_crumbs( $term ) {
		$crumbs = array();

		foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				$crumbs[] = array(
					'text' => $ancestor->name,
					'url'  => (string) get_term_link( $ancestor ),
				);
			}
		}

		$link     = get_term_link( $term );
		$crumbs[] = array(
			'text' => $term->name,
			'url'  => is_wp_error( $link ) ? '' : $link,
		);

		return $crumbs;
	}
}
