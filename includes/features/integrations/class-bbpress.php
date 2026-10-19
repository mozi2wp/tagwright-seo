<?php
/**
 * bbPress integration.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Integrations;

use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Frontend\Replacer;
use TagwrightSEO\Modules;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Breadcrumbs, robots and schema for bbPress forums.
 *
 * Every bbPress function is checked before use, so a bbPress update that
 * renames something degrades to "no integration" rather than an error.
 */
class Bbpress {

	/**
	 * Replies listed as comments in the topic schema.
	 */
	const SCHEMA_REPLIES = 10;

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_filter( 'tagwright_seo_breadcrumbs', array( $this, 'breadcrumbs' ), 10, 2 );

		if ( Options::get( 'integrations_bbpress_noindex_users' ) || Options::get( 'integrations_bbpress_noindex_search' ) ) {
			add_filter( 'tagwright_seo_robots', array( $this, 'robots' ), 10, 2 );
		}

		if ( Options::get( 'integrations_bbpress_schema' ) && Modules::is_active( 'schema' ) ) {
			add_filter( 'tagwright_seo_schema_graph', array( $this, 'schema' ), 10, 2 );
		}
	}

	/**
	 * Calls a bbPress function when it exists.
	 *
	 * @param string $name     Function name.
	 * @param mixed  $fallback Value when the function is missing.
	 * @param mixed  ...$args  Arguments.
	 * @return mixed
	 */
	private static function call( $name, $fallback, ...$args ) {
		return function_exists( $name ) ? call_user_func_array( $name, $args ) : $fallback;
	}

	/**
	 * Post types bbPress registers.
	 *
	 * @return string[]
	 */
	private static function post_types() {
		return array(
			(string) self::call( 'bbp_get_forum_post_type', 'forum' ),
			(string) self::call( 'bbp_get_topic_post_type', 'topic' ),
			(string) self::call( 'bbp_get_reply_post_type', 'reply' ),
		);
	}

	/**
	 * Home › Forums › Parent forum › Forum › Topic.
	 *
	 * Topics and replies hang under their forum through post_parent, so the
	 * ancestors already give the forum path; what the core trail lacks is
	 * the forums index and the right order.
	 *
	 * @param array[] $crumbs  Trail.
	 * @param Context $context Page context.
	 * @return array[]
	 */
	public function breadcrumbs( $crumbs, $context ) {
		if ( 'singular' !== $context->type || ! $context->object instanceof \WP_Post || ! in_array( $context->object->post_type, self::post_types(), true ) ) {
			return $crumbs;
		}

		$post  = $context->object;
		$trail = array( reset( $crumbs ) );

		$forums_url = (string) self::call( 'bbp_get_forums_url', '' );
		if ( '' !== $forums_url ) {
			$trail[] = array(
				'text' => wp_strip_all_tags( (string) self::call( 'bbp_get_forum_archive_title', __( 'Forums', 'tagwright-seo' ) ) ),
				'url'  => $forums_url,
			);
		}

		foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
			$trail[] = array(
				'text' => get_the_title( $ancestor_id ),
				'url'  => (string) get_permalink( $ancestor_id ),
			);
		}

		$trail[] = array(
			'text' => get_the_title( $post ),
			'url'  => (string) get_permalink( $post ),
		);

		return $trail;
	}

	/**
	 * Noindex for profile pages and forum search.
	 *
	 * @param array   $robots  Directives.
	 * @param Context $context Page context.
	 * @return array
	 */
	public function robots( $robots, $context ) {
		$noindex = false;

		if ( Options::get( 'integrations_bbpress_noindex_users' ) ) {
			foreach ( array( 'bbp_is_single_user', 'bbp_is_single_user_edit', 'bbp_is_single_user_topics', 'bbp_is_single_user_replies', 'bbp_is_favorites', 'bbp_is_subscriptions', 'bbp_is_single_user_engagements' ) as $check ) {
				if ( self::call( $check, false ) ) {
					$noindex = true;
					break;
				}
			}
		}

		if ( ! $noindex && Options::get( 'integrations_bbpress_noindex_search' ) && self::call( 'bbp_is_search', false ) ) {
			$noindex = true;
		}

		return $noindex ? Feature::noindex( $robots ) : $robots;
	}

	/**
	 * DiscussionForumPosting for a single topic, with its first replies.
	 *
	 * @see https://developers.google.com/search/docs/appearance/structured-data/discussion-forum
	 *
	 * @param array[] $graph   Graph pieces.
	 * @param Context $context Page context.
	 * @return array[]
	 */
	public function schema( $graph, $context ) {
		if ( ! self::call( 'bbp_is_single_topic', false ) || ! $context->object instanceof \WP_Post ) {
			return $graph;
		}

		$topic = $context->object;
		$url   = $context->get_canonical();
		$url   = '' !== $url ? $url : (string) get_permalink( $topic );

		$posting = array(
			'@type'            => 'DiscussionForumPosting',
			'@id'              => $url . '#discussion',
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'url'              => $url,
			'headline'         => Replacer::plain( get_the_title( $topic ), 110 ),
			'text'             => Replacer::plain( $topic->post_content, 5000 ),
			'datePublished'    => get_post_time( 'c', true, $topic ),
			'dateModified'     => get_post_modified_time( 'c', true, $topic ),
			'author'           => self::author( $topic, 'topic' ),
		);

		$reply_count = (int) self::call( 'bbp_get_topic_reply_count', 0, $topic->ID, true );

		$posting['interactionStatistic'] = array(
			'@type'                => 'InteractionCounter',
			'interactionType'      => 'https://schema.org/CommentAction',
			'userInteractionCount' => $reply_count,
		);

		$forum_id = (int) $topic->post_parent;
		if ( $forum_id ) {
			$posting['isPartOf'] = array(
				'@type' => 'WebPage',
				'url'   => (string) get_permalink( $forum_id ),
				'name'  => wp_strip_all_tags( get_the_title( $forum_id ) ),
			);
		}

		if ( $reply_count > 0 ) {
			$comments = array();
			$replies  = get_posts(
				array(
					'post_type'              => self::post_types()[2],
					'post_parent'            => $topic->ID,
					'post_status'            => 'publish',
					'numberposts'            => self::SCHEMA_REPLIES,
					'orderby'                => 'date',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $replies as $reply ) {
				$text = Replacer::plain( $reply->post_content, 2000 );
				if ( '' === $text ) {
					continue;
				}

				$comments[] = array(
					'@type'         => 'Comment',
					'url'           => (string) self::call( 'bbp_get_reply_url', get_permalink( $reply ), $reply->ID ),
					'text'          => $text,
					'datePublished' => get_post_time( 'c', true, $reply ),
					'author'        => self::author( $reply, 'reply' ),
				);
			}

			if ( $comments ) {
				$posting['comment'] = $comments;
			}
		}

		$graph[] = $posting;

		return $graph;
	}

	/**
	 * Person for a topic or reply author, anonymous posters included.
	 *
	 * @param \WP_Post $post Topic or reply.
	 * @param string   $kind `topic` or `reply`.
	 * @return array
	 */
	private static function author( $post, $kind ) {
		$name = (string) self::call( 'bbp_get_' . $kind . '_author_display_name', '', $post->ID );
		if ( '' === $name && $post->post_author ) {
			$name = (string) get_the_author_meta( 'display_name', $post->post_author );
		}

		$person = array(
			'@type' => 'Person',
			'name'  => '' !== $name ? wp_strip_all_tags( $name ) : __( 'Anonymous', 'tagwright-seo' ),
		);

		if ( $post->post_author ) {
			$profile = (string) self::call( 'bbp_get_user_profile_url', '', $post->post_author );
			if ( '' !== $profile ) {
				$person['url'] = $profile;
			}
		}

		return $person;
	}
}
