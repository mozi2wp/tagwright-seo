<?php
/**
 * Settings storage.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin settings.
 *
 * Everything lives in one autoloaded option. WordPress loads all autoloaded
 * options in a single query on every request, so this costs nothing extra.
 * Per post type and per taxonomy keys are not stored until someone changes
 * them; until then their defaults are worked out on the fly.
 */
class Options {

	/**
	 * Name of the option row.
	 */
	const OPTION_NAME = 'tagwright_seo_settings';

	/**
	 * In-memory copy of the stored settings.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Static defaults. Dynamic keys are handled in dynamic_default().
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			// Search appearance.
			'separator'                  => '-',
			'home_title'                 => '%sitename% %page% %sep% %sitedesc%',
			'home_description'           => '',
			'home_keywords'              => '',
			'meta_keywords'              => false,
			'noindex_empty_terms'        => true,
			'noindex_paginated'          => false,
			'date_archive_title'         => '%date% %page% %sep% %sitename%',
			'date_archive_description'   => '',
			'date_archive_noindex'       => true,
			'author_archive_title'       => '%name% %page% %sep% %sitename%',
			'author_archive_description' => '',
			'author_archive_noindex'     => false,
			'search_title'               => '%searchphrase% %page% %sep% %sitename%',
			'not_found_title'            => 'Page not found %sep% %sitename%',

			// Social.
			'og_default_image_id' => 0,
			'home_og_title'       => '',
			'home_og_description' => '',
			'home_og_image_id'    => 0,
			'facebook_page_url'   => '',
			'facebook_app_id'     => '',
			'twitter_username'    => '',
			'twitter_card_type'   => 'summary_large_image',
			'social_profiles'     => '',

			// Schema.
			'site_represents'       => 'organization',
			'org_name'              => '',
			'org_alternate_name'    => '',
			'org_logo_id'           => 0,
			'org_email'             => '',
			'org_phone'             => '',
			'person_user_id'        => 0,
			'person_image_id'       => 0,
			'website_search_action' => true,

			// Sitemaps. Defaults follow the classic XML Sitemap Generator for Google.
			'sitemap_index_name'       => 'sitemap_index',
			'sitemap_max_entries'      => 1000,
			'sitemap_images'           => true,
			'sitemap_authors'          => true,
			'sitemap_exclude_ids'      => '',
			'sitemap_robots_txt'       => true,
			'sitemap_gzip'             => false,
			'sitemap_lastmod'          => true,
			'sitemap_changefreq'       => true,
			'sitemap_priority'         => true,
			'sitemap_priority_mode'    => 'manual',
			'sitemap_home_freq'        => 'daily',
			'sitemap_home_priority'    => '1.0',
			'sitemap_author_freq'      => 'weekly',
			'sitemap_author_priority'  => '0.3',
			'sitemap_archives'         => false,
			'sitemap_archive_freq'     => 'daily',
			'sitemap_archive_old_freq' => 'yearly',
			'sitemap_archive_priority' => '0.3',
			'sitemap_custom_urls'      => array(),

			// Webmaster verification.
			'verify_google'    => '',
			'verify_bing'      => '',
			'verify_yandex'    => '',
			'verify_baidu'     => '',
			'verify_pinterest' => '',

			// Technical.
			'attachment_redirect' => true,
			'remove_replytocom'   => true,
			'strip_category_base' => false,
			'robots_txt_enabled'  => false,
			'robots_txt_content'  => '',
			'llms_post_types'     => array( 'post', 'page' ),
			'llms_limit'          => 50,
			'llms_intro'          => '',

			// Content analysis.
			'analysis_readability' => true,

			// General.
			'admin_bar_menu'           => true,
			'delete_data_on_uninstall' => false,

			// Module switches. Add-ons append to this through the filter.
			'modules' => array(
				'social'           => true,
				'schema'           => true,
				'sitemaps'         => true,
				'content_analysis' => true,
				'llms_txt'         => false,
			),
		);

		// Built-in features bring their own defaults and module switches.
		$defaults = Features\Registry::add_defaults( $defaults );

		/**
		 * Filters the default settings so add-ons can register their own keys.
		 *
		 * @param array $defaults Default settings.
		 */
		return apply_filters( 'tagwright_seo_default_settings', $defaults );
	}

	/**
	 * Works out defaults for keys that depend on post types and taxonomies.
	 *
	 * Keys look like `pt_{post_type}_{field}`, `pta_{post_type}_{field}` for
	 * post type archives and `tax_{taxonomy}_{field}`.
	 *
	 * @param string $key Setting key.
	 * @return mixed|null Null when the key is unknown.
	 */
	public static function dynamic_default( $key ) {
		if ( preg_match( '/^pt_(.+)_(title|description|keywords|noindex|sitemap|sitemap_freq|sitemap_priority|article_type|page_type|analysis)$/', $key, $m ) ) {
			list( , $post_type, $field ) = $m;

			switch ( $field ) {
				case 'title':
					return '%title% %page% %sep% %sitename%';
				case 'description':
					return '%excerpt%';
				case 'keywords':
					return '';
				case 'noindex':
					return false;
				case 'sitemap':
					return 'attachment' !== $post_type;
				case 'sitemap_freq':
					return 'page' === $post_type ? 'weekly' : 'monthly';
				case 'sitemap_priority':
					return 'page' === $post_type ? '0.6' : ( 'post' === $post_type ? '0.6' : '0.5' );
				case 'article_type':
					return 'post' === $post_type ? 'BlogPosting' : 'None';
				case 'page_type':
					return 'WebPage';
				case 'analysis':
					return true;
			}
		}

		if ( preg_match( '/^pta_(.+)_(title|description|noindex)$/', $key, $m ) ) {
			switch ( $m[2] ) {
				case 'title':
					return '%pt_plural% %page% %sep% %sitename%';
				case 'description':
					return '';
				case 'noindex':
					return false;
			}
		}

		if ( preg_match( '/^tax_(.+)_(title|description|keywords|noindex|sitemap|sitemap_freq|sitemap_priority)$/', $key, $m ) ) {
			list( , $taxonomy, $field ) = $m;

			switch ( $field ) {
				case 'title':
					return '%term_title% %page% %sep% %sitename%';
				case 'description':
					return '%term_description%';
				case 'keywords':
					return '';
				case 'noindex':
					return 'post_format' === $taxonomy;
				case 'sitemap':
					return 'post_format' !== $taxonomy;
				case 'sitemap_freq':
					return 'weekly';
				case 'sitemap_priority':
					return '0.3';
			}
		}

		return null;
	}

	/**
	 * Returns every stored setting merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$settings ) {
			$stored         = get_option( self::OPTION_NAME, array() );
			$stored         = is_array( $stored ) ? $stored : array();
			$defaults       = self::defaults();
			self::$settings = array_merge( $defaults, $stored );

			// Merge module switches one level deep so new modules get their defaults.
			self::$settings['modules'] = array_merge(
				$defaults['modules'],
				isset( $stored['modules'] ) && is_array( $stored['modules'] ) ? $stored['modules'] : array()
			);
		}

		return self::$settings;
	}

	/**
	 * Reads one setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Returned when there is no stored value and no default.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$settings = self::all();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		$dynamic = self::dynamic_default( $key );

		return null === $dynamic ? $fallback : $dynamic;
	}

	/**
	 * Saves a batch of settings. Values must already be sanitized.
	 *
	 * @param array $values Key value pairs to store.
	 * @return bool
	 */
	public static function update( array $values ) {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();

		foreach ( $values as $key => $value ) {
			$stored[ $key ] = $value;
		}

		self::$settings = null;

		$result = update_option( self::OPTION_NAME, $stored, true );

		/**
		 * Fires after settings are saved.
		 *
		 * @param array $values The values that were changed.
		 */
		do_action( 'tagwright_seo_settings_updated', $values );

		return $result;
	}

	/**
	 * Replaces all settings, used by import and reset.
	 *
	 * @param array $settings Full settings array.
	 * @return void
	 */
	public static function replace( array $settings ) {
		self::$settings = null;
		update_option( self::OPTION_NAME, $settings, true );
		do_action( 'tagwright_seo_settings_updated', $settings );
	}

	/**
	 * Clears the in-memory copy, mostly useful in tests and after switch_to_blog().
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$settings = null;
	}
}
