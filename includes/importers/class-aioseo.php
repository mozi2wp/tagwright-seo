<?php
/**
 * All in One SEO importer.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Importers;

use TagwrightSEO\Data\Object_Sync;
use TagwrightSEO\Data\Objects;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Imports post and term data plus a few key settings from All in One SEO,
 * which keeps its data in its own aioseo_posts and aioseo_terms tables.
 */
class Aioseo extends Importer {

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'aioseo';
	}

	/**
	 * {@inheritdoc}
	 */
	public function name() {
		return 'All in One SEO';
	}

	/**
	 * Name of an AIOSEO table, or an empty string when it does not exist.
	 *
	 * @param string $name `posts` or `terms`.
	 * @return string
	 */
	private function table( $name ) {
		global $wpdb;

		static $exists = array();

		$table = $wpdb->prefix . 'aioseo_' . $name;
		if ( ! isset( $exists[ $table ] ) ) {
			$exists[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return $exists[ $table ] ? $table : '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_detected() {
		return '' !== $this->table( 'posts' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function count_posts() {
		global $wpdb;

		$table = $this->table( 'posts' );

		return $table ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * {@inheritdoc}
	 */
	protected function post_ids( $offset, $limit ) {
		global $wpdb;

		$table = $this->table( 'posts' );
		if ( ! $table ) {
			return array();
		}

		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$table} ORDER BY post_id ASC LIMIT %d OFFSET %d", $limit, $offset ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function post_fields( $post_id ) {
		global $wpdb;

		$table = $this->table( 'posts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return $row ? $this->map_row( $row ) : array();
	}

	/**
	 * Maps an AIOSEO row to our fields.
	 *
	 * @param array $row Row from aioseo_posts or aioseo_terms.
	 * @return array
	 */
	private function map_row( array $row ) {
		$keyphrase = '';
		if ( ! empty( $row['keyphrases'] ) ) {
			$decoded   = json_decode( $row['keyphrases'], true );
			$keyphrase = (string) ( $decoded['focus']['keyphrase'] ?? '' );
		}

		// AIOSEO only applies its robots columns when robots_default is off.
		$custom_robots = isset( $row['robots_default'] ) && ! (int) $row['robots_default'];
		$flag          = static fn( $key ) => $custom_robots && ! empty( $row[ $key ] ) ? 1 : null;

		return array(
			'title'               => $this->convert_variables( $row['title'] ?? '' ),
			'description'         => $this->convert_variables( $row['description'] ?? '' ),
			'focus_keyphrase'     => $keyphrase,
			'canonical'           => esc_url_raw( $row['canonical_url'] ?? '' ),
			'robots_noindex'      => $custom_robots ? ( empty( $row['robots_noindex'] ) ? 0 : 1 ) : null,
			'robots_nofollow'     => $flag( 'robots_nofollow' ),
			'robots_noarchive'    => $flag( 'robots_noarchive' ),
			'robots_nosnippet'    => $flag( 'robots_nosnippet' ),
			'robots_noimageindex' => $flag( 'robots_noimageindex' ),
			'og_title'            => $this->convert_variables( $row['og_title'] ?? '' ),
			'og_description'      => $this->convert_variables( $row['og_description'] ?? '' ),
			'og_image_url'        => esc_url_raw( $row['og_image_custom_url'] ?? '' ),
			'twitter_title'       => $this->convert_variables( $row['twitter_title'] ?? '' ),
			'twitter_description' => $this->convert_variables( $row['twitter_description'] ?? '' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function import_terms_and_users() {
		global $wpdb;

		$table = $this->table( 'terms' );
		if ( ! $table ) {
			return 0;
		}

		$count = 0;
		$rows  = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		foreach ( (array) $rows as $row ) {
			$term = get_term( (int) $row['term_id'] );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$fields = array_filter( $this->map_row( $row ), static fn( $value ) => null !== $value && '' !== $value );
			if ( $fields ) {
				Objects::save( 'term', (int) $term->term_id, $fields );
				Object_Sync::index_term( (int) $term->term_id, $term->taxonomy );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * {@inheritdoc}
	 */
	public function import_settings() {
		$options  = json_decode( (string) get_option( 'aioseo_options', '' ), true );
		$settings = array();

		if ( ! is_array( $options ) ) {
			return 0;
		}

		$global = $options['searchAppearance']['global'] ?? array();
		if ( ! empty( $global['siteTitle'] ) ) {
			$settings['home_title'] = $this->convert_variables( $global['siteTitle'] );
		}
		if ( ! empty( $global['metaDescription'] ) ) {
			$settings['home_description'] = $this->convert_variables( $global['metaDescription'] );
		}
		if ( ! empty( $global['separator'] ) ) {
			$separator = html_entity_decode( $global['separator'], ENT_QUOTES, 'UTF-8' );
			$map       = array(
				'»' => '&raquo;',
				'«' => '&laquo;',
				'>' => '&gt;',
			);
			$separator = $map[ $separator ] ?? $separator;
			if ( array_key_exists( $separator, \TagwrightSEO\Frontend\Replacer::separators() ) ) {
				$settings['separator'] = $separator;
			}
		}

		$webmaster = $options['webmasterTools'] ?? array();
		foreach ( array( 'google', 'bing', 'yandex', 'baidu', 'pinterest' ) as $engine ) {
			if ( ! empty( $webmaster[ $engine ] ) ) {
				$settings[ 'verify_' . $engine ] = \TagwrightSEO\Admin\Settings_Registry::sanitize_verification( $webmaster[ $engine ] );
			}
		}

		$profiles = $options['social']['profiles']['urls'] ?? array();
		if ( is_array( $profiles ) ) {
			$urls = array_filter( array_map( 'esc_url_raw', array_values( $profiles ) ) );
			if ( $urls ) {
				$settings['social_profiles'] = implode( "\n", $urls );
			}
		}

		if ( $settings ) {
			Options::update( $settings );
		}

		return count( $settings );
	}

	/**
	 * Converts #smart_tags to %variables%.
	 *
	 * @param string $text AIOSEO template.
	 * @return string
	 */
	protected function convert_variables( $text ) {
		$map = array(
			'#post_title'           => '%title%',
			'#site_title'           => '%sitename%',
			'#tagline'              => '%sitedesc%',
			'#separator_sa'         => '%sep%',
			'#post_excerpt_only'    => '%excerpt_only%',
			'#post_excerpt'         => '%excerpt%',
			'#post_content'         => '%excerpt%',
			'#taxonomy_title'       => '%term_title%',
			'#taxonomy_description' => '%term_description%',
			'#author_name'          => '%name%',
			'#author_first_name'    => '%name%',
			'#post_date'            => '%date%',
			'#current_year'         => '%currentyear%',
			'#current_month'        => '%currentmonth%',
			'#current_date'         => '%currentdate%',
			'#page_number'          => '%page%',
			'#categories'           => '%categories%',
			'#search_term'          => '%searchphrase%',
			'#archive_title'        => '%title%',
			'#permalink'            => '',
		);

		// Longest first so #post_excerpt_only wins over #post_excerpt.
		uksort( $map, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$text = str_replace( array_keys( $map ), array_values( $map ), (string) $text );

		// Drop any smart tags we do not know.
		$text = preg_replace( '/#[a-z_]+/i', '', $text );

		return \TagwrightSEO\Frontend\Replacer::sanitize_template( $text );
	}
}
