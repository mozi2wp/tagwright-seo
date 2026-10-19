<?php
/**
 * Front end: redirects and 404 logging.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Redirects;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Matches the request against the rules and logs 404s.
 *
 * Matching runs on `parse_request`, before WordPress queries the database
 * for the page. A redirected request therefore skips the main query, the
 * 404 handling and any canonical redirect guessing, and an address that
 * still resolves to content can be redirected too. REST requests are left
 * alone.
 */
class Redirector {

	/**
	 * Status (410 or 451) to send instead of the theme's 404.
	 *
	 * @var int
	 */
	private $gone = 0;

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'parse_request', array( $this, 'maybe_redirect' ), 0 );

		if ( Options::get( 'redirects_log_404' ) ) {
			// Late, so canonical redirects and other plugins get their chance
			// to rescue the request before it counts as a 404.
			add_action( 'template_redirect', array( $this, 'maybe_log_404' ), 99 );
		}
	}

	/**
	 * Redirects the request when a rule matches.
	 *
	 * @param \WP $wp Current WordPress environment.
	 * @return void
	 */
	public function maybe_redirect( $wp ) {
		if ( ! empty( $wp->query_vars['rest_route'] ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Normalized and never output; only used to look up rules.
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$match = self::match(
			$uri,
			array(
				'case_insensitive' => (bool) Options::get( 'redirects_case_insensitive' ),
				'ignore_query'     => (bool) Options::get( 'redirects_ignore_query' ),
			)
		);

		if ( ! $match ) {
			return;
		}

		Repository::record_hit( $match['id'] );

		/**
		 * Fires when a redirect rule matched, just before the response is sent.
		 *
		 * @param array $match id, status, url (empty for 410 and 451), local.
		 */
		do_action( 'tagwright_seo_redirects_hit', $match );

		if ( in_array( $match['status'], Repository::gone_statuses(), true ) ) {
			// Let WordPress render the theme's 404 template, then swap the status.
			$this->gone        = $match['status'];
			$wp->query_vars    = array( 'error' => '404' );
			$wp->matched_rule  = '';
			$wp->matched_query = '';
			remove_action( 'template_redirect', 'redirect_canonical' );
			add_action( 'template_redirect', array( $this, 'send_gone_status' ), 0 );
			return;
		}

		if ( $match['local'] ) {
			wp_safe_redirect( $match['url'], $match['status'], 'Tagwright SEO' );
		} else {
			wp_redirect( $match['url'], $match['status'], 'Tagwright SEO' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The site owner chose this external target.
		}
		exit;
	}

	/**
	 * Replaces the 404 status with 410 or 451.
	 *
	 * @return void
	 */
	public function send_gone_status() {
		if ( $this->gone && ! headers_sent() ) {
			status_header( $this->gone );
		}
	}

	/**
	 * Finds the rule for a request and works out where it leads.
	 *
	 * Order: exact rules (the address with its query string, then without
	 * when query strings are ignored), then prefix rules from longest to
	 * shortest, then regex rules in the order they were added.
	 *
	 * @param string $uri     Request URI.
	 * @param array  $options case_insensitive, ignore_query.
	 * @return array|null id, status, url, local. Null when nothing matches
	 *                    or the rule would loop back to the same address.
	 */
	public static function match( $uri, array $options ) {
		list( $path, $query ) = Repository::normalize( $uri );

		if ( '' === $path ) {
			return null;
		}

		$case_insensitive = ! empty( $options['case_insensitive'] );
		$ignore_query     = ! empty( $options['ignore_query'] );
		$request_key      = Repository::key( $path, $query );

		// Exact rules. A rule written with the query string beats one without.
		$candidates = array();
		if ( '' !== $query ) {
			$candidates[ $request_key ] = '';
		}
		if ( '' === $query || $ignore_query ) {
			$candidates[ $path ] = $query;
		}

		$hashes = array();
		foreach ( array_keys( $candidates ) as $key ) {
			$hash = Repository::hash( (string) $key );
			if ( Repository::might_exist( $hash ) ) {
				$hashes[ $key ] = $hash;
			}
		}

		if ( $hashes ) {
			$rows = Repository::find_exact( array_values( $hashes ) );

			foreach ( $hashes as $key => $hash ) {
				foreach ( $rows as $row ) {
					if ( $row['source_hash'] !== $hash || ( ! $case_insensitive && (string) $key !== $row['source'] ) ) {
						continue;
					}

					$result = self::resolve( (int) $row['id'], (int) $row['status'], (string) $row['target'], array(), $candidates[ $key ], $request_key, $case_insensitive );
					if ( $result ) {
						return $result;
					}
				}
			}
		}

		// Prefix and regex rules, straight from the index.
		$lower_path = $case_insensitive ? Repository::lower( $path ) : $path;
		$subject    = $ignore_query ? $path : $request_key;
		$pass_query = $ignore_query ? $query : '';

		foreach ( Repository::patterns() as $rule ) {
			list( $id, $type, $source, $target, $status ) = $rule;

			if ( 'p' === $type ) {
				$prefix = $case_insensitive ? Repository::lower( $source ) : $source;
				if ( 0 !== strpos( $lower_path, $prefix ) ) {
					continue;
				}
				// The rest of the path after the prefix is available as $1.
				$captures = array( (string) substr( $path, strlen( $source ) ) );
				$result   = self::resolve( $id, $status, $target, $captures, $query, $request_key, $case_insensitive );
			} else {
				// A broken pattern simply never matches; it was validated on save.
				if ( ! @preg_match( Repository::regex( $source, $case_insensitive ), $subject, $found ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					continue;
				}
				$result = self::resolve( $id, $status, $target, array_slice( $found, 1 ), $pass_query, $request_key, $case_insensitive );
			}

			if ( $result ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Builds the final URL for a matched rule.
	 *
	 * @param int      $id               Rule id.
	 * @param int      $status           Status code.
	 * @param string   $target           Target as stored, may contain $1.
	 * @param string[] $captures         Values for $1, $2 and so on.
	 * @param string   $query            Query string to pass on, '' for none.
	 * @param string   $request_key      Normalized request, to catch loops.
	 * @param bool     $case_insensitive Compare without case.
	 * @return array|null Null when the rule would redirect to the request itself.
	 */
	public static function resolve( $id, $status, $target, array $captures, $query, $request_key, $case_insensitive ) {
		$result = array(
			'id'     => $id,
			'status' => $status,
			'url'    => '',
			'local'  => true,
		);

		if ( in_array( $status, Repository::gone_statuses(), true ) ) {
			return $result;
		}

		// Decide before filling in $1 and friends: a capture like "/evil.com"
		// must not turn "/$1" into a protocol-relative URL on another host.
		$relative = 0 === strpos( $target, '/' ) && 0 !== strpos( $target, '//' );
		// A target that starts with a capture ("$1") gets its path from the request.
		$from_capture = 0 === strpos( $target, '$' );

		if ( $captures ) {
			$target = preg_replace_callback(
				'/\$(\d{1,2})/',
				static fn( $m ) => $captures[ (int) $m[1] - 1 ] ?? '',
				$target
			);
		}

		if ( '' === $target ) {
			return null;
		}

		// A path filled in from a capture is a path on this site, never another host.
		if ( $from_capture && preg_match( '#^[/\\\\]#', $target ) ) {
			$relative = true;
		}

		if ( $relative ) {
			$target = '/' . ltrim( $target, '/\\' );
			$local  = true;
			$url    = home_url( $target );
		} else {
			$local = Repository::is_local( $target );
			$url   = $target;
		}

		if ( '' !== $query ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
		}

		if ( $local ) {
			list( $to_path, $to_query ) = Repository::normalize( $url );
			$to_key                     = Repository::key( $to_path, $to_query );
			$same                       = $case_insensitive ? Repository::lower( $to_key ) === Repository::lower( $request_key ) : $to_key === $request_key;
			if ( $same ) {
				return null;
			}
		}

		$result['url']   = $url;
		$result['local'] = $local;

		return $result;
	}

	/**
	 * Logs the request when it ended in a 404.
	 *
	 * @return void
	 */
	public function maybe_log_404() {
		if ( $this->gone || ! is_404() ) {
			return;
		}

		$uri                  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized below and escaped on output.
		list( $path, $query ) = Repository::normalize( $uri );
		$user_agent           = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( '' === $path || Log::should_skip( $path, $user_agent, Log::settings() ) ) {
			return;
		}

		$referrer = wp_get_raw_referer();

		Log::record(
			wp_check_invalid_utf8( Repository::key( $path, $query ), true ),
			$referrer ? esc_url_raw( $referrer ) : '',
			$user_agent
		);
		Repository::count_event( '404s' );
	}
}
