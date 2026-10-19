<?php
/**
 * Class autoloader.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Maps TagwrightSEO classes to files using the WordPress file naming style.
 *
 * TagwrightSEO\Admin\Settings_Page lives in includes/admin/class-settings-page.php,
 * so the folder structure always mirrors the namespace.
 */
class Autoloader {

	/**
	 * Registers the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class file if it belongs to this plugin.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$parts    = explode( '\\', $relative );
		$class    = array_pop( $parts );

		$folder = '';
		foreach ( $parts as $part ) {
			$folder .= self::slugify( $part ) . '/';
		}

		$file = TAGWRIGHT_SEO_PATH . 'includes/' . $folder . 'class-' . self::slugify( $class ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Turns `Settings_Page` into `settings-page`.
	 *
	 * @param string $name Class or namespace segment.
	 * @return string
	 */
	private static function slugify( $name ) {
		return strtolower( str_replace( '_', '-', $name ) );
	}
}
