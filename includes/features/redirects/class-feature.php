<?php
/**
 * Redirects & 404 Monitor.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Redirects;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the feature up. Only created while the module is switched on.
 */
class Feature {

	/**
	 * Schema version of the two tables.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'tagwright_seo_redirects_db_version';

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
		add_action( 'tagwright_seo_daily', array( Log::class, 'prune' ) );
		add_action( 'tagwright_seo_daily_stats', array( Repository::class, 'seed_daily_stats' ) );
		add_filter( 'tagwright_seo_dashboard_data', array( Dashboard::class, 'data' ), 10, 2 );

		// Posts are saved from wp-admin and over REST (the block editor), so
		// this one is needed on both sides.
		if ( Options::get( 'redirects_auto_slug' ) ) {
			add_action( 'post_updated', array( Post_Watcher::class, 'post_updated' ), 10, 3 );
		}

		if ( is_admin() ) {
			new Admin_Page();
			add_filter( 'tagwright_seo_dashboard_widgets', array( Dashboard::class, 'widgets' ) );
		} else {
			new Redirector();
		}
	}

	/**
	 * Tables this feature owns, without the site prefix.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'tagwright_redirects', 'tagwright_404_log' );
	}

	/**
	 * Creates or upgrades the tables and builds the lookup index.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$redirects       = Repository::table();
		$log             = Log::table();

		// dbDelta wants two spaces after PRIMARY KEY and one column per line.
		dbDelta(
			"CREATE TABLE {$redirects} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(2048) NOT NULL,
			source_hash char(32) NOT NULL,
			match_type varchar(10) NOT NULL DEFAULT 'exact',
			target varchar(2048) NOT NULL DEFAULT '',
			status smallint(3) unsigned NOT NULL DEFAULT 301,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime NULL DEFAULT NULL,
			notes varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY source_hash (source_hash),
			KEY match_enabled (match_type,enabled)
		) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(2048) NOT NULL,
			url_hash char(32) NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 1,
			referrer varchar(1024) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY last_seen (last_seen),
			KEY hits (hits)
		) {$charset_collate};"
		);

		Repository::rebuild_index();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Installs or upgrades when the stored schema is older than the code.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( version_compare( (string) get_option( self::DB_VERSION_OPTION, '0' ), self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Removes the feature's own options. The Registry drops the tables and
	 * the schema version option.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_option( Repository::INDEX_OPTION );
		delete_option( Repository::PATTERNS_OPTION );
	}
}
