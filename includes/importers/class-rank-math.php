<?php
/**
 * Rank Math importer.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Importers;

use TagwrightSEO\Data\Object_Sync;
use TagwrightSEO\Data\Objects;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Imports post, term and author data plus the main settings from Rank Math.
 * Rank Math's own data is left in place.
 */
class Rank_Math extends Importer {

	/**
	 * Meta keys Rank Math uses on posts, terms and users.
	 *
	 * @var string[]
	 */
	const META_KEYS = array(
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
		'rank_math_canonical_url',
		'rank_math_robots',
		'rank_math_facebook_title',
		'rank_math_twitter_title',
	);

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'rank_math';
	}

	/**
	 * {@inheritdoc}
	 */
	public function name() {
		return 'Rank Math';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_detected() {
		return class_exists( 'RankMath' ) || false !== get_option( 'rank-math-options-titles' ) || $this->count_posts() > 0;
	}

	/**
	 * {@inheritdoc}
	 */
	public function count_posts() {
		global $wpdb;


		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN (" . implode( ',', array_fill( 0, count( self::META_KEYS ), '%s' ) ) . ')', self::META_KEYS )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function post_ids( $offset, $limit ) {
		global $wpdb;


		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (" . implode( ',', array_fill( 0, count( self::META_KEYS ), '%s' ) ) . ") ORDER BY post_id ASC LIMIT %d OFFSET %d",
					array_merge( self::META_KEYS, array( $limit, $offset ) )
				)
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function post_fields( $post_id ) {
		return $this->map_fields( get_post_meta( $post_id ) );
	}

	/**
	 * Maps Rank Math meta (the same keys on posts, terms and users).
	 *
	 * @param array $meta All meta from get_*_meta( $id ).
	 * @return array
	 */
	private function map_fields( array $meta ) {
		$robots   = maybe_unserialize( $this->meta( $meta, 'rank_math_robots' ) );
		$robots   = is_array( $robots ) ? $robots : array();
		$advanced = maybe_unserialize( $this->meta( $meta, 'rank_math_advanced_robots' ) );
		$advanced = is_array( $advanced ) ? $advanced : array();

		// Rank Math allows several focus keywords; ours is the first one.
		$keywords = explode( ',', $this->meta( $meta, 'rank_math_focus_keyword' ) );

		$noindex = null;
		if ( in_array( 'noindex', $robots, true ) ) {
			$noindex = 1;
		} elseif ( in_array( 'index', $robots, true ) ) {
			$noindex = 0;
		}

		$image_id = absint( $this->meta( $meta, 'rank_math_facebook_image_id' ) );

		return array(
			'title'               => $this->convert_variables( $this->meta( $meta, 'rank_math_title' ) ),
			'description'         => $this->convert_variables( $this->meta( $meta, 'rank_math_description' ) ),
			'focus_keyphrase'     => trim( (string) reset( $keywords ) ),
			'canonical'           => esc_url_raw( $this->meta( $meta, 'rank_math_canonical_url' ) ),
			'robots_noindex'      => $noindex,
			'robots_nofollow'     => in_array( 'nofollow', $robots, true ) ? 1 : null,
			'robots_noarchive'    => in_array( 'noarchive', $robots, true ) ? 1 : null,
			'robots_nosnippet'    => in_array( 'nosnippet', $robots, true ) ? 1 : null,
			'robots_noimageindex' => in_array( 'noimageindex', $robots, true ) ? 1 : null,
			'max_snippet'         => isset( $advanced['max-snippet'] ) ? (int) $advanced['max-snippet'] : null,
			'max_video_preview'   => isset( $advanced['max-video-preview'] ) ? (int) $advanced['max-video-preview'] : null,
			'max_image_preview'   => isset( $advanced['max-image-preview'] ) && in_array( $advanced['max-image-preview'], array( 'none', 'standard', 'large' ), true ) ? $advanced['max-image-preview'] : null,
			'og_title'            => $this->convert_variables( $this->meta( $meta, 'rank_math_facebook_title' ) ),
			'og_description'      => $this->convert_variables( $this->meta( $meta, 'rank_math_facebook_description' ) ),
			'og_image_id'         => $image_id,
			'og_image_url'        => $image_id ? '' : esc_url_raw( $this->meta( $meta, 'rank_math_facebook_image' ) ),
			'twitter_title'       => $this->convert_variables( $this->meta( $meta, 'rank_math_twitter_title' ) ),
			'twitter_description' => $this->convert_variables( $this->meta( $meta, 'rank_math_twitter_description' ) ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function import_terms_and_users() {
		global $wpdb;

		$count      = 0;
		$skip_empty = static fn( $value ) => null !== $value && '' !== $value && 0 !== $value;

		$term_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key IN (" . implode( ',', array_fill( 0, count( self::META_KEYS ), '%s' ) ) . ')', self::META_KEYS )
		);

		foreach ( $term_ids as $term_id ) {
			$term = get_term( (int) $term_id );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$fields = array_filter( $this->map_fields( get_term_meta( (int) $term_id ) ), $skip_empty );
			if ( $fields ) {
				Objects::save( 'term', (int) $term_id, $fields );
				Object_Sync::index_term( (int) $term_id, $term->taxonomy );
				++$count;
			}
		}

		$user_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN (" . implode( ',', array_fill( 0, count( self::META_KEYS ), '%s' ) ) . ')', self::META_KEYS )
		);

		foreach ( $user_ids as $user_id ) {
			$fields = array_filter(
				array_intersect_key( $this->map_fields( get_user_meta( (int) $user_id ) ), array_flip( array( 'title', 'description', 'robots_noindex' ) ) ),
				$skip_empty
			);
			if ( $fields ) {
				Objects::save( 'user', (int) $user_id, $fields );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * {@inheritdoc}
	 */
	public function import_settings() {
		$titles   = (array) get_option( 'rank-math-options-titles', array() );
		$general  = (array) get_option( 'rank-math-options-general', array() );
		$settings = array();

		if ( ! empty( $titles['title_separator'] ) ) {
			$settings['separator'] = $this->separator( $titles['title_separator'] );
		}

		$map = array(
			'homepage_title'             => 'home_title',
			'homepage_description'       => 'home_description',
			'author_archive_title'       => 'author_archive_title',
			'author_archive_description' => 'author_archive_description',
			'date_archive_title'         => 'date_archive_title',
			'date_archive_description'   => 'date_archive_description',
			'search_title'               => 'search_title',
			'404_title'                  => 'not_found_title',
		);
		foreach ( $map as $from => $to ) {
			if ( ! empty( $titles[ $from ] ) ) {
				$settings[ $to ] = $this->convert_variables( $titles[ $from ] );
			}
		}

		if ( isset( $titles['disable_author_archives'] ) || isset( $titles['author_robots'] ) ) {
			$settings['author_archive_noindex'] = 'on' === ( $titles['disable_author_archives'] ?? 'off' ) || in_array( 'noindex', (array) ( $titles['author_robots'] ?? array() ), true );
		}
		if ( isset( $titles['date_archive_robots'] ) ) {
			$settings['date_archive_noindex'] = in_array( 'noindex', (array) $titles['date_archive_robots'], true );
		}

		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( ! empty( $titles[ "pt_{$post_type}_title" ] ) ) {
				$settings[ "pt_{$post_type}_title" ] = $this->convert_variables( $titles[ "pt_{$post_type}_title" ] );
			}
			if ( ! empty( $titles[ "pt_{$post_type}_description" ] ) ) {
				$settings[ "pt_{$post_type}_description" ] = $this->convert_variables( $titles[ "pt_{$post_type}_description" ] );
			}
			if ( isset( $titles[ "pt_{$post_type}_robots" ] ) && 'on' === ( $titles[ "pt_{$post_type}_custom_robots" ] ?? 'off' ) ) {
				$settings[ "pt_{$post_type}_noindex" ] = in_array( 'noindex', (array) $titles[ "pt_{$post_type}_robots" ], true );
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			if ( ! empty( $titles[ "tax_{$taxonomy}_title" ] ) ) {
				$settings[ "tax_{$taxonomy}_title" ] = $this->convert_variables( $titles[ "tax_{$taxonomy}_title" ] );
			}
			if ( ! empty( $titles[ "tax_{$taxonomy}_description" ] ) ) {
				$settings[ "tax_{$taxonomy}_description" ] = $this->convert_variables( $titles[ "tax_{$taxonomy}_description" ] );
			}
			if ( isset( $titles[ "tax_{$taxonomy}_robots" ] ) && 'on' === ( $titles[ "tax_{$taxonomy}_custom_robots" ] ?? 'off' ) ) {
				$settings[ "tax_{$taxonomy}_noindex" ] = in_array( 'noindex', (array) $titles[ "tax_{$taxonomy}_robots" ], true );
			}
		}

		if ( ! empty( $titles['knowledgegraph_type'] ) ) {
			$settings['site_represents'] = 'person' === $titles['knowledgegraph_type'] ? 'person' : 'organization';
		}
		if ( ! empty( $titles['knowledgegraph_name'] ) ) {
			$settings['org_name'] = sanitize_text_field( $titles['knowledgegraph_name'] );
		}
		if ( ! empty( $titles['knowledgegraph_logo_id'] ) ) {
			$settings['org_logo_id'] = absint( $titles['knowledgegraph_logo_id'] );
		}
		if ( ! empty( $titles['social_url_facebook'] ) ) {
			$settings['facebook_page_url'] = esc_url_raw( $titles['social_url_facebook'] );
		}
		if ( ! empty( $titles['twitter_author_names'] ) ) {
			$settings['twitter_username'] = sanitize_text_field( $titles['twitter_author_names'] );
		}
		if ( ! empty( $titles['open_graph_image_id'] ) ) {
			$settings['og_default_image_id'] = absint( $titles['open_graph_image_id'] );
		}
		if ( ! empty( $titles['social_additional_profiles'] ) ) {
			$settings['social_profiles'] = sanitize_textarea_field( $titles['social_additional_profiles'] );
		}

		$verification = array(
			'google_verify'    => 'verify_google',
			'bing_verify'      => 'verify_bing',
			'yandex_verify'    => 'verify_yandex',
			'baidu_verify'     => 'verify_baidu',
			'pinterest_verify' => 'verify_pinterest',
		);
		foreach ( $verification as $from => $to ) {
			if ( ! empty( $general[ $from ] ) ) {
				$settings[ $to ] = \TagwrightSEO\Admin\Settings_Registry::sanitize_verification( $general[ $from ] );
			}
		}

		if ( $settings ) {
			Options::update( $settings );
		}

		return count( $settings );
	}

	/**
	 * Maps a separator character to one of ours.
	 *
	 * @param string $separator Rank Math separator.
	 * @return string
	 */
	private function separator( $separator ) {
		$map = array(
			'»' => '&raquo;',
			'«' => '&laquo;',
			'>' => '&gt;',
		);
		$separator = html_entity_decode( (string) $separator, ENT_QUOTES, 'UTF-8' );

		if ( isset( $map[ $separator ] ) ) {
			return $map[ $separator ];
		}

		return array_key_exists( $separator, \TagwrightSEO\Frontend\Replacer::separators() ) ? $separator : '-';
	}

	/**
	 * Converts Rank Math variables. They already use %name%, only a few differ.
	 *
	 * @param string $text Template.
	 * @return string
	 */
	protected function convert_variables( $text ) {
		$renames = array(
			'%term%'                   => '%term_title%',
			'%search_query%'           => '%searchphrase%',
			'%primary_category%'       => '%category%',
			'%primary_taxonomy_terms%' => '%category%',
			'%seo_title%'              => '%title%',
			'%seo_description%'        => '%excerpt%',
			'%post_thumbnail%'         => '',
			'%org_name%'               => '%sitename%',
			'%filename%'               => '%title%',
			'%keywords%'               => '%focuskw%',
			'%focuskw%'                => '%focuskw%',
			'%userid%'                 => '',
			'%post_author%'            => '%name%',
		);

		return \TagwrightSEO\Frontend\Replacer::sanitize_template( str_replace( array_keys( $renames ), array_values( $renames ), (string) $text ) );
	}
}
