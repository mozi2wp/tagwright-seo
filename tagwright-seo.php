<?php
/**
 * Plugin Name:       Tagwright SEO
 * Plugin URI:        https://github.com/mozi2wp/tagwright-seo
 * Description:       Fast, lightweight SEO for WordPress. Meta tags, social cards, schema, XML sitemaps and content analysis, built on dedicated database tables so your site stays quick.
 * Version:           1.5.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Mozayyan Abbas
 * Author URI:        https://profiles.wordpress.org/mozi2wp/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tagwright-seo
 * Domain Path:       /languages
 *
 * @package TagwrightSEO
 */

defined( 'ABSPATH' ) || exit;

define( 'TAGWRIGHT_SEO_VERSION', '1.5.0' );
define( 'TAGWRIGHT_SEO_DB_VERSION', '1.1.0' );
define( 'TAGWRIGHT_SEO_FILE', __FILE__ );
define( 'TAGWRIGHT_SEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAGWRIGHT_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'TAGWRIGHT_SEO_BASENAME', plugin_basename( __FILE__ ) );

require_once TAGWRIGHT_SEO_PATH . 'includes/class-autoloader.php';
require_once TAGWRIGHT_SEO_PATH . 'includes/functions.php';

TagwrightSEO\Autoloader::register();

register_activation_hook( __FILE__, array( 'TagwrightSEO\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TagwrightSEO\\Installer', 'deactivate' ) );

/**
 * Returns the main plugin instance.
 *
 * Add-ons should hook into `tagwright_seo_loaded` rather than calling this
 * early, so they know the core has finished booting.
 *
 * @return TagwrightSEO\Plugin
 */
function tagwright_seo() {
	return TagwrightSEO\Plugin::instance();
}

add_action( 'plugins_loaded', 'tagwright_seo', 5 );
