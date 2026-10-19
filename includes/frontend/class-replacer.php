<?php
/**
 * Template variables such as %title% and %sitename%.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Frontend;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Swaps %variables% in title and description templates for real values.
 */
class Replacer {

	/**
	 * Available separators. The key is stored, the value is printed.
	 *
	 * @return array<string, string>
	 */
	public static function separators() {
		return array(
			'-'       => '-',
			'–'       => '–',
			'—'       => '—',
			'|'       => '|',
			'·'       => '·',
			'•'       => '•',
			'*'       => '*',
			'~'       => '~',
			'/'       => '/',
			'&raquo;' => '»',
			'&laquo;' => '«',
			'&gt;'    => '>',
		);
	}

	/**
	 * The separator character currently in use.
	 *
	 * @return string
	 */
	public static function separator() {
		$key        = (string) Options::get( 'separator', '-' );
		$separators = self::separators();

		return $separators[ $key ] ?? $key;
	}

	/**
	 * Describes every variable, for the variable picker in the admin.
	 *
	 * @return array<string, string> Variable name (without %) => description.
	 */
	public static function describe() {
		$variables = array(
			'title'            => __( 'Title of the post, page, term or author', 'tagwright-seo' ),
			'sitename'         => __( 'Site title', 'tagwright-seo' ),
			'sitedesc'         => __( 'Site tagline', 'tagwright-seo' ),
			'sep'              => __( 'Separator', 'tagwright-seo' ),
			'excerpt'          => __( 'Excerpt, or the start of the content', 'tagwright-seo' ),
			'excerpt_only'     => __( 'Manual excerpt only', 'tagwright-seo' ),
			'category'         => __( 'First category', 'tagwright-seo' ),
			'categories'       => __( 'All categories', 'tagwright-seo' ),
			'tag'              => __( 'First tag', 'tagwright-seo' ),
			'tags'             => __( 'All tags', 'tagwright-seo' ),
			'term_title'       => __( 'Term name', 'tagwright-seo' ),
			'term_description' => __( 'Term description', 'tagwright-seo' ),
			'name'             => __( 'Author display name', 'tagwright-seo' ),
			'date'             => __( 'Publish date, or archive date', 'tagwright-seo' ),
			'modified'         => __( 'Last modified date', 'tagwright-seo' ),
			'currentdate'      => __( 'Today\'s date', 'tagwright-seo' ),
			'currentyear'      => __( 'Current year', 'tagwright-seo' ),
			'currentmonth'     => __( 'Current month', 'tagwright-seo' ),
			'page'             => __( '"Page 2 of 4" on paginated pages', 'tagwright-seo' ),
			'pagenumber'       => __( 'Current page number', 'tagwright-seo' ),
			'pagetotal'        => __( 'Total number of pages', 'tagwright-seo' ),
			'pt_single'        => __( 'Post type name, singular', 'tagwright-seo' ),
			'pt_plural'        => __( 'Post type name, plural', 'tagwright-seo' ),
			'parent_title'     => __( 'Parent page title', 'tagwright-seo' ),
			'searchphrase'     => __( 'Search phrase', 'tagwright-seo' ),
			'focuskw'          => __( 'Focus keyphrase', 'tagwright-seo' ),
			'id'               => __( 'Post id', 'tagwright-seo' ),
			'customfield(key)' => __( 'Value of a custom field', 'tagwright-seo' ),
		);

		/**
		 * Filters the variable descriptions shown in the admin.
		 *
		 * @param array $variables Variable name => description.
		 */
		return apply_filters( 'tagwright_seo_variable_descriptions', $variables );
	}

