<?php
/**
 * Built-in features.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features;

use TagwrightSEO\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up the free features that ship inside Tagwright SEO.
 *
 * Each feature lives in its own folder under includes/features and is built
 * the same way an add-on would be, so it can be read, tested and switched off
 * on its own:
 *
 * - `Settings` holds the defaults, the module card and the settings tab. It
 *   is tiny and always available, so the Modules screen and the settings
 *   export can describe a feature that is switched off.
 * - `Feature` registers every hook. It is only created while the module is
 *   switched on, so a feature that is off costs nothing.
 *
 * A feature with its own tables also has these static methods on `Feature`:
 * `tables()` (table names without the site prefix), `install()` (creates or
 * upgrades them with dbDelta) and `maybe_install()` (runs install() when the
 * stored schema version is older than the code). An optional `uninstall()`
 * removes the feature's own options.
 */
class Registry {

	/**
	 * Feature ids mapped to their namespace segment, plus whether the module
	 * is on for new installs.
	 *
	 * Features that talk to outside services stay off until the site owner
	 * switches them on, as WordPress.org expects.
	 *
	 * @var array<string, array{0: string, 1: bool}>
	 */
	const FEATURES = array(
		'redirects'       => array( 'Redirects', true ),
		'blocks'          => array( 'Blocks', true ),
		'indexing'        => array( 'Indexing', false ),
		'image_seo'       => array( 'Image_Seo', false ),
		'technical_tools' => array( 'Technical_Tools', false ),
		'integrations'    => array( 'Integrations', true ),
	);

	/**
	 * Fully qualified class name for one of a feature's classes.
	 *
	 * @param string $id    Feature id.
	 * @param string $class Class name inside the feature namespace.
	 * @return string
	 */
	public static function class_name( $id, $class ) {
		return __NAMESPACE__ . '\\' . self::FEATURES[ $id ][0] . '\\' . $class;
	}

	/**
	 * Adds every feature's defaults and module switch to the core defaults.
	 *
	 * @param array $defaults Core defaults.
	 * @return array
	 */
	public static function add_defaults( array $defaults ) {
		foreach ( self::FEATURES as $id => $feature ) {
			$settings = self::class_name( $id, 'Settings' );
			$defaults = array_merge( $defaults, $settings::defaults() );

			$defaults['modules'][ $id ] = $feature[1];
		}

		return $defaults;
	}

	/**
	 * Module cards for the Modules screen.
	 *
	 * @return array[]
	 */
	public static function modules() {
		$modules = array();

		foreach ( array_keys( self::FEATURES ) as $id ) {
			$settings       = self::class_name( $id, 'Settings' );
			$modules[ $id ] = $settings::module();
		}

		return $modules;
	}

	/**
	 * Settings tabs, shown even while a module is off so people can see what
	 * it offers before switching it on.
	 *
	 * @return array[]
	 */
	public static function tabs() {
		$tabs = array();

		foreach ( array_keys( self::FEATURES ) as $id ) {
			$settings = self::class_name( $id, 'Settings' );
			$tab      = $settings::tab();

			if ( $tab ) {
				$tabs[ $id ] = $tab;
			}
		}

		return $tabs;
	}

	/**
	 * Starts every feature that is switched on.
	 *
	 * @return object[] Feature instances keyed by id.
	 */
	public static function boot() {
		$loaded = array();

		foreach ( array_keys( self::FEATURES ) as $id ) {
			if ( ! Modules::is_active( $id ) ) {
				continue;
			}

			$feature = self::class_name( $id, 'Feature' );

			if ( method_exists( $feature, 'install' ) ) {
				add_action( 'init', array( $feature, 'maybe_install' ), 1 );
			}

			$loaded[ $id ] = new $feature();
		}

		return $loaded;
	}

	/**
	 * Creates tables for a feature the moment it is switched on, so its
	 * screens work on the very next request.
	 *
	 * @param string $id     Module id.
	 * @param bool   $active New state.
	 * @return void
	 */
	public static function on_module_toggled( $id, $active ) {
		if ( ! $active || ! isset( self::FEATURES[ $id ] ) ) {
			return;
		}

		$feature = self::class_name( $id, 'Feature' );

		if ( method_exists( $feature, 'install' ) ) {
			$feature::install();
		}
	}

	/**
	 * Every table the features own, without the site prefix.
	 *
	 * @return string[]
	 */
	public static function tables() {
		$tables = array();

		foreach ( array_keys( self::FEATURES ) as $id ) {
			$feature = self::class_name( $id, 'Feature' );

			if ( method_exists( $feature, 'tables' ) ) {
				$tables = array_merge( $tables, $feature::tables() );
			}
		}

		return $tables;
	}

	/**
	 * Lets WordPress drop feature tables when a network site is deleted.
	 *
	 * @param string[] $tables Tables to drop.
	 * @return string[]
	 */
	public static function drop_tables_for_site( $tables ) {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$tables[] = $wpdb->prefix . $table;
		}

		return $tables;
	}

	/**
	 * Removes every feature's tables and options. Called from uninstall.php.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		foreach ( array_keys( self::FEATURES ) as $id ) {
			$feature = self::class_name( $id, 'Feature' );

			delete_option( 'tagwright_seo_' . $id . '_db_version' );

			if ( method_exists( $feature, 'uninstall' ) ) {
				$feature::uninstall();
			}
		}
	}
}
