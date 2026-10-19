<?php
/**
 * Yoast SEO importer.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Importers;

use TagwrightSEO\Data\Object_Sync;
use TagwrightSEO\Data\Objects;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Imports post, term and author data plus the main settings from Yoast SEO.
 * Yoast's own data is left untouched, so switching back is always possible.
 */
class Yoast extends Importer {

	/**
	 * Post meta keys Yoast uses.
	 *
	 * @var string[]
	 */
	const META_KEYS = array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		'_yoast_wpseo_canonical',
		'_yoast_wpseo_meta-robots-noindex',
		'_yoast_wpseo_meta-robots-nofollow',
		'_yoast_wpseo_opengraph-title',
		'_yoast_wpseo_opengraph-description',
		'_yoast_wpseo_twitter-title',
	);

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'yoast';
	}

	/**
	 * {@inheritdoc}
	 */
	public function name() {
		return 'Yoast SEO';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_detected() {
		return defined( 'WPSEO_VERSION' ) || false !== get_option( 'wpseo_titles' ) || $this->count_posts() > 0;
	}

	/**
	 * {@inheritdoc}
	 */
	public function count_posts() {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( self::META_KEYS ), '%s' ) );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", self::META_KEYS ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function post_ids( $offset, $limit ) {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( self::META_KEYS ), '%s' ) );

		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) ORDER BY post_id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					array_merge( self::META_KEYS, array( $limit, $offset ) )
				)
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function post_fields( $post_id ) {
		$meta = get_post_meta( $post_id );

		$noindex  = $this->meta( $meta, '_yoast_wpseo_meta-robots-noindex' );
		$nofollow = $this->meta( $meta, '_yoast_wpseo_meta-robots-nofollow' );
		$advanced = array_map( 'trim', explode( ',', $this->meta( $meta, '_yoast_wpseo_meta-robots-adv' ) ) );

		return array(
			'title'               => $this->convert_variables( $this->meta( $meta, '_yoast_wpseo_title' ) ),
			'description'         => $this->convert_variables( $this->meta( $meta, '_yoast_wpseo_metadesc' ) ),
			'focus_keyphrase'     => $this->meta( $meta, '_yoast_wpseo_focuskw' ),
			'canonical'           => esc_url_raw( $this->meta( $meta, '_yoast_wpseo_canonical' ) ),
			'robots_noindex'      => '1' === $noindex ? 1 : ( '2' === $noindex ? 0 : null ),
			'robots_nofollow'     => '1' === $nofollow ? 1 : null,
			'robots_noarchive'    => in_array( 'noarchive', $advanced, true ) ? 1 : null,
			'robots_nosnippet'    => in_array( 'nosnippet', $advanced, true ) ? 1 : null,
			'robots_noimageindex' => in_array( 'noimageindex', $advanced, true ) ? 1 : null,
			'og_title'            => $this->convert_variables( $this->meta( $meta, '_yoast_wpseo_opengraph-title' ) ),
			'og_description'      => $this->convert_variables( $this->meta( $meta, '_yoast_wpseo_opengraph-description' ) ),
			'og_image_id'         => absint( $this->meta( $meta, '_yoast_wpseo_opengraph-image-id' ) ),
			'og_image_url'        => absint( $this->meta( $meta, '_yoast_wpseo_opengraph-image-id' ) ) ? '' : esc_url_raw( $this->meta( $meta, '_yoast_wpseo_opengraph-image' ) ),
			'twitter_title'       => $this->convert_variables( $this->meta( $meta, '_yoast_wpseo_twitter-title' ) ),
			'twitter_description' => $this->convert_variables( $this->meta( $meta, '_yoast_wpseo_twitter-description' ) ),
			'schema_page_type'    => $this->schema_type( $this->meta( $meta, '_yoast_wpseo_schema_page_type' ), 'page' ),
			'schema_article_type' => $this->schema_type( $this->meta( $meta, '_yoast_wpseo_schema_article_type' ), 'article' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function import_terms_and_users() {
		$count    = 0;
		$taxonomy = get_option( 'wpseo_taxonomy_meta', array() );

		foreach ( (array) $taxonomy as $terms ) {
			foreach ( (array) $terms as $term_id => $data ) {
				if ( ! is_array( $data ) || ! get_term( (int) $term_id ) ) {
					continue;
				}

				$noindex = $data['wpseo_noindex'] ?? 'default';
				$fields  = array_filter(
					array(
						'title'               => $this->convert_variables( $data['wpseo_title'] ?? '' ),
						'description'         => $this->convert_variables( $data['wpseo_desc'] ?? '' ),
						'focus_keyphrase'     => (string) ( $data['wpseo_focuskw'] ?? '' ),
						'canonical'           => esc_url_raw( $data['wpseo_canonical'] ?? '' ),
						'robots_noindex'      => 'noindex' === $noindex ? 1 : ( 'index' === $noindex ? 0 : null ),
						'og_title'            => $this->convert_variables( $data['wpseo_opengraph-title'] ?? '' ),
						'og_description'      => $this->convert_variables( $data['wpseo_opengraph-description'] ?? '' ),
						'og_image_id'         => absint( $data['wpseo_opengraph-image-id'] ?? 0 ),
						'twitter_title'       => $this->convert_variables( $data['wpseo_twitter-title'] ?? '' ),
						'twitter_description' => $this->convert_variables( $data['wpseo_twitter-description'] ?? '' ),
					),
					static fn( $value ) => null !== $value && '' !== $value && 0 !== $value
				);

				if ( $fields ) {
					$term = get_term( (int) $term_id );
					Objects::save( 'term', (int) $term_id, $fields );
					Object_Sync::index_term( (int) $term_id, $term->taxonomy );
					++$count;
				}
			}
		}

		$users = get_users(
			array(
				'meta_key' => 'wpseo_title', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'   => 'ID',
				'number'   => 500,
			)
		);
		foreach ( $users as $user_id ) {
			$fields = array_filter(
				array(
					'title'       => $this->convert_variables( get_user_meta( $user_id, 'wpseo_title', true ) ),
					'description' => $this->convert_variables( get_user_meta( $user_id, 'wpseo_metadesc', true ) ),
				)
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
		$titles   = (array) get_option( 'wpseo_titles', array() );
		$social   = (array) get_option( 'wpseo_social', array() );
		$general  = (array) get_option( 'wpseo', array() );
		$settings = array();

		$separators = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '—',
			'sc-middot' => '·',
			'sc-bull'   => '•',
			'sc-star'   => '*',
			'sc-smstar' => '*',
			'sc-pipe'   => '|',
			'sc-tilde'  => '~',
			'sc-laquo'  => '&laquo;',
			'sc-raquo'  => '&raquo;',
			'sc-gt'     => '&gt;',
		);
		if ( isset( $titles['separator'], $separators[ $titles['separator'] ] ) ) {
			$settings['separator'] = $separators[ $titles['separator'] ];
		}

		$map = array(
			'title-home-wpseo'       => 'home_title',
			'metadesc-home-wpseo'    => 'home_description',
			'title-author-wpseo'     => 'author_archive_title',
			'metadesc-author-wpseo'  => 'author_archive_description',
			'title-archive-wpseo'    => 'date_archive_title',
			'metadesc-archive-wpseo' => 'date_archive_description',
			'title-search-wpseo'     => 'search_title',
			'title-404-wpseo'        => 'not_found_title',
		);
		foreach ( $map as $from => $to ) {
			if ( ! empty( $titles[ $from ] ) ) {
				$settings[ $to ] = $this->convert_variables( $titles[ $from ] );
			}
		}

		if ( isset( $titles['noindex-author-wpseo'] ) ) {
			$settings['author_archive_noindex'] = (bool) $titles['noindex-author-wpseo'];
		}
		if ( isset( $titles['noindex-archive-wpseo'] ) ) {
			$settings['date_archive_noindex'] = (bool) $titles['noindex-archive-wpseo'];
		}

		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( ! empty( $titles[ 'title-' . $post_type ] ) ) {
				$settings[ "pt_{$post_type}_title" ] = $this->convert_variables( $titles[ 'title-' . $post_type ] );
			}
			if ( ! empty( $titles[ 'metadesc-' . $post_type ] ) ) {
				$settings[ "pt_{$post_type}_description" ] = $this->convert_variables( $titles[ 'metadesc-' . $post_type ] );
			}
			if ( isset( $titles[ 'noindex-' . $post_type ] ) ) {
				$settings[ "pt_{$post_type}_noindex" ] = (bool) $titles[ 'noindex-' . $post_type ];
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			if ( ! empty( $titles[ 'title-tax-' . $taxonomy ] ) ) {
				$settings[ "tax_{$taxonomy}_title" ] = $this->convert_variables( $titles[ 'title-tax-' . $taxonomy ] );
			}
			if ( ! empty( $titles[ 'metadesc-tax-' . $taxonomy ] ) ) {
				$settings[ "tax_{$taxonomy}_description" ] = $this->convert_variables( $titles[ 'metadesc-tax-' . $taxonomy ] );
			}
			if ( isset( $titles[ 'noindex-tax-' . $taxonomy ] ) ) {
				$settings[ "tax_{$taxonomy}_noindex" ] = (bool) $titles[ 'noindex-tax-' . $taxonomy ];
			}
		}

		if ( ! empty( $titles['company_or_person'] ) ) {
			$settings['site_represents'] = 'person' === $titles['company_or_person'] ? 'person' : 'organization';
		}
		if ( ! empty( $titles['company_name'] ) ) {
			$settings['org_name'] = sanitize_text_field( $titles['company_name'] );
		}
		if ( ! empty( $titles['company_logo_id'] ) ) {
			$settings['org_logo_id'] = absint( $titles['company_logo_id'] );
		}
		if ( ! empty( $titles['company_or_person_user_id'] ) ) {
			$settings['person_user_id'] = absint( $titles['company_or_person_user_id'] );
		}

		if ( ! empty( $social['facebook_site'] ) ) {
			$settings['facebook_page_url'] = esc_url_raw( $social['facebook_site'] );
		}
		if ( ! empty( $social['twitter_site'] ) ) {
			$settings['twitter_username'] = sanitize_text_field( $social['twitter_site'] );
		}
		if ( ! empty( $social['og_default_image_id'] ) ) {
			$settings['og_default_image_id'] = absint( $social['og_default_image_id'] );
		}
		if ( ! empty( $social['other_social_urls'] ) && is_array( $social['other_social_urls'] ) ) {
			$settings['social_profiles'] = implode( "\n", array_map( 'esc_url_raw', $social['other_social_urls'] ) );
		}

		$verification = array(
			'googleverify'    => 'verify_google',
			'msverify'        => 'verify_bing',
			'yandexverify'    => 'verify_yandex',
			'baiduverify'     => 'verify_baidu',
			'pinterestverify' => 'verify_pinterest',
		);
		foreach ( $verification as $from => $to ) {
			$value = $general[ $from ] ?? ( $social[ $from ] ?? '' );
			if ( $value ) {
				$settings[ $to ] = sanitize_text_field( $value );
			}
		}

		if ( $settings ) {
			Options::update( $settings );
		}

		return count( $settings );
	}

	/**
	 * Converts %%variables%% to %variables%.
	 *
	 * @param string $text Yoast template.
	 * @return string
	 */
	protected function convert_variables( $text ) {
		$map = array(
			'title'                => 'title',
			'sitename'             => 'sitename',
			'sitedesc'             => 'sitedesc',
			'sep'                  => 'sep',
			'excerpt'              => 'excerpt',
			'excerpt_only'         => 'excerpt_only',
			'category'             => 'category',
			'primary_category'     => 'category',
			'tag'                  => 'tag',
			'term_title'           => 'term_title',
			'term_description'     => 'term_description',
			'category_description' => 'term_description',
			'tag_description'      => 'term_description',
			'name'                 => 'name',
			'date'                 => 'date',
			'modified'             => 'modified',
			'currentdate'          => 'currentdate',
			'currentyear'          => 'currentyear',
			'currentmonth'         => 'currentmonth',
			'page'                 => 'page',
			'pagenumber'           => 'pagenumber',
			'pagetotal'            => 'pagetotal',
			'pt_single'            => 'pt_single',
			'pt_plural'            => 'pt_plural',
			'parent_title'         => 'parent_title',
			'searchphrase'         => 'searchphrase',
			'focuskw'              => 'focuskw',
			'id'                   => 'id',
			'archive_title'        => 'title',
		);

		$text = preg_replace_callback(
			'/%%([a-z_]+)%%/i',
			static function ( $match ) use ( $map ) {
				$name = strtolower( $match[1] );
				if ( 0 === strpos( $name, 'cf_' ) ) {
					return '%customfield(' . substr( $name, 3 ) . ')%';
				}
				return isset( $map[ $name ] ) ? '%' . $map[ $name ] . '%' : '';
			},
			(string) $text
		);

		return \TagwrightSEO\Frontend\Replacer::sanitize_template( $text );
	}
}
