<?php
/**
 * Installs and upgrades the plugin's database tables and options.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation, deactivation, schema upgrades and multisite setup.
 */
class Installer {

	/**
	 * Option that stores the installed database schema version.
	 */
	const DB_VERSION_OPTION = 'tagwright_seo_db_version';

	/**
	 * Option that tells the admin to redirect to the setup wizard once.
	 */
	const WIZARD_REDIRECT_OPTION = 'tagwright_seo_wizard_redirect';

	/**
	 * Hooks that need to be present on every request.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_initialize_site', array( __CLASS__, 'install_new_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'drop_tables_for_site' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 1 );
	}

	/**
	 * Activation callback.
	 *
	 * On a network-wide activation we have to visit every site because each
	 * site gets its own set of tables.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}

		self::install();

		// Only send people to the wizard when they activate a single site by hand.
		if ( ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! get_option( 'tagwright_seo_setup_completed' ) ) {
			update_option( self::WIZARD_REDIRECT_OPTION, 1, false );
		}
	}

	/**
	 * Deactivation callback. Leaves data alone, only tidies up scheduled work.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'tagwright_seo_daily' );
		wp_clear_scheduled_hook( 'tagwright_seo_index_batch' );
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Sets up tables when a new site is created on a network where we are
	 * network active.
	 *
	 * @param \WP_Site $site New site object.
	 * @return void
	 */
	public static function install_new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( TAGWRIGHT_SEO_BASENAME ) ) {
			return;
		}

		switch_to_blog( $site->blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Lets WordPress drop our tables when a site is deleted from the network.
	 *
	 * @param string[] $tables Table names WordPress is about to drop.
	 * @return string[]
	 */
	public static function drop_tables_for_site( $tables ) {
		foreach ( self::get_table_names() as $table ) {
			$tables[] = $table;
		}
		return $tables;
	}

	/**
	 * Runs the installer again if the stored schema is older than the code.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( version_compare( (string) get_option( self::DB_VERSION_OPTION, '0' ), TAGWRIGHT_SEO_DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Creates tables, default settings and scheduled events for the current site.
	 *
	 * @return void
	 */
	public static function install() {
		self::create_tables();

		// add_option() is a no-op when the option exists, so existing settings survive upgrades.
		add_option( Options::OPTION_NAME, Options::defaults(), '', true );
		update_option( 'tagwright_seo_version', TAGWRIGHT_SEO_VERSION, false );
		update_option( self::DB_VERSION_OPTION, TAGWRIGHT_SEO_DB_VERSION, false );

		if ( ! get_option( 'tagwright_seo_installed_at' ) ) {
			update_option( 'tagwright_seo_installed_at', time(), false );
		}

		if ( ! wp_next_scheduled( 'tagwright_seo_daily' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', 'tagwright_seo_daily' );
		}

		// Existing content gets indexed in the background, a batch at a time.
		Data\Indexer::queue_full_index();

		// Rewrite rules for sitemaps and llms.txt are flushed on the next load.
		update_option( 'tagwright_seo_flush_rewrite', 1, false );
	}

	/**
	 * Creates or updates the custom tables using dbDelta.
	 *
	 * dbDelta is picky about formatting: two spaces after PRIMARY KEY, one
	 * field per line, and KEY instead of INDEX.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = self::get_table_names();

		$objects_sql = "CREATE TABLE {$tables['objects']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(20) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			object_sub_type varchar(64) NOT NULL DEFAULT '',
			author_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_status varchar(20) NOT NULL DEFAULT '',
			is_public tinyint(1) NOT NULL DEFAULT 1,
			permalink text NULL,
			permalink_hash char(40) NOT NULL DEFAULT '',
			title text NULL,
			description text NULL,
			meta_keywords text NULL,
			focus_keyphrase varchar(191) NOT NULL DEFAULT '',
			canonical text NULL,
			robots_noindex tinyint(1) NULL DEFAULT NULL,
			robots_nofollow tinyint(1) NULL DEFAULT NULL,
			robots_noarchive tinyint(1) NULL DEFAULT NULL,
			robots_nosnippet tinyint(1) NULL DEFAULT NULL,
			robots_noimageindex tinyint(1) NULL DEFAULT NULL,
			max_snippet int(11) NULL DEFAULT NULL,
			max_image_preview varchar(10) NULL DEFAULT NULL,
			max_video_preview int(11) NULL DEFAULT NULL,
			og_title text NULL,
			og_description text NULL,
			og_image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			og_image_url text NULL,
			twitter_title text NULL,
			twitter_description text NULL,
			schema_page_type varchar(50) NOT NULL DEFAULT '',
			schema_article_type varchar(50) NOT NULL DEFAULT '',
			seo_score tinyint(3) unsigned NULL DEFAULT NULL,
			readability_score tinyint(3) unsigned NULL DEFAULT NULL,
			sitemap_exclude tinyint(1) NOT NULL DEFAULT 0,
			sitemap_priority decimal(2,1) NULL DEFAULT NULL,
			sitemap_changefreq varchar(10) NULL DEFAULT NULL,
			comment_count int(11) unsigned NOT NULL DEFAULT 0,
			images longtext NULL,
			extra longtext NULL,
			published_at datetime NULL DEFAULT NULL,
			modified_at datetime NULL DEFAULT NULL,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY object (object_type,object_id),
			KEY sitemap (object_type,object_sub_type,is_public,modified_at),
			KEY permalink_hash (permalink_hash),
			KEY scores (object_type,seo_score,readability_score),
			KEY author (author_id),
			KEY focus_keyphrase (focus_keyphrase)
		) $charset_collate;";

		$stats_sql = "CREATE TABLE {$tables['stats']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stat_date date NOT NULL,
			source varchar(40) NOT NULL DEFAULT 'core',
			metric varchar(64) NOT NULL,
			dimension varchar(100) NOT NULL DEFAULT '',
			value double NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY daily_metric (stat_date,source,metric,dimension),
			KEY metric_lookup (source,metric,stat_date)
		) $charset_collate;";

		dbDelta( $objects_sql );
		dbDelta( $stats_sql );
	}

	/**
	 * Full table names for the current site.
	 *
	 * @return array{objects: string, stats: string}
	 */
	public static function get_table_names() {
		global $wpdb;

		return array(
			'objects' => $wpdb->prefix . 'tagwright_objects',
			'stats'   => $wpdb->prefix . 'tagwright_stats',
		);
	}
}
