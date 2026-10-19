<?php
/**
 * Related posts.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Finds posts that share a category, tag or other public term with the
 * current post, newest first.
 *
 * The ids are cached in a transient whose key includes the post's modified
 * date. Editing the post (and so possibly its terms) moves it to a fresh
 * key, and the day-long expiry lets newly published posts show up without
 * any invalidation code.
 */
class Related {

	/**
	 * Hard ceiling for the number of posts, whatever the block says.
	 */
	const MAX = 12;

	/**
	 * Related post ids.
	 *
	 * @param \WP_Post $post  Post.
	 * @param int      $count How many.
	 * @return int[]
	 */
	public static function ids( $post, $count ) {
		$count = max( 1, min( self::MAX, (int) $count ) );
		$key   = 'tagwright_seo_related_' . $post->ID . '_' . md5( $post->post_modified_gmt . '|' . $count );

		$ids = get_transient( $key );
		if ( is_array( $ids ) ) {
			return array_map( 'intval', $ids );
		}

		$ids = self::query( $post, $count );
		set_transient( $key, $ids, DAY_IN_SECONDS );

		return $ids;
	}

	/**
	 * Runs the lookup. Ordered by date so the database can use its index;
	 * random ordering would scan every matching row.
	 *
	 * @param \WP_Post $post  Post.
	 * @param int      $count How many.
	 * @return int[]
	 */
	private static function query( $post, $count ) {
		$tax_query = array( 'relation' => 'OR' );

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || 'post_format' === $taxonomy->name ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) || ! $terms ) {
				continue;
			}

			// "Uncategorized" links everything to everything, so it doesn't count.
			$term_ids = array_diff( wp_list_pluck( $terms, 'term_id' ), array( (int) get_option( 'default_category' ) ) );
			if ( ! $term_ids ) {
				continue;
			}

			$tax_query[] = array(
				'taxonomy' => $taxonomy->name,
				'field'    => 'term_id',
				'terms'    => array_values( array_map( 'intval', $term_ids ) ),
			);
		}

		if ( count( $tax_query ) < 2 ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $post->post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => $count,
				'post__not_in'           => array( $post->ID ),
				'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Cached for a day per post.
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'has_password'           => false,
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Related posts markup.
	 *
	 * @param \WP_Post|null $post Current post.
	 * @param array         $args title, count, show_image, wrapper.
	 * @return string
	 */
	public static function render( $post, array $args ) {
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$ids = self::ids( $post, $args['count'] ?? 4 );
		if ( ! $ids ) {
			return '';
		}

		// One query for all posts (and their thumbnails) instead of one each.
		_prime_post_caches( $ids, false, ! empty( $args['show_image'] ) );

		if ( ! empty( $args['show_image'] ) ) {
			$thumbnails = array_filter( array_map( 'get_post_thumbnail_id', $ids ) );
			if ( $thumbnails ) {
				_prime_post_caches( array_map( 'intval', $thumbnails ), false, true );
			}
		}

		$items = '';
		foreach ( $ids as $id ) {
			$related = get_post( $id );
			if ( ! $related || 'publish' !== $related->post_status ) {
				continue;
			}

			$image = '';
			if ( ! empty( $args['show_image'] ) && has_post_thumbnail( $related ) ) {
				$image = get_the_post_thumbnail( $related, 'medium', array( 'class' => 'tagwright-related__image' ) );
			}

			$items .= sprintf(
				'<li class="tagwright-related__item"><a href="%1$s">%2$s<span class="tagwright-related__name">%3$s</span></a></li>',
				esc_url( get_permalink( $related ) ),
				$image,
				esc_html( wp_strip_all_tags( get_the_title( $related ) ) )
			);
		}

		if ( '' === $items ) {
			return '';
		}

		Renderer::enqueue_style();

		$title = trim( (string) ( $args['title'] ?? '' ) );

		return sprintf(
			'<section %1$s>%2$s<ul class="tagwright-related__list">%3$s</ul></section>',
			$args['wrapper'] ?? 'class="tagwright-related"',
			'' !== $title ? '<h2 class="tagwright-related__title tagwright-toc-skip">' . esc_html( $title ) . '</h2>' : '',
			$items
		);
	}
}
