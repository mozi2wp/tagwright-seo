<?php
/**
 * REST routes for the Redirects screen.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Redirects;

use TagwrightSEO\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /tagwright-seo/v1/redirects. Every route needs the Tagwright SEO
 * capability; the REST nonce is checked by WordPress.
 */
class Rest {

	/**
	 * Core namespace.
	 */
	const NAMESPACE = 'tagwright-seo/v1';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$manage = array( __CLASS__, 'can_manage' );
		$list   = array(
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			'order'    => array(
				'type'    => 'string',
				'default' => 'desc',
				'enum'    => array( 'asc', 'desc' ),
			),
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 200,
			),
		);
		$ids    = array(
			'type'     => 'array',
			'required' => true,
			'items'    => array( 'type' => 'integer' ),
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_rules' ),
					'permission_callback' => $manage,
					'args'                => array_merge(
						$list,
						array(
							'match_type' => array(
								'type'    => 'string',
								'default' => '',
								'enum'    => array( '', 'exact', 'prefix', 'regex' ),
							),
							'status'     => array(
								'type'    => 'integer',
								'default' => 0,
							),
						)
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_rule' ),
					'permission_callback' => $manage,
					'args'                => self::rule_args( true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_rule' ),
					'permission_callback' => $manage,
					'args'                => self::rule_args( false ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_rule' ),
					'permission_callback' => $manage,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'bulk_rules' ),
				'permission_callback' => $manage,
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'delete', 'enable', 'disable' ),
					),
					'ids'    => $ids,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'export_rules' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'import_rules' ),
				'permission_callback' => $manage,
				'args'                => array(
					'rows'   => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => 1000,
						'items'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/import-sources',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'import_sources' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/import-from',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'import_from' ),
				'permission_callback' => $manage,
				'args'                => array(
					'source' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_keys( Importer::sources() ),
					),
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_log' ),
				'permission_callback' => $manage,
				'args'                => $list,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/log/delete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'delete_log' ),
				'permission_callback' => $manage,
				'args'                => array( 'ids' => $ids ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/redirects/log/clear',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'clear_log' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( Admin::capability() );
	}

	/**
	 * Arguments for creating and editing a rule. Values are validated in
	 * Repository::prepare(), which also serves the PHP API.
	 *
	 * @param bool $create Whether source is required.
	 * @return array
	 */
	private static function rule_args( $create ) {
		return array(
			'source'     => array(
				'type'     => 'string',
				'required' => $create,
			),
			'target'     => array(
				'type' => 'string',
			),
			'status'     => array(
				'type' => 'integer',
				'enum' => Repository::statuses(),
			),
			'match_type' => array(
				'type' => 'string',
				'enum' => array_keys( Repository::match_labels() ),
			),
			'notes'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'enabled'    => array(
				'type' => 'boolean',
			),
			'log_url'    => array(
				'type'        => 'string',
				'description' => 'A 404 log address to remove once the redirect is saved.',
			),
		);
	}

	/**
	 * Fields of a rule that were sent with the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private static function rule_fields( $request ) {
		$fields = array();

		foreach ( array( 'source', 'target', 'status', 'match_type', 'notes', 'enabled' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$fields[ $key ] = $request->get_param( $key );
			}
		}

		return $fields;
	}

	/**
	 * Lists rules.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_rules( $request ) {
		$result = Repository::query( $request->get_params() );

		return rest_ensure_response( self::paged( $result, $request ) );
	}

	/**
	 * Creates a rule, and optionally clears the 404 it came from.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_rule( $request ) {
		$fields = self::rule_fields( $request );
		$id     = Repository::add( $fields['source'] ?? '', $fields['target'] ?? '', $fields['status'] ?? '', $fields['match_type'] ?? 'exact', $fields );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$log_url = (string) $request->get_param( 'log_url' );
		if ( '' !== $log_url ) {
			Log::delete_url( $log_url );
		}

		return rest_ensure_response(
			array(
				'item'    => Repository::present( Repository::get( $id ) ),
				'message' => __( 'Redirect added.', 'tagwright-seo' ),
			)
		);
	}

	/**
	 * Updates a rule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_rule( $request ) {
		$id     = (int) $request['id'];
		$result = Repository::update( $id, self::rule_fields( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'item'    => Repository::present( Repository::get( $id ) ),
				'message' => __( 'Redirect updated.', 'tagwright-seo' ),
			)
		);
	}

	/**
	 * Deletes a rule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_rule( $request ) {
		if ( ! Repository::delete( array( (int) $request['id'] ) ) ) {
			return new \WP_Error( 'tagwright_seo_redirect_missing', __( 'That redirect no longer exists.', 'tagwright-seo' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'message' => __( 'Redirect deleted.', 'tagwright-seo' ) ) );
	}

	/**
	 * Bulk delete, enable or disable.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function bulk_rules( $request ) {
		$ids = (array) $request->get_param( 'ids' );

		switch ( $request->get_param( 'action' ) ) {
			case 'delete':
				$count = Repository::delete( $ids );
				/* translators: %d: number of redirects. */
				$message = _n( '%d redirect deleted.', '%d redirects deleted.', $count, 'tagwright-seo' );
				break;

			case 'enable':
				$count = Repository::set_enabled( $ids, true );
				/* translators: %d: number of redirects. */
				$message = _n( '%d redirect switched on.', '%d redirects switched on.', $count, 'tagwright-seo' );
				break;

			default:
				$count = Repository::set_enabled( $ids, false );
				/* translators: %d: number of redirects. */
				$message = _n( '%d redirect switched off.', '%d redirects switched off.', $count, 'tagwright-seo' );
		}

		return rest_ensure_response( array( 'message' => sprintf( $message, $count ) ) );
	}

	/**
	 * Every rule as CSV.
	 *
	 * @return \WP_REST_Response
	 */
	public static function export_rules() {
		return rest_ensure_response( array( 'csv' => Repository::export_csv() ) );
	}

	/**
	 * Imports one batch of CSV rows. The browser parses the file and sends
	 * it in batches, so large files never hit the request size or time limit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function import_rules( $request ) {
		$rows = array();

		foreach ( (array) $request->get_param( 'rows' ) as $row ) {
			$rows[] = array_map( 'strval', array_slice( (array) $row, 0, 4 ) );
		}

		return rest_ensure_response( Repository::import( $rows, (int) $request->get_param( 'offset' ) ) );
	}

	/**
	 * Other plugins with redirects on this site.
	 *
	 * @return \WP_REST_Response
	 */
	public static function import_sources() {
		return rest_ensure_response( array( 'sources' => Importer::detected() ) );
	}

	/**
	 * Imports one batch from another plugin. The browser keeps calling with
	 * the returned offset until the status is "complete".
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function import_from( $request ) {
		return rest_ensure_response( Importer::import_batch( (string) $request->get_param( 'source' ), (int) $request->get_param( 'offset' ) ) );
	}

	/**
	 * Lists 404 log rows.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_log( $request ) {
		return rest_ensure_response( self::paged( Log::query( $request->get_params() ), $request ) );
	}

	/**
	 * Deletes 404 log rows.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function delete_log( $request ) {
		$count = Log::delete( (array) $request->get_param( 'ids' ) );

		return rest_ensure_response(
			array(
				/* translators: %d: number of log entries. */
				'message' => sprintf( _n( '%d entry deleted.', '%d entries deleted.', $count, 'tagwright-seo' ), $count ),
			)
		);
	}

	/**
	 * Empties the 404 log.
	 *
	 * @return \WP_REST_Response
	 */
	public static function clear_log() {
		Log::clear();

		return rest_ensure_response( array( 'message' => __( 'The 404 log is empty.', 'tagwright-seo' ) ) );
	}

	/**
	 * Adds paging numbers to a result.
	 *
	 * @param array            $result  items and total.
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private static function paged( array $result, $request ) {
		$per_page = (int) $request->get_param( 'per_page' );

		$result['page']  = (int) $request->get_param( 'page' );
		$result['pages'] = max( 1, (int) ceil( $result['total'] / max( 1, $per_page ) ) );

		return $result;
	}
}
