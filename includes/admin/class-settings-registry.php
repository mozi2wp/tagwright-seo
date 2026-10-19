<?php
/**
 * Settings tabs, sections and fields.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

use TagwrightSEO\Frontend\Replacer;
use TagwrightSEO\Modules;
use TagwrightSEO\Options;
use TagwrightSEO\Schema\Graph;

defined( 'ABSPATH' ) || exit;

/**
 * Describes every setting once. The same description drives the settings
 * screen and the sanitizer, so a field can never be shown without being
 * validated, or saved without being shown.
 */
class Settings_Registry {

	/**
	 * Cached tabs.
	 *
	 * @var array|null
	 */
	private static $tabs = null;

	/**
	 * Groups shown in the side navigation.
	 *
	 * @return array<string, string>
	 */
	public static function groups() {
		return apply_filters(
			'tagwright_seo_settings_groups',
			array(
				'appearance' => __( 'Search Appearance', 'tagwright-seo' ),
				'features'   => __( 'Features', 'tagwright-seo' ),
				'advanced'   => __( 'Advanced', 'tagwright-seo' ),
				'addons'     => __( 'Add-ons', 'tagwright-seo' ),
			)
		);
	}

	/**
	 * Returns every tab with its sections and fields.
	 *
	 * @return array[]
	 */
	public static function tabs() {
		if ( null !== self::$tabs ) {
			return self::$tabs;
		}

		$tabs = array(
			'general'    => self::general_tab(),
			'content'    => self::content_tab(),
			'taxonomies' => self::taxonomies_tab(),
			'archives'   => self::archives_tab(),
			'social'     => self::social_tab(),
			'schema'     => self::schema_tab(),
			'sitemaps'   => self::sitemaps_tab(),
			'webmaster'  => self::webmaster_tab(),
			'technical'  => self::technical_tab(),
		);

		// Tabs of the built-in features sit between Technical and Modules.
		$tabs = array_merge( $tabs, \TagwrightSEO\Features\Registry::tabs() );

		$tabs += array(
			'modules' => array(
				'title'       => __( 'Modules', 'tagwright-seo' ),
				'description' => __( 'Switch features on or off. A module that is off loads no code at all.', 'tagwright-seo' ),
				'icon'        => 'layers',
				'group'       => 'advanced',
				'render'      => array( Settings_Page::class, 'render_modules' ),
				'sections'    => array(),
			),
		);

		/**
		 * Filters the settings tabs. Add-ons add their own tab here, using
		 * the same field types as the core so it looks and validates the same.
		 *
		 * A tab is an array with title, description, icon, group and
		 * sections. Each section has title, description and fields.
		 *
		 * @param array $tabs Tabs keyed by id.
		 */
		self::$tabs = apply_filters( 'tagwright_seo_settings_tabs', $tabs );

		return self::$tabs;
	}

	/**
	 * Flat list of every field, keyed by setting key.
	 *
	 * @return array[]
	 */
	public static function fields() {
		$fields = array();

		foreach ( self::tabs() as $tab ) {
			foreach ( $tab['sections'] ?? array() as $section ) {
				foreach ( $section['fields'] ?? array() as $field ) {
					// A group shows several controls on one row; each is its own setting.
					$children = 'group' === ( $field['type'] ?? '' ) ? ( $field['fields'] ?? array() ) : array( $field );

					foreach ( $children as $child ) {
						$type = $child['type'] ?? '';

						// An html field draws its own controls; it stores a value only when it brings its own sanitize callback.
						if ( 'html' === $type && ! empty( $child['sanitize'] ) && ! empty( $child['id'] ) ) {
							$fields[ $child['id'] ] = $child;
							continue;
						}

						if ( ! empty( $child['id'] ) && ! in_array( $type, array( 'heading', 'html' ), true ) ) {
							$fields[ $child['id'] ] = $child;
						}
					}
				}
			}
		}

		return $fields;
	}

	/**
	 * Cleans submitted values. Unknown keys are dropped.
	 *
	 * @param array $input Raw values keyed by setting key.
	 * @return array Clean values.
	 */
	public static function sanitize( array $input ) {
		$fields = self::fields();
		$clean  = array();

		foreach ( $input as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}

			$clean[ $key ] = self::sanitize_value( $value, $fields[ $key ] );
		}

