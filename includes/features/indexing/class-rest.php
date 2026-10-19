<?php
/**
 * Instant Indexing REST routes.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Indexing;

use TagwrightSEO\Admin\Admin;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /tagwright-seo/v1/indexing used by the settings tab.
 */
class Rest {

	/**
	 * Core namespace.
	 */
	const NAMESPACE = 'tagwright-seo/v1';

	/**
	 * Most URLs the manual form accepts at once.
	 */
	const MANUAL_LIMIT = 500;

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$manage = array( __CLASS__, 'can_manage' );

		register_rest_route(
			self::NAMESPACE,
			'/indexing/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => $manage,
				'args'                => array(
					// Not sanitize_textarea_field(): it strips %xx sequences and would break encoded slugs. Each line goes through esc_url_raw() instead.
					'urls' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => static fn( $value ) => wp_check_invalid_utf8( wp_strip_all_tags( (string) $value ) ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/indexing/key',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'regenerate_key' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/indexing/google-key',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_google_key' ),
					'permission_callback' => $manage,
					'args'                => array(
						// The private key needs its line breaks, so this is checked by Google::save_credentials() instead of a text sanitizer.
						'json' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_google_key' ),
					'permission_callback' => $manage,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/indexing/log',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => static fn() => rest_ensure_response( Log::for_display() ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'clear_log' ),
					'permission_callback' => $manage,
				),
			)
		);
	}

	/**
	 * Settings-level permission.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( Admin::capability() );
	}

	/**
	 * Sends the pasted URLs right away.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function submit( $request ) {
		$lines = preg_split( '/[\r\n]+/', (string) $request->get_param( 'urls' ) );
		$urls  = Queue::filter_urls( (array) $lines );

		if ( empty( $urls ) ) {
			return new \WP_Error( 'tagwright_seo_indexing_no_urls', __( 'Add at least one full URL from this site, for example https://example.com/my-post/.', 'tagwright-seo' ), array( 'status' => 400 ) );
		}

		if ( count( $urls ) > self::MANUAL_LIMIT ) {
			return new \WP_Error(
				'tagwright_seo_indexing_too_many',
				/* translators: %d: maximum number of URLs. */
				sprintf( __( 'Submit at most %d URLs at a time.', 'tagwright-seo' ), self::MANUAL_LIMIT ),
				array( 'status' => 400 )
			);
		}

		if ( ! Options::get( 'indexing_indexnow' ) && ! ( Options::get( 'indexing_google' ) && Google::credentials() ) ) {
			return new \WP_Error( 'tagwright_seo_indexing_no_engine', __( 'Switch on IndexNow or set up Google first, then save the settings.', 'tagwright-seo' ), array( 'status' => 400 ) );
		}

		$results = Queue::send( array_fill_keys( $urls, 'update' ) );
		$names   = Log::engine_names();
		$parts   = array();
		$ok      = true;

		foreach ( $results as $engine => $result ) {
			$parts[] = ( $names[ $engine ] ?? $engine ) . ': ' . $result['message'];
			$ok      = $ok && $result['ok'];
		}

		return rest_ensure_response(
			array(
				'success' => $ok,
				'message' => implode( ' ', $parts ),
				'log'     => Log::for_display(),
			)
		);
	}

	/**
	 * Creates a new IndexNow key.
	 *
	 * @return \WP_REST_Response
	 */
	public static function regenerate_key() {
		$key = Indexnow::regenerate();

		return rest_ensure_response(
			array(
				'key'     => $key,
				'url'     => Indexnow::key_url( $key ),
				'message' => __( 'New key created. Search engines will check the new key file on your next submission.', 'tagwright-seo' ),
			)
		);
	}

	/**
	 * Stores a pasted service account key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_google_key( $request ) {
		$email = Google::save_credentials( (string) $request->get_param( 'json' ) );

		if ( is_wp_error( $email ) ) {
			return $email;
		}

		return rest_ensure_response(
			array(
				'email'   => $email,
				'message' => sprintf(
					/* translators: %s: service account email. */
					__( 'Key saved for %s. Add this address as an owner of your property in Search Console.', 'tagwright-seo' ),
					$email
				),
			)
		);
	}

	/**
	 * Removes the service account key.
	 *
	 * @return \WP_REST_Response
	 */
	public static function delete_google_key() {
		Google::delete_credentials();

		return rest_ensure_response( array( 'message' => __( 'Service account key removed.', 'tagwright-seo' ) ) );
	}

	/**
	 * Empties the log.
	 *
	 * @return \WP_REST_Response
	 */
	public static function clear_log() {
		Log::clear();

		return rest_ensure_response(
			array(
				'message' => __( 'Log cleared.', 'tagwright-seo' ),
				'log'     => array(),
			)
		);
	}
}
