<?php
/**
 * Keeps the objects table in step with WordPress content.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Data;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for content changes and refreshes the matching index rows.
 *
 * Only the "system" columns are written here (permalink, status, dates,
 * images and so on). SEO fields typed by editors are never touched.
 */
class Object_Sync {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		// wp_after_insert_post runs once terms and meta are saved, so permalinks are final.
		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 20, 2 );
		add_action( 'trashed_post', array( $this, 'on_post_id_changed' ) );
		add_action( 'untrashed_post', array( $this, 'on_post_id_changed' ) );
		add_action( 'deleted_post', array( $this, 'on_post_deleted' ) );
		add_action( 'add_attachment', array( $this, 'on_post_id_changed' ) );
		add_action( 'edit_attachment', array( $this, 'on_post_id_changed' ) );
		add_action( 'wp_update_comment_count', array( $this, 'on_comment_count' ), 10, 2 );

		add_action( 'created_term', array( $this, 'on_term_saved' ), 20, 3 );
		add_action( 'edited_term', array( $this, 'on_term_saved' ), 20, 3 );
		add_action( 'delete_term', array( $this, 'on_term_deleted' ), 20, 3 );

		add_action( 'user_register', array( $this, 'on_user_saved' ), 20 );
		add_action( 'profile_update', array( $this, 'on_user_saved' ), 20 );
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ) );

		// Every stored permalink changes when the structure does.
		add_action( 'update_option_permalink_structure', array( $this, 'on_permalinks_changed' ) );
		add_action( 'update_option_category_base', array( $this, 'on_permalinks_changed' ) );
		add_action( 'update_option_tag_base', array( $this, 'on_permalinks_changed' ) );
		add_action( 'update_option_home', array( $this, 'on_permalinks_changed' ) );

		// These settings change which objects and images are stored for the sitemaps.
		add_action( 'tagwright_seo_settings_updated', array( $this, 'on_settings_updated' ) );
	}

	/**
	 * Reindexes when a setting that shapes the stored rows changes.
	 *
	 * The settings screen only passes values that really changed.
	 *
	 * @param array $values Changed settings.
	 * @return void
	 */
	public function on_settings_updated( $values ) {
		if ( array_key_exists( 'attachment_redirect', (array) $values ) || array_key_exists( 'sitemap_images', (array) $values ) ) {
			Indexer::queue_full_index();
		}
	}

	/**
	 * Post saved callback.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function on_post_saved( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		self::index_post( $post );
	}

	/**
	 * Re-indexes a post when only its id is passed to the hook.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function on_post_id_changed( $post_id ) {
		$post = get_post( $post_id );
		if ( $post ) {
			self::index_post( $post );
		}
	}

	/**
	 * Keeps the comment count current for comment-based sitemap priorities.
	 *
	 * @param int $post_id Post id.
	 * @param int $count   New approved comment count.
	 * @return void
	 */
	public function on_comment_count( $post_id, $count ) {
		if ( Objects::get( 'post', $post_id ) ) {
			Objects::save( 'post', $post_id, array( 'comment_count' => (int) $count ) );
		}
	}

	/**
	 * Post deleted callback.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function on_post_deleted( $post_id ) {
		Objects::delete( 'post', $post_id );
	}

	/**
	 * Term created or edited callback.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function on_term_saved( $term_id, $tt_id, $taxonomy ) {
		self::index_term( $term_id, $taxonomy );
	}

	/**
	 * Term deleted callback.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function on_term_deleted( $term_id, $tt_id, $taxonomy ) {
		Objects::delete( 'term', $term_id );
	}

	/**
	 * User saved callback.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function on_user_saved( $user_id ) {
		self::index_user( $user_id );
	}

	/**
	 * User deleted callback.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function on_user_deleted( $user_id ) {
		Objects::delete( 'user', $user_id );
	}

	/**
	 * Schedules a background refresh of every permalink.
	 *
	 * @return void
	 */
	public function on_permalinks_changed() {
		Indexer::queue_full_index();
	}

	/**
	 * Writes the system columns for a post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public static function index_post( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'auto-draft' === $post->post_status || 'revision' === $post->post_type ) {
			return false;
		}

		$is_public = self::is_post_public( $post );

		$saved = Objects::save(
			'post',
			$post->ID,
			array(
				'object_sub_type' => $post->post_type,
				'author_id'       => (int) $post->post_author,
				'post_status'     => $post->post_status,
				'is_public'       => $is_public ? 1 : 0,
				'permalink'       => $is_public ? (string) get_permalink( $post ) : '',
				'images'          => $is_public ? self::collect_images( $post ) : array(),
				'comment_count'   => (int) $post->comment_count,
				'published_at'    => self::valid_date( $post->post_date_gmt ),
				'modified_at'     => self::valid_date( $post->post_modified_gmt ),
			)
		);

		if ( $is_public ) {
			self::touch_related_archives( $post );
		}

		return $saved;
	}

	/**
	 * Writes the system columns for a term.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	public static function index_term( $term_id, $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		$is_public = is_taxonomy_viewable( $taxonomy );
		$link      = $is_public ? get_term_link( $term ) : '';

		return Objects::save(
			'term',
			$term->term_id,
			array(
				'object_sub_type' => $taxonomy,
				'is_public'       => $is_public ? 1 : 0,
				'permalink'       => is_wp_error( $link ) ? '' : (string) $link,
				'modified_at'     => self::term_last_modified( $term ),
			)
		);
	}

	/**
	 * Writes the system columns for a user (their author archive).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function index_user( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$post_types = array_values( get_post_types( array( 'public' => true ) ) );
		$has_posts  = count_user_posts( $user->ID, $post_types, true ) > 0;

		return Objects::save(
			'user',
			$user->ID,
			array(
				'object_sub_type' => 'author',
				'is_public'       => $has_posts ? 1 : 0,
				'permalink'       => (string) get_author_posts_url( $user->ID ),
				'modified_at'     => $has_posts ? self::author_last_modified( $user->ID, $post_types ) : null,
			)
		);
	}

	/**
	 * Date of the most recently modified published post in a term, so
	 * taxonomy sitemaps have a real last modified date from day one.
	 *
	 * @param \WP_Term $term Term.
	 * @return string|null MySQL GMT date.
	 */
	public static function term_last_modified( $term ) {
		global $wpdb;

		$date = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(p.post_modified_gmt) FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE tr.term_taxonomy_id = %d AND p.post_status = 'publish'",
				$term->term_taxonomy_id
			)
		);

		return self::valid_date( (string) $date );
	}

	/**
	 * Date of an author's most recently modified published post.
	 *
	 * @param int      $user_id    User id.
	 * @param string[] $post_types Public post types.
	 * @return string|null MySQL GMT date.
	 */
	public static function author_last_modified( $user_id, array $post_types ) {
		global $wpdb;

		if ( empty( $post_types ) ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$date         = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_author = %d AND post_status = 'publish' AND post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( (int) $user_id ), $post_types )
			)
		);

		return self::valid_date( (string) $date );
	}

	/**
	 * Whether a post can be visited by the public and so belongs in sitemaps.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public static function is_post_public( $post ) {
		if ( ! is_post_type_viewable( $post->post_type ) || ! empty( $post->post_password ) ) {
			return false;
		}

		if ( 'attachment' === $post->post_type ) {
			// Attachment pages get redirected away by default, so they are not real pages.
			return ! Options::get( 'attachment_redirect' );
		}

		return 'publish' === $post->post_status;
	}

	/**
	 * Gathers image URLs for the image sitemap.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string[]
	 */
	public static function collect_images( $post ) {
		if ( ! Options::get( 'sitemap_images' ) ) {
			return array();
		}

		$images = array();

		if ( 'attachment' === $post->post_type && wp_attachment_is_image( $post->ID ) ) {
			$images[] = wp_get_attachment_url( $post->ID );
		}

		$thumbnail_id = get_post_thumbnail_id( $post );
		if ( $thumbnail_id ) {
			$images[] = wp_get_attachment_url( $thumbnail_id );
		}

		if ( $post->post_content && preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				if ( 0 === strpos( $src, 'data:' ) ) {
					continue;
				}
				$images[] = esc_url_raw( $src );
			}
		}

		/**
		 * Filters the images listed for a post in the sitemap.
		 *
		 * @param string[] $images Image URLs.
		 * @param \WP_Post $post   Post object.
		 */
		$images = apply_filters( 'tagwright_seo_post_images', $images, $post );

		// Google reads up to 1,000 images per URL, but 30 is plenty and keeps rows small.
		return array_slice( array_values( array_unique( array_filter( $images ) ) ), 0, 30 );
	}

	/**
	 * Updates the last-modified date on the post's terms and author archive.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	private static function touch_related_archives( $post ) {
		$modified = self::valid_date( $post->post_modified_gmt );
		if ( ! $modified ) {
			return;
		}

		$term_ids = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_array( $terms ) ) {
				$term_ids = array_merge( $term_ids, wp_list_pluck( $terms, 'term_id' ) );
			}
		}

		Objects::touch( 'term', $term_ids, $modified );

		$author = Objects::get( 'user', (int) $post->post_author );
		if ( ! $author || ! $author['is_public'] ) {
			self::index_user( (int) $post->post_author );
		}
		Objects::touch( 'user', array( (int) $post->post_author ), $modified );
	}

	/**
	 * Returns null for WordPress' "zero" dates so they are stored as NULL.
	 *
	 * @param string $date MySQL date.
	 * @return string|null
	 */
	private static function valid_date( $date ) {
		return ( $date && '0000-00-00 00:00:00' !== $date ) ? $date : null;
	}
}
