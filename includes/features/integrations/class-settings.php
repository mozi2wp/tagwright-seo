<?php
/**
 * Integrations settings.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults, module card and settings tab for Integrations.
 */
final class Settings {

	/**
	 * Default values. Read on every request, so it stays a plain array.
	 *
	 * Every integration is on by default; it still only loads when the
	 * plugin or theme it is for is active.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'integrations_amp'                    => true,
			'integrations_web_stories'            => true,
			'integrations_bbpress'                => true,
			'integrations_bbpress_noindex_users'  => true,
			'integrations_bbpress_noindex_search' => true,
			'integrations_bbpress_schema'         => true,
			'integrations_buddypress'             => true,
			'integrations_bp_noindex_members'     => false,
			'integrations_bp_noindex_activity'    => true,
			'integrations_bp_noindex_groups'      => false,
			'integrations_builders'               => true,
		);
	}

	/**
	 * Card on the Modules screen.
	 *
	 * @return array
	 */
	public static function module(): array {
		return array(
			'title'       => __( 'Integrations', 'tagwright-seo' ),
			'description' => __( 'Makes Tagwright SEO work hand in hand with AMP, Web Stories, bbPress, BuddyPress and page builders. Costs nothing when none of them are installed.', 'tagwright-seo' ),
			'icon'        => 'blocks',
			'settings'    => 'integrations',
		);
	}

	/**
	 * Settings tab: one card per integration.
	 *
	 * @return array
	 */
	public static function tab(): array {
		$detected = Detect::all();
		$builders = Detect::builders();

		return array(
			'title'       => __( 'Integrations', 'tagwright-seo' ),
			'description' => __( 'Each integration only runs when its plugin is active and its switch is on.', 'tagwright-seo' ),
			'icon'        => 'blocks',
			'group'       => 'features',
			'module'      => 'integrations',
			'sections'    => array(
				array(
					'title'       => __( 'AMP', 'tagwright-seo' ),
					'description' => self::status( $detected['amp'] ) . ' ' . esc_html__( 'Adds your titles, meta, canonical, social tags and schema to AMP reader templates and stops the AMP plugin printing its own schema next to ours.', 'tagwright-seo' ),
					'icon'        => 'zap',
					'fields'      => array(
						self::toggle( 'integrations_amp', __( 'Use Tagwright SEO on AMP pages', 'tagwright-seo' ) ),
					),
				),
				array(
					'title'       => __( 'Web Stories', 'tagwright-seo' ),
					'description' => self::status( $detected['web_stories'] ) . ' ' . esc_html__( 'Prints Tagwright SEO tags on single stories and switches off the duplicate description, Open Graph, X and schema tags the Web Stories plugin adds.', 'tagwright-seo' ),
					'icon'        => 'image',
					'fields'      => array(
						self::toggle( 'integrations_web_stories', __( 'Use Tagwright SEO on stories', 'tagwright-seo' ) ),
					),
				),
				array(
					'title'       => __( 'bbPress', 'tagwright-seo' ),
					'description' => self::status( $detected['bbpress'] ) . ' ' . esc_html__( 'Forum levels in breadcrumbs, discussion schema on topics and cleaner indexing of profiles and searches.', 'tagwright-seo' ),
					'icon'        => 'user',
					'fields'      => array(
						self::toggle( 'integrations_bbpress', __( 'Enable the bbPress integration', 'tagwright-seo' ) ),
						self::toggle( 'integrations_bbpress_schema', __( 'DiscussionForumPosting schema on topics', 'tagwright-seo' ), 'integrations_bbpress' ),
						self::toggle( 'integrations_bbpress_noindex_users', __( 'Noindex forum user profiles', 'tagwright-seo' ), 'integrations_bbpress' ),
						self::toggle( 'integrations_bbpress_noindex_search', __( 'Noindex forum search results', 'tagwright-seo' ), 'integrations_bbpress' ),
					),
				),
				array(
					'title'       => __( 'BuddyPress', 'tagwright-seo' ),
					'description' => self::status( $detected['buddypress'] ) . ' ' . esc_html__( 'Proper titles, descriptions and canonicals for member profiles and groups, plus noindex switches for the directories.', 'tagwright-seo' ),
					'icon'        => 'user',
					'fields'      => array(
						self::toggle( 'integrations_buddypress', __( 'Enable the BuddyPress integration', 'tagwright-seo' ) ),
						self::toggle( 'integrations_bp_noindex_members', __( 'Noindex the members directory', 'tagwright-seo' ), 'integrations_buddypress' ),
						self::toggle( 'integrations_bp_noindex_activity', __( 'Noindex the activity stream', 'tagwright-seo' ), 'integrations_buddypress' ),
						self::toggle( 'integrations_bp_noindex_groups', __( 'Noindex the groups directory', 'tagwright-seo' ), 'integrations_buddypress' ),
					),
				),
				array(
					'title'       => __( 'Page builders', 'tagwright-seo' ),
					'description' => self::status( $detected['builders'], $builders ) . ' ' . esc_html__( 'Elementor, Beaver Builder, Divi, Bricks and Oxygen keep your layout outside the normal editor. This feeds the real page text to the content analysis in the Tagwright SEO box.', 'tagwright-seo' ),
					'icon'        => 'blocks',
					'fields'      => array(
						self::toggle( 'integrations_builders', __( 'Analyse page builder content', 'tagwright-seo' ) ),
					),
				),
			),
		);
	}

	/**
	 * Detected or not detected badge.
	 *
	 * @param bool     $found Whether it is active.
	 * @param string[] $names Names to list, for builders.
	 * @return string
	 */
	private static function status( $found, array $names = array() ) {
		if ( ! $found ) {
			return '<span class="tagwright-badge tagwright-badge--muted">' . esc_html__( 'Not detected', 'tagwright-seo' ) . '</span>';
		}

		$label = __( 'Detected', 'tagwright-seo' );
		if ( $names ) {
			/* translators: %s: comma separated list of page builders. */
			$label = sprintf( __( 'Detected: %s', 'tagwright-seo' ), implode( ', ', $names ) );
		}

		return '<span class="tagwright-badge tagwright-badge--good">' . esc_html( $label ) . '</span>';
	}

	/**
	 * A toggle row, optionally shown only while its parent switch is on.
	 *
	 * @param string $id     Setting key.
	 * @param string $label  Toggle label.
	 * @param string $parent Parent setting key.
	 * @return array
	 */
	private static function toggle( $id, $label, $parent = '' ) {
		$field = array(
			'id'           => $id,
			'type'         => 'toggle',
			'label'        => '',
			'toggle_label' => $label,
		);

		if ( '' !== $parent ) {
			$field['show_if'] = array(
				'field'   => $parent,
				'checked' => true,
			);
		}

		return $field;
	}
}
