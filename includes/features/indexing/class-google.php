<?php
/**
 * Google Indexing API client.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * Signs in with a service account and publishes URL notifications.
 *
 * The service account JSON contains a private key, so it is kept in its own
 * option (not autoloaded, not part of the settings export) instead of the
 * main settings array, and the admin screen never prints it back.
 *
 * @see https://developers.google.com/search/apis/indexing-api/v3/quickstart
 */
class Google {

	/**
	 * Option holding the service account JSON.
	 */
	const KEY_OPTION = 'tagwright_seo_indexing_google_key';

	/**
	 * Transient holding the current access token.
	 */
	const TOKEN_TRANSIENT = 'tagwright_seo_indexing_google_token';

	/**
	 * OAuth token endpoint.
	 */
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Indexing API publish endpoint.
	 */
	const PUBLISH_URL = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

	/**
	 * Scope the token is requested for.
	 */
	const SCOPE = 'https://www.googleapis.com/auth/indexing';

	/**
	 * The default quota is 200 publish requests a day, so one run never
	 * sends more than this.
	 */
	const MAX_PER_RUN = 100;

	/**
	 * Saved service account, or null.
	 *
	 * @return array{client_email: string, private_key: string, token_uri: string}|null
	 */
	public static function credentials() {
		$stored = get_option( self::KEY_OPTION, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$data = json_decode( $stored, true );

		return is_array( $data ) && ! empty( $data['client_email'] ) && ! empty( $data['private_key'] ) ? $data : null;
	}

	/**
	 * Email of the saved service account, for the settings screen.
	 *
	 * @return string
	 */
	public static function client_email() {
		$credentials = self::credentials();

		return $credentials ? (string) $credentials['client_email'] : '';
	}

	/**
	 * Checks and stores a pasted service account JSON file.
	 *
	 * Only the fields we need are kept, so nothing else from the file ends
	 * up in the database.
	 *
	 * @param string $json Raw JSON.
	 * @return string|\WP_Error Client email on success.
	 */
	public static function save_credentials( $json ) {
		$data = json_decode( trim( (string) $json ), true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'tagwright_seo_indexing_bad_json', __( 'That is not valid JSON. Paste the whole key file you downloaded from Google Cloud.', 'tagwright-seo' ), array( 'status' => 400 ) );
		}

		if ( 'service_account' !== ( $data['type'] ?? '' ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return new \WP_Error( 'tagwright_seo_indexing_not_service_account', __( 'This is not a service account key. It needs "type": "service_account", a client_email and a private_key.', 'tagwright-seo' ), array( 'status' => 400 ) );
		}

		if ( ! is_email( $data['client_email'] ) ) {
			return new \WP_Error( 'tagwright_seo_indexing_bad_email', __( 'The client_email in this key is not a valid email address.', 'tagwright-seo' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'openssl_pkey_get_private' ) ) {
			return new \WP_Error( 'tagwright_seo_indexing_no_openssl', __( 'The OpenSSL PHP extension is needed to sign requests to Google. Ask your host to enable it.', 'tagwright-seo' ), array( 'status' => 500 ) );
		}

		if ( false === openssl_pkey_get_private( (string) $data['private_key'] ) ) {
			return new \WP_Error( 'tagwright_seo_indexing_bad_key', __( 'The private key in this file could not be read.', 'tagwright-seo' ), array( 'status' => 400 ) );
		}

		$keep = array(
			'type'         => 'service_account',
			'client_email' => sanitize_email( $data['client_email'] ),
			'private_key'  => (string) $data['private_key'],
			// Never trust the file's token_uri: the signed key would be posted there.
			'token_uri'    => self::TOKEN_URL,
		);

		update_option( self::KEY_OPTION, wp_json_encode( $keep ), false );
		delete_transient( self::TOKEN_TRANSIENT );

		return $keep['client_email'];
	}

	/**
	 * Forgets the service account and its token.
	 *
	 * @return void
	 */
	public static function delete_credentials() {
		delete_option( self::KEY_OPTION );
		delete_transient( self::TOKEN_TRANSIENT );
	}

	/**
	 * Publishes notifications, one request per URL as the API expects.
	 *
	 * @param array<string, string> $items URL => 'update' or 'delete'.
	 * @return array{ok: bool, status: int, message: string, count: int}
	 */
	public static function submit( array $items ) {
		$urls  = Queue::filter_urls( array_keys( $items ) );
		$token = $urls ? self::access_token() : '';

		if ( is_wp_error( $token ) ) {
			Log::add( 'google', count( $urls ), 0, $token->get_error_message() );

			return array(
				'ok'      => false,
				'status'  => 0,
				'message' => $token->get_error_message(),
				'count'   => count( $urls ),
			);
		}

		if ( empty( $urls ) ) {
			return array(
				'ok'      => false,
				'status'  => 0,
				'message' => __( 'No URLs from this site to submit.', 'tagwright-seo' ),
				'count'   => 0,
			);
		}

		$skipped = max( 0, count( $urls ) - self::MAX_PER_RUN );
		$urls    = array_slice( $urls, 0, self::MAX_PER_RUN );
		$sent    = 0;
		$status  = 0;
		$error   = '';

		foreach ( $urls as $url ) {
			$response = wp_remote_post(
				self::PUBLISH_URL,
				array(
					'timeout' => 15,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $token,
					),
					'body'    => wp_json_encode(
						array(
							'url'  => $url,
							'type' => 'delete' === ( $items[ $url ] ?? 'update' ) ? 'URL_DELETED' : 'URL_UPDATED',
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$error = $response->get_error_message();
				break;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $status ) {
				$error = self::error_message( $response, $status );

				// A bad token or an exhausted quota will fail for every other URL too.
				if ( in_array( $status, array( 401, 403, 429 ), true ) ) {
					if ( 401 === $status ) {
						delete_transient( self::TOKEN_TRANSIENT );
					}
					break;
				}
				continue;
			}

			++$sent;
		}

		if ( '' === $error ) {
			$message = $skipped
				? sprintf(
					/* translators: %d: number of URLs that were not sent. */
					_n( 'Submitted. %d URL was left out to stay inside the daily quota.', 'Submitted. %d URLs were left out to stay inside the daily quota.', $skipped, 'tagwright-seo' ),
					$skipped
				)
				: __( 'Submitted.', 'tagwright-seo' );
		} else {
			$message = sprintf(
				/* translators: 1: number of URLs accepted, 2: number of URLs tried, 3: error message. */
				__( '%1$d of %2$d accepted. %3$s', 'tagwright-seo' ),
				$sent,
				count( $urls ),
				$error
			);
		}

		Log::add( 'google', count( $urls ), $status, $message );

		return array(
			'ok'      => '' === $error,
			'status'  => $status,
			'message' => $message,
			'count'   => count( $urls ),
		);
	}

	/**
	 * A cached access token, or a new one.
	 *
	 * @return string|\WP_Error
	 */
	public static function access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$credentials = self::credentials();
		if ( ! $credentials ) {
			return new \WP_Error( 'tagwright_seo_indexing_no_google_key', __( 'No Google service account key is saved.', 'tagwright-seo' ) );
		}

		$jwt = self::jwt( $credentials );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		// Fixed URL, so keys saved before this change cannot send the JWT elsewhere.
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$detail = is_array( $body ) && ! empty( $body['error_description'] ) ? sanitize_text_field( $body['error_description'] ) : '';

			return new \WP_Error(
				'tagwright_seo_indexing_token',
				/* translators: %s: error returned by Google. */
				sprintf( __( 'Google did not accept the service account: %s', 'tagwright-seo' ), '' !== $detail ? $detail : wp_remote_retrieve_response_message( $response ) )
			);
		}

		// Tokens last an hour; refresh a minute early so a long run never uses a stale one.
		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 60 );
		set_transient( self::TOKEN_TRANSIENT, (string) $body['access_token'], $ttl );

		return (string) $body['access_token'];
	}

	/**
	 * Builds the signed JWT the token endpoint expects (RS256).
	 *
	 * @param array $credentials Service account.
	 * @return string|\WP_Error
	 */
	public static function jwt( array $credentials ) {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new \WP_Error( 'tagwright_seo_indexing_no_openssl', __( 'The OpenSSL PHP extension is needed to sign requests to Google.', 'tagwright-seo' ) );
		}

		$now    = time();
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		$claims = array(
			'iss'   => $credentials['client_email'],
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + HOUR_IN_SECONDS,
		);

		$input     = self::base64url( wp_json_encode( $header ) ) . '.' . self::base64url( wp_json_encode( $claims ) );
		$signature = '';

		if ( ! openssl_sign( $input, $signature, (string) $credentials['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new \WP_Error( 'tagwright_seo_indexing_sign', __( 'Could not sign the request with the saved private key.', 'tagwright-seo' ) );
		}

		return $input . '.' . self::base64url( $signature );
	}

	/**
	 * URL-safe base64 without padding, as JWTs use.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding, not obfuscation.
	}

	/**
	 * Pulls Google's error text out of a failed publish response.
	 *
	 * @param array $response HTTP response.
	 * @param int   $status   HTTP status.
	 * @return string
	 */
	private static function error_message( $response, $status ) {
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( is_array( $body ) && ! empty( $body['error']['message'] ) ) {
			return sanitize_text_field( $body['error']['message'] );
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'HTTP %d.', 'tagwright-seo' ),
			$status
		);
	}
}
