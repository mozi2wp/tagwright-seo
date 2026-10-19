<?php
/**
 * Image SEO feature.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Image_Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up the image variables, front end filters, upload handling and the
 * bulk tool's REST routes.
 */
class Feature {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		Variables::register();

		// Uploads happen in wp-admin and over REST (the block editor), so
		// these hooks are needed on both sides.
		new Uploads();

		// Created only on REST requests, so page views never load the class.
		add_action(
			'rest_api_init',
			static function () {
				( new Bulk_Tool() )->routes();
			}
		);

		if ( ! is_admin() ) {
			new Frontend();
		}
	}
}
