<?php
/**
 * Redirect rules storage.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Redirects;

use TagwrightSEO\Installer;
use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes redirect rules, and keeps the small lookup index the
 * front end uses.
 *
 * Other features and importers create rules through the static API here,
 * for example:
 *
 *     Repository::add( '/old-page', '/new-page/', 301 );
 *     Repository::add( '^/blog/(\d+)/(.*)$', '/news/$2/', 301, 'regex' );
 *
 * How matching works, in short:
 *
 * - Every rule stores an md5 of its lower-cased source, so exact rules are
 *   found with one indexed query, whatever the case setting is. The case
 *   check happens in PHP afterwards.
 * - The autoloaded index option holds the first 8 characters of every exact
 *   hash (up to HASH_LIMIT rules). Requests that cannot match any rule, which
 *   is nearly all of them, never touch the database.
 * - Prefix and regex rules are copied into the index too, so they can be
 *   checked without a query. When the list is large it moves to its own
 *   option that is not autoloaded.
 */
class Repository {

	/**
	 * Autoloaded lookup index.
	 */
	const INDEX_OPTION = 'tagwright_seo_redirects_index';

	/**
	 * Prefix and regex rules once they are too big to autoload.
	 */
	const PATTERNS_OPTION = 'tagwright_seo_redirects_patterns';

	/**
	 * Object cache group.
	 */
	const CACHE_GROUP = 'tagwright_seo_redirects';

	/**
	 * Up to this many exact rules get a hash prefix in the autoloaded index
	 * (9 bytes each). Above it every request runs the indexed query instead.
	 */
	const HASH_LIMIT = 500;

	/**
	 * Largest serialized pattern list kept in the autoloaded index.
	 */
	const INLINE_BYTES = 8192;

	/**
	 * Longest source or target we accept.
	 */
	const MAX_LENGTH = 2048;

	/**
	 * Index loaded for this request.
	 *
	 * @var array|null
	 */
	private static $index = null;

	/**
	 * While true, changed() only notes that the index is stale. Bulk imports
	 * set it so the index is rebuilt once instead of once per row.
	 *
	 * @var bool
	 */
	private static $deferred = false;

