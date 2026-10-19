<?php
/**
 * Submission log.
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Features\Indexing;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the last few submissions so the site owner can see what was sent
 * and what the engines said. It is capped, so a plain option (not
 * autoloaded) is enough; a table would be overkill for 50 rows.
 */
class Log {

	/**
	 * Option name.
	 */
	const OPTION = 'tagwright_seo_indexing_log';

	/**
	 * Entries kept.
	 */
	const LIMIT = 50;

	/**
	 * Records one request.
	 *
	 * @param string $engine  `indexnow` or `google`.
	 * @param int    $count   URLs in the request.
	 * @param int    $status  HTTP status, 0 when the request failed before reaching the engine.
	 * @param string $message Short result.
	 * @return void
	 */
	public static function add( $engine, $count, $status, $message ) {
		$entries = self::all();

		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'engine'  => sanitize_key( $engine ),
				'count'   => (int) $count,
				'status'  => (int) $status,
				'message' => sanitize_text_field( $message ),
			)
		);

		update_option( self::OPTION, array_slice( $entries, 0, self::LIMIT ), false );
	}

	/**
	 * Every stored entry, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Entries ready for the admin screen, with formatted dates.
	 *
	 * @return array[]
	 */
	public static function for_display() {
		$engines = self::engine_names();
		$rows    = array();

		foreach ( self::all() as $entry ) {
			$time   = (int) ( $entry['time'] ?? 0 );
			$rows[] = array(
				'time'    => $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '',
				'engine'  => $engines[ $entry['engine'] ?? '' ] ?? (string) ( $entry['engine'] ?? '' ),
				'count'   => (int) ( $entry['count'] ?? 0 ),
				'status'  => (int) ( $entry['status'] ?? 0 ),
				'ok'      => in_array( (int) ( $entry['status'] ?? 0 ), array( 200, 202 ), true ),
				'message' => (string) ( $entry['message'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * Display names of the engines.
	 *
	 * @return array<string, string>
	 */
	public static function engine_names() {
		return array(
			'indexnow' => __( 'IndexNow', 'tagwright-seo' ),
			'google'   => __( 'Google', 'tagwright-seo' ),
		);
	}

	/**
	 * Deletes the log.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
