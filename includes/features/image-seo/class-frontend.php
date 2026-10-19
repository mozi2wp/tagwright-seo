<?php
/**
 * Alt and title text on the front end.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Image_Seo;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Fills in alt (and optionally title) attributes as images are printed.
 *
 * Two cheap hooks cover nearly every image: images printed with
 * wp_get_attachment_image() (featured images, galleries, most themes and
 * blocks that render in PHP) and images in post content, which WordPress
 * already hands over one tag at a time through wp_content_img_tag. Nobody
 * runs a regular expression over the whole page.
 */
class Frontend {

	/**
	 * Replace every alt text, not just missing ones.
	 *
	 * @var bool
	 */
	private $override;

	/**
	 * Add title attributes.
	 *
	 * @var bool
	 */
	private $add_title;

	/**
	 * True while WordPress builds a featured image and those are excluded.
	 *
	 * @var bool
	 */
	private $in_thumbnail = false;

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		$this->override  = 'all' === Options::get( 'image_seo_alt_mode', 'empty' );
		$this->add_title = (bool) Options::get( 'image_seo_add_title' );

		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'attachment_attributes' ), 20, 2 );
		add_filter( 'wp_content_img_tag', array( $this, 'content_image' ), 20, 3 );

		if ( ! Options::get( 'image_seo_featured', true ) ) {
			add_action( 'begin_fetch_post_thumbnail_html', array( $this, 'thumbnail_start' ) );
			add_action( 'end_fetch_post_thumbnail_html', array( $this, 'thumbnail_end' ) );
		}
	}

	/**
	 * Marks the start of a featured image.
	 *
	 * @return void
	 */
	public function thumbnail_start() {
		$this->in_thumbnail = true;
	}

	/**
	 * Marks the end of a featured image.
	 *
	 * @return void
	 */
	public function thumbnail_end() {
		$this->in_thumbnail = false;
	}

	/**
	 * Alt text for an image, or '' when the template gives nothing.
	 *
	 * When only missing text is filled, an alt text saved in the media
	 * library wins over the template. That covers images inserted before
	 * someone added alt text to the attachment: the post still has alt="",
	 * but the library knows better.
	 *
	 * @param int    $attachment_id Attachment id or 0.
	 * @param string $src           Image URL for images outside the library.
	 * @return string
	 */
	public function alt_for( $attachment_id, $src = '' ) {
		$image = Variables::image_data( $attachment_id, $src );

		if ( ! $this->override && '' !== $image['alt'] ) {
			return $image['alt'];
		}

		$alt = Variables::replace( (string) Options::get( 'image_seo_alt_template', '%filename%' ), $image, get_post() );

		return '' !== $alt ? $alt : $image['filename'];
	}

	/**
	 * Title text for an image, or ''.
	 *
	 * @param int    $attachment_id Attachment id or 0.
	 * @param string $src           Image URL for images outside the library.
	 * @return string
	 */
	public function title_for( $attachment_id, $src = '' ) {
		$image = Variables::image_data( $attachment_id, $src );

		return Variables::replace( (string) Options::get( 'image_seo_title_template', '%imagetitle%' ), $image, get_post() );
	}

	/**
	 * Images printed with wp_get_attachment_image().
	 *
	 * @param array    $attr       Attributes.
	 * @param \WP_Post $attachment Attachment.
	 * @return array
	 */
	public function attachment_attributes( $attr, $attachment ) {
		if ( $this->in_thumbnail || ! $attachment instanceof \WP_Post ) {
			return $attr;
		}

		$current = trim( (string) ( $attr['alt'] ?? '' ) );

		if ( '' === $current || $this->override ) {
			$alt = $this->alt_for( $attachment->ID );
			if ( '' !== $alt ) {
				$attr['alt'] = $alt;
			}
		}

		if ( $this->add_title && empty( $attr['title'] ) ) {
			$title = $this->title_for( $attachment->ID );
			if ( '' !== $title ) {
				$attr['title'] = $title;
			}
		}

		return $attr;
	}

	/**
	 * Images in post content. WordPress passes one <img> tag at a time.
	 *
	 * @param string $html          The img tag.
	 * @param string $context       Where the content comes from.
	 * @param int    $attachment_id Attachment id, 0 when unknown.
	 * @return string
	 */
	public function content_image( $html, $context, $attachment_id ) {
		$tag = new \WP_HTML_Tag_Processor( (string) $html );
		if ( ! $tag->next_tag( 'img' ) ) {
			return $html;
		}

		$src     = $attachment_id ? '' : (string) $tag->get_attribute( 'src' );
		$current = $tag->get_attribute( 'alt' );
		$current = is_string( $current ) ? trim( $current ) : '';
		$changed = false;

		if ( '' === $current || $this->override ) {
			$alt = $this->alt_for( (int) $attachment_id, $src );
			if ( '' !== $alt && $alt !== $current ) {
				$tag->set_attribute( 'alt', $alt );
				$changed = true;
			}
		}

		if ( $this->add_title && null === $tag->get_attribute( 'title' ) ) {
			$title = $this->title_for( (int) $attachment_id, $src );
			if ( '' !== $title ) {
				$tag->set_attribute( 'title', $title );
				$changed = true;
			}
		}

		return $changed ? $tag->get_updated_html() : $html;
	}
}
