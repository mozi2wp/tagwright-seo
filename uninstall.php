<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Data is only removed when the site owner asked for it under
 * Tagwright SEO > Tools, because losing years of SEO data by accident hurts.
 *
 * @package TagwrightSEO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The built-in features know their own tables, so load the class map.
if ( ! defined( 'TAGWRIGHT_SEO_PATH' ) ) {
	define( 'TAGWRIGHT_SEO_PATH', plugin_dir_path( __FILE__ ) );
}
require_once TAGWRIGHT_SEO_PATH . 'includes/class-autoloader.php';
TagwrightSEO\Autoloader::register();

/**
 * Removes Tagwright SEO data for the current site if the owner opted in.
 *
 * @return void
 */
function tagwright_seo_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'tagwright_seo_settings', array() );

	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	TagwrightSEO\Features\Registry::uninstall();

	foreach ( array( 'tagwright_objects', 'tagwright_stats' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	$options = array(
		'tagwright_seo_settings',
		'tagwright_seo_version',
		'tagwright_seo_db_version',
		'tagwright_seo_installed_at',
		'tagwright_seo_setup_completed',
		'tagwright_seo_wizard_redirect',
		'tagwright_seo_flush_rewrite',
		'tagwright_seo_index_state',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	wp_clear_scheduled_hook( 'tagwright_seo_daily' );
	wp_clear_scheduled_hook( 'tagwright_seo_index_batch' );
}

if ( is_multisite() ) {
	$tagwright_seo_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $tagwright_seo_site_ids as $tagwright_seo_site_id ) {
		switch_to_blog( $tagwright_seo_site_id );
		tagwright_seo_uninstall_site();
		restore_current_blog();
	}
} else {
	tagwright_seo_uninstall_site();
}
