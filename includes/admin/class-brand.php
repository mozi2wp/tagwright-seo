<?php
/**
 * Admin branding.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The logo, menu icon and credits shown in the admin, in one place so a
 * white-label add-on can change them with a single filter.
 */
final class Brand {

	/**
	 * Branding for this request.
	 *
	 * @var array|null
	 */
	private static $brand = null;

	/**
	 * Branding values.
	 *
	 * @return array {
	 *     @type string $logo      Logo image URL for the admin header. Empty for the built-in mark.
	 *     @type string $menu_icon Menu icon (URL, data URI or dashicons class). Empty for the built-in icon.
	 *     @type bool   $version   Show the version number in the header.
	 *     @type bool   $credits   Show the review request in the admin footer.
	 *     @type bool   $upsells   Show the Add-ons and Pro screens.
	 * }
	 */
	public static function get(): array {
		if ( null === self::$brand ) {
			/**
			 * Filters the admin branding.
			 *
			 * @param array $brand logo, menu_icon, version, credits and upsells.
			 */
			self::$brand = (array) apply_filters(
				'tagwright_seo_brand',
				array(
					'logo'      => '',
					'menu_icon' => '',
					'version'   => true,
					'credits'   => true,
					'upsells'   => true,
				)
			);
		}

		return self::$brand;
	}

	/**
	 * One branding value.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Value when missing.
	 * @return mixed
	 */
	public static function value( $key, $fallback = null ) {
		return self::get()[ $key ] ?? $fallback;
	}
}
