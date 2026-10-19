<?php
/**
 * 404 log storage.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Redirects;

use TagwrightSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one row per missing address with a hit counter, so a busy site with
 * a broken link writes one small row instead of thousands.
 */
class Log {

	/**
	 * Hard cap on rows, whatever the retention setting. Stops a bot that
	 * invents random URLs from filling the database.
	 */
	const MAX_ROWS = 10000;

	/**
	 * File extensions treated as static assets.
	 */
	const ASSET_PATTERN = '/\.(?:css|js|mjs|map|jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|woff2?|ttf|otf|eot|mp3|mp4|webm|ogg|wav|mov|zip|gz|rar|7z)$/i';

	/**
	 * User agents that are not people. Matching any of these words is
	 * enough; the list favours the crawlers that make up most 404 noise.
	 */
	const BOT_PATTERN = '/bot\b|bot\/|crawl|spider|slurp|scrape|fetch|curl|wget|python|httpclient|okhttp|go-http|java\/|libwww|axios|node-fetch|headless|phantom|lighthouse|pingdom|uptime|monitor|facebookexternalhit|bingpreview|mediapartners|semrush|ahrefs|mj12|dotbot|petalbot|bytespider|gptbot|claudebot|ccbot|yandex|baiduspider|duckduckgo|applebot|feed|preview/i';

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'tagwright_404_log';
	}

	/**
	 * Whether a 404 should be left out of the log.
	 *
	 * @param string $path       Normalized path.
	 * @param string $user_agent User agent.
	 * @param array  $settings   ignore_bots, ignore_files and exclude (newline separated paths).
	 * @return bool
	 */
	public static function should_skip( $path, $user_agent, array $settings ) {
		if ( ! empty( $settings['ignore_files'] ) && preg_match( self::ASSET_PATTERN, $path ) ) {
			return true;
		}

		if ( ! empty( $settings['ignore_bots'] ) && ( '' === trim( $user_agent ) || preg_match( self::BOT_PATTERN, $user_agent ) ) ) {
			return true;
		}

		if ( ! empty( $settings['exclude'] ) ) {
			$lower = Repository::lower( $path );
			foreach ( explode( "\n", $settings['exclude'] ) as $prefix ) {
				$prefix = Repository::lower( trim( $prefix ) );
				if ( '' !== $prefix && ( 0 === strpos( $lower, $prefix ) || rtrim( $prefix, '/' ) === $lower ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The log settings in the shape should_skip() expects.
	 *
	 * @return array
	 */
	public static function settings() {
		return array(
			'ignore_bots'  => (bool) Options::get( 'redirects_log_ignore_bots' ),
			'ignore_files' => (bool) Options::get( 'redirects_log_ignore_files' ),
			'exclude'      => (string) Options::get( 'redirects_log_exclude', '' ),
		);
	}

	/**
	 * Records a hit on a missing address: insert or bump, in one query.
	 *
	 * @param string $url        Path and query string.
	 * @param string $referrer   Referring URL.
	 * @param string $user_agent User agent.
	 * @return void
	 */
	public static function record( $url, $referrer, $user_agent ) {
		global $wpdb;

		$url   = substr( (string) $url, 0, Repository::MAX_LENGTH );
		$now   = current_time( 'mysql', true );
		$table = self::table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (url, url_hash, hits, referrer, user_agent, first_seen, last_seen) VALUES (%s, %s, 1, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, referrer = IF(VALUES(referrer) = '', referrer, VALUES(referrer)), user_agent = VALUES(user_agent), last_seen = VALUES(last_seen)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$url,
				md5( $url ),
				substr( (string) $referrer, 0, 1024 ),
				substr( (string) $user_agent, 0, 255 ),
				$now,
				$now
			)
		);

		// Pruning on every insert would be wasteful; now and then is enough
		// to keep a bot storm in check between the daily runs.
		if ( 1 === wp_rand( 1, 500 ) ) {
			self::enforce_cap();
		}
	}

	/**
	 * A page of log rows for the admin table.
	 *
	 * @param array $args search, orderby, order, page, per_page.
	 * @return array{items: array[], total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;

		$table = self::table();
		$where = '1=1';
		$vals  = array();

		if ( ! empty( $args['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where  = '(url LIKE %s OR referrer LIKE %s)';
			$vals[] = $like;
			$vals[] = $like;
		}

		$sortable = array( 'url', 'hits', 'last_seen', 'first_seen' );
		$orderby  = in_array( $args['orderby'] ?? '', $sortable, true ) ? $args['orderby'] : 'last_seen';
		$order    = 'asc' === strtolower( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;

		// $where only holds placeholders; every value goes through prepare().
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) $wpdb->get_var( $vals ? $wpdb->prepare( $count_sql, $vals ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", array_merge( $vals, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			ARRAY_A
		);

		return array(
			'items' => array_map( array( __CLASS__, 'present' ), (array) $rows ),
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
		$last  = strtotime( $row['last_seen'] . ' UTC' );
		$first = strtotime( $row['first_seen'] . ' UTC' );

		return array(
			'id'         => (int) $row['id'],
			'url'        => $row['url'],
			'link'       => home_url( $row['url'] ),
			'hits'       => (int) $row['hits'],
			'referrer'   => (string) $row['referrer'],
			'user_agent' => (string) $row['user_agent'],
			/* translators: %s: time span such as "3 hours". */
			'last_seen'  => sprintf( __( '%s ago', 'tagwright-seo' ), human_time_diff( $last ) ),
			/* translators: %s: time span such as "3 hours". */
			'first_seen' => sprintf( __( '%s ago', 'tagwright-seo' ), human_time_diff( $first ) ),
		);
	}

	/**
	 * Most-hit addresses, for the dashboard.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function top( $limit = 5 ) {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, url, hits, last_seen FROM {$table} ORDER BY hits DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return (array) $rows;
	}

	/**
	 * Number of logged addresses.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes log rows.
	 *
	 * @param int[] $ids Row ids.
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

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$holders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes the log row for an address, if there is one. Used after a
	 * redirect was created from the log.
	 *
	 * @param string $url Path and query string as logged.
	 * @return void
	 */
	public static function delete_url( $url ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'url_hash' => md5( (string) $url ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Daily clean-up: drops addresses nobody hit within the retention
	 * period, then trims to the row cap.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$days  = max( 1, (int) Options::get( 'redirects_log_retention', 30 ) );
		$table = self::table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		self::enforce_cap();
	}

	/**
	 * Removes the stalest rows above MAX_ROWS.
	 *
	 * @return void
	 */
	public static function enforce_cap() {
		global $wpdb;

		$table  = self::table();
		$cutoff = $wpdb->get_var( $wpdb->prepare( "SELECT last_seen FROM {$table} ORDER BY last_seen DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen <= %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}
}
