<?php
/**
 * IndexNow client.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Indexing;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the IndexNow key, serves the key file and sends URL batches.
 *
 * The key file is virtual: a rewrite rule maps any 32 character hex .txt
 * file in the site root to a query var, and the request is answered only
 * when it matches the stored key. Matching every hex name (instead of the
 * one key) means regenerating the key never needs a rewrite flush.
 *
 * @see https://www.indexnow.org/documentation
 */
class Indexnow {

	/**
	 * Shared endpoint. It forwards every submission to all participating engines.
	 */
	const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * The protocol accepts up to 10,000 URLs per request.
	 */
	const BATCH_SIZE = 10000;

	/**
	 * Query var used for the key file.
	 */
	const QUERY_VAR = 'tagwright_indexnow_key';

	/**
	 * Registers the key file hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rule' ), 5 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve_key' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'stop_trailing_slash' ), 10, 2 );
	}

	/**
	 * The stored key, created on first use.
	 *
	 * @param bool $create Whether to create a key when none is stored yet.
	 * @return string Empty when there is no key and $create is false.
	 */
	public static function key( $create = true ) {
		$key = (string) Options::get( 'indexing_indexnow_key', '' );

		if ( ! self::is_valid_key( $key ) ) {
			$key = $create ? self::regenerate() : '';
		}

		return $key;
	}

	/**
	 * Replaces the key with a fresh random one.
	 *
	 * @return string New key.
	 */
	public static function regenerate() {
		$key = bin2hex( random_bytes( 16 ) );

		Options::update( array( 'indexing_indexnow_key' => $key ) );

		return $key;
	}

	/**
	 * Keys we create are 32 lowercase hex characters.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return (bool) preg_match( '/^[a-f0-9]{32}$/', (string) $key );
	}

	/**
	 * Public URL of the key file.
	 *
	 * Pretty permalinks get /{key}.txt; plain permalinks fall back to the
	 * query string form, which IndexNow accepts because we always send the
	 * keyLocation explicitly.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function key_url( $key ) {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . $key . '.txt' );
		}

		return add_query_arg( self::QUERY_VAR, $key, home_url( '/' ) );
	}

	/**
	 * Adds the key file rule.
	 *
	 * @return void
	 */
	public function add_rewrite_rule() {
		add_rewrite_rule( '^([a-f0-9]{32})\.txt$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Registers the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Answers the key file request. Any other hex name gets the normal 404.
	 *
	 * @param \WP $wp WordPress environment.
	 * @return void
	 */
	public function maybe_serve_key( $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$requested = (string) $wp->query_vars[ self::QUERY_VAR ];
		$key       = self::key( false );

		// A bot hitting the URL should never be the thing that creates a key.
		if ( '' === $key || ! hash_equals( $key, $requested ) ) {
			$wp->query_vars['error'] = '404';
			unset( $wp->query_vars[ self::QUERY_VAR ] );
			return;
		}

		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex', true );
		}

		echo esc_html( $key );
		exit;
	}

	/**
	 * Stops WordPress adding a trailing slash to the key file URL.
	 *
	 * @param string $redirect_url  URL WordPress wants to redirect to.
	 * @param string $requested_url Requested URL.
	 * @return string|false
	 */
	public function stop_trailing_slash( $redirect_url, $requested_url ) {
		if ( preg_match( '#/[a-f0-9]{32}\.txt$#', (string) wp_parse_url( $requested_url, PHP_URL_PATH ) ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Sends URLs to IndexNow and logs each request.
	 *
	 * @param string[] $urls URLs on this site.
	 * @return array{ok: bool, status: int, message: string, count: int}
	 */
	public static function submit( array $urls ) {
		$urls = Queue::filter_urls( $urls );

		if ( empty( $urls ) ) {
			return array(
				'ok'      => false,
				'status'  => 0,
				'message' => __( 'No URLs from this site to submit.', 'tagwright-seo' ),
				'count'   => 0,
			);
		}

		$key    = self::key();
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$result = null;

		foreach ( array_chunk( $urls, self::BATCH_SIZE ) as $batch ) {
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode(
						array(
							'host'        => $host,
							'key'         => $key,
							'keyLocation' => self::key_url( $key ),
							'urlList'     => array_values( $batch ),
						)
					),
				)
			);

			$result = self::read_response( $response, count( $batch ) );
			Log::add( 'indexnow', count( $batch ), $result['status'], $result['message'] );
		}

		return $result;
	}

	/**
	 * Turns a response into a short, human message.
	 *
	 * @param array|\WP_Error $response HTTP response.
	 * @param int             $count    URLs in the request.
	 * @return array{ok: bool, status: int, message: string, count: int}
	 */
	private static function read_response( $response, $count ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'status'  => 0,
				'message' => $response->get_error_message(),
				'count'   => $count,
			);
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$messages = array(
			200 => __( 'Submitted.', 'tagwright-seo' ),
			202 => __( 'Accepted. The engine is still checking the key file.', 'tagwright-seo' ),
			400 => __( 'Bad request: the URL list was not accepted.', 'tagwright-seo' ),
			403 => __( 'Key not valid: the key file could not be read. Check that /{key}.txt opens in a browser.', 'tagwright-seo' ),
			422 => __( 'Some URLs do not belong to this site or do not match the key.', 'tagwright-seo' ),
			429 => __( 'Too many requests. The engine asked us to slow down.', 'tagwright-seo' ),
		);

		$message = $messages[ $status ] ?? sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Unexpected response (HTTP %d).', 'tagwright-seo' ),
			$status
		);

		return array(
			'ok'      => in_array( $status, array( 200, 202 ), true ),
			'status'  => $status,
			'message' => $message,
			'count'   => $count,
		);
	}
}
