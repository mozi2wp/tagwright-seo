<?php
/**
 * Upload handling.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Image_Seo;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Tidies new uploads: optional clean file names, and alt text plus a
 * readable title taken from the file name.
 */
class Uploads {

	/**
	 * Registers hooks for the switched-on options.
	 */
	public function __construct() {
		if ( Options::get( 'image_seo_on_upload', true ) ) {
			add_action( 'add_attachment', array( $this, 'describe' ) );
		}

		if ( Options::get( 'image_seo_clean_filenames' ) ) {
			add_filter( 'wp_handle_upload_prefilter', array( $this, 'clean_name' ) );
			add_filter( 'wp_handle_sideload_prefilter', array( $this, 'clean_name' ) );
		}
	}

	/**
	 * Lowercases a new upload's file name and swaps spaces and odd
	 * characters for dashes. Names that would end up empty (for example
	 * one written only in a non-Latin script) are left to WordPress.
	 *
	 * @param array $file Upload data from $_FILES.
	 * @return array
	 */
	public function clean_name( $file ) {
		if ( empty( $file['name'] ) || ! is_string( $file['name'] ) ) {
			return $file;
		}

		$file['name'] = self::clean_file_name( $file['name'] );

		return $file;
	}

	/**
	 * The renaming rule on its own.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function clean_file_name( $name ) {
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$base      = '' !== $extension ? substr( $name, 0, -( strlen( $extension ) + 1 ) ) : $name;

		$clean = strtolower( remove_accents( $base ) );
		$clean = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $clean ), '-' );

		if ( '' === $clean ) {
			return $name;
		}

		return '' !== $extension ? $clean . '.' . $extension : $clean;
	}

	/**
	 * Gives a new image alt text and a readable title from its file name,
	 * when they are missing or still the raw file name.
	 *
	 * Writing _wp_attachment_image_alt is fine here: it is WordPress's own
	 * field for image alt text, and what every editor reads.
	 *
	 * @param int $attachment_id New attachment id.
	 * @return void
	 */
	public function describe( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) {
			return;
		}

		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$file = '' !== $file ? $file : (string) wp_parse_url( (string) $attachment->guid, PHP_URL_PATH );
		$name = Variables::clean_filename( $file );

		if ( '' === $name ) {
			return;
		}

		if ( '' === trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $name );
		}

		if ( Variables::is_raw_filename( $attachment->post_title, $file ) && $name !== $attachment->post_title ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => $name,
				)
			);
		}
	}
}
