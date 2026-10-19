<?php
/**
 * Technical Tools feature.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Technical_Tools;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Starts the parts of Technical Tools. Only constructed while the module
 * is on, and each part only hooks in for the settings that use it.
 */
class Feature {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		new Crawl_Cleanup();
		new Html_Sitemap();

		if ( '' !== (string) Options::get( 'technical_tools_rss_before', '' ) || '' !== (string) Options::get( 'technical_tools_rss_after', '' ) || is_admin() ) {
			new Rss();
		}

		new Htaccess();

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		}
	}

	/**
	 * Loads the .htaccess editor script on the settings screen only.
	 *
	 * @return void
	 */
	public function enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the page slug.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'tagwright-seo-settings' !== $page ) {
			return;
		}

		wp_enqueue_style( 'tagwright-seo-technical-tools', TAGWRIGHT_SEO_URL . 'assets/features/technical-tools/admin.css', array( 'tagwright-seo-admin' ), TAGWRIGHT_SEO_VERSION );
		wp_enqueue_script( 'tagwright-seo-technical-tools', TAGWRIGHT_SEO_URL . 'assets/features/technical-tools/admin.js', array( 'tagwright-seo-admin', 'wp-i18n' ), TAGWRIGHT_SEO_VERSION, true );
		wp_set_script_translations( 'tagwright-seo-technical-tools', 'tagwright-seo' );
	}

	/**
	 * Removes the feature's own options.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_option( Htaccess::BACKUP_OPTION );
		delete_transient( Html_Sitemap::CACHE_KEY );
	}
}
