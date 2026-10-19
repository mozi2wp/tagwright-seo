<?php
/**
 * REST API for the admin screens.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Api;

use TagwrightSEO\Admin\Admin;
use TagwrightSEO\Admin\Settings_Registry;
use TagwrightSEO\Data\Indexer;
use TagwrightSEO\Data\Objects;
use TagwrightSEO\Data\Stats;
use TagwrightSEO\Importers\Importers;
use TagwrightSEO\Modules;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints under /wp-json/tagwright-seo/v1 used by the dashboard, settings,
 * tools, wizard and editor screens.
 */
class Rest_Controller {

	/**
	 * Namespace.
	 */
	const NAMESPACE = 'tagwright-seo/v1';

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $manage,
					'args'                => array(
						'settings' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/modules',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle_module' ),
				'permission_callback' => $manage,
				'args'                => array(
					'module' => array(
						'type'     => 'string',
						'required' => true,
					),
					'active' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'dashboard' ),
				'permission_callback' => $manage,
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 730,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/keyphrase',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'keyphrase_usage' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'keyphrase'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'current_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'type'       => array(
						'type'    => 'string',
						'default' => 'post',
						'enum'    => array( 'post', 'term' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/custom-field',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'custom_field' ),
				'permission_callback' => array( $this, 'can_read_object' ),
				'args'                => array(
					'type' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'post', 'term', 'user' ),
					),
					'id'   => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'key'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/index',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static fn() => rest_ensure_response( Indexer::get_state() ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/index/(?P<action>start|batch)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'index_action' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tools/(?P<action>[a-z_]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'tool_action' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import-settings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_settings' ),
				'permission_callback' => $manage,
				'args'                => array(
					'settings' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/importers/(?P<source>[a-z_]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_importer' ),
				'permission_callback' => $manage,
				'args'                => array(
					'offset'   => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'settings' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wizard',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_wizard' ),
				'permission_callback' => $manage,
				'args'                => array(
					'settings' => array(
						'type'    => 'object',
						'default' => array(),
					),
					'modules'  => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
	}

	/**
	 * Permission check for settings level endpoints.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( Admin::capability() );
	}

	/**
	 * Only people who can edit an object may read its custom fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_read_object( $request ) {
		$id = (int) $request->get_param( 'id' );

		switch ( $request->get_param( 'type' ) ) {
			case 'post':
				return current_user_can( 'edit_post', $id );
			case 'term':
				return current_user_can( 'edit_term', $id );
			case 'user':
				return current_user_can( 'edit_user', $id );
		}

		return false;
	}

	/**
	 * Resolves a custom field the same way the front end does, for previews.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function custom_field( $request ) {
		$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $request->get_param( 'key' ) );

		return rest_ensure_response(
			array(
				'value' => \TagwrightSEO\Frontend\Replacer::custom_field( $request->get_param( 'type' ), (int) $request->get_param( 'id' ), $key ),
			)
		);
	}

	/**
	 * Returns all settings.
	 *
	 * Only the Tools export reads this. Admins export everything; people given
	 * access through the tagwright_seo_capability filter do not get API keys and
	 * secrets. Those keys are left out rather than blanked, so importing such
	 * a file keeps the stored secrets.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		$settings = Options::all();

		if ( ! current_user_can( 'manage_options' ) ) {
			foreach ( array_keys( $settings ) as $key ) {
				if ( preg_match( '/(?:^|_)(?:key|token|secret|password)(?:_|$)/', (string) $key ) ) {
					unset( $settings[ $key ] );
				}
			}
		}

		return rest_ensure_response( $settings );
	}

	/**
	 * Saves settings. Only values that actually changed are written, so
	 * listeners such as the category base flush only react to real changes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( $request ) {
		$input = (array) $request->get_param( 'settings' );
		$clean = Settings_Registry::sanitize( $input );

		$changed = array();
		foreach ( $clean as $key => $value ) {
			if ( Options::get( $key ) !== $value ) {
				$changed[ $key ] = $value;
			}
		}

		if ( $changed ) {
			Options::update( $changed );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'changed' => array_keys( $changed ),
				'message' => $changed ? __( 'Settings saved.', 'tagwright-seo' ) : __( 'Nothing changed.', 'tagwright-seo' ),
			)
		);
	}

	/**
	 * Switches a module on or off.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_module( $request ) {
		$module  = sanitize_key( $request->get_param( 'module' ) );
		$active  = (bool) $request->get_param( 'active' );
		$modules = Modules::all();

		if ( ! isset( $modules[ $module ] ) ) {
			return new \WP_Error( 'tagwright_seo_unknown_module', __( 'Unknown module.', 'tagwright-seo' ), array( 'status' => 404 ) );
		}

		Modules::set_active( $module, $active );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: module name. */
					$active ? __( '%s switched on.', 'tagwright-seo' ) : __( '%s switched off.', 'tagwright-seo' ),
					$modules[ $module ]['title']
				),
			)
		);
	}

	/**
	 * Dashboard numbers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function dashboard( $request ) {
		$days = (int) $request->get_param( 'days' );

		Stats::maybe_snapshot_today();

		$metrics = array( 'seo_score_avg', 'readability_avg', 'posts_published', 'sitemap_urls', 'seo_poor', 'seo_good', 'no_description', 'no_focus_keyphrase', 'noindex' );
		$series  = array();
		foreach ( $metrics as $metric ) {
			$series[ $metric ] = Stats::series( $metric, $days );
		}

		$data = array(
			'days'       => $days,
			'summary'    => Stats::live_summary(),
			'series'     => $series,
			'index'      => Indexer::get_state(),
			'sitemapUrl' => Modules::is_active( 'sitemaps' ) ? \TagwrightSEO\Sitemaps\Router::index_url() : '',
			'kpis'       => array(),
		);

		/**
		 * Filters the dashboard data. Add-ons append their own series and
		 * extra KPI tiles (`kpis`, each with label, icon, value, spark and
		 * url) here.
		 *
		 * @param array $data Dashboard data.
		 * @param int   $days Days in the selected range.
		 */
		return rest_ensure_response( apply_filters( 'tagwright_seo_dashboard_data', $data, $days ) );
	}

	/**
	 * Lists other content that already targets a keyphrase.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function keyphrase_usage( $request ) {
		global $wpdb;

		$keyphrase = trim( sanitize_text_field( $request->get_param( 'keyphrase' ) ) );
		$type      = $request->get_param( 'type' );
		$current   = (int) $request->get_param( 'current_id' );

		if ( '' === $keyphrase ) {
			return rest_ensure_response( array( 'items' => array() ) );
		}

		$table = Objects::table();
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT object_id FROM %i WHERE object_type = %s AND focus_keyphrase = %s AND object_id <> %d AND post_status <> 'trash' LIMIT 10",
				$table,
				$type,
				$keyphrase,
				$current
			)
		);

		$items = array();
		foreach ( $ids as $id ) {
			if ( 'post' === $type ) {
				if ( ! current_user_can( 'edit_post', (int) $id ) ) {
					continue;
				}
				$items[] = array(
					'id'    => (int) $id,
					'title' => get_the_title( (int) $id ),
					'edit'  => get_edit_post_link( (int) $id, 'raw' ),
				);
			} else {
				$term = get_term( (int) $id );
				// Same rule as posts: only list terms this user may edit.
				if ( $term && ! is_wp_error( $term ) && current_user_can( 'edit_term', $term->term_id ) ) {
					$items[] = array(
						'id'    => (int) $id,
						'title' => $term->name,
						'edit'  => get_edit_term_link( $term ),
					);
				}
			}
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * Starts a full index or runs one batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index_action( $request ) {
		if ( 'start' === $request->get_param( 'action' ) ) {
			Indexer::queue_full_index();
		}

		return rest_ensure_response( Indexer::run_batch( 200 ) );
	}

	/**
	 * One-click maintenance tools.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function tool_action( $request ) {
		switch ( $request->get_param( 'action' ) ) {
			case 'snapshot':
				Stats::snapshot();
				$message = __( 'Statistics updated.', 'tagwright-seo' );
				break;

			case 'clear_stats':
				Stats::clear();
				Stats::snapshot();
				$message = __( 'Statistics history cleared.', 'tagwright-seo' );
				break;

			case 'flush_rewrite':
				flush_rewrite_rules( false );
				$message = __( 'Permalinks refreshed. Sitemaps and llms.txt URLs should work now.', 'tagwright-seo' );
				break;

			case 'reset_settings':
				Options::replace( Options::defaults() );
				update_option( 'tagwright_seo_flush_rewrite', 1, false );
				$message = __( 'All settings are back to their defaults.', 'tagwright-seo' );
				break;

			case 'flush_cache':
				if ( wp_cache_supports( 'flush_group' ) ) {
					wp_cache_flush_group( Objects::CACHE_GROUP );
				} else {
					wp_cache_flush();
				}
				$message = __( 'Cache cleared.', 'tagwright-seo' );
				break;

			default:
				return new \WP_Error( 'tagwright_seo_unknown_tool', __( 'Unknown tool.', 'tagwright-seo' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => $message,
			)
		);
	}

	/**
	 * Imports a settings export.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_settings( $request ) {
		$input = (array) $request->get_param( 'settings' );

		if ( isset( $input['plugin'] ) && 'tagwright-seo' === $input['plugin'] && isset( $input['settings'] ) ) {
			$input = (array) $input['settings'];
		}

		$clean = Settings_Registry::sanitize( $input );

		if ( isset( $input['modules'] ) && is_array( $input['modules'] ) ) {
			$modules = array();
			foreach ( array_keys( Modules::all() ) as $module ) {
				if ( isset( $input['modules'][ $module ] ) ) {
					$modules[ $module ] = (bool) $input['modules'][ $module ];
				}
			}
			$clean['modules'] = array_merge( Options::get( 'modules', array() ), $modules );
		}

		if ( empty( $clean ) ) {
			return new \WP_Error( 'tagwright_seo_empty_import', __( 'That file does not contain any Tagwright SEO settings.', 'tagwright-seo' ), array( 'status' => 400 ) );
		}

		Options::update( $clean );
		update_option( 'tagwright_seo_flush_rewrite', 1, false );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: number of settings. */
					_n( '%d setting imported.', '%d settings imported.', count( $clean ), 'tagwright-seo' ),
					count( $clean )
				),
			)
		);
	}

	/**
	 * Runs one batch of an importer.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_importer( $request ) {
		$importer = Importers::get( $request->get_param( 'source' ) );

		if ( ! $importer ) {
			return new \WP_Error( 'tagwright_seo_unknown_importer', __( 'Unknown importer.', 'tagwright-seo' ), array( 'status' => 404 ) );
		}

		$offset = (int) $request->get_param( 'offset' );

		if ( 0 === $offset && $request->get_param( 'settings' ) ) {
			$importer->import_settings();
		}

		return rest_ensure_response( $importer->import_batch( $offset ) );
	}

	/**
	 * Saves the wizard choices.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function complete_wizard( $request ) {
		$clean = Settings_Registry::sanitize( (array) $request->get_param( 'settings' ) );

		$modules = (array) $request->get_param( 'modules' );
		if ( $modules ) {
			$switches = Options::get( 'modules', array() );
			foreach ( array_keys( Modules::all() ) as $module ) {
				if ( array_key_exists( $module, $modules ) ) {
					$switches[ $module ] = (bool) $modules[ $module ];
				}
			}
			$clean['modules'] = $switches;
		}

		if ( $clean ) {
			Options::update( $clean );
		}

		update_option( 'tagwright_seo_setup_completed', time(), false );
		update_option( 'tagwright_seo_flush_rewrite', 1, false );

		return rest_ensure_response(
			array(
				'success'  => true,
				'redirect' => admin_url( 'admin.php?page=tagwright-seo' ),
			)
		);
	}
}