	/**
	 * Short labels shown on variable chips in the admin.
	 *
	 * @return array<string, string> Variable name => label.
	 */
	public static function labels() {
		$labels = array(
			'title'            => __( 'Title', 'tagwright-seo' ),
			'sitename'         => __( 'Site title', 'tagwright-seo' ),
			'sitedesc'         => __( 'Tagline', 'tagwright-seo' ),
			'sep'              => __( 'Separator', 'tagwright-seo' ),
			'excerpt'          => __( 'Excerpt', 'tagwright-seo' ),
			'excerpt_only'     => __( 'Manual excerpt', 'tagwright-seo' ),
			'category'         => __( 'Category', 'tagwright-seo' ),
			'categories'       => __( 'Categories', 'tagwright-seo' ),
			'tag'              => __( 'Tag', 'tagwright-seo' ),
			'tags'             => __( 'Tags', 'tagwright-seo' ),
			'term_title'       => __( 'Term title', 'tagwright-seo' ),
			'term_description' => __( 'Term description', 'tagwright-seo' ),
			'name'             => __( 'Author', 'tagwright-seo' ),
			'date'             => __( 'Date', 'tagwright-seo' ),
			'modified'         => __( 'Modified date', 'tagwright-seo' ),
			'currentdate'      => __( 'Current date', 'tagwright-seo' ),
			'currentyear'      => __( 'Current year', 'tagwright-seo' ),
			'currentmonth'     => __( 'Current month', 'tagwright-seo' ),
			'page'             => __( 'Page', 'tagwright-seo' ),
			'pagenumber'       => __( 'Page number', 'tagwright-seo' ),
			'pagetotal'        => __( 'Page total', 'tagwright-seo' ),
			'pt_single'        => __( 'Post type (singular)', 'tagwright-seo' ),
			'pt_plural'        => __( 'Post type (plural)', 'tagwright-seo' ),
			'parent_title'     => __( 'Parent title', 'tagwright-seo' ),
			'searchphrase'     => __( 'Search phrase', 'tagwright-seo' ),
			'focuskw'          => __( 'Focus keyphrase', 'tagwright-seo' ),
			'id'               => __( 'ID', 'tagwright-seo' ),
			'customfield'      => __( 'Custom field', 'tagwright-seo' ),
		);

		/**
		 * Filters the chip labels. Add-ons that register variables add a label here.
		 *
		 * @param array $labels Variable name => label.
		 */
		return apply_filters( 'tagwright_seo_variable_labels', $labels );
	}

	/**
	 * Replaces every variable in a template.
	 *
	 * @param string  $template Template text.
	 * @param Context $context  Page context.
	 * @return string
	 */
	public static function replace( $template, Context $context ) {
		$template = (string) $template;
		if ( '' === $template || false === strpos( $template, '%' ) ) {
			return self::tidy( $template );
		}

		$output = preg_replace_callback(
			'/%([a-z_]+)(?:\(([^)%]+)\))?%/i',
			static function ( $match ) use ( $context ) {
				$value = self::value( strtolower( $match[1] ), $match[2] ?? '', $context );

				return null === $value ? $match[0] : $value;
			},
			$template
		);

		return self::tidy( $output );
	}