	/**
	 * Whether a change happened while deferred.
	 *
	 * @var bool
	 */
	private static $stale = false;

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'tagwright_redirects';
	}

	/* ------------------------------------------------------------------
	 * Vocabulary
	 * --------------------------------------------------------------- */

	/**
	 * Status codes with a label for dropdowns.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels() {
		return array(
			'301' => __( '301 Moved permanently', 'tagwright-seo' ),
			'302' => __( '302 Found (temporary)', 'tagwright-seo' ),
			'307' => __( '307 Temporary redirect', 'tagwright-seo' ),
			'410' => __( '410 Content deleted', 'tagwright-seo' ),
			'451' => __( '451 Unavailable for legal reasons', 'tagwright-seo' ),
		);
	}

	/**
	 * Allowed status codes.
	 *
	 * @return int[]
	 */
	public static function statuses() {
		return array( 301, 302, 307, 410, 451 );
	}

	/**
	 * Status codes that answer the request themselves instead of redirecting.
	 *
	 * @return int[]
	 */
	public static function gone_statuses() {
		return array( 410, 451 );
	}

	/**
	 * Match types with a label.
	 *
	 * @return array<string, string>
	 */
	public static function match_labels() {
		return array(
			'exact'  => __( 'Exact', 'tagwright-seo' ),
			'prefix' => __( 'Starts with', 'tagwright-seo' ),
			'regex'  => __( 'Regex', 'tagwright-seo' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Normalizing
	 * --------------------------------------------------------------- */

	/**
	 * Path of the home URL without a trailing slash, '' for a root install.
	 *
	 * @return string
	 */
	public static function home_path() {
		// Not cached in a static: it has to follow switch_to_blog() on multisite.
		return rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	}

	/**
	 * Splits a URL or path into a clean path and its query string.
	 *
	 * The path is decoded, starts with a slash, is relative to the home URL
	 * (so subfolder installs work) and has no trailing slash unless it is the
	 * home page. Requests and stored rules go through the same function, which
	 * is what lets /Old-Page/ and /old-page meet in the middle.
	 *
	 * @param string $url           URL, path, or path with query string.
	 * @param bool   $keep_trailing Keep a trailing slash, used for prefix rules
	 *                              where "/blog/" and "/blog" mean different things.
	 * @return array{0: string, 1: string} Path ('' when empty) and query.
	 */
	public static function normalize( $url, $keep_trailing = false ) {
		$url = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $url ) );

		if ( '' === $url ) {
			return array( '', '' );
		}

		if ( preg_match( '#^(https?:)?//#i', $url ) ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) ) {
				return array( '', '' );
			}
			$url = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}

		$fragment = strpos( $url, '#' );
		if ( false !== $fragment ) {
			$url = substr( $url, 0, $fragment );
		}

		$query    = '';
		$question = strpos( $url, '?' );
		if ( false !== $question ) {
			$query = trim( substr( $url, $question + 1 ) );
			$url   = substr( $url, 0, $question );
		}

		$path = '/' . ltrim( rawurldecode( $url ), '/' );
		$path = preg_replace( '#/{2,}#', '/', $path );

		$home = self::home_path();
		if ( '' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
			$path = '' === $path ? '/' : $path;
		}

		if ( ! $keep_trailing && '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return array( '' === $path ? '/' : $path, $query );
	}

	/**
	 * Joins a path and query string back into one key.
	 *
	 * @param string $path  Path.
	 * @param string $query Query string.
	 * @return string
	 */
	public static function key( $path, $query = '' ) {
		return '' === $query ? $path : $path . '?' . $query;
	}

	/**
	 * Lower case that understands accented paths when mbstring is around.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/**
	 * Hash stored in source_hash. Always of the lower-cased source, so the
	 * case setting can change without rewriting the table.
	 *
	 * @param string $source Normalized source.
	 * @return string
	 */
	public static function hash( $source ) {
		return md5( self::lower( $source ) );
	}

	/**
	 * Turns a stored regex source into a PCRE pattern.
	 *
	 * People type patterns without delimiters, so a `~` delimiter is added
	 * and any bare `~` inside is escaped.
	 *
	 * @param string $source           Pattern as entered.
	 * @param bool   $case_insensitive Add the i flag.
	 * @return string
	 */
	public static function regex( $source, $case_insensitive ) {
		return '~' . preg_replace( '/(?<!\\\\)~/', '\\~', (string) $source ) . '~u' . ( $case_insensitive ? 'i' : '' );
	}

	/**
	 * Whether a regex source compiles.
	 *
	 * @param string $source Pattern as entered.
	 * @return bool
	 */
	public static function is_valid_regex( $source ) {
		// The @ is deliberate: an invalid pattern raises a warning we turn into a form error.
		return false !== @preg_match( self::regex( $source, false ), '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Cleans the "excluded paths" setting: one normalized path per line.
	 *
	 * @param mixed $value Raw textarea value.
	 * @return string
	 */
	public static function sanitize_exclusions( $value ) {
		$paths = array();

		foreach ( preg_split( '/[\r\n]+/', sanitize_textarea_field( (string) $value ) ) as $line ) {
			list( $path ) = self::normalize( $line, true );
			if ( '' !== $path && '/' !== $path ) {
				$paths[] = $path;
			}
		}

		return implode( "\n", array_slice( array_unique( $paths ), 0, 200 ) );
	}

	/* ------------------------------------------------------------------
	 * Validation
	 * --------------------------------------------------------------- */

	/**
	 * Validates and cleans a rule before it is saved.
	 *
	 * @param array $input Raw fields: source, target, status, match_type, notes, enabled.
	 * @param int   $id    Rule being edited, 0 for a new one.
	 * @return array|\WP_Error Clean columns ready for the table.
	 */
	public static function prepare( array $input, $id = 0 ) {
		$type = isset( $input['match_type'] ) ? sanitize_key( $input['match_type'] ) : 'exact';
		$type = isset( self::match_labels()[ $type ] ) ? $type : 'exact';

		$status = isset( $input['status'] ) && '' !== $input['status'] ? (int) $input['status'] : (int) Options::get( 'redirects_default_status', 301 );
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return new \WP_Error( 'tagwright_seo_redirect_status', __( 'Choose a valid redirect type.', 'tagwright-seo' ), array( 'status' => 400 ) );
		}

		$raw_source = trim( (string) ( $input['source'] ?? '' ) );

		if ( 'regex' === $type ) {
			$source = preg_replace( '/[\x00-\x1F\x7F]/', '', $raw_source );
			if ( '' === $source ) {
				return new \WP_Error( 'tagwright_seo_redirect_source', __( 'Enter a pattern to match.', 'tagwright-seo' ), array( 'status' => 400 ) );
			}
			if ( ! self::is_valid_regex( $source ) ) {
				return new \WP_Error( 'tagwright_seo_redirect_regex', __( 'That regular expression is not valid. Check the brackets and escape special characters with a backslash.', 'tagwright-seo' ), array( 'status' => 400 ) );
			}
		} else {
			list( $path, $query ) = self::normalize( $raw_source, 'prefix' === $type );
			$source               = 'prefix' === $type ? $path : self::key( $path, $query );
			if ( '' === $path || '' === $raw_source ) {
				return new \WP_Error( 'tagwright_seo_redirect_source', __( 'Enter the old address to redirect.', 'tagwright-seo' ), array( 'status' => 400 ) );
			}
		}

		if ( strlen( $source ) > self::MAX_LENGTH ) {
			return new \WP_Error( 'tagwright_seo_redirect_length', __( 'That address is too long.', 'tagwright-seo' ), array( 'status' => 400 ) );
		}

		$target = '';
		if ( ! in_array( $status, self::gone_statuses(), true ) ) {
			$target = self::sanitize_target( (string) ( $input['target'] ?? '' ) );
			if ( '' === $target ) {
				return new \WP_Error( 'tagwright_seo_redirect_target', __( 'Enter where the redirect should go: a path such as /new-page/ or a full URL.', 'tagwright-seo' ), array( 'status' => 400 ) );
			}
			if ( 'exact' === $type && self::points_to_itself( $source, $target ) ) {
				return new \WP_Error( 'tagwright_seo_redirect_loop', __( 'The redirect points to its own address, which would loop forever.', 'tagwright-seo' ), array( 'status' => 400 ) );
			}
		}

		$existing = self::find( $source, $type );
		if ( $existing && (int) $existing['id'] !== (int) $id ) {
			return new \WP_Error(
				'tagwright_seo_redirect_exists',
				__( 'There is already a redirect for this address. Edit that one instead.', 'tagwright-seo' ),
				array(
					'status' => 409,
					'id'     => (int) $existing['id'],
				)
			);
		}

		return array(
			'source'      => $source,
			'source_hash' => self::hash( $source ),
			'match_type'  => $type,
			'target'      => $target,
			'status'      => $status,
			'enabled'     => isset( $input['enabled'] ) ? ( rest_sanitize_boolean( $input['enabled'] ) ? 1 : 0 ) : 1,
			'notes'       => substr( sanitize_text_field( (string) ( $input['notes'] ?? '' ) ), 0, 255 ),
		);
	}

	/**
	 * Cleans a target: a full http(s) URL, or a path on this site.
	 *
	 * `$1` style placeholders survive, they are filled in at redirect time.
	 *
	 * @param string $target Raw target.
	 * @return string Empty when unusable.
	 */
	public static function sanitize_target( $target ) {
		$target = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $target ) );

		if ( '' === $target || strlen( $target ) > self::MAX_LENGTH ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $target ) ) {
			return (string) esc_url_raw( $target, array( 'http', 'https' ) );
		}

		// Protocol-relative and other schemes (javascript:, data:) are not targets.
		if ( 0 === strpos( $target, '//' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) ) {
			return '';
		}

		return (string) esc_url_raw( '/' . ltrim( $target, '/' ) );
	}

	/**
	 * Whether an exact rule would send people back to the same address.
	 *
	 * @param string $source Normalized source.
	 * @param string $target Clean target.
	 * @return bool
	 */
	public static function points_to_itself( $source, $target ) {
		if ( ! self::is_local( $target ) ) {
			return false;
		}

		list( $path, $query ) = self::normalize( $target );

		return self::lower( self::key( $path, $query ) ) === self::lower( $source );
	}

	/**
	 * Whether a URL or path belongs to this site.
	 *
	 * @param string $url URL or path.
	 * @return bool
	 */
	public static function is_local( $url ) {
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			return true;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return $host && $home && strtolower( $host ) === strtolower( $home );
	}

	/* ------------------------------------------------------------------
	 * Public API for other features and importers
	 * --------------------------------------------------------------- */

	/**
	 * Adds a rule.
	 *
	 * @param string $source     Old address (exact or prefix) or pattern (regex).
	 * @param string $target     New address. Ignored for 410 and 451.
	 * @param int    $status     301, 302, 307, 410 or 451.
	 * @param string $match_type exact, prefix or regex.
	 * @param array  $args       Optional notes and enabled.
	 * @return int|\WP_Error New rule id.
	 */
	public static function add( $source, $target = '', $status = 301, $match_type = 'exact', array $args = array() ) {
		global $wpdb;

		$data = self::prepare(
			array_merge(
				$args,
				array(
					'source'     => $source,
					'target'     => $target,
					'status'     => $status,
					'match_type' => $match_type,
				)
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now                = current_time( 'mysql', true );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		if ( ! $wpdb->insert( self::table(), $data ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new \WP_Error( 'tagwright_seo_redirect_db', __( 'The redirect could not be saved.', 'tagwright-seo' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;
		self::changed();

		/**
		 * Fires after a redirect rule is created.
		 *
		 * @param int   $id   Rule id.
		 * @param array $data Saved columns.
		 */
		do_action( 'tagwright_seo_redirects_added', $id, $data );

		return $id;
	}

	/**
	 * Updates a rule. Fields left out keep their current value.
	 *
	 * @param int   $id     Rule id.
	 * @param array $fields source, target, status, match_type, notes, enabled.
	 * @return true|\WP_Error
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;

		$current = self::get( $id );
		if ( ! $current ) {
			return new \WP_Error( 'tagwright_seo_redirect_missing', __( 'That redirect no longer exists.', 'tagwright-seo' ), array( 'status' => 404 ) );
		}

		$data = self::prepare( array_merge( $current, $fields ), (int) $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['updated_at'] = current_time( 'mysql', true );

		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::changed();

		return true;
	}

	/**
	 * Deletes rules.
	 *
	 * @param int[] $ids Rule ids.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}

		$table   = self::table();
		$holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$holders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery

		self::changed();

		return $deleted;
	}

	/**
	 * Switches rules on or off.
	 *
	 * @param int[] $ids     Rule ids.
	 * @param bool  $enabled New state.
	 * @return int Rows changed.
	 */
	public static function set_enabled( array $ids, $enabled ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}

		$table   = self::table();
		$holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$changed = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "UPDATE {$table} SET enabled = %d, updated_at = %s WHERE id IN ({$holders})", array_merge( array( $enabled ? 1 : 0, current_time( 'mysql', true ) ), $ids ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		);

		self::changed();

		return $changed;
	}

	/**
	 * One rule.
	 *
	 * @param int $id Rule id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return $row ? $row : null;
	}

	/**
	 * Finds a rule by its source.
	 *
	 * Sources that only differ in case count as the same rule while matching
	 * ignores case, so people can't add two rules where only one could win.
	 *
	 * @param string $source     Normalized source.
	 * @param string $match_type Match type.
	 * @return array|null
	 */
	public static function find( $source, $match_type = 'exact' ) {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source_hash = %s AND match_type = %s", self::hash( $source ), $match_type ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$case_insensitive = (bool) Options::get( 'redirects_case_insensitive' );
		foreach ( (array) $rows as $row ) {
			if ( $row['source'] === $source || $case_insensitive ) {
				return $row;
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------
	 * Admin listing and CSV
	 * --------------------------------------------------------------- */

	/**
	 * A page of rules for the admin table.
	 *
	 * @param array $args search, match_type, status, orderby, order, page, per_page.
	 * @return array{items: array[], total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;

		$table = self::table();
		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(source LIKE %s OR target LIKE %s OR notes LIKE %s)';
			array_push( $vals, $like, $like, $like );
		}

		if ( ! empty( $args['match_type'] ) && isset( self::match_labels()[ $args['match_type'] ] ) ) {
			$where[] = 'match_type = %s';
			$vals[]  = $args['match_type'];
		}

		if ( ! empty( $args['status'] ) && in_array( (int) $args['status'], self::statuses(), true ) ) {
			$where[] = 'status = %d';
			$vals[]  = (int) $args['status'];
		}

		$sortable = array( 'id', 'source', 'target', 'status', 'hits', 'last_hit', 'created_at' );
		$orderby  = in_array( $args['orderby'] ?? '', $sortable, true ) ? $args['orderby'] : 'id';
		$order    = 'asc' === strtolower( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$where    = implode( ' AND ', $where );

		// $where only holds placeholders; every value goes through prepare().
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) $wpdb->get_var( $vals ? $wpdb->prepare( $count_sql, $vals ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", array_merge( $vals, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			ARRAY_A
		);

		return array(
			'items' => array_map( array( __CLASS__, 'present' ), (array) $items ),
			'total' => $total,
		);
	}

	/**
	 * Shapes a row for the REST response.
	 *
	 * @param array $row Table row.
	 * @return array
	 */
	public static function present( array $row ) {
		$last_hit = $row['last_hit'] ? strtotime( $row['last_hit'] . ' UTC' ) : 0;

		return array(
			'id'         => (int) $row['id'],
			'source'     => $row['source'],
			'target'     => $row['target'],
			'match_type' => $row['match_type'],
			'status'     => (int) $row['status'],
			'enabled'    => (bool) $row['enabled'],
			'hits'       => (int) $row['hits'],
			'notes'      => (string) $row['notes'],
			'last_hit'   => $last_hit ? sprintf(
				/* translators: %s: time span such as "3 hours". */
				__( '%s ago', 'tagwright-seo' ),
				human_time_diff( $last_hit )
			) : '',
			'test_url'   => 'regex' === $row['match_type'] ? '' : home_url( $row['source'] ),
		);
	}

	/**
	 * Every rule as CSV rows (source, target, status, match_type).
	 *
	 * @return string CSV text with a header row.
	 */
	public static function export_csv() {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT source, target, status, match_type FROM {$table} ORDER BY id ASC", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$lines = array( 'source,target,status,match_type' );
		foreach ( (array) $rows as $row ) {
			$lines[] = implode( ',', array_map( array( __CLASS__, 'csv_cell' ), $row ) );
		}

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Quotes one CSV cell.
	 *
	 * Cells that a spreadsheet would run as a formula get a leading quote
	 * mark, the usual defence against CSV injection.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			$value = "'" . $value;
		}

		// The importer also splits on semicolons, so those need quotes too.
		if ( preg_match( '/[",;\r\n]/', $value ) ) {
			$value = '"' . str_replace( '"', '""', $value ) . '"';
		}

		return $value;
	}

	/**
	 * Imports parsed CSV rows. Existing rules with the same source are
	 * updated, so importing the same file twice is harmless.
	 *
	 * @param array[] $rows   Lists of [source, target, status, match_type].
	 * @param int     $offset Line number of the first row, for error messages.
	 * @return array{added: int, updated: int, errors: string[]}
	 */
	public static function import( array $rows, $offset = 0 ) {
		$rules = array();

		foreach ( array_values( $rows ) as $index => $row ) {
			$row   = array_values( (array) $row );
			$input = array(
				'source'     => ltrim( (string) ( $row[0] ?? '' ), "'" ),
				'target'     => ltrim( (string) ( $row[1] ?? '' ), "'" ),
				'status'     => trim( (string) ( $row[2] ?? '' ) ),
				'match_type' => strtolower( trim( (string) ( $row[3] ?? 'exact' ) ) ),
			);

			if ( '' !== trim( $input['source'] ) ) {
				$rules[ $index ] = $input;
			}
		}

		$result = self::import_rules( $rules );
		$errors = array();

		foreach ( $result['errors'] as $index => $error ) {
			$errors[] = sprintf(
				/* translators: 1: line number in the CSV file, 2: error message. */
				__( 'Line %1$d: %2$s', 'tagwright-seo' ),
				$offset + $index + 1,
				$error
			);
		}

		$result['errors'] = $errors;

		return $result;
	}

	/**
	 * Saves a list of rules in one go: new sources are added, sources that
	 * already have a rule update it. The lookup index is rebuilt once at the
	 * end rather than after every row.
	 *
	 * Used by the CSV import and by the importers for other plugins.
	 *
	 * @param array[] $rules Rules with source, target, status, match_type and
	 *                       optionally enabled, notes, hits and last_hit (GMT).
	 * @return array{added: int, updated: int, errors: array<int|string, string>}
	 *               Errors are keyed like the rules they belong to.
	 */
	public static function import_rules( array $rules ) {
		$result = array(
			'added'   => 0,
			'updated' => 0,
			'errors'  => array(),
		);

		self::$deferred = true;

		try {
			foreach ( $rules as $key => $rule ) {
				$saved = self::import_rule( (array) $rule );

				if ( is_wp_error( $saved ) ) {
					$result['errors'][ $key ] = $saved->get_error_message();
				} else {
					++$result[ $saved ];
				}
			}
		} finally {
			self::$deferred = false;
			if ( self::$stale ) {
				self::changed();
			}
		}

		return $result;
	}

	/**
	 * Adds or updates one imported rule.
	 *
	 * @param array $rule Rule, see import_rules().
	 * @return string|\WP_Error `added` or `updated`.
	 */
	private static function import_rule( array $rule ) {
		$fields = array_intersect_key( $rule, array_flip( array( 'source', 'target', 'status', 'match_type', 'notes', 'enabled' ) ) );

		$fields['match_type'] = empty( $fields['match_type'] ) ? 'exact' : $fields['match_type'];

		$outcome = 'added';
		$id      = self::add( $fields['source'] ?? '', $fields['target'] ?? '', $fields['status'] ?? '', $fields['match_type'], $fields );

		if ( is_wp_error( $id ) && 'tagwright_seo_redirect_exists' === $id->get_error_code() ) {
			$existing = (int) $id->get_error_data()['id'];
			$updated  = self::update( $existing, $fields );
			$id       = is_wp_error( $updated ) ? $updated : $existing;
			$outcome  = 'updated';
		}

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( ! empty( $rule['hits'] ) ) {
			self::import_hits( $id, (int) $rule['hits'], (string) ( $rule['last_hit'] ?? '' ) );
		}

		return $outcome;
	}

	/**
	 * Carries a hit count over from another plugin. Only ever raises the
	 * count, so importing twice does not add the same hits again and does
	 * not wipe hits counted here since.
	 *
	 * @param int    $id       Rule id.
	 * @param int    $hits     Hits in the other plugin.
	 * @param string $last_hit Last hit, GMT MySQL date, or ''.
	 * @return void
	 */
	private static function import_hits( $id, $hits, $last_hit ) {
		global $wpdb;

		$table = self::table();

		if ( '' !== $last_hit ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = %d, last_hit = %s WHERE id = %d AND hits < %d", $hits, $last_hit, $id, $hits ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = %d WHERE id = %d AND hits < %d", $hits, $id, $hits ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}

	/* ------------------------------------------------------------------
	 * Slug changes
	 * --------------------------------------------------------------- */

	/**
	 * Records that content moved from one address to another.
	 *
	 * - Adds (or repoints) a 301 from the old address to the new one.
	 * - Repoints rules that sent people to the old address, so there are
	 *   no chains of redirects.
	 * - Removes rules that would send people away from the new address,
	 *   which matters when a slug is changed back.
	 * - Optionally covers everything below the old address, for pages with
	 *   child pages.
	 *
	 * @param string $old_url      Old permalink.
	 * @param string $new_url      New permalink.
	 * @param bool   $with_children Also redirect /old/anything to /new/anything.
	 * @param string $note         Note stored on the new rules.
	 * @return void
	 */
	public static function moved( $old_url, $new_url, $with_children = false, $note = '' ) {
		global $wpdb;

		list( $old_path ) = self::normalize( $old_url );
		list( $new_path ) = self::normalize( $new_url );
		$new_target       = self::normalize( $new_url, true )[0];

		if ( '' === $old_path || '/' === $old_path || self::lower( $old_path ) === self::lower( $new_path ) ) {
			return;
		}

		$table          = self::table();
		self::$deferred = true;

		// The new address is live, nothing may redirect away from it.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE (match_type = 'exact' AND source_hash = %s) OR (match_type = 'prefix' AND source_hash = %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::hash( $new_path ),
				self::hash( $new_path . '/' )
			)
		);

		// Rules that led to the old address now lead straight to the new one.
		$old_targets = array_unique( array( $old_path, trailingslashit( $old_path ), home_url( $old_path ), home_url( trailingslashit( $old_path ) ) ) );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET target = %s, updated_at = %s WHERE target IN (%s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $new_target, current_time( 'mysql', true ) ), array_pad( $old_targets, 4, $old_path ) )
			)
		);

		$existing = self::find( $old_path, 'exact' );
		if ( $existing ) {
			self::update(
				(int) $existing['id'],
				array(
					'target'  => $new_target,
					'status'  => 301,
					'enabled' => 1,
				)
			);
		} else {
			self::add( $old_path, $new_target, 301, 'exact', array( 'notes' => $note ) );
		}

		if ( $with_children ) {
			$prefix   = $old_path . '/';
			$children = rtrim( $new_path, '/' ) . '/$1';
			$existing = self::find( $prefix, 'prefix' );

			if ( $existing ) {
				self::update( (int) $existing['id'], array( 'target' => $children ) );
			} else {
				self::add( $prefix, $children, 301, 'prefix', array( 'notes' => $note ) );
			}
		}

		self::$deferred = false;
		self::changed();
	}

	/* ------------------------------------------------------------------
	 * Front end lookups
	 * --------------------------------------------------------------- */

	/**
	 * The autoloaded lookup index.
	 *
	 * @return array{exact: int, hashes: ?string, rules: ?array, count: int}
	 */
	public static function index() {
		if ( null === self::$index ) {
			$index = get_option( self::INDEX_OPTION );

			if ( ! is_array( $index ) || ! isset( $index['exact'] ) ) {
				$index = self::rebuild_index();
			}

			self::$index = $index;
		}

		return self::$index;
	}

	/**
	 * Enabled prefix and regex rules, longest prefix first, then regex rules
	 * in the order they were added.
	 *
	 * @return array[] Lists of [id, type, source, target, status].
	 */
	public static function patterns() {
		$index = self::index();

		if ( ! $index['count'] ) {
			return array();
		}

		if ( is_array( $index['rules'] ) ) {
			return $index['rules'];
		}

		$rules = get_option( self::PATTERNS_OPTION, array() );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Whether an exact hash might be in the table, without a query.
	 *
	 * @param string $hash Full md5.
	 * @return bool False only when the rule is certainly absent.
	 */
	public static function might_exist( $hash ) {
		$index = self::index();

		if ( ! $index['exact'] ) {
			return false;
		}

		if ( null === $index['hashes'] ) {
			return true;
		}

		return false !== strpos( $index['hashes'], ' ' . substr( $hash, 0, 8 ) );
	}

	/**
	 * Enabled exact rules for a set of hashes, in one indexed query.
	 *
	 * @param string[] $hashes Source hashes.
	 * @return array[] Rows with id, source, source_hash, target, status.
	 */
	public static function find_exact( array $hashes ) {
		global $wpdb;

		$hashes = array_values( array_unique( $hashes ) );
		if ( ! $hashes ) {
			return array();
		}

		$key   = 'exact:' . md5( implode( ',', $hashes ) ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$found = false;
		$rows  = wp_cache_get( $key, self::CACHE_GROUP, false, $found );

		if ( $found && is_array( $rows ) ) {
			return $rows;
		}

		$table   = self::table();
		$holders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id, source, source_hash, target, status FROM {$table} WHERE source_hash IN ({$holders}) AND match_type = 'exact' AND enabled = 1", $hashes ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			ARRAY_A
		);
		$rows    = is_array( $rows ) ? $rows : array();

		wp_cache_set( $key, $rows, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $rows;
	}

	/**
	 * Counts a hit: one UPDATE on the rule, one upsert on today's stats.
	 *
	 * @param int $id Rule id.
	 * @return void
	 */
	public static function record_hit( $id ) {
		global $wpdb;

		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		self::count_event( 'redirect_hits' );
	}

	/**
	 * Adds one to today's value of a metric in the core stats table.
	 *
	 * Stats::record() overwrites a value, which suits daily snapshots. Hits
	 * and 404s happen one at a time, so they are counted with an atomic
	 * upsert instead, and today's number is live on the dashboard.
	 *
	 * @param string $metric `redirect_hits` or `404s`.
	 * @return void
	 */
	public static function count_event( $metric ) {
		global $wpdb;

		$table = Installer::get_table_names()['stats'];
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (stat_date, source, metric, dimension, value) VALUES (%s, 'redirects', %s, '', 1) ON DUPLICATE KEY UPDATE value = value + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				wp_date( 'Y-m-d' ),
				$metric
			)
		);
	}

	/**
	 * Daily stats hook: starts today's counters at zero, so quiet days show
	 * as 0 on the charts instead of a gap.
	 *
	 * @return void
	 */
	public static function seed_daily_stats() {
		global $wpdb;

		$table = Installer::get_table_names()['stats'];
		foreach ( array( 'redirect_hits', '404s' ) as $metric ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT INTO {$table} (stat_date, source, metric, dimension, value) VALUES (%s, 'redirects', %s, '', 0) ON DUPLICATE KEY UPDATE value = value", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					wp_date( 'Y-m-d' ),
					$metric
				)
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Index maintenance
	 * --------------------------------------------------------------- */

	/**
	 * Call after any change to rules: rebuilds the index and expires cached
	 * lookups.
	 *
	 * @return void
	 */
	public static function changed() {
		if ( self::$deferred ) {
			self::$stale = true;
			return;
		}

		self::$stale = false;
		self::rebuild_index();
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Rebuilds the lookup index from the table.
	 *
	 * @return array The new index.
	 */
	public static function rebuild_index() {
		global $wpdb;

		$table = self::table();
		$index = array(
			'exact'  => 0,
			'hashes' => '',
			'rules'  => array(),
			'count'  => 0,
		);

		// Before the table exists (fresh install mid-request) the empty index is right.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $exists ) {
			$index['exact'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE match_type = 'exact' AND enabled = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

			if ( $index['exact'] > self::HASH_LIMIT ) {
				$index['hashes'] = null;
			} elseif ( $index['exact'] ) {
				$hashes = $wpdb->get_col( "SELECT source_hash FROM {$table} WHERE match_type = 'exact' AND enabled = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				foreach ( array_unique( (array) $hashes ) as $hash ) {
					$index['hashes'] .= ' ' . substr( $hash, 0, 8 );
				}
			}

			$rows           = $wpdb->get_results( "SELECT id, match_type, source, target, status FROM {$table} WHERE match_type IN ('prefix', 'regex') AND enabled = 1 ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$index['rules'] = self::compact_patterns( (array) $rows );
		}

		$index['count'] = count( $index['rules'] );

		if ( strlen( serialize( $index['rules'] ) ) > self::INLINE_BYTES ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Measuring what WordPress will store.
			update_option( self::PATTERNS_OPTION, $index['rules'], false );
			$index['rules'] = null;
		} else {
			delete_option( self::PATTERNS_OPTION );
		}

		update_option( self::INDEX_OPTION, $index, true );
		self::$index = $index;

		return $index;
	}

	/**
	 * Orders and shrinks prefix and regex rows for the index.
	 *
	 * Longer prefixes are more specific, so they are tried first. Regex
	 * rules keep the order they were added in.
	 *
	 * @param array[] $rows Rows with id, match_type, source, target, status.
	 * @return array[] Lists of [id, type, source, target, status].
	 */
	public static function compact_patterns( array $rows ) {
		$prefix = array();
		$regex  = array();

		foreach ( $rows as $row ) {
			$rule = array( (int) $row['id'], 'prefix' === $row['match_type'] ? 'p' : 'r', (string) $row['source'], (string) $row['target'], (int) $row['status'] );
			if ( 'p' === $rule[1] ) {
				$prefix[] = $rule;
			} else {
				$regex[] = $rule;
			}
		}

		usort( $prefix, static fn( $a, $b ) => strlen( $b[2] ) <=> strlen( $a[2] ) ?: $a[0] <=> $b[0] );

		return array_merge( $prefix, $regex );
	}

	/**
	 * Forgets the per-request copy of the index. Used by tests and after
	 * switch_to_blog().
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$index = null;
	}
}