		return $clean;
	}

	/**
	 * Sanitizes one value by field type.
	 *
	 * @param mixed $value Raw value.
	 * @param array $field Field definition.
	 * @return mixed
	 */
	public static function sanitize_value( $value, array $field ) {
		if ( isset( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
			return call_user_func( $field['sanitize'], $value, $field );
		}

		$type = $field['type'] ?? 'text';

		switch ( $type ) {
			case 'toggle':
				return in_array( $value, array( true, 1, '1', 'true', 'on' ), true );

			case 'number':
				$value = (int) $value;
				if ( isset( $field['min'] ) ) {
					$value = max( (int) $field['min'], $value );
				}
				if ( isset( $field['max'] ) ) {
					$value = min( (int) $field['max'], $value );
				}
				return $value;

			case 'image':
				return absint( $value );

			case 'url':
				return esc_url_raw( trim( (string) $value ) );

			case 'email':
				return sanitize_email( (string) $value );

			case 'select':
			case 'segmented':
			case 'radio_cards':
				$value   = (string) $value;
				$options = array_map( 'strval', array_keys( $field['options'] ?? array() ) );
				return in_array( $value, $options, true ) ? $value : (string) ( $field['default'] ?? reset( $options ) );

			case 'checkboxes':
				$options = array_map( 'strval', array_keys( $field['options'] ?? array() ) );
				return array_values( array_intersect( array_map( 'strval', (array) $value ), $options ) );

			case 'date':
				$value = sanitize_text_field( (string) $value );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';

			case 'repeater':
				$rows = array();
				foreach ( (array) $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean_row = array();
					foreach ( $field['columns'] ?? array() as $column ) {
						$clean_row[ $column['id'] ] = self::sanitize_value( $row[ $column['id'] ] ?? '', $column );
					}
					// Rows without their first column (for example an empty URL) are dropped.
					$first = reset( $clean_row );
					if ( '' !== $first && null !== $first ) {
						$rows[] = $clean_row;
					}
				}
				return array_slice( $rows, 0, (int) ( $field['max_rows'] ?? 500 ) );

			case 'template':
			case 'template_textarea':
				return Replacer::sanitize_template( $value );

			case 'textarea':
			case 'code':
				return sanitize_textarea_field( (string) $value );

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * General tab.
	 *
	 * @return array
	 */
	private static function general_tab() {
		$separators = array();
		foreach ( Replacer::separators() as $key => $character ) {
			$separators[ $key ] = $character;
		}

		return array(
			'title'       => __( 'General', 'tagwright-seo' ),
			'description' => __( 'Site-wide defaults and your homepage in search results.', 'tagwright-seo' ),
			'icon'        => 'title',
			'group'       => 'appearance',
			'sections'    => array(
				array(
					'title'       => __( 'Titles', 'tagwright-seo' ),
					'description' => __( 'How titles are put together across the site.', 'tagwright-seo' ),
					'icon'        => 'title',
					'fields'      => array(
						array(
							'id'          => 'separator',
							'type'        => 'segmented',
							'label'       => __( 'Title separator', 'tagwright-seo' ),
							/* translators: %sep% is the name of a title variable; keep it as it is. */
							'description' => __( 'Shown wherever you use the %sep% variable, usually between the page title and your site name.', 'tagwright-seo' ),
							'options'     => $separators,
							'default'     => '-',
						),
						array(
							'id'           => 'meta_keywords',
							'type'         => 'toggle',
							'label'        => __( 'Meta keywords', 'tagwright-seo' ),
							'description'  => __( 'Adds the legacy keywords meta tag next to the title and description. Google and Bing ignore it, but some internal search tools and smaller engines still read it. Turning it on adds a keywords field everywhere you can edit SEO data.', 'tagwright-seo' ),
							'toggle_label' => __( 'Output meta keywords', 'tagwright-seo' ),
							'badge'        => __( 'Legacy', 'tagwright-seo' ),
							'badge_type'   => 'muted',
						),
					),
				),
				array(
					'title'       => __( 'Homepage', 'tagwright-seo' ),
					'description' => self::homepage_note(),
					'icon'        => 'home',
					'preview'     => array(
						'type'        => 'serp',
						'context'     => array( 'kind' => 'home' ),
						'title'       => array( 'home_title' ),
						'description' => array( 'home_description' ),
					),
					'fields'      => array(
						array(
							'id'          => 'home_title',
							'type'        => 'template',
							'label'       => __( 'Homepage title', 'tagwright-seo' ),
							'placeholder' => '%sitename% %sep% %sitedesc%',
						),
						array(
							'id'          => 'home_description',
							'type'        => 'template_textarea',
							'label'       => __( 'Homepage description', 'tagwright-seo' ),
							'description' => __( 'Aim for 120 to 160 characters that make people want to click.', 'tagwright-seo' ),
						),
						array(
							'id'          => 'home_keywords',
							'type'        => 'text',
							'label'       => __( 'Homepage keywords', 'tagwright-seo' ),
							'description' => __( 'Comma separated.', 'tagwright-seo' ),
							'show_if'     => array(
								'field'   => 'meta_keywords',
								'checked' => true,
							),
						),
					),
				),
				array(
					'title'  => __( 'Indexing', 'tagwright-seo' ),
					'icon'   => 'search',
					'fields' => array(
						array(
							'id'           => 'noindex_empty_terms',
							'type'         => 'toggle',
							'label'        => __( 'Empty archives', 'tagwright-seo' ),
							'description'  => __( 'Keep categories and tags without any posts out of search results and sitemaps.', 'tagwright-seo' ),
							'toggle_label' => __( 'Noindex empty category and tag archives', 'tagwright-seo' ),
						),
						array(
							'id'           => 'noindex_paginated',
							'type'         => 'toggle',
							'label'        => __( 'Paginated pages', 'tagwright-seo' ),
							'description'  => __( 'Usually best left off. Google recommends letting page 2, 3 and so on be indexed so it can find older posts.', 'tagwright-seo' ),
							'toggle_label' => __( 'Noindex page 2 and beyond', 'tagwright-seo' ),
						),
						array(
							'id'           => 'analysis_readability',
							'type'         => 'toggle',
							'label'        => __( 'Readability analysis', 'tagwright-seo' ),
							'description'  => __( 'Shows readability checks next to the SEO checks in the editor.', 'tagwright-seo' ),
							'toggle_label' => __( 'Show readability analysis', 'tagwright-seo' ),
						),
						array(
							'id'           => 'admin_bar_menu',
							'type'         => 'toggle',
							'label'        => __( 'Toolbar menu', 'tagwright-seo' ),
							'description'  => __( 'Adds a Tagwright SEO menu with the page score and handy testing links to the toolbar.', 'tagwright-seo' ),
							'toggle_label' => __( 'Show in the toolbar', 'tagwright-seo' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Content types tab, one card per public post type.
	 *
	 * @return array
	 */
	private static function content_tab() {
		$sections = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$name   = $post_type->name;
			$fields = array(
				array(
					'id'          => "pt_{$name}_title",
					'type'        => 'template',
					'label'       => __( 'SEO title', 'tagwright-seo' ),
					'placeholder' => '%title% %sep% %sitename%',
				),
				array(
					'id'    => "pt_{$name}_description",
					'type'  => 'template_textarea',
					'label' => __( 'Meta description', 'tagwright-seo' ),
				),
				array(
					'id'          => "pt_{$name}_keywords",
					'type'        => 'template',
					'label'       => __( 'Meta keywords', 'tagwright-seo' ),
					'description' => __( 'Used when a post has no keywords of its own. Try %tags%.', 'tagwright-seo' ),
					'show_if'     => array(
						'field'   => 'meta_keywords',
						'checked' => true,
					),
				),
				array(
					'id'           => "pt_{$name}_noindex",
					'type'         => 'toggle',
					'label'        => __( 'Search visibility', 'tagwright-seo' ),
					/* translators: %s: post type name. */
					'toggle_label' => sprintf( __( 'Hide %s from search results (noindex)', 'tagwright-seo' ), strtolower( $post_type->labels->name ) ),
				),
				array(
					'id'      => "pt_{$name}_page_type",
					'type'    => 'select',
					'label'   => __( 'Schema page type', 'tagwright-seo' ),
					'options' => Graph::page_types(),
					'default' => 'WebPage',
				),
				array(
					'id'      => "pt_{$name}_article_type",
					'type'    => 'select',
					'label'   => __( 'Schema article type', 'tagwright-seo' ),
					'options' => Graph::article_types(),
					'default' => 'None',
				),
				array(
					'id'           => "pt_{$name}_analysis",
					'type'         => 'toggle',
					'label'        => __( 'Content analysis', 'tagwright-seo' ),
					'toggle_label' => __( 'Show SEO checks in the editor', 'tagwright-seo' ),
				),
			);

			if ( 'attachment' === $name ) {
				$fields = array_values(
					array_filter(
						$fields,
						static fn( $field ) => ! in_array( $field['id'], array( "pt_{$name}_analysis", "pt_{$name}_article_type" ), true )
					)
				);
			}

			$sections[] = array(
				'title'       => $post_type->labels->name,
				/* translators: %s: post type slug. */
				'description' => sprintf( __( 'Defaults for %s. Each item can override these in the editor.', 'tagwright-seo' ), '<code>' . esc_html( $name ) . '</code>' ),
				'icon'        => 'page' === $name ? 'file' : ( 'attachment' === $name ? 'image' : 'pen' ),
				'accordion'   => true,
				'open'        => 'post' === $name,
				'preview'     => array(
					'type'        => 'serp',
					'context'     => array(
						'kind' => 'post_type',
						'name' => $name,
					),
					'title'       => array( "pt_{$name}_title" ),
					'description' => array( "pt_{$name}_description" ),
				),
				'fields'      => $fields,
			);

			if ( $post_type->has_archive ) {
				$sections[] = array(
					/* translators: %s: post type name. */
					'title'     => sprintf( __( '%s archive', 'tagwright-seo' ), $post_type->labels->name ),
					'icon'      => 'layers',
					'accordion' => true,
					'preview'   => array(
						'type'        => 'serp',
						'context'     => array(
							'kind' => 'post_type_archive',
							'name' => $name,
						),
						'title'       => array( "pta_{$name}_title" ),
						'description' => array( "pta_{$name}_description" ),
					),
					'fields'    => array(
						array(
							'id'          => "pta_{$name}_title",
							'type'        => 'template',
							'label'       => __( 'Archive title', 'tagwright-seo' ),
							'placeholder' => '%pt_plural% %sep% %sitename%',
						),
						array(
							'id'    => "pta_{$name}_description",
							'type'  => 'template_textarea',
							'label' => __( 'Archive description', 'tagwright-seo' ),
						),
						array(
							'id'           => "pta_{$name}_noindex",
							'type'         => 'toggle',
							'label'        => __( 'Search visibility', 'tagwright-seo' ),
							'toggle_label' => __( 'Hide this archive from search results', 'tagwright-seo' ),
						),
					),
				);
			}
		}

		return array(
			'title'       => __( 'Content Types', 'tagwright-seo' ),
			'description' => __( 'Title and description templates, indexing, sitemap and schema defaults for each type of content.', 'tagwright-seo' ),
			'icon'        => 'file',
			'group'       => 'appearance',
			'sections'    => $sections,
		);
	}

	/**
	 * Taxonomies tab.
	 *
	 * @return array
	 */
	private static function taxonomies_tab() {
		$sections = array();

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$name = $taxonomy->name;

			$sections[] = array(
				'title'       => $taxonomy->labels->name,
				/* translators: %s: taxonomy slug. */
				'description' => sprintf( __( 'Defaults for %s archives.', 'tagwright-seo' ), '<code>' . esc_html( $name ) . '</code>' ),
				'icon'        => 'tag',
				'accordion'   => true,
				'open'        => 'category' === $name,
				'preview'     => array(
					'type'        => 'serp',
					'context'     => array(
						'kind' => 'taxonomy',
						'name' => $name,
					),
					'title'       => array( "tax_{$name}_title" ),
					'description' => array( "tax_{$name}_description" ),
				),
				'fields'      => array(
					array(
						'id'          => "tax_{$name}_title",
						'type'        => 'template',
						'label'       => __( 'SEO title', 'tagwright-seo' ),
						'placeholder' => '%term_title% %sep% %sitename%',
					),
					array(
						'id'    => "tax_{$name}_description",
						'type'  => 'template_textarea',
						'label' => __( 'Meta description', 'tagwright-seo' ),
					),
					array(
						'id'      => "tax_{$name}_keywords",
						'type'    => 'template',
						'label'   => __( 'Meta keywords', 'tagwright-seo' ),
						'show_if' => array(
							'field'   => 'meta_keywords',
							'checked' => true,
						),
					),
					array(
						'id'           => "tax_{$name}_noindex",
						'type'         => 'toggle',
						'label'        => __( 'Search visibility', 'tagwright-seo' ),
						'toggle_label' => __( 'Hide these archives from search results (noindex)', 'tagwright-seo' ),
					),
				),
			);
		}

		return array(
			'title'       => __( 'Taxonomies', 'tagwright-seo' ),
			'description' => __( 'Categories, tags and custom taxonomies.', 'tagwright-seo' ),
			'icon'        => 'tag',
			'group'       => 'appearance',
			'sections'    => $sections,
		);
	}

	/**
	 * Archives and special pages tab.
	 *
	 * @return array
	 */
	private static function archives_tab() {
		return array(
			'title'       => __( 'Archives', 'tagwright-seo' ),
			'description' => __( 'Author and date archives, search results and the 404 page.', 'tagwright-seo' ),
			'icon'        => 'layers',
			'group'       => 'appearance',
			'sections'    => array(
				array(
					'title'   => __( 'Author archives', 'tagwright-seo' ),
					'icon'    => 'user',
					'preview' => array(
						'type'        => 'serp',
						'context'     => array( 'kind' => 'author' ),
						'title'       => array( 'author_archive_title' ),
						'description' => array( 'author_archive_description' ),
					),
					'fields'  => array(
						array(
							'id'           => 'author_archive_noindex',
							'type'         => 'toggle',
							'label'        => __( 'Search visibility', 'tagwright-seo' ),
							'description'  => __( 'On a site with a single author the author archive duplicates the blog. Hiding it is a good idea there.', 'tagwright-seo' ),
							'toggle_label' => __( 'Hide author archives from search results', 'tagwright-seo' ),
						),
						array(
							'id'          => 'author_archive_title',
							'type'        => 'template',
							'label'       => __( 'SEO title', 'tagwright-seo' ),
							'placeholder' => '%name% %sep% %sitename%',
						),
						array(
							'id'    => 'author_archive_description',
							'type'  => 'template_textarea',
							'label' => __( 'Meta description', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'   => __( 'Date archives', 'tagwright-seo' ),
					'icon'    => 'clock',
					'preview' => array(
						'type'        => 'serp',
						'context'     => array( 'kind' => 'date' ),
						'title'       => array( 'date_archive_title' ),
						'description' => array( 'date_archive_description' ),
					),
					'fields'  => array(
						array(
							'id'           => 'date_archive_noindex',
							'type'         => 'toggle',
							'label'        => __( 'Search visibility', 'tagwright-seo' ),
							'toggle_label' => __( 'Hide date archives from search results', 'tagwright-seo' ),
						),
						array(
							'id'          => 'date_archive_title',
							'type'        => 'template',
							'label'       => __( 'SEO title', 'tagwright-seo' ),
							'placeholder' => '%date% %sep% %sitename%',
						),
						array(
							'id'    => 'date_archive_description',
							'type'  => 'template_textarea',
							'label' => __( 'Meta description', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'       => __( 'Special pages', 'tagwright-seo' ),
					'description' => __( 'These pages are always kept out of search results.', 'tagwright-seo' ),
					'icon'        => 'search',
					'fields'      => array(
						array(
							'id'          => 'search_title',
							'type'        => 'template',
							'label'       => __( 'Search results title', 'tagwright-seo' ),
							'placeholder' => '%searchphrase% %sep% %sitename%',
						),
						array(
							'id'          => 'not_found_title',
							'type'        => 'template',
							'label'       => __( '404 page title', 'tagwright-seo' ),
							'placeholder' => 'Page not found %sep% %sitename%',
						),
					),
				),
			),
		);
	}

	/**
	 * Social tab.
	 *
	 * @return array
	 */
	private static function social_tab() {
		return array(
			'title'       => __( 'Social', 'tagwright-seo' ),
			'description' => __( 'Control how your pages look when they are shared on Facebook, X, LinkedIn, WhatsApp and others.', 'tagwright-seo' ),
			'icon'        => 'share',
			'group'       => 'features',
			'module'      => 'social',
			'sections'    => array(
				array(
					'title'   => __( 'Default sharing image', 'tagwright-seo' ),
					'icon'    => 'image',
					'preview' => array(
						'type'        => 'social',
						'context'     => array(
							'kind' => 'post_type',
							'name' => 'post',
						),
						'title'       => array( 'pt_post_title' ),
						'description' => array( 'pt_post_description' ),
						'image'       => array( 'og_default_image_id' ),
					),
					'fields'  => array(
						array(
							'id'          => 'og_default_image_id',
							'type'        => 'image',
							'label'       => __( 'Fallback image', 'tagwright-seo' ),
							'description' => __( 'Used when a page has no featured image or images of its own.', 'tagwright-seo' ),
							'recommended' => __( 'Recommended size: 1200 × 630 pixels.', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'   => __( 'Homepage sharing', 'tagwright-seo' ),
					'icon'    => 'home',
					'preview' => array(
						'type'        => 'social',
						'context'     => array( 'kind' => 'home' ),
						'title'       => array( 'home_og_title', 'home_title' ),
						'description' => array( 'home_og_description', 'home_description' ),
						'image'       => array( 'home_og_image_id', 'og_default_image_id' ),
					),
					'fields'  => array(
						array(
							'id'          => 'home_og_title',
							'type'        => 'template',
							'label'       => __( 'Title', 'tagwright-seo' ),
							'description' => __( 'Leave empty to use the homepage SEO title.', 'tagwright-seo' ),
						),
						array(
							'id'          => 'home_og_description',
							'type'        => 'template_textarea',
							'label'       => __( 'Description', 'tagwright-seo' ),
							'description' => __( 'Leave empty to use the homepage meta description.', 'tagwright-seo' ),
						),
						array(
							'id'          => 'home_og_image_id',
							'type'        => 'image',
							'label'       => __( 'Image', 'tagwright-seo' ),
							'recommended' => __( 'Recommended size: 1200 × 630 pixels.', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'  => __( 'Accounts', 'tagwright-seo' ),
					'icon'   => 'user',
					'fields' => array(
						array(
							'id'          => 'facebook_page_url',
							'type'        => 'url',
							'label'       => __( 'Facebook page URL', 'tagwright-seo' ),
							'placeholder' => 'https://www.facebook.com/yourpage',
						),
						array(
							'id'          => 'facebook_app_id',
							'type'        => 'text',
							'label'       => __( 'Facebook app ID', 'tagwright-seo' ),
							'description' => __( 'Optional. Only needed for Facebook Insights.', 'tagwright-seo' ),
						),
						array(
							'id'          => 'twitter_username',
							'type'        => 'text',
							'label'       => __( 'X (Twitter) username', 'tagwright-seo' ),
							'placeholder' => '@yourhandle',
						),
						array(
							'id'      => 'twitter_card_type',
							'type'    => 'segmented',
							'label'   => __( 'X card style', 'tagwright-seo' ),
							'options' => array(
								'summary_large_image' => __( 'Large image', 'tagwright-seo' ),
								'summary'             => __( 'Small image', 'tagwright-seo' ),
							),
						),
						array(
							'id'          => 'social_profiles',
							'type'        => 'textarea',
							'label'       => __( 'Other profiles', 'tagwright-seo' ),
							'description' => __( 'One URL per line: Instagram, LinkedIn, YouTube, TikTok, Wikipedia and so on. Search engines use these to connect your profiles to your site.', 'tagwright-seo' ),
							'rows'        => 4,
							'placeholder' => "https://www.instagram.com/yourname\nhttps://www.linkedin.com/company/yourname",
						),
					),
				),
			),
		);
	}

	/**
	 * Schema tab.
	 *
	 * @return array
	 */
	private static function schema_tab() {
		$users = self::person_options();

		return array(
			'title'       => __( 'Schema', 'tagwright-seo' ),
			'description' => __( 'Tell search engines who is behind this site. This feeds the Knowledge Panel and connects all your content to you.', 'tagwright-seo' ),
			'icon'        => 'code',
			'group'       => 'features',
			'module'      => 'schema',
			'sections'    => array(
				array(
					'title'  => __( 'Site owner', 'tagwright-seo' ),
					'icon'   => 'shield',
					'fields' => array(
						array(
							'id'      => 'site_represents',
							'type'    => 'radio_cards',
							'label'   => __( 'This site represents', 'tagwright-seo' ),
							'options' => array(
								'organization' => array(
									'label'       => __( 'An organization', 'tagwright-seo' ),
									'description' => __( 'A company, brand, shop, club or publication.', 'tagwright-seo' ),
									'icon'        => 'globe',
								),
								'person'       => array(
									'label'       => __( 'A person', 'tagwright-seo' ),
									'description' => __( 'A personal site, portfolio or blog.', 'tagwright-seo' ),
									'icon'        => 'user',
								),
							),
						),
						array(
							'id'          => 'org_name',
							'type'        => 'text',
							'label'       => __( 'Organization name', 'tagwright-seo' ),
							'placeholder' => get_bloginfo( 'name' ),
							'show_if'     => array(
								'field' => 'site_represents',
								'value' => 'organization',
							),
						),
						array(
							'id'      => 'org_alternate_name',
							'type'    => 'text',
							'label'   => __( 'Alternate name', 'tagwright-seo' ),
							'show_if' => array(
								'field' => 'site_represents',
								'value' => 'organization',
							),
						),
						array(
							'id'          => 'org_logo_id',
							'type'        => 'image',
							'label'       => __( 'Logo', 'tagwright-seo' ),
							'description' => __( 'Falls back to the logo set in your theme.', 'tagwright-seo' ),
							'recommended' => __( 'At least 112 × 112 pixels, square works best.', 'tagwright-seo' ),
							'show_if'     => array(
								'field' => 'site_represents',
								'value' => 'organization',
							),
						),
						array(
							'id'      => 'org_email',
							'type'    => 'email',
							'label'   => __( 'Contact email', 'tagwright-seo' ),
							'show_if' => array(
								'field' => 'site_represents',
								'value' => 'organization',
							),
						),
						array(
							'id'      => 'org_phone',
							'type'    => 'text',
							'label'   => __( 'Phone number', 'tagwright-seo' ),
							'show_if' => array(
								'field' => 'site_represents',
								'value' => 'organization',
							),
						),
						array(
							'id'      => 'person_user_id',
							'type'    => 'select',
							'label'   => __( 'Person', 'tagwright-seo' ),
							'options' => $users,
							'show_if' => array(
								'field' => 'site_represents',
								'value' => 'person',
							),
						),
						array(
							'id'          => 'person_image_id',
							'type'        => 'image',
							'label'       => __( 'Photo or logo', 'tagwright-seo' ),
							'description' => __( 'Falls back to the user\'s avatar.', 'tagwright-seo' ),
							'show_if'     => array(
								'field' => 'site_represents',
								'value' => 'person',
							),
						),
					),
				),
				array(
					'title'  => __( 'Website', 'tagwright-seo' ),
					'icon'   => 'search',
					'fields' => array(
						array(
							'id'           => 'website_search_action',
							'type'         => 'toggle',
							'label'        => __( 'Site search', 'tagwright-seo' ),
							'description'  => __( 'Tells search engines how to search your site.', 'tagwright-seo' ),
							'toggle_label' => __( 'Add a SearchAction to the WebSite schema', 'tagwright-seo' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Sitemaps tab. Modelled on XML Sitemap Generator for Google: every type
	 * of URL gets its own switch, change frequency and priority.
	 *
	 * @return array
	 */
	private static function sitemaps_tab() {
		$index       = \TagwrightSEO\Sitemaps\Router::index_url();
		$frequencies = \TagwrightSEO\Sitemaps\Builder::frequency_options();
		$priorities  = \TagwrightSEO\Sitemaps\Builder::priority_options();

		// One row per content type: include switch, change frequency and priority.
		$type_row = static function ( $prefix, $label, $description = '' ) use ( $frequencies, $priorities ) {
			return array(
				'id'          => $prefix . 'sitemap_row',
				'type'        => 'group',
				'label'       => $label,
				'description' => $description,
				'fields'      => array(
					array(
						'id'           => $prefix . 'sitemap',
						'type'         => 'toggle',
						'toggle_label' => __( 'Include', 'tagwright-seo' ),
					),
					array(
						'id'      => $prefix . 'sitemap_freq',
						'type'    => 'select',
						'label'   => __( 'Change frequency', 'tagwright-seo' ),
						'options' => $frequencies,
						'show_if' => array(
							'field'   => $prefix . 'sitemap',
							'checked' => true,
						),
					),
					array(
						'id'      => $prefix . 'sitemap_priority',
						'type'    => 'select',
						'label'   => __( 'Priority', 'tagwright-seo' ),
						'options' => $priorities,
						'show_if' => array(
							'field'   => $prefix . 'sitemap',
							'checked' => true,
						),
					),
				),
			);
		};

		$post_type_rows = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$note = Options::get( 'pt_' . $post_type->name . '_noindex' ) ? __( 'Hidden from search results, so only items you set to index are listed.', 'tagwright-seo' ) : '';
			if ( 'attachment' === $post_type->name && Options::get( 'attachment_redirect' ) ) {
				$note = __( 'Attachment pages are redirected (Settings > Technical), so they are never listed.', 'tagwright-seo' );
			}
			$post_type_rows[] = $type_row( "pt_{$post_type->name}_", $post_type->labels->name, $note );
		}

		$taxonomy_rows = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$note            = Options::get( 'tax_' . $taxonomy->name . '_noindex' ) ? __( 'Hidden from search results, so only terms you set to index are listed.', 'tagwright-seo' ) : '';
			$taxonomy_rows[] = $type_row( "tax_{$taxonomy->name}_", $taxonomy->labels->name, $note );
		}

		$frequency_field = static function ( $id, $label, $description = '' ) use ( $frequencies ) {
			return array(
				'id'          => $id,
				'type'        => 'select',
				'label'       => $label,
				'description' => $description,
				'options'     => $frequencies,
			);
		};

		$priority_field = static function ( $id, $label ) use ( $priorities ) {
			return array(
				'id'      => $id,
				'type'    => 'select',
				'label'   => $label,
				'options' => $priorities,
			);
		};

		return array(
			'title'       => __( 'Sitemaps', 'tagwright-seo' ),
			'description' => sprintf(
				/* translators: %s: sitemap URL. */
				__( 'Your sitemap lives at %s. Submit this address in Google Search Console and Bing Webmaster Tools.', 'tagwright-seo' ),
				'<a href="' . esc_url( $index ) . '" target="_blank" rel="noopener" data-sitemap-link>' . esc_html( $index ) . '</a>'
			),
			'icon'        => 'sitemap',
			'group'       => 'features',
			'module'      => 'sitemaps',
			'sections'    => array(
				array(
					'title'  => __( 'Basic options', 'tagwright-seo' ),
					'icon'   => 'sitemap',
					'fields' => array(
						array(
							'id'          => 'sitemap_index_name',
							'type'        => 'text',
							'label'       => __( 'Sitemap file name', 'tagwright-seo' ),
							/* translators: %s: example file names. */
							'description' => sprintf( __( 'The address of your main sitemap, without .xml. For example %s. The usual names redirect here automatically.', 'tagwright-seo' ), '<code>sitemap</code>, <code>sitemap_index</code>' ),
							'prefix_text' => trailingslashit( home_url() ),
							'suffix'      => '.xml',
							'placeholder' => 'sitemap_index',
							'sanitize'    => static function ( $value ) {
								$name = \TagwrightSEO\Sitemaps\Router::sanitize_name( (string) $value );
								return '' !== $name ? $name : 'sitemap_index';
							},
						),
						array(
							'id'          => 'sitemap_max_entries',
							'type'        => 'number',
							'label'       => __( 'Links per sitemap', 'tagwright-seo' ),
							'description' => __( 'Large sitemaps are split into pages. Lower this if your server struggles.', 'tagwright-seo' ),
							'min'         => 10,
							'max'         => 50000,
							'step'        => 10,
						),
						array(
							'id'           => 'sitemap_lastmod',
							'type'         => 'toggle',
							'label'        => __( 'Last modified', 'tagwright-seo' ),
							'description'  => __( 'The date each URL last changed, including categories, tags and author pages.', 'tagwright-seo' ),
							'toggle_label' => __( 'Include <lastmod>', 'tagwright-seo' ),
						),
						array(
							'id'           => 'sitemap_changefreq',
							'type'         => 'toggle',
							'label'        => __( 'Change frequency', 'tagwright-seo' ),
							'description'  => __( 'A hint about how often a page changes. Set it per content type below.', 'tagwright-seo' ),
							'toggle_label' => __( 'Include <changefreq>', 'tagwright-seo' ),
						),
						array(
							'id'           => 'sitemap_priority',
							'type'         => 'toggle',
							'label'        => __( 'Priority', 'tagwright-seo' ),
							'description'  => __( 'How important a page is compared with the rest of your site, from 0.0 to 1.0.', 'tagwright-seo' ),
							'toggle_label' => __( 'Include <priority>', 'tagwright-seo' ),
						),
						array(
							'id'           => 'sitemap_images',
							'type'         => 'toggle',
							'label'        => __( 'Images', 'tagwright-seo' ),
							'description'  => __( 'Lists featured and in-content images so they show up in image search.', 'tagwright-seo' ),
							'toggle_label' => __( 'Include images', 'tagwright-seo' ),
						),
						array(
							'id'           => 'sitemap_gzip',
							'type'         => 'toggle',
							'label'        => __( 'Compressed sitemaps', 'tagwright-seo' ),
							/* translators: %s: example compressed sitemap URL. */
							'description'  => sprintf( __( 'Also serves a gzip copy of every sitemap, for example %s.', 'tagwright-seo' ), '<code>' . esc_html( basename( $index ) ) . '.gz</code>' ),
							'toggle_label' => __( 'Enable .xml.gz versions', 'tagwright-seo' ),
						),
						array(
							'id'           => 'sitemap_robots_txt',
							'type'         => 'toggle',
							'label'        => __( 'robots.txt', 'tagwright-seo' ),
							'toggle_label' => __( 'Add the sitemap URL to robots.txt', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'       => __( 'Homepage', 'tagwright-seo' ),
					'description' => __( 'Your homepage is always included.', 'tagwright-seo' ),
					'icon'        => 'home',
					'fields'      => array(
						array(
							'id'     => 'sitemap_home_row',
							'type'   => 'group',
							'label'  => __( 'Homepage', 'tagwright-seo' ),
							'fields' => array(
								$frequency_field( 'sitemap_home_freq', __( 'Change frequency', 'tagwright-seo' ) ),
								$priority_field( 'sitemap_home_priority', __( 'Priority', 'tagwright-seo' ) ),
							),
						),
					),
				),
				array(
					'title'       => __( 'Content types', 'tagwright-seo' ),
					'description' => __( 'Choose what to include, how often it changes and how important it is. Single items can override this from their SEO box.', 'tagwright-seo' ),
					'icon'        => 'file',
					'fields'      => $post_type_rows,
				),
				array(
					'title'       => __( 'Taxonomies', 'tagwright-seo' ),
					'description' => __( 'Categories, tags and custom taxonomies. Their last modified date is the newest post inside them.', 'tagwright-seo' ),
					'icon'        => 'tag',
					'fields'      => $taxonomy_rows,
				),
				array(
					'title'  => __( 'Authors and archives', 'tagwright-seo' ),
					'icon'   => 'user',
					'fields' => array(
						array(
							'id'          => 'sitemap_author_row',
							'type'        => 'group',
							'label'       => __( 'Author pages', 'tagwright-seo' ),
							'description' => Options::get( 'author_archive_noindex' ) ? __( 'Author archives are hidden from search (Settings > Archives), so they are not listed.', 'tagwright-seo' ) : __( 'Only authors with published posts are listed.', 'tagwright-seo' ),
							'fields'      => array(
								array(
									'id'           => 'sitemap_authors',
									'type'         => 'toggle',
									'toggle_label' => __( 'Include', 'tagwright-seo' ),
								),
								$frequency_field( 'sitemap_author_freq', __( 'Change frequency', 'tagwright-seo' ) ),
								$priority_field( 'sitemap_author_priority', __( 'Priority', 'tagwright-seo' ) ),
							),
						),
						array(
							'id'          => 'sitemap_archive_row',
							'type'        => 'group',
							'label'       => __( 'Monthly archives', 'tagwright-seo' ),
							'description' => Options::get( 'date_archive_noindex' ) ? __( 'Date archives are hidden from search (Settings > Archives). Show them in search first to list them here.', 'tagwright-seo' ) : __( 'One URL per month that has posts.', 'tagwright-seo' ),
							'fields'      => array(
								array(
									'id'           => 'sitemap_archives',
									'type'         => 'toggle',
									'toggle_label' => __( 'Include', 'tagwright-seo' ),
								),
								$frequency_field( 'sitemap_archive_freq', __( 'This month', 'tagwright-seo' ) ),
								$frequency_field( 'sitemap_archive_old_freq', __( 'Older months', 'tagwright-seo' ) ),
								$priority_field( 'sitemap_archive_priority', __( 'Priority', 'tagwright-seo' ) ),
							),
						),
					),
				),
				array(
					'title'  => __( 'Post priority', 'tagwright-seo' ),
					'icon'   => 'trend',
					'fields' => array(
						array(
							'id'      => 'sitemap_priority_mode',
							'type'    => 'radio_cards',
							'label'   => __( 'How post priority is set', 'tagwright-seo' ),
							'options' => array(
								'manual'       => array(
									'label'       => __( 'Content type defaults', 'tagwright-seo' ),
									'description' => __( 'Every post gets the priority set for its type above.', 'tagwright-seo' ),
									'icon'        => 'settings',
								),
								'comments'     => array(
									'label'       => __( 'Comment count', 'tagwright-seo' ),
									'description' => __( 'The most discussed post gets 1.0, the others scale down from there.', 'tagwright-seo' ),
									'icon'        => 'analysis',
								),
								'comments_avg' => array(
									'label'       => __( 'Average comment count', 'tagwright-seo' ),
									'description' => __( 'Posts with more comments than average move above the type default, quieter ones move below.', 'tagwright-seo' ),
									'icon'        => 'trend',
								),
							),
						),
					),
				),
				array(
					'title'       => __( 'Additional pages', 'tagwright-seo' ),
					'description' => __( 'Add pages WordPress does not know about, such as static files or pages from another app on this domain.', 'tagwright-seo' ),
					'icon'        => 'plus',
					'fields'      => array(
						array(
							'id'        => 'sitemap_custom_urls',
							'type'      => 'repeater',
							'label'     => __( 'Pages', 'tagwright-seo' ),
							'wide'      => true,
							'add_label' => __( 'Add page', 'tagwright-seo' ),
							'max_rows'  => 1000,
							'columns'   => array(
								array(
									'id'          => 'url',
									'type'        => 'url',
									'label'       => __( 'URL', 'tagwright-seo' ),
									'placeholder' => home_url( '/my-page/' ),
								),
								array(
									'id'      => 'priority',
									'type'    => 'select',
									'label'   => __( 'Priority', 'tagwright-seo' ),
									'options' => $priorities,
									'default' => '0.5',
								),
								array(
									'id'      => 'changefreq',
									'type'    => 'select',
									'label'   => __( 'Change frequency', 'tagwright-seo' ),
									'options' => $frequencies,
									'default' => 'monthly',
								),
								array(
									'id'    => 'lastmod',
									'type'  => 'date',
									'label' => __( 'Last modified', 'tagwright-seo' ),
								),
							),
						),
					),
				),
				array(
					'title'  => __( 'Exclusions', 'tagwright-seo' ),
					'icon'   => 'eye-off',
					'fields' => array(
						array(
							'id'          => 'sitemap_exclude_ids',
							'type'        => 'text',
							'label'       => __( 'Exclude posts', 'tagwright-seo' ),
							'description' => __( 'Comma separated post IDs to leave out. You can also exclude any single item from its SEO box.', 'tagwright-seo' ),
							'placeholder' => '12, 45, 78',
							'sanitize'    => static function ( $value ) {
								return implode( ',', array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) );
							},
						),
					),
				),
			),
		);
	}

	/**
	 * Webmaster tools tab.
	 *
	 * @return array
	 */
	private static function webmaster_tab() {
		$fields = array();
		$tools  = array(
			'verify_google'    => array( __( 'Google Search Console', 'tagwright-seo' ), 'https://search.google.com/search-console' ),
			'verify_bing'      => array( __( 'Bing Webmaster Tools', 'tagwright-seo' ), 'https://www.bing.com/webmasters' ),
			'verify_yandex'    => array( __( 'Yandex Webmaster', 'tagwright-seo' ), 'https://webmaster.yandex.com' ),
			'verify_baidu'     => array( __( 'Baidu Webmaster Tools', 'tagwright-seo' ), 'https://ziyuan.baidu.com' ),
			'verify_pinterest' => array( __( 'Pinterest', 'tagwright-seo' ), 'https://www.pinterest.com/settings/claim' ),
		);

		foreach ( $tools as $key => $tool ) {
			$fields[] = array(
				'id'          => $key,
				'type'        => 'text',
				'label'       => $tool[0],
				/* translators: %s: link to the webmaster tool. */
				'description' => sprintf( __( 'Paste the content value of the HTML tag. %s', 'tagwright-seo' ), '<a href="' . esc_url( $tool[1] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get your code', 'tagwright-seo' ) . '</a>' ),
				'sanitize'    => array( __CLASS__, 'sanitize_verification' ),
			);
		}

		return array(
			'title'       => __( 'Webmaster Tools', 'tagwright-seo' ),
			'description' => __( 'Verify your site with search engines. The tags are only added to your homepage.', 'tagwright-seo' ),
			'icon'        => 'key',
			'group'       => 'advanced',
			'sections'    => array(
				array(
					'title'  => __( 'Verification codes', 'tagwright-seo' ),
					'icon'   => 'shield',
					'fields' => $fields,
				),
			),
		);
	}

	/**
	 * Technical tab.
	 *
	 * @return array
	 */
	private static function technical_tab() {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( 'attachment' !== $post_type->name ) {
				$post_types[ $post_type->name ] = $post_type->labels->name;
			}
		}

		$robots_description = __( 'Replaces the robots.txt WordPress generates. Leave empty to keep the default.', 'tagwright-seo' );
		if ( \TagwrightSEO\Technical\Robots_Txt::has_physical_file() ) {
			$robots_description = '<strong>' . __( 'A robots.txt file exists in your site root, so the server serves that file and these settings have no effect. Delete the file to manage it here.', 'tagwright-seo' ) . '</strong>';
		}

		return array(
			'title'       => __( 'Technical', 'tagwright-seo' ),
			'description' => __( 'Crawl cleanup, robots.txt and llms.txt.', 'tagwright-seo' ),
			'icon'        => 'tools',
			'group'       => 'advanced',
			'sections'    => array(
				array(
					'title'  => __( 'Clean URLs', 'tagwright-seo' ),
					'icon'   => 'link',
					'fields' => array(
						array(
							'id'           => 'attachment_redirect',
							'type'         => 'toggle',
							'label'        => __( 'Attachment pages', 'tagwright-seo' ),
							'description'  => __( 'WordPress creates a thin page for every uploaded file. Redirecting them to the post they belong to stops search engines from wasting time on them.', 'tagwright-seo' ),
							'toggle_label' => __( 'Redirect attachment pages', 'tagwright-seo' ),
						),
						array(
							'id'           => 'remove_replytocom',
							'type'         => 'toggle',
							'label'        => __( '?replytocom links', 'tagwright-seo' ),
							'description'  => __( 'Comment reply links create a duplicate URL for every comment.', 'tagwright-seo' ),
							'toggle_label' => __( 'Remove ?replytocom from reply links', 'tagwright-seo' ),
						),
						array(
							'id'           => 'strip_category_base',
							'type'         => 'toggle',
							'label'        => __( 'Category base', 'tagwright-seo' ),
							'description'  => __( 'Turns /category/news/ into /news/. Old links are redirected. Make sure no page shares a slug with a category.', 'tagwright-seo' ),
							'toggle_label' => __( 'Remove /category/ from URLs', 'tagwright-seo' ),
						),
					),
				),
				array(
					'title'  => __( 'robots.txt', 'tagwright-seo' ),
					'icon'   => 'robot',
					'fields' => array(
						array(
							'id'           => 'robots_txt_enabled',
							'type'         => 'toggle',
							'label'        => __( 'Custom robots.txt', 'tagwright-seo' ),
							'description'  => $robots_description,
							'toggle_label' => __( 'Use my own robots.txt rules', 'tagwright-seo' ),
						),
						array(
							'id'          => 'robots_txt_content',
							'type'        => 'code',
							'label'       => __( 'Rules', 'tagwright-seo' ),
							'rows'        => 10,
							'placeholder' => \TagwrightSEO\Technical\Robots_Txt::default_content(),
							'description' => sprintf(
								/* translators: %s: robots.txt URL. */
								__( 'Preview it at %s.', 'tagwright-seo' ),
								'<a href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank" rel="noopener">/robots.txt</a>'
							),
							'show_if'     => array(
								'field'   => 'robots_txt_enabled',
								'checked' => true,
							),
						),
					),
				),
				array(
					'title'       => __( 'llms.txt', 'tagwright-seo' ),
					'description' => Modules::is_active( 'llms_txt' )
						? sprintf(
							/* translators: %s: llms.txt URL. */
							__( 'Live at %s.', 'tagwright-seo' ),
							'<a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener">/llms.txt</a>'
						)
						: __( 'Switch on the llms.txt module under Modules to publish the file.', 'tagwright-seo' ),
					'icon'        => 'sparkles',
					'fields'      => array(
						array(
							'id'      => 'llms_post_types',
							'type'    => 'checkboxes',
							'label'   => __( 'Content to list', 'tagwright-seo' ),
							'options' => $post_types,
						),
						array(
							'id'    => 'llms_limit',
							'type'  => 'number',
							'label' => __( 'Items per type', 'tagwright-seo' ),
							'min'   => 1,
							'max'   => 500,
						),
						array(
							'id'          => 'llms_intro',
							'type'        => 'textarea',
							'label'       => __( 'Introduction', 'tagwright-seo' ),
							'description' => __( 'A few sentences about your site that an AI assistant should know.', 'tagwright-seo' ),
							'rows'        => 4,
						),
					),
				),
				array(
					'title'  => __( 'Data', 'tagwright-seo' ),
					'icon'   => 'database',
					'fields' => array(
						array(
							'id'           => 'delete_data_on_uninstall',
							'type'         => 'toggle',
							'label'        => __( 'Uninstall', 'tagwright-seo' ),
							'description'  => __( 'When on, deleting the plugin also deletes its tables and settings. Leave it off unless you are sure.', 'tagwright-seo' ),
							'toggle_label' => __( 'Remove all data when the plugin is deleted', 'tagwright-seo' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Users who can represent the site: everyone who can write posts.
	 *
	 * The list is capped so huge sites stay fast, but the saved person is
	 * always included. The dropdown gets a search box once it is long.
	 *
	 * @return array<string, string> User id => name.
	 */
	public static function person_options() {
		$options = array( '0' => __( '— Select a user —', 'tagwright-seo' ) );

		/**
		 * Filters how many users are listed in the person dropdown.
		 *
		 * @param int $limit Maximum number of users. Default 1000.
		 */
		$limit = (int) apply_filters( 'tagwright_seo_person_limit', 1000 );

		$users = get_users(
			array(
				'capability' => array( 'edit_posts' ),
				'fields'     => array( 'ID', 'display_name', 'user_login' ),
				'orderby'    => 'display_name',
				'number'     => $limit,
			)
		);

		foreach ( $users as $user ) {
			$options[ (string) $user->ID ] = $user->display_name . ( $user->display_name !== $user->user_login ? ' (' . $user->user_login . ')' : '' );
		}

		$saved = (int) Options::get( 'person_user_id' );
		if ( $saved && ! isset( $options[ (string) $saved ] ) ) {
			$user = get_userdata( $saved );
			if ( $user ) {
				$options[ (string) $saved ] = $user->display_name;
			}
		}

		return $options;
	}

	/**
	 * Accepts a verification code or a full meta tag and keeps only the code.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function sanitize_verification( $value ) {
		$value = trim( (string) $value );

		if ( preg_match( '/content=["\']([^"\']+)["\']/i', $value, $match ) ) {
			$value = $match[1];
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Explains what the homepage section controls, which depends on the
	 * Reading settings.
	 *
	 * @return string
	 */
	private static function homepage_note() {
		if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
			return sprintf(
				/* translators: %s: link to edit the static homepage. */
				__( 'Your homepage is a static page. These values are used unless you set them on %s itself.', 'tagwright-seo' ),
				'<a href="' . esc_url( get_edit_post_link( (int) get_option( 'page_on_front' ) ) ) . '">' . esc_html__( 'the page', 'tagwright-seo' ) . '</a>'
			);
		}

		return __( 'Your homepage shows your latest posts.', 'tagwright-seo' );
	}

	/**
	 * Current value of a setting for the form.
	 *
	 * @param array $field Field definition.
	 * @return mixed
	 */
	public static function value( array $field ) {
		return Options::get( $field['id'], $field['default'] ?? null );
	}
}
