<?php
/**
 * Image template variables.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Image_Seo;

use TagwrightSEO\Frontend\Context;
use TagwrightSEO\Frontend\Replacer;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Adds %filename%, %imagetitle%, %imagecaption% and %imagealt% to the core
 * variable system and fills image templates.
 *
 * The core replacer works on a page context, which has no slot for an
 * image. So the image being described is held here for the length of one
 * replace() call, and the variable filter reads it from there. The context
 * itself is the post the image belongs to, which makes %title%, %category%
 * and every other core variable describe that post.
 */
class Variables {

	/**
	 * Names this class answers for.
	 */
	const NAMES = array( 'filename', 'imagetitle', 'imagecaption', 'imagealt' );

	/**
	 * Image being described during replace(), or null.
	 *
	 * @var array|null
	 */
	private static $image = null;

	/**
	 * Registers the variable filters.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'tagwright_seo_replace_variable', array( __CLASS__, 'value' ), 10, 4 );
		add_filter( 'tagwright_seo_variable_descriptions', array( __CLASS__, 'descriptions' ) );
		add_filter( 'tagwright_seo_variable_labels', array( __CLASS__, 'labels' ) );
	}

	/**
	 * Fills an image template.
	 *
	 * @param string        $template Template.
	 * @param array         $image    Image data from image_data().
	 * @param \WP_Post|null $post     Post the image appears in or belongs to.
	 * @return string Plain text.
	 */
	public static function replace( $template, array $image, $post = null ) {
		$previous    = self::$image;
		self::$image = $image;

		try {
			$text = Replacer::replace( (string) $template, self::context( $post ) );
		} finally {
			self::$image = $previous;
		}

		return Replacer::plain( $text );
	}

	/**
	 * Context for the post an image belongs to.
	 *
	 * On the front end the image almost always sits in the page being
	 * viewed, so that context is reused instead of loading the post's SEO
	 * row a second time.
	 *
	 * @param \WP_Post|null $post Post.
	 * @return Context
	 */
	private static function context( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return new Context();
		}

		if ( ! is_admin() && did_action( 'wp' ) ) {
			$current = Context::current();
			if ( $current->object instanceof \WP_Post && (int) $current->object->ID === (int) $post->ID ) {
				return $current;
			}
		}

