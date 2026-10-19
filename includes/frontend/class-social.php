<?php
/**
 * Open Graph and X (Twitter) Card tags.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Frontend;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the tags social networks read when a link is shared.
 */
class Social {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'tagwright_seo_head', array( $this, 'output' ), 20 );

		// Jetpack prints its own Open Graph tags; two sets confuse Facebook.
		add_filter( 'jetpack_enable_open_graph', '__return_false' );
	}

	/**
	 * Prints all social tags.
	 *
	 * @param Context $context Current context.
	 * @return void
	 */
	public function output( $context ) {
		$tags = $this->get_tags( $context );

		foreach ( $tags as $tag ) {
			list( $attribute, $key, $value ) = $tag;

			if ( '' === (string) $value ) {
				continue;
			}

			$value = 'url' === $this->value_type( $key ) ? esc_url( $value ) : esc_attr( $value );

			printf( '<meta %1$s="%2$s" content="%3$s" />' . "\n", esc_attr( $attribute ), esc_attr( $key ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped just above.
		}
	}

	/**
	 * Builds the list of tags as array( attribute, name, value ) triples.
	 *
	 * @param Context $context Current context.
	 * @return array[]
	 */
	public function get_tags( Context $context ) {
		// Only the raw overrides hold variables. The fallbacks are already replaced,
		// and a second pass would mangle a literal % in a resolved title.
		$title = $this->pick( $context->row_value( 'og_title' ), $context->is_front() ? Options::get( 'home_og_title' ) : '' );
		$title = '' !== $title ? Replacer::replace( $title, $context ) : $context->get_title();

		$description = $this->pick( $context->row_value( 'og_description' ), $context->is_front() ? Options::get( 'home_og_description' ) : '' );
		$description = '' !== $description ? Replacer::replace( $description, $context ) : $context->get_description();

		$image       = $this->get_image( $context );
		$url         = $context->get_canonical();

		if ( '' === $url && $context->object instanceof \WP_Post ) {
			$url = get_permalink( $context->object );
		}

		$type = 'website';
		if ( 'singular' === $context->type && 'post' === $context->object->post_type ) {
			$type = 'article';
		}

		/**
		 * Filters the Open Graph type.
		 *
		 * @param string  $type    Open Graph type.
		 * @param Context $context Current context.
		 */
		$type = apply_filters( 'tagwright_seo_og_type', $type, $context );

		$tags = array(
			array( 'property', 'og:locale', get_locale() ),
			array( 'property', 'og:type', $type ),
			array( 'property', 'og:title', $title ),
			array( 'property', 'og:description', $description ),
			array( 'property', 'og:url', $url ),
			array( 'property', 'og:site_name', get_bloginfo( 'name' ) ),
		);

		if ( 'article' === $type ) {
			$post   = $context->object;
			$tags[] = array( 'property', 'article:published_time', get_post_time( 'c', true, $post ) );
			$tags[] = array( 'property', 'article:modified_time', get_post_modified_time( 'c', true, $post ) );
			$tags[] = array( 'property', 'article:publisher', Options::get( 'facebook_page_url' ) );

			$category = get_the_category( $post->ID );
			if ( $category ) {
				$tags[] = array( 'property', 'article:section', $category[0]->name );
			}

			$post_tags = get_the_tags( $post->ID );
			if ( is_array( $post_tags ) ) {
				foreach ( array_slice( $post_tags, 0, 6 ) as $post_tag ) {
					$tags[] = array( 'property', 'article:tag', $post_tag->name );
				}
			}
		}

		if ( $image ) {
			$tags[] = array( 'property', 'og:image', $image['url'] );
			if ( 0 === strpos( $image['url'], 'https://' ) ) {
				$tags[] = array( 'property', 'og:image:secure_url', $image['url'] );
			}
			if ( ! empty( $image['width'] ) ) {
				$tags[] = array( 'property', 'og:image:width', $image['width'] );
				$tags[] = array( 'property', 'og:image:height', $image['height'] );
			}
			if ( ! empty( $image['alt'] ) ) {
				$tags[] = array( 'property', 'og:image:alt', $image['alt'] );
			}
			if ( ! empty( $image['type'] ) ) {
				$tags[] = array( 'property', 'og:image:type', $image['type'] );
			}
		}

		$tags[] = array( 'property', 'fb:app_id', Options::get( 'facebook_app_id' ) );

		// X falls back to Open Graph for anything we leave out.
		$twitter_title       = $context->row_value( 'twitter_title' );
		$twitter_description = $context->row_value( 'twitter_description' );
		$site_handle         = $this->twitter_handle( Options::get( 'twitter_username' ) );

		$tags[] = array( 'name', 'twitter:card', $image ? Options::get( 'twitter_card_type', 'summary_large_image' ) : 'summary' );
		$tags[] = array( 'name', 'twitter:title', $twitter_title ? Replacer::replace( $twitter_title, $context ) : '' );
		$tags[] = array( 'name', 'twitter:description', $twitter_description ? Replacer::replace( $twitter_description, $context ) : '' );
		$tags[] = array( 'name', 'twitter:site', $site_handle );

		if ( $context->object instanceof \WP_Post ) {
			$creator = $this->twitter_handle( get_the_author_meta( 'twitter', $context->object->post_author ) );
			$tags[]  = array( 'name', 'twitter:creator', $creator ? $creator : $site_handle );
		}

		/**
		 * Filters the social tags before they are printed.
		 *
		 * @param array[] $tags    List of array( attribute, name, value ).
		 * @param Context $context Current context.
		 */
		return apply_filters( 'tagwright_seo_social_tags', $tags, $context );
	}

	/**
	 * Picks the image to share: custom, featured, first in content, then default.
	 *
	 * @param Context $context Current context.
	 * @return array|null array( url, width, height, alt, type ) or null.
	 */
	public function get_image( Context $context ) {
		$row = $context->row;

		$candidates = array();

		if ( ! empty( $row['og_image_id'] ) ) {
			$candidates[] = (int) $row['og_image_id'];
		}
		if ( ! empty( $row['og_image_url'] ) ) {
			$candidates[] = $row['og_image_url'];
		}
		if ( $context->is_front() && Options::get( 'home_og_image_id' ) ) {
			$candidates[] = (int) Options::get( 'home_og_image_id' );
		}
		if ( $context->object instanceof \WP_Post ) {
			$thumbnail = get_post_thumbnail_id( $context->object );
			if ( $thumbnail ) {
				$candidates[] = (int) $thumbnail;
			}
			if ( ! empty( $row['images'][0] ) ) {
				$candidates[] = $row['images'][0];
			}
		}
		if ( Options::get( 'og_default_image_id' ) ) {
			$candidates[] = (int) Options::get( 'og_default_image_id' );
		}

		/**
		 * Filters the list of image candidates, in order of preference. Each
		 * entry is an attachment id or a URL.
		 *
		 * @param array   $candidates Attachment ids or URLs.
		 * @param Context $context    Current context.
		 */
		$candidates = apply_filters( 'tagwright_seo_social_image_candidates', $candidates, $context );

		foreach ( $candidates as $candidate ) {
			$image = self::image_data( $candidate );
			if ( $image ) {
				return $image;
			}
		}

		return null;
	}

	/**
	 * Turns an attachment id or URL into image details.
	 *
	 * @param int|string $image Attachment id or URL.
	 * @param string     $size  Image size for attachments.
	 * @return array|null
	 */
	public static function image_data( $image, $size = 'full' ) {
		if ( is_numeric( $image ) ) {
			$source = wp_get_attachment_image_src( (int) $image, $size );
			if ( ! $source ) {
				return null;
			}

			return array(
				'id'     => (int) $image,
				'url'    => $source[0],
				'width'  => (int) $source[1],
				'height' => (int) $source[2],
				'alt'    => (string) get_post_meta( (int) $image, '_wp_attachment_image_alt', true ),
				'type'   => (string) get_post_mime_type( (int) $image ),
			);
		}

		if ( is_string( $image ) && '' !== $image ) {
			return array(
				'id'     => 0,
				'url'    => $image,
				'width'  => 0,
				'height' => 0,
				'alt'    => '',
				'type'   => '',
			);
		}

		return null;
	}

	/**
	 * Returns the first non-empty value.
	 *
	 * @param string ...$values Values in order of preference.
	 * @return string
	 */
	private function pick( ...$values ) {
		foreach ( $values as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Normalizes an X username or profile URL to "@handle".
	 *
	 * @param string $value Username or URL.
	 * @return string
	 */
	private function twitter_handle( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$value = preg_replace( '#^https?://(www\.)?(twitter|x)\.com/#i', '', $value );

		return '@' . ltrim( trim( $value, '/' ), '@' );
	}

	/**
	 * Which escaping a tag needs.
	 *
	 * @param string $key Tag name.
	 * @return string `url` or `text`.
	 */
	private function value_type( $key ) {
		return in_array( $key, array( 'og:url', 'og:image', 'og:image:secure_url', 'article:publisher' ), true ) ? 'url' : 'text';
	}
}
