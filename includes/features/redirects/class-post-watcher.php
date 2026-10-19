<?php
/**
 * Automatic redirects when content moves.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a 301 when a published post, page or custom post type gets a new
 * address because its slug, parent or date changed.
 *
 * Trashing is deliberately ignored: WordPress renames the slug of a trashed
 * post, but the post is no longer published, so no rule is created and the
 * old address simply returns a 404 (and shows up in the 404 log).
 */
class Post_Watcher {

	/**
	 * Compares the old and new permalink after a post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   Post after the update.
	 * @param \WP_Post $before  Post before the update.
	 * @return void
	 */
	public static function post_updated( $post_id, $after, $before ) {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post ) {
			return;
		}

		if ( 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}

		// Only these fields change a permalink, so most saves stop here.
		if ( $before->post_name === $after->post_name && (int) $before->post_parent === (int) $after->post_parent && $before->post_date === $after->post_date ) {
			return;
		}

		if ( 'attachment' === $after->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! is_post_type_viewable( $after->post_type ) ) {
			return;
		}

		$old_url = get_permalink( $before );
		$new_url = get_permalink( $after );

		if ( ! $old_url || ! $new_url || $old_url === $new_url ) {
			return;
		}

		// Child pages live below the parent's address, so they move with it.
		$with_children = false;
		if ( is_post_type_hierarchical( $after->post_type ) && $before->post_name !== $after->post_name ) {
			$with_children = (bool) get_posts(
				array(
					'post_type'      => $after->post_type,
					'post_parent'    => $post_id,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
		}

		Repository::moved(
			$old_url,
			$new_url,
			$with_children,
			/* translators: %d: post ID. */
			sprintf( __( 'Added automatically when post #%d moved', 'tagwright-seo' ), $post_id )
		);
	}
}