	/**
	 * Resolves a single variable.
	 *
	 * @param string  $name     Variable name without percent signs.
	 * @param string  $argument Optional argument, used by customfield(key).
	 * @param Context $context  Page context.
	 * @return string|null Null for unknown variables so they are left alone.
	 */
	private static function value( $name, $argument, Context $context ) {
		$post = $context->object instanceof \WP_Post ? $context->object : null;
		$term = $context->object instanceof \WP_Term ? $context->object : null;
		$user = $context->object instanceof \WP_User ? $context->object : null;

		switch ( $name ) {
			case 'sitename':
				return get_bloginfo( 'name' );

			case 'sitedesc':
				return get_bloginfo( 'description' );

			case 'sep':
				return self::separator();

			case 'title':
				if ( $post ) {
					return get_the_title( $post );
				}
				if ( $term ) {
					return $term->name;
				}
				if ( $user ) {
					return $user->display_name;
				}
				if ( $context->object instanceof \WP_Post_Type ) {
					return $context->object->labels->name;
				}
				return '';

			case 'excerpt':
				if ( ! $post ) {
					return $term ? self::plain( $term->description, 155 ) : '';
				}
				if ( post_password_required( $post ) ) {
					return '';
				}
				return $post->post_excerpt ? self::plain( $post->post_excerpt, 155 ) : self::plain( strip_shortcodes( $post->post_content ), 155 );

			case 'excerpt_only':
				// Same rule as %excerpt%: never leak text from a protected post.
				if ( ! $post || post_password_required( $post ) ) {
					return '';
				}
				return self::plain( $post->post_excerpt, 0 );

			case 'category':
			case 'categories':
				return $post ? self::term_names( $post, 'category', 'category' === $name ) : '';

			case 'tag':
			case 'tags':
				return $post ? self::term_names( $post, 'post_tag', 'tag' === $name ) : '';

			case 'term_title':
				return $term ? $term->name : '';

			case 'term_description':
				return $term ? self::plain( $term->description, 155 ) : '';

			case 'name':
				if ( $user ) {
					return $user->display_name;
				}
				return $post ? get_the_author_meta( 'display_name', $post->post_author ) : '';

			case 'date':
				if ( $post ) {
					return get_the_date( '', $post );
				}
				if ( 'date' === $context->type ) {
					return wp_strip_all_tags( get_the_archive_title() );
				}
				return '';

			case 'modified':
				return $post ? get_the_modified_date( '', $post ) : '';

			case 'currentdate':
				return wp_date( get_option( 'date_format' ) );

			case 'currentyear':
				return wp_date( 'Y' );

			case 'currentmonth':
				return wp_date( 'F' );

			case 'page':
				if ( $context->paged < 2 ) {
					return '';
				}
				return sprintf(
					/* translators: 1: current page number, 2: total number of pages. */
					__( 'Page %1$d of %2$d', 'tagwright-seo' ),
					$context->paged,
					max( $context->paged, $context->max_pages )
				);

			case 'pagenumber':
				return (string) $context->paged;

			case 'pagetotal':
				return (string) max( $context->paged, $context->max_pages );

			case 'pt_single':
			case 'pt_plural':
				$post_type = null;
				if ( $post ) {
					$post_type = get_post_type_object( $post->post_type );
				} elseif ( $context->object instanceof \WP_Post_Type ) {
					$post_type = $context->object;
				}
				if ( ! $post_type ) {
					return '';
				}
				return 'pt_single' === $name ? $post_type->labels->singular_name : $post_type->labels->name;

			case 'parent_title':
				return ( $post && $post->post_parent ) ? get_the_title( $post->post_parent ) : '';

			case 'searchphrase':
				return get_search_query( false );

			case 'focuskw':
				return $context->row_value( 'focus_keyphrase' );

			case 'id':
				return $post ? (string) $post->ID : '';

			case 'customfield':
				if ( $post ) {
					return self::custom_field( 'post', $post->ID, $argument );
				}
				if ( $term ) {
					return self::custom_field( 'term', $term->term_id, $argument );
				}
				if ( $user ) {
					return self::custom_field( 'user', $user->ID, $argument );
				}
				return '';
		}

		/**
		 * Filters the value of a custom variable. Return null to leave the
		 * variable untouched.
		 *
		 * @param string|null $value    Value.
		 * @param string      $name     Variable name.
		 * @param string      $argument Argument passed in brackets.
		 * @param Context     $context  Page context.
		 */
		return apply_filters( 'tagwright_seo_replace_variable', null, $name, $argument, $context );
	}

	/**
	 * Cleans a title or description template.
	 *
	 * sanitize_text_field() is not safe here: it strips anything that looks
	 * like a URL-encoded byte, so %date% and %category% would lose their
	 * first letters ("%da" and "%ca" look like encoded bytes). This does the
	 * same job without touching the variables.
	 *
	 * @param string $text Raw template.
	 * @return string
	 */
	public static function sanitize_template( $text ) {
		$text = wp_check_invalid_utf8( (string) $text );
		$text = wp_strip_all_tags( $text, true );

		return trim( preg_replace( '/[\r\n\t ]+/', ' ', $text ) );
	}

	/**
	 * Reads a custom field as plain text.
	 *
	 * Themes store fields in different ways, so this tries, in order: the
	 * exact meta key, the same key with a leading underscore (hidden fields),
	 * and ACF's get_field() when ACF is active. Lists are joined with commas.
	 *
	 * @param string $type Object type: post, term or user.
	 * @param int    $id   Object id.
	 * @param string $key  Meta key.
	 * @return string
	 */
	public static function custom_field( $type, $id, $key ) {
		$key = trim( (string) $key );
		$id  = (int) $id;

		if ( '' === $key || $id <= 0 || ! in_array( $type, array( 'post', 'term', 'user' ), true ) ) {
			return '';
		}

		$value = get_metadata( $type, $id, $key, true );

		if ( self::is_empty_value( $value ) && 0 !== strpos( $key, '_' ) ) {
			$value = get_metadata( $type, $id, '_' . $key, true );
		}

		if ( self::is_empty_value( $value ) && function_exists( 'get_field' ) ) {
			$acf_id = 'post' === $type ? $id : $type . '_' . $id;
			$value  = get_field( $key, $acf_id );
		}

		$text = self::flatten( $value );

		/**
		 * Filters the value of a custom field variable.
		 *
		 * Use this when a theme keeps its data somewhere else, for example in
		 * its own table or under a different key.
		 *
		 * @param string $text  Plain text value.
		 * @param string $key   Field name used in the template.
		 * @param string $type  Object type: post, term or user.
		 * @param int    $id    Object id.
		 * @param mixed  $value Raw value that was found.
		 */
		return (string) apply_filters( 'tagwright_seo_custom_field_value', $text, $key, $type, $id, $value );
	}