		return Context::for_post( $post );
	}

	/**
	 * Resolves the image variables.
	 *
	 * Outside an image template (say, someone puts %filename% in a post
	 * title template) they resolve to nothing instead of showing the raw
	 * variable to visitors.
	 *
	 * @param string|null $value    Value from earlier filters.
	 * @param string      $name     Variable name.
	 * @param string      $argument Unused.
	 * @param Context     $context  Unused.
	 * @return string|null
	 */
	public static function value( $value, $name, $argument = '', $context = null ) {
		if ( null !== $value || ! in_array( $name, self::NAMES, true ) ) {
			return $value;
		}

		if ( null === self::$image ) {
			return '';
		}

		switch ( $name ) {
			case 'filename':
				return self::$image['filename'];
			case 'imagetitle':
				return self::$image['title'];
			case 'imagecaption':
				return self::$image['caption'];
			case 'imagealt':
				return self::$image['alt'];
		}

		return '';
	}

	/**
	 * Picker descriptions.
	 *
	 * @param array $variables Variable descriptions.
	 * @return array
	 */
	public static function descriptions( $variables ) {
		$variables['filename']     = __( 'Image file name, made readable (image templates only)', 'tagwright-seo' );
		$variables['imagetitle']   = __( 'Image title from the media library (image templates only)', 'tagwright-seo' );
		$variables['imagecaption'] = __( 'Image caption (image templates only)', 'tagwright-seo' );
		$variables['imagealt']     = __( 'Saved alt text of the image (image templates only)', 'tagwright-seo' );

		return $variables;
	}

	/**
	 * Chip labels.
	 *
	 * @param array $labels Variable labels.
	 * @return array
	 */
	public static function labels( $labels ) {
		$labels['filename']     = __( 'File name', 'tagwright-seo' );
		$labels['imagetitle']   = __( 'Image title', 'tagwright-seo' );
		$labels['imagecaption'] = __( 'Image caption', 'tagwright-seo' );
		$labels['imagealt']     = __( 'Image alt', 'tagwright-seo' );

		return $labels;
	}

	/**
	 * Everything the variables need to know about one image.
	 *
	 * @param int    $attachment_id Attachment id, 0 for images that are not
	 *                              in the media library.
	 * @param string $src           Image URL, used when there is no attachment.
	 * @return array{id: int, filename: string, title: string, caption: string, alt: string}
	 */
	public static function image_data( $attachment_id, $src = '' ) {
		static $cache = array();

		$attachment_id = (int) $attachment_id;
		$attachment    = $attachment_id ? get_post( $attachment_id ) : null;

		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			$path = (string) wp_parse_url( (string) $src, PHP_URL_PATH );

			return array(
				'id'       => 0,
				'filename' => self::clean_filename( rawurldecode( wp_basename( $path ) ) ),
				'title'    => '',
				'caption'  => '',
				'alt'      => '',
			);
		}

		if ( isset( $cache[ $attachment_id ] ) ) {
			return $cache[ $attachment_id ];
		}

		$file     = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$file     = '' !== $file ? $file : (string) wp_parse_url( (string) $attachment->guid, PHP_URL_PATH );
		$base     = wp_basename( $file );
		$filename = self::clean_filename( $base );
		$title    = trim( (string) $attachment->post_title );

		// WordPress names new uploads after the file, so "img-2034-scaled"
		// is not a real title. Use the readable file name instead.
		if ( self::is_raw_filename( $title, $base ) ) {
			$title = $filename;
		}

		$cache[ $attachment_id ] = array(
			'id'       => $attachment_id,
			'filename' => $filename,
			'title'    => Replacer::plain( $title ),
			'caption'  => Replacer::plain( (string) $attachment->post_excerpt ),
			'alt'      => trim( wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ),
		);

		return $cache[ $attachment_id ];
	}

	/**
	 * Whether a title is just the file name WordPress gave the upload.
	 *
	 * @param string $title Title.
	 * @param string $file  File name, with or without folders.
	 * @return bool
	 */
	public static function is_raw_filename( $title, $file ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			return true;
		}

		// "My Photo" was typed by a person; raw names have no spaces.
		if ( preg_match( '/\s/', $title ) ) {
			return false;
		}

		$normalize = static fn( $name ) => strtolower( (string) preg_replace( '/[_\-.+~]+/', '-', self::strip_suffixes( $name ) ) );

		return $normalize( $title ) === $normalize( preg_replace( '/\.[a-z0-9]{2,5}$/i', '', wp_basename( (string) $file ) ) );
	}

	/**
	 * Removes the suffixes WordPress adds to file names: sizes, "-scaled",
	 * "-rotated" and edit stamps. They can stack, as in
	 * photo-scaled-e1700000000000-300x200.
	 *
	 * @param string $name File name without extension.
	 * @return string
	 */
	private static function strip_suffixes( $name ) {
		do {
			$before = $name;
			$name   = (string) preg_replace( '/-(?:\d+x\d+|scaled|rotated|e\d{10,})$/i', '', $name );
		} while ( $name !== $before );

		return $name;
	}

	/**
	 * Turns a file name into readable words.
	 *
	 * "Red-Apple_on-table-300x200.webp" becomes "Red apple on table" (with
	 * the first-letter setting). The extension, WordPress size suffixes
	 * and "-scaled", "-rotated" and edit stamps are removed. Camera names
	 * like "IMG_2034" or "DSC0001" say nothing about the picture, so they
	 * come back empty rather than as alt text that only looks helpful.
	 *
	 * @param string      $file File name.
	 * @param string|null $case none, sentence or title. Defaults to the setting.
	 * @return string
	 */
	public static function clean_filename( $file, $case = null ) {
		$name = wp_basename( (string) $file );
		$name = self::strip_suffixes( (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $name ) );

		$name = (string) preg_replace( '/[\s_\-.+~]+/u', ' ', $name );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );

		if ( preg_match( '/^(?:img|dsc|dscn|dscf|dcim|pxl|mvimg|vid|image|photo|pic|screenshot|screen shot|scan|p)?(?:[\d\s]|\b(?:at|am|pm)\b)*$/i', $name ) ) {
			return '';
		}

		$case = null === $case ? (string) Options::get( 'image_seo_filename_case', 'sentence' ) : $case;

		if ( 'sentence' === $case ) {
			$name = mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
		} elseif ( 'title' === $case ) {
			// Capitalises each word but leaves the rest alone, so "NASA" stays "NASA".
			$name = (string) preg_replace_callback(
				'/(^|\s)(\p{Ll})/u',
				static fn( $match ) => $match[1] . mb_strtoupper( $match[2] ),
				$name
			);
		}

		return $name;
	}
}
