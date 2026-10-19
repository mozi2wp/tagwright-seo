<?php
/**
 * Instant Indexing feature.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Indexing;

use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Watches content changes and queues the affected URLs for IndexNow and,
 * when set up, the Google Indexing API.
 *
 * Only constructed while the module is on.
 */
class Feature {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		new Indexnow();

		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 30, 4 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ), 10, 2 );
		add_action( Queue::CRON_HOOK, array( Queue::class, 'flush' ) );

		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );

		if ( is_admin() ) {
			new Admin_View();
		}
	}

	/**
	 * Queues a post after it was saved.
	 *
	 * wp_after_insert_post runs once terms and meta are saved, so the
	 * permalink is final, and it hands us the post as it was before, which
	 * is how publish, update and unpublish (including trash) are told apart.
	 *
	 * @param int           $post_id     Post id.
	 * @param \WP_Post      $post        Post after saving.
	 * @param bool          $update      Whether this was an update.
	 * @param \WP_Post|null $post_before Post before saving.
	 * @return void
	 */
	public function on_post_saved( $post_id, $post, $update, $post_before ) {
		if ( ! $post instanceof \WP_Post || self::is_busy() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! self::is_enabled_type( $post->post_type ) ) {
			return;
		}

		$was_live = $post_before instanceof \WP_Post && 'publish' === $post_before->post_status && empty( $post_before->post_password );
		$is_live  = 'publish' === $post->post_status && empty( $post->post_password );

		if ( $is_live ) {
			$event = $was_live ? 'update' : 'publish';

			if ( self::event_enabled( $event ) && ! self::is_noindex( $post ) ) {
				Queue::add( (string) get_permalink( $post ), 'update' );
			}
			return;
		}

		// Trashed, drafted, scheduled again or made private: the old URL is gone.
		if ( $was_live && self::event_enabled( 'delete' ) ) {
			Queue::add( (string) get_permalink( $post_before ), 'delete' );
		}
	}

	/**
	 * Queues a published post that is deleted without going to the trash.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public function on_post_deleted( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || self::is_busy() || ! self::is_enabled_type( $post->post_type ) || ! self::event_enabled( 'delete' ) ) {
			return;
		}

		Queue::add( (string) get_permalink( $post ), 'delete' );
	}

	/**
	 * Requests where pinging search engines would be noise.
	 *
	 * @return bool
	 */
	private static function is_busy() {
		return ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| ( defined( 'WP_IMPORTING' ) && WP_IMPORTING )
			|| ! get_option( 'blog_public' )
			|| self::is_local_site();
	}

	/**
	 * Search engines can't reach a local or development copy of a site, so
	 * pinging them about it only fills their logs with errors.
	 *
	 * @return bool
	 */
	public static function is_local_site() {
		if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return true;
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( 'localhost' === $host || preg_match( '/\.(local|test|localhost|invalid|example)$/', $host ) ) {
			return true;
		}

		// A private or reserved IP address, such as 127.0.0.1 or 192.168.x.x.
		return false !== filter_var( $host, FILTER_VALIDATE_IP )
			&& false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether a post type is chosen in the settings and can be visited.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_enabled_type( $post_type ) {
		return in_array( $post_type, (array) Options::get( 'indexing_post_types', array() ), true ) && is_post_type_viewable( $post_type );
	}

	/**
	 * Whether an event is ticked in the settings.
	 *
	 * @param string $event `publish`, `update` or `delete`.
	 * @return bool
	 */
	private static function event_enabled( $event ) {
		return in_array( $event, (array) Options::get( 'indexing_events', array() ), true );
	}

	/**
	 * Pages kept out of search results should not be pushed to search engines.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function is_noindex( $post ) {
		return Context::for_post( $post )->is_noindex();
	}

	/**
	 * Removes the feature's own options and scheduled event.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		Queue::clear();
		Log::clear();
		Google::delete_credentials();
	}
}