	/**
	 * Whether a stored value counts as empty.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty_value( $value ) {
		return null === $value || false === $value || '' === $value || array() === $value;
	}

	/**
	 * Turns a stored value into plain text. Arrays become a comma separated
	 * list of their text values; objects with a name or title use that.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function flatten( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'tagwright-seo' ) : '';
		}

		if ( is_scalar( $value ) ) {
			return self::plain( (string) $value, 0 );
		}

		if ( $value instanceof \WP_Post ) {
			return self::plain( get_the_title( $value ), 0 );
		}

		if ( $value instanceof \WP_Term ) {
			return self::plain( $value->name, 0 );
		}

		if ( is_object( $value ) ) {
			foreach ( array( 'name', 'title', 'label', 'value' ) as $property ) {
				if ( isset( $value->$property ) && is_scalar( $value->$property ) ) {
					return self::plain( (string) $value->$property, 0 );
				}
			}
			return '';
		}

		if ( is_array( $value ) ) {
			// Simple key/value pairs such as array( 'label' => 'Drama' ).
			foreach ( array( 'label', 'name', 'title', 'value' ) as $property ) {
				if ( isset( $value[ $property ] ) && is_scalar( $value[ $property ] ) ) {
					return self::plain( (string) $value[ $property ], 0 );
				}
			}

			$parts = array();
			foreach ( $value as $item ) {
				$text = self::flatten( $item );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
				if ( count( $parts ) >= 10 ) {
					break;
				}
			}
			return implode( ', ', $parts );
		}

		return '';
	}

	/**
	 * Values the editor preview needs to replace variables in the browser.
	 *
	 * @return array<string, string>
	 */
	public static function static_values() {
		return array(
			'sitename'     => get_bloginfo( 'name' ),
			'sitedesc'     => get_bloginfo( 'description' ),
			'sep'          => self::separator(),
			'currentdate'  => wp_date( get_option( 'date_format' ) ),
			'currentyear'  => wp_date( 'Y' ),
			'currentmonth' => wp_date( 'F' ),
			'page'         => '',
			'pagenumber'   => '1',
			'pagetotal'    => '1',
		);
	}

	/**
	 * Names of a post's terms.
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $taxonomy Taxonomy.
	 * @param bool     $first    Only return the first term.
	 * @return string
	 */
	private static function term_names( $post, $taxonomy, $first ) {
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		$names = wp_list_pluck( $terms, 'name' );

		return $first ? (string) reset( $names ) : implode( ', ', $names );
	}

	/**
	 * Strips markup and trims to a length on a word boundary.
	 *
	 * @param string $text   Raw text.
	 * @param int    $length Maximum characters, 0 for no limit.
	 * @return string
	 */
	public static function plain( $text, $length = 0 ) {
		$text = wp_strip_all_tags( excerpt_remove_blocks( (string) $text ), true );
		$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );

		if ( $length > 0 && mb_strlen( $text ) > $length ) {
			$cut  = mb_substr( $text, 0, $length );
			$last = mb_strrpos( $cut, ' ' );
			$text = rtrim( false !== $last && $last > $length * 0.6 ? mb_substr( $cut, 0, $last ) : $cut, " ,.;:-" ) . '…';
		}

		return $text;
	}

	/**
	 * Cleans up after replacement: collapses spaces and removes separators
	 * that ended up dangling because a variable was empty.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function tidy( $text ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		$sep  = preg_quote( self::separator(), '/' );

		// "Title - - Site" becomes "Title - Site".
		$text = preg_replace( '/(' . $sep . ')(\s*' . $sep . ')+/u', '$1', $text );

		// Leading or trailing separators.
		$text = preg_replace( '/^\s*' . $sep . '\s*|\s*' . $sep . '\s*$/u', '', $text );

		return trim( $text );
	}
}
