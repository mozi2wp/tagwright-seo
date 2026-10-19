<?php
/**
 * BuddyPress integration.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Integrations;

use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Frontend\Replacer;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Titles, descriptions, canonicals and robots for BuddyPress pages.
 *
 * BuddyPress serves every member and group from one directory page, so
 * without this each profile would inherit that page's title, and its
 * canonical would point every profile at the directory.
 */
class Buddypress {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_filter( 'tagwright_seo_title', array( $this, 'title' ), 10, 2 );
		add_filter( 'tagwright_seo_description', array( $this, 'description' ), 10, 2 );
		add_filter( 'tagwright_seo_canonical', array( $this, 'canonical' ), 10, 2 );
		add_filter( 'tagwright_seo_robots', array( $this, 'robots' ), 10, 2 );

		// BuddyPress prints its own canonical on bp_head; ours already covers it.
		add_action( 'bp_head', array( $this, 'remove_bp_canonical' ), 0 );
	}

	/**
	 * Calls a BuddyPress function when it exists.
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
	 * The group being viewed, if any.
	 *
	 * @return object|null
	 */
	private static function current_group() {
		if ( ! self::call( 'bp_is_group', false ) ) {
			return null;
		}

		$group = self::call( 'groups_get_current_group', null );

		return is_object( $group ) && ! empty( $group->name ) ? $group : null;
	}

	/**
	 * "Name - Site" for profiles and groups.
	 *
	 * @param string  $title   Title.
	 * @param Context $context Page context.
	 * @return string
	 */
	public function title( $title, $context ) {
		$name = '';

		if ( self::call( 'bp_is_user', false ) ) {
			$name = (string) self::call( 'bp_get_displayed_user_fullname', '' );
		} else {
			$group = self::current_group();
			$name  = $group ? (string) $group->name : '';
		}

		if ( '' === trim( $name ) ) {
			return $title;
		}

		return wp_strip_all_tags( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) . ' ' . Replacer::separator() . ' ' . get_bloginfo( 'name' );
	}

	/**
	 * Profile bio or group description.
	 *
	 * @param string  $description Description.
	 * @param Context $context     Page context.
	 * @return string
	 */
	public function description( $description, $context ) {
		$text = '';

		if ( self::call( 'bp_is_user', false ) ) {
			$user_id = (int) self::call( 'bp_displayed_user_id', 0 );
			$text    = $user_id ? (string) get_the_author_meta( 'description', $user_id ) : '';
		} else {
			$group = self::current_group();
			$text  = $group && isset( $group->description ) ? (string) $group->description : '';
		}

		$text = Replacer::plain( $text, 155 );

		return '' !== $text ? $text : $description;
	}

	/**
	 * Self-referencing canonical for profile and group pages.
	 *
	 * Built from the request path, which works across BuddyPress versions
	 * (12.0 changed how member and group URLs are generated).
	 *
	 * @param string  $canonical Canonical.
	 * @param Context $context   Page context.
	 * @return string
	 */
	public function canonical( $canonical, $context ) {
		if ( ! self::call( 'bp_is_user', false ) && ! self::current_group() ) {
			return $canonical;
		}

		global $wp;

		if ( ! $wp || '' === (string) $wp->request ) {
			return $canonical;
		}

		return user_trailingslashit( home_url( '/' . ltrim( (string) $wp->request, '/' ) ) );
	}

	/**
	 * Noindex for the directories that are switched off.
	 *
	 * @param array   $robots  Directives.
	 * @param Context $context Page context.
	 * @return array
	 */
	public function robots( $robots, $context ) {
		$checks = array(
			'integrations_bp_noindex_members'  => 'bp_is_members_directory',
			'integrations_bp_noindex_activity' => 'bp_is_activity_directory',
			'integrations_bp_noindex_groups'   => 'bp_is_groups_directory',
		);

		foreach ( $checks as $setting => $check ) {
			if ( Options::get( $setting ) && self::call( $check, false ) ) {
				return Feature::noindex( (array) $robots );
			}
		}

		return $robots;
	}

	/**
	 * Unhooks BuddyPress's canonical.
	 *
	 * @return void
	 */
	public function remove_bp_canonical() {
		remove_action( 'bp_head', 'bp_rel_canonical' );
	}
}
